<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Domain\Subscription;

/**
 * The quota rule that applies to an owner, chosen polymorphically by Owner.
 *
 * Each limit reads exactly what it needs: a per-owner quota loads the (small) set of
 * the owner's active subscriptions, the anonymous cap only counts.
 */
interface SubscriptionLimit
{
    /**
     * Decides what making room for $candidate requires. Called BEFORE $candidate is
     * saved; $candidate is excluded from what is counted.
     */
    public function makeRoomFor(Subscription $candidate, SubscriptionRepository $subscriptions): QuotaDecision;
}
