<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Tests\Integration\Symfony\App;

use RomainMillan\WebPushNotification\Bridge\Symfony\Notifier\WebPushNotificationOptionsInterface;
use RomainMillan\WebPushNotification\Domain\Message\DeliveryOptions;
use RomainMillan\WebPushNotification\Domain\Message\Urgency;
use Symfony\Component\Notifier\Notification\Notification;

/** A notification overriding the application push headers. */
final class UrgentNotification extends Notification implements WebPushNotificationOptionsInterface
{
    public function webPushOptions(DeliveryOptions $defaults): DeliveryOptions
    {
        return $defaults->withUrgency(Urgency::High)->withTopic('payment');
    }
}
