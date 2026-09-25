<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Bridge\Laravel\Eloquent;

use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\UniqueConstraintViolationException;
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
use RomainMillan\WebPushNotification\Domain\Subscription\SubscriberId;
use RomainMillan\WebPushNotification\Domain\Subscription\Subscription;
use RomainMillan\WebPushNotification\Domain\Subscription\SubscriptionId;
use RomainMillan\WebPushNotification\Domain\Subscription\SubscriptionRepository;
use RomainMillan\WebPushNotification\Infrastructure\Persistence\SubscriptionRowMapper;

use function Symfony\Component\String\u;

/**
 * Eloquent adapter of the persistence ports. Rows go through the core
 * SubscriptionRowMapper (cipher included), so Doctrine and Eloquent store exactly the
 * same thing. Every owner filter is part of the SQL query.
 */
final readonly class EloquentSubscriptionRepository implements SubscriptionRepository, PurgeableSubscriptions, SubscriptionReadModel
{
    /**
     * @param string $connectionName '' for the default connection
     */
    public function __construct(
        private SubscriptionRowMapper $subscriptionRowMapper,
        private AllowedPushServices $allowedPushServices,
        private string $connectionName = '',
    ) {
    }

    public function get(SubscriptionId $id): Subscription
    {
        return $this->hydrateFirst(
            static fn (QueryBuilder $query): QueryBuilder => $query->where('id', '=', $id->toString()),
            SubscriptionNotFound::withId($id->toString()),
        );
    }

    public function hasFingerprint(EndpointFingerprint $fingerprint): bool
    {
        return $this->table()->where('endpoint_hash', '=', $fingerprint->toString())->exists();
    }

    public function getByFingerprint(EndpointFingerprint $fingerprint): Subscription
    {
        return $this->hydrateFirst(
            static fn (QueryBuilder $query): QueryBuilder => $query->where('endpoint_hash', '=', $fingerprint->toString()),
            SubscriptionNotFound::withId($fingerprint->short()),
        );
    }

    public function ownedBy(Owner $owner): OwnerSubscriptions
    {
        return new OwnerSubscriptions($owner->fold(
            fn (SubscriberId $id): array => $this->hydrateAll($this->recordsMatching(
                static fn (QueryBuilder $query): QueryBuilder => $query->where('subscriber_id', '=', $id->toString())->whereNull('retired_at')->orderBy('id'),
            )),
            static fn (): array => [],
        ));
    }

    public function getOwnedSubscription(Owner $owner, SubscriptionId $id): Subscription
    {
        $notFound = SubscriptionNotFound::withId($id->toString());

        return $owner->fold(
            fn (SubscriberId $subscriberId): Subscription => $this->hydrateFirst(
                static fn (QueryBuilder $query): QueryBuilder => $query->where('id', '=', $id->toString())->where('subscriber_id', '=', $subscriberId->toString()),
                $notFound,
            ),
            static fn (): Subscription => throw $notFound,
        );
    }

    public function getMany(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        $wanted = array_map(static fn (SubscriptionId $id): string => $id->toString(), $ids);

        return $this->hydrateAll($this->recordsMatching(static fn (QueryBuilder $query): QueryBuilder => $query->whereIn('id', $wanted)->orderBy('id')));
    }

    public function activeInBatches(int $size): iterable
    {
        $after = '';

        do {
            // Keyset pagination on the primary key: stable under concurrent inserts,
            // and never an OFFSET scan.
            $rows = array_map(
                static fn (SubscriptionRecord $record): array => $record->toRow(),
                $this->recordsMatching(static fn (QueryBuilder $query): QueryBuilder => $query->whereNull('retired_at')->where('id', '>', $after)->orderBy('id')->limit($size)),
            );

            if ([] === $rows) {
                return;
            }

            yield array_map($this->subscriptionRowMapper->fromRow(...), $rows);

            $after = $rows[\count($rows) - 1]['id'];
        } while (\count($rows) === $size);
    }

    public function countActiveAnonymous(): int
    {
        return $this->table()->where('owner_type', '=', SubscriptionRowMapper::OWNER_ANONYMOUS)->whereNull('retired_at')->count();
    }

    public function save(Subscription $subscription): void
    {
        $row = $this->subscriptionRowMapper->toRow($subscription);
        $existing = $this->recordsMatching(static fn (QueryBuilder $query): QueryBuilder => $query->where('id', '=', $row['id']));
        $record = $existing[0] ?? $this->newRecord();
        $record->fill($row);

        try {
            $record->save();
        } catch (UniqueConstraintViolationException) {
            // Without previous: the driver message quotes the offending row.
            throw SubscriptionAlreadyExists::forFingerprint(u($row['endpoint_hash'])->slice(0, 12)->toString());
        }
    }

    public function remove(Subscription $subscription): void
    {
        $this->table()->where('id', '=', $subscription->id()->toString())->delete();
    }

    public function deleteRetiredBefore(\DateTimeImmutable $cutoff): int
    {
        return $this->table()->whereNotNull('retired_at')->where('retired_at', '<', $cutoff)->delete();
    }

    public function deleteStaleAnonymousBefore(\DateTimeImmutable $cutoff): int
    {
        return $this->table()->where('owner_type', '=', SubscriptionRowMapper::OWNER_ANONYMOUS)->where('last_registered_at', '<', $cutoff)->delete();
    }

    public function deleteAllOwnedBy(Owner $owner): int
    {
        return $owner->fold(
            fn (SubscriberId $id): int => $this->table()->where('subscriber_id', '=', $id->toString())->delete(),
            static fn (): int => 0,
        );
    }

    public function listFor(Owner $owner): array
    {
        $records = $owner->fold(
            fn (SubscriberId $id): array => $this->recordsMatching(
                static fn (QueryBuilder $query): QueryBuilder => $query->where('subscriber_id', '=', $id->toString())->orderByDesc('last_registered_at')->orderBy('id'),
            ),
            static fn (): array => [],
        );

        return array_map(function (SubscriptionRecord $record): SubscriptionView {
            // Straight from the columns: listing devices never decrypts a secret.
            $row = $record->toRow();

            return new SubscriptionView(
                $row['id'],
                u($row['endpoint_hash'])->slice(0, 12)->toString(),
                $this->allowedPushServices->serviceNameForHost($row['push_host']),
                new SubscriptionPeriod($row['registered_at'], $row['last_registered_at'], null === $row['retired_at'] ? 'active' : ($row['retirement_reason'] ?? 'retired'), $row['retired_at']),
            );
        }, $records);
    }

    /**
     * Writes and counts need no model: the query builder of the record's connection.
     */
    private function table(): QueryBuilder
    {
        return $this->newRecord()->newQuery()->toBase();
    }

    /**
     * @param \Closure(QueryBuilder): QueryBuilder $constraints
     *
     * @return list<SubscriptionRecord>
     */
    private function recordsMatching(\Closure $constraints): array
    {
        $query = $this->newRecord()->newQuery();
        $constraints($query->getQuery());

        return array_values($query->get()->all());
    }

    /**
     * @param \Closure(QueryBuilder): QueryBuilder $constraints
     */
    private function hydrateFirst(\Closure $constraints, SubscriptionNotFound $notFound): Subscription
    {
        $records = $this->recordsMatching(static fn (QueryBuilder $query): QueryBuilder => $constraints($query)->limit(1));

        return [] !== $records ? $this->hydrate($records[0]) : throw $notFound;
    }

    private function newRecord(): SubscriptionRecord
    {
        $record = new SubscriptionRecord();

        return '' === $this->connectionName ? $record : $record->setConnection($this->connectionName);
    }

    private function hydrate(SubscriptionRecord $record): Subscription
    {
        return $this->subscriptionRowMapper->fromRow($record->toRow());
    }

    /**
     * @param list<SubscriptionRecord> $records
     *
     * @return list<Subscription>
     */
    private function hydrateAll(array $records): array
    {
        return array_map($this->hydrate(...), $records);
    }
}
