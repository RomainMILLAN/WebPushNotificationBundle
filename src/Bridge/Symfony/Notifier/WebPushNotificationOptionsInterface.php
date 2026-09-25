<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Bridge\Symfony\Notifier;

use RomainMillan\WebPushNotification\Domain\Message\DeliveryOptions;

/**
 * Implemented by a Notification that overrides the push headers (TTL, urgency,
 * topic) of the application defaults (delivery.ttl, delivery.urgency).
 */
interface WebPushNotificationOptionsInterface
{
    public function webPushOptions(DeliveryOptions $defaults): DeliveryOptions;
}
