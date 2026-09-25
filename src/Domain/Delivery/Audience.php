<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Domain\Delivery;

use RomainMillan\WebPushNotification\Domain\Subscription\Subscription;
use RomainMillan\WebPushNotification\Domain\Subscription\SubscriptionRepository;

/**
 * Who a notification goes to. Strategy: each audience knows how to select its
 * subscriptions and which of them it still admits — no caller matches on its type.
 */
interface Audience
{
    /**
     * @param positive-int $batchSize
     *
     * @return iterable<list<Subscription>> batches, never everything at once
     */
    public function selectFrom(SubscriptionRepository $subscriptions, int $batchSize): iterable;

    /**
     * Whether a selected subscription may still receive it. Checked again right before
     * sending: an async message may be consumed after the device changed hands.
     * FailureCategory::None means admitted.
     */
    public function admits(Subscription $subscription): FailureCategory;
}
