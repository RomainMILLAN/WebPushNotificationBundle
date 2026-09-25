<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Bridge\Laravel\Lock;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use RomainMillan\WebPushNotification\Application\Exception\LockNotAcquired;
use RomainMillan\WebPushNotification\Application\Port\SubscriptionLock;
use RomainMillan\WebPushNotification\Domain\Subscription\LockKey;

/**
 * Cache::lock() per key, acquired in the global order (endpoint first, then owners
 * sorted) and released in reverse order.
 *
 * With a store that is not shared between servers ("array", "file" on several hosts)
 * the exclusion is local only: the quota becomes soft (docs/consistency.md).
 */
final readonly class CacheLockSubscriptionLock implements SubscriptionLock
{
    /** Outlives any registration, yet frees a key held by a crashed process. */
    private const LOCK_TTL_SECONDS = 30;

    public function __construct(
        private LockProvider $lockProvider,
        private int $waitSeconds = 5,
    ) {
    }

    public function synchronized(array $keys, callable $work): mixed
    {
        /** @var list<Lock> $acquired */
        $acquired = [];

        try {
            foreach (LockKey::inAcquisitionOrder($keys) as $key) {
                $lock = $this->lockProvider->lock($key->toString(), self::LOCK_TTL_SECONDS);

                try {
                    $lock->block($this->waitSeconds);
                } catch (LockTimeoutException) {
                    throw LockNotAcquired::create();
                }

                $acquired[] = $lock;
            }

            return $work();
        } finally {
            foreach (array_reverse($acquired) as $lock) {
                $lock->release();
            }
        }
    }
}
