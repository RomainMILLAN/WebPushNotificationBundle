<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Bridge\Laravel\Notifications;

use RomainMillan\WebPushNotification\Domain\Message\DeliveryOptions;

/** Optional: TTL, urgency or topic of this notification, instead of the configured defaults. */
interface WebPushNotificationOptions
{
    public function toWebPushOptions(object $notifiable): DeliveryOptions;
}
