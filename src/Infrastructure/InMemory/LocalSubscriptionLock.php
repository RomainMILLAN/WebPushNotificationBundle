<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Infrastructure\InMemory;

use RomainMillan\WebPushNotification\Application\Port\SubscriptionLock;

/**
 * No cross-process exclusion: used when no shared lock store is configured. The quota
 * becomes soft (N+1 transiently), and concurrent registrations of one endpoint fall
 * back on the unique index + replay. See docs/consistency.md.
 */
final readonly class LocalSubscriptionLock implements SubscriptionLock
{
    public function synchronized(array $keys, callable $work): mixed
    {
        return $work();
    }
}
