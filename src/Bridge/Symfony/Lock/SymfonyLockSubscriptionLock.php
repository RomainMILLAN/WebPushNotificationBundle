<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Bridge\Symfony\Lock;

use RomainMillan\WebPushNotification\Application\Exception\LockNotAcquired;
use RomainMillan\WebPushNotification\Application\Port\SubscriptionLock;
use RomainMillan\WebPushNotification\Domain\Subscription\LockKey;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;

/**
 * Keys acquired in the global order (endpoint, then owners sorted), released in
 * reverse. A short wait, then a neutral 503 — never a request queue that grows.
 */
final readonly class SymfonyLockSubscriptionLock implements SubscriptionLock
{
    private const WAIT_MICROSECONDS = 5_000_000;
    private const RETRY_MICROSECONDS = 50_000;

    public function __construct(
        private LockFactory $lockFactory,
    ) {
    }

    public function synchronized(array $keys, callable $work): mixed
    {
        $held = [];

        try {
            foreach (LockKey::inAcquisitionOrder($keys) as $key) {
                $held[] = $this->acquire($key);
            }

            return $work();
        } finally {
            foreach (array_reverse($held) as $lock) {
                $lock->release();
            }
        }
    }

    private function acquire(LockKey $key): LockInterface
    {
        $lock = $this->lockFactory->createLock($key->toString(), 30.0);
        $waited = 0;

        while (!$lock->acquire(false)) {
            if ($waited >= self::WAIT_MICROSECONDS) {
                throw LockNotAcquired::create();
            }

            usleep(self::RETRY_MICROSECONDS);
            $waited += self::RETRY_MICROSECONDS;
        }

        return $lock;
    }
}
