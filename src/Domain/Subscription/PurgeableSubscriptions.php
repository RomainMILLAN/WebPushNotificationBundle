<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Domain\Subscription;

/**
 * Bulk deletions for the purge command. They bypass the aggregates on purpose and
 * emit no per-subscription event (only SubscriptionsPurged).
 */
interface PurgeableSubscriptions
{
    /** @return int deleted rows */
    public function deleteRetiredBefore(\DateTimeImmutable $cutoff): int;

    /** @return int deleted rows: anonymous subscriptions not re-registered since $cutoff */
    public function deleteStaleAnonymousBefore(\DateTimeImmutable $cutoff): int;

    /** @return int deleted rows, active and retired (account deletion) */
    public function deleteAllOwnedBy(Owner $owner): int;
}
