<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Domain\Delivery;

use RomainMillan\WebPushNotification\Domain\Subscription\Subscription;
use RomainMillan\WebPushNotification\Domain\Subscription\SubscriptionRepository;

/**
 * Every active subscription, identified and anonymous — the only way to reach
 * anonymous subscribers. Mind the volume in async mode: one message per subscription.
 */
final readonly class Everyone implements Audience
{
    public function selectFrom(SubscriptionRepository $subscriptions, int $batchSize): iterable
    {
        yield from $subscriptions->activeInBatches($batchSize);
    }

    public function admits(Subscription $subscription): FailureCategory
    {
        return $subscription->isActive() ? FailureCategory::None : FailureCategory::NotActive;
    }
}
