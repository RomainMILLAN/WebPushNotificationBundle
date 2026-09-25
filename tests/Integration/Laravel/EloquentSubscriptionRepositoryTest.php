<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Tests\Integration\Laravel;

use RomainMillan\WebPushNotification\Application\Port\TransactionBoundary;
use RomainMillan\WebPushNotification\Bridge\Laravel\Eloquent\EloquentSubscriptionRepository;
use RomainMillan\WebPushNotification\Testing\SubscriptionRepositoryContract;

final class EloquentSubscriptionRepositoryTest extends SubscriptionRepositoryContract
{
    private EloquentStorage $eloquentStorage;

    protected function setUp(): void
    {
        $this->eloquentStorage = EloquentStorage::createInMemorySqlite();
    }

    protected function tearDown(): void
    {
        $this->eloquentStorage->shutdownAfter($this);
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
