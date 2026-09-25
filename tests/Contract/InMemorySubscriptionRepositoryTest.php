<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Tests\Contract;

use RomainMillan\WebPushNotification\Application\Port\TransactionBoundary;
use RomainMillan\WebPushNotification\Domain\Subscription\AllowedPushServices;
use RomainMillan\WebPushNotification\Infrastructure\Crypto\NullSubscriptionCipher;
use RomainMillan\WebPushNotification\Infrastructure\InMemory\InMemorySubscriptionRepository;
use RomainMillan\WebPushNotification\Infrastructure\InMemory\InMemoryTransactionBoundary;
use RomainMillan\WebPushNotification\Infrastructure\Persistence\SubscriptionRowMapper;
use RomainMillan\WebPushNotification\Testing\SubscriptionRepositoryContract;

final class InMemorySubscriptionRepositoryTest extends SubscriptionRepositoryContract
{
    private InMemorySubscriptionRepository $repository;
    private InMemoryTransactionBoundary $transactionBoundary;

    protected function setUp(): void
    {
        $this->repository = new InMemorySubscriptionRepository(new SubscriptionRowMapper(new NullSubscriptionCipher()), AllowedPushServices::createWithKnownServices());
        $this->transactionBoundary = new InMemoryTransactionBoundary();
    }

    protected function repository(): InMemorySubscriptionRepository
    {
        return $this->repository;
    }

    protected function transactionBoundary(): TransactionBoundary
    {
        return $this->transactionBoundary;
    }
}
