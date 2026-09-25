<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Infrastructure\InMemory;

use RomainMillan\WebPushNotification\Application\Port\SubscriptionReadModel;
use RomainMillan\WebPushNotification\Application\ReadModel\SubscriptionPeriod;
use RomainMillan\WebPushNotification\Application\ReadModel\SubscriptionView;
use RomainMillan\WebPushNotification\Domain\Exception\SubscriptionAlreadyExists;
use RomainMillan\WebPushNotification\Domain\Exception\SubscriptionNotFound;
use RomainMillan\WebPushNotification\Domain\Subscription\AllowedPushServices;
use RomainMillan\WebPushNotification\Domain\Subscription\EndpointFingerprint;
use RomainMillan\WebPushNotification\Domain\Subscription\Owner;
use RomainMillan\WebPushNotification\Domain\Subscription\OwnerSubscriptions;
use RomainMillan\WebPushNotification\Domain\Subscription\PurgeableSubscriptions;
use RomainMillan\WebPushNotification\Domain\Subscription\Subscription;
use RomainMillan\WebPushNotification\Domain\Subscription\SubscriptionId;
use RomainMillan\WebPushNotification\Domain\Subscription\SubscriptionRepository;
use RomainMillan\WebPushNotification\Infrastructure\Persistence\SubscriptionRowMapper;

use function Symfony\Component\String\u;

/**
 * Reference adapter, for tests (yours included). It stores ROWS, not objects: every
 * read reconstitutes a fresh aggregate, exactly like the Doctrine and Eloquent
 * adapters, so no test passes thanks to shared object identity.
 *
 * @phpstan-import-type SubscriptionRow from SubscriptionRowMapper
 */
final class InMemorySubscriptionRepository implements SubscriptionRepository, PurgeableSubscriptions, SubscriptionReadModel
{
    /** @var array<string, SubscriptionRow> keyed by id */
    private array $rows = [];

    public function __construct(
        private readonly SubscriptionRowMapper $subscriptionRowMapper,
        private readonly AllowedPushServices $allowedPushServices,
    ) {
    }

    public function get(SubscriptionId $id): Subscription
    {
        return isset($this->rows[$id->toString()])
            ? $this->subscriptionRowMapper->fromRow($this->rows[$id->toString()])
            : throw SubscriptionNotFound::withId($id->toString());
    }

    public function hasFingerprint(EndpointFingerprint $fingerprint): bool
    {
        return [] !== $this->rowsWhere(static fn (array $row): bool => $row['endpoint_hash'] === $fingerprint->toString());
    }

    public function getByFingerprint(EndpointFingerprint $fingerprint): Subscription
    {
        foreach ($this->rowsWhere(static fn (array $row): bool => $row['endpoint_hash'] === $fingerprint->toString()) as $row) {
            return $this->subscriptionRowMapper->fromRow($row);
        }

        throw SubscriptionNotFound::withId($fingerprint->short());
    }

    public function ownedBy(Owner $owner): OwnerSubscriptions
    {
        $subscriberId = $this->subscriberIdOf($owner);

        return new OwnerSubscriptions('' === $subscriberId ? [] : $this->hydrate($this->rowsWhere(
            static fn (array $row): bool => $row['subscriber_id'] === $subscriberId && null === $row['retired_at'],
        )));
    }

    public function getOwnedSubscription(Owner $owner, SubscriptionId $id): Subscription
    {
        $subscriberId = $this->subscriberIdOf($owner);

        foreach ($this->rowsWhere(static fn (array $row): bool => '' !== $subscriberId && $row['subscriber_id'] === $subscriberId && $row['id'] === $id->toString()) as $row) {
            return $this->subscriptionRowMapper->fromRow($row);
        }

        throw SubscriptionNotFound::withId($id->toString());
    }

    public function getMany(array $ids): array
    {
        $wanted = array_map(static fn (SubscriptionId $id): string => $id->toString(), $ids);

        return $this->hydrate($this->rowsWhere(static fn (array $row): bool => \in_array($row['id'], $wanted, true)));
    }

    public function activeInBatches(int $size): iterable
    {
        $active = $this->rowsWhere(static fn (array $row): bool => null === $row['retired_at']);
        ksort($active);

        foreach (array_chunk($active, $size) as $chunk) {
            yield $this->hydrate($chunk);
        }
    }

    public function countActiveAnonymous(): int
    {
        return \count($this->rowsWhere(static fn (array $row): bool => SubscriptionRowMapper::OWNER_ANONYMOUS === $row['owner_type'] && null === $row['retired_at']));
    }

    public function save(Subscription $subscription): void
    {
        $row = $this->subscriptionRowMapper->toRow($subscription);

        foreach ($this->rows as $existing) {
            if ($existing['endpoint_hash'] === $row['endpoint_hash'] && $existing['id'] !== $row['id']) {
                throw SubscriptionAlreadyExists::forFingerprint(u($row['endpoint_hash'])->slice(0, 12)->toString());
            }
        }

        $this->rows[$row['id']] = $row;
    }

    public function remove(Subscription $subscription): void
    {
        unset($this->rows[$subscription->id()->toString()]);
    }

    public function deleteRetiredBefore(\DateTimeImmutable $cutoff): int
    {
        return $this->deleteWhere(static fn (array $row): bool => null !== $row['retired_at'] && $row['retired_at'] < $cutoff);
    }

    public function deleteStaleAnonymousBefore(\DateTimeImmutable $cutoff): int
    {
        return $this->deleteWhere(static fn (array $row): bool => SubscriptionRowMapper::OWNER_ANONYMOUS === $row['owner_type'] && $row['last_registered_at'] < $cutoff);
    }

    public function deleteAllOwnedBy(Owner $owner): int
    {
        $subscriberId = $this->subscriberIdOf($owner);

        return $this->deleteWhere(static fn (array $row): bool => '' !== $subscriberId && $row['subscriber_id'] === $subscriberId);
    }

    public function listFor(Owner $owner): array
    {
        $subscriberId = $this->subscriberIdOf($owner);
        $rows = $this->rowsWhere(static fn (array $row): bool => '' !== $subscriberId && $row['subscriber_id'] === $subscriberId);
        usort($rows, static fn (array $left, array $right): int => $right['last_registered_at'] <=> $left['last_registered_at']);

        return array_map(fn (array $row): SubscriptionView => new SubscriptionView(
            $row['id'],
            u($row['endpoint_hash'])->slice(0, 12)->toString(),
            $this->allowedPushServices->serviceNameForHost($row['push_host']),
            new SubscriptionPeriod($row['registered_at'], $row['last_registered_at'], null === $row['retired_at'] ? 'active' : ($row['retirement_reason'] ?? 'retired'), $row['retired_at']),
        ), $rows);
    }

    /** Test helper: how many rows are stored, active or not. */
    public function countRows(): int
    {
        return \count($this->rows);
    }

    /**
     * @param callable(SubscriptionRow): bool $predicate
     *
     * @return array<string, SubscriptionRow>
     */
    private function rowsWhere(callable $predicate): array
    {
        return array_filter($this->rows, $predicate);
    }

    /**
     * @param callable(SubscriptionRow): bool $predicate
     */
    private function deleteWhere(callable $predicate): int
    {
        $doomed = $this->rowsWhere($predicate);

        foreach (array_keys($doomed) as $id) {
            unset($this->rows[$id]);
        }

        return \count($doomed);
    }

    /**
     * @param array<array-key, SubscriptionRow> $rows
     *
     * @return list<Subscription>
     */
    private function hydrate(array $rows): array
    {
        return array_values(array_map($this->subscriptionRowMapper->fromRow(...), $rows));
    }

    private function subscriberIdOf(Owner $owner): string
    {
        return $owner->fold(static fn ($id): string => $id->toString(), static fn (): string => '');
    }
}
