<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Bridge\Symfony\Doctrine;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
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
 * Doctrine adapter, on DBAL rather than the ORM unit of work: the aggregate is mapped
 * by SubscriptionRowMapper (shared with Eloquent), a failed insert does not close any
 * EntityManager, and the owner filter is always part of the SQL.
 *
 * @phpstan-import-type SubscriptionRow from SubscriptionRowMapper
 */
final readonly class DoctrineSubscriptionRepository implements SubscriptionRepository, PurgeableSubscriptions, SubscriptionReadModel
{
    private const TABLE = 'web_push_subscription';

    public function __construct(
        private Connection $connection,
        private SubscriptionRowMapper $subscriptionRowMapper,
        private AllowedPushServices $allowedPushServices,
    ) {
    }

    public function get(SubscriptionId $id): Subscription
    {
        return $this->first('id = ?', [$id->toString()]) ?? throw SubscriptionNotFound::withId($id->toString());
    }

    public function hasFingerprint(EndpointFingerprint $fingerprint): bool
    {
        return false !== $this->connection->fetchOne('SELECT 1 FROM '.self::TABLE.' WHERE endpoint_hash = ?', [$fingerprint->toString()]);
    }

    public function getByFingerprint(EndpointFingerprint $fingerprint): Subscription
    {
        return $this->first('endpoint_hash = ?', [$fingerprint->toString()]) ?? throw SubscriptionNotFound::withId($fingerprint->short());
    }

    public function ownedBy(Owner $owner): OwnerSubscriptions
    {
        return $owner->fold(
            fn (SubscriberId $id): OwnerSubscriptions => new OwnerSubscriptions($this->all('owner_type = ? AND subscriber_id = ? AND retired_at IS NULL', [SubscriptionRowMapper::OWNER_IDENTIFIED, $id->toString()])),
            static fn (): OwnerSubscriptions => new OwnerSubscriptions([]),
        );
    }

    public function getOwnedSubscription(Owner $owner, SubscriptionId $id): Subscription
    {
        $subscription = $owner->fold(
            fn (SubscriberId $subscriberId): ?Subscription => $this->first('id = ? AND owner_type = ? AND subscriber_id = ?', [$id->toString(), SubscriptionRowMapper::OWNER_IDENTIFIED, $subscriberId->toString()]),
            static fn (): ?Subscription => null,
        );

        return $subscription ?? throw SubscriptionNotFound::withId($id->toString());
    }

    public function getMany(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        return $this->hydrate($this->connection->fetchAllAssociative(
            'SELECT * FROM '.self::TABLE.' WHERE id IN (?)',
            [array_map(static fn (SubscriptionId $id): string => $id->toString(), $ids)],
            [ArrayParameterType::STRING],
        ));
    }

    public function activeInBatches(int $size): iterable
    {
        $after = '';

        do {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT * FROM '.self::TABLE.' WHERE retired_at IS NULL AND id > ? ORDER BY id ASC LIMIT ?',
                [$after, $size],
                [ParameterType::STRING, ParameterType::INTEGER],
            );

            if ([] === $rows) {
                return;
            }

            $batch = $this->hydrate($rows);
            $after = $batch[\count($batch) - 1]->id()->toString();

            yield $batch;
        } while (\count($rows) === $size);
    }

    public function countActiveAnonymous(): int
    {
        return (int) $this->text($this->connection->fetchOne('SELECT COUNT(*) FROM '.self::TABLE.' WHERE owner_type = ? AND retired_at IS NULL', [SubscriptionRowMapper::OWNER_ANONYMOUS]));
    }

    public function save(Subscription $subscription): void
    {
        $row = $this->subscriptionRowMapper->toRow($subscription);
        $types = ['registered_at' => Types::DATETIME_IMMUTABLE, 'last_registered_at' => Types::DATETIME_IMMUTABLE, 'retired_at' => Types::DATETIME_IMMUTABLE];

        try {
            if (false !== $this->connection->fetchOne('SELECT 1 FROM '.self::TABLE.' WHERE id = ?', [$row['id']])) {
                $this->connection->update(self::TABLE, $row, ['id' => $row['id']], $types);

                return;
            }

            $this->connection->insert(self::TABLE, $row, $types);
        } catch (UniqueConstraintViolationException $violation) {
            throw SubscriptionAlreadyExists::forFingerprint($subscription->fingerprint()->short(), $violation);
        }
    }

    public function remove(Subscription $subscription): void
    {
        $this->connection->delete(self::TABLE, ['id' => $subscription->id()->toString()]);
    }

    public function deleteRetiredBefore(\DateTimeImmutable $cutoff): int
    {
        return (int) $this->connection->executeStatement('DELETE FROM '.self::TABLE.' WHERE retired_at IS NOT NULL AND retired_at < ?', [$cutoff], [Types::DATETIME_IMMUTABLE]);
    }

    public function deleteStaleAnonymousBefore(\DateTimeImmutable $cutoff): int
    {
        return (int) $this->connection->executeStatement('DELETE FROM '.self::TABLE.' WHERE owner_type = ? AND last_registered_at < ?', [SubscriptionRowMapper::OWNER_ANONYMOUS, $cutoff], [ParameterType::STRING, Types::DATETIME_IMMUTABLE]);
    }

    public function deleteAllOwnedBy(Owner $owner): int
    {
        return $owner->fold(
            fn (SubscriberId $id): int => (int) $this->connection->executeStatement('DELETE FROM '.self::TABLE.' WHERE owner_type = ? AND subscriber_id = ?', [SubscriptionRowMapper::OWNER_IDENTIFIED, $id->toString()]),
            static fn (): int => 0,
        );
    }

    public function listFor(Owner $owner): array
    {
        return $owner->fold(
            fn (SubscriberId $id): array => array_map(
                $this->view(...),
                $this->connection->fetchAllAssociative(
                    'SELECT id, endpoint_hash, push_host, registered_at, last_registered_at, retired_at, retirement_reason FROM '.self::TABLE.' WHERE owner_type = ? AND subscriber_id = ? ORDER BY last_registered_at DESC',
                    [SubscriptionRowMapper::OWNER_IDENTIFIED, $id->toString()],
                ),
            ),
            static fn (): array => [],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function view(array $row): SubscriptionView
    {
        $retiredAt = $this->date($row['retired_at'] ?? null);

        return new SubscriptionView(
            $this->text($row['id']),
            u($this->text($row['endpoint_hash']))->slice(0, 12)->toString(),
            $this->allowedPushServices->serviceNameForHost($this->text($row['push_host'])),
            new SubscriptionPeriod(
                $this->date($row['registered_at']) ?? new \DateTimeImmutable('@0'),
                $this->date($row['last_registered_at']) ?? new \DateTimeImmutable('@0'),
                $retiredAt instanceof \DateTimeImmutable ? $this->text($row['retirement_reason']) : 'active',
                $retiredAt,
            ),
        );
    }

    /**
     * @param list<mixed> $parameters
     */
    private function first(string $where, array $parameters): ?Subscription
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM '.self::TABLE.' WHERE '.$where, $parameters);

        return false === $row ? null : $this->subscriptionRowMapper->fromRow($this->normalize($row));
    }

    /**
     * @param list<mixed> $parameters
     *
     * @return list<Subscription>
     */
    private function all(string $where, array $parameters): array
    {
        return $this->hydrate($this->connection->fetchAllAssociative('SELECT * FROM '.self::TABLE.' WHERE '.$where, $parameters));
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<Subscription>
     */
    private function hydrate(array $rows): array
    {
        return array_map(fn (array $row): Subscription => $this->subscriptionRowMapper->fromRow($this->normalize($row)), $rows);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return SubscriptionRow
     */
    private function normalize(array $row): array
    {
        return [
            'id' => $this->text($row['id']),
            'owner_type' => $this->text($row['owner_type']),
            'subscriber_id' => null === $row['subscriber_id'] ? null : $this->text($row['subscriber_id']),
            'endpoint' => $this->text($row['endpoint']),
            'endpoint_hash' => $this->text($row['endpoint_hash']),
            'push_host' => $this->text($row['push_host']),
            'p256dh' => $this->text($row['p256dh']),
            'auth' => $this->text($row['auth']),
            'content_encoding' => $this->text($row['content_encoding']),
            'registered_at' => $this->date($row['registered_at']) ?? new \DateTimeImmutable('@0'),
            'last_registered_at' => $this->date($row['last_registered_at']) ?? new \DateTimeImmutable('@0'),
            'retired_at' => $this->date($row['retired_at'] ?? null),
            'retirement_reason' => null === ($row['retirement_reason'] ?? null) ? null : $this->text($row['retirement_reason']),
        ];
    }

    private function text(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : throw new \UnexpectedValueException('Cannot read a non-scalar web push subscription column.');
    }

    private function date(mixed $value): ?\DateTimeImmutable
    {
        if (null === $value) {
            return null;
        }

        $converted = $this->connection->convertToPHPValue($value, Types::DATETIME_IMMUTABLE);

        return $converted instanceof \DateTimeImmutable ? $converted : null;
    }
}
