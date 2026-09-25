<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Application\Port;

use RomainMillan\WebPushNotification\Application\Exception\LockNotAcquired;
use RomainMillan\WebPushNotification\Domain\Subscription\LockKey;

/**
 * Mutual exclusion across processes (Symfony Lock, Laravel Cache::lock).
 *
 * Implementations acquire the keys in LockKey::inAcquisitionOrder() — endpoint first,
 * then owners sorted — and release them in reverse order. With a non-shared store
 * (in-memory), the quota becomes soft: see docs/consistency.md.
 */
interface SubscriptionLock
{
    /**
     * @template T
     *
     * @param list<LockKey> $keys
     * @param callable(): T $work
     *
     * @return T
     *
     * @throws LockNotAcquired
     */
    public function synchronized(array $keys, callable $work): mixed;
}
