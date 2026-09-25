<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Application\Port;

use RomainMillan\WebPushNotification\Domain\Delivery\DeliveryOutcome;
use RomainMillan\WebPushNotification\Domain\Subscription\SubscriptionId;

/**
 * Application extension point (metrics, "your device expired" e-mail...). Runs AFTER
 * the mandatory RetireOnExpiry step. A listener failure is logged, never propagated to
 * the sender.
 */
interface DeliveryOutcomeListener
{
    public function onDeliveryOutcome(SubscriptionId $subscriptionId, DeliveryOutcome $outcome): void;
}
