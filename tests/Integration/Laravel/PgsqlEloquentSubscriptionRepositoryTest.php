<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Tests\Integration\Laravel;

use RomainMillan\WebPushNotification\Application\Port\TransactionBoundary;
use RomainMillan\WebPushNotification\Bridge\Laravel\Eloquent\EloquentSubscriptionRepository;
use RomainMillan\WebPushNotification\Testing\SubscriptionRepositoryContract;

/** The same contract on PostgreSQL: unique violation, strict types and byte-wise keyset order. */
final class PgsqlEloquentSubscriptionRepositoryTest extends SubscriptionRepositoryContract
{
    private EloquentStorage $eloquentStorage;

    protected function setUp(): void
    {
        $dsn = getenv('WEB_PUSH_TEST_PGSQL_DSN');

        if (!\is_string($dsn) || '' === $dsn) {
            self::markTestSkipped('WEB_PUSH_TEST_PGSQL_DSN is not set.');
        }

        $this->eloquentStorage = EloquentStorage::createFromPgsqlDsn($dsn);
    }

    protected function tearDown(): void
    {
        if (isset($this->eloquentStorage)) {
            $this->eloquentStorage->shutdownAfter($this);
        }
    }

    protected function repository(): EloquentSubscriptionRepository
    {
        return $this->eloquentStorage->repository();
    }

    protected function transactionBoundary(): TransactionBoundary
    {
        return $this->eloquentStorage->transactionBoundary();
    }
}
