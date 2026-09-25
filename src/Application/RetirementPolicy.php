<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Application;

use RomainMillan\WebPushNotification\Domain\Subscription\Subscription;
use RomainMillan\WebPushNotification\Domain\Subscription\SubscriptionRepository;

/**
 * What happens IN STORAGE to a retired subscription (expired or evicted): deleted, or
 * kept deactivated for display and purged later. The business fact (event) is the
 * same either way.
 */
enum RetirementPolicy: string
{
    case Delete = 'delete';
    case Deactivate = 'deactivate';

    /** Applied after Subscription::expire() / evict() recorded the fact. */
    public function store(Subscription $retired, SubscriptionRepository $subscriptions): void
    {
        match ($this) {
            self::Delete => $subscriptions->remove($retired),
            self::Deactivate => $subscriptions->save($retired),
        };
    }
}
