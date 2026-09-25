<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Bridge\Symfony;

use RomainMillan\WebPushNotification\Domain\Message\DeliveryOptions;
use RomainMillan\WebPushNotification\Domain\Message\Urgency;

/** The application-wide default push headers, from delivery.ttl and delivery.urgency. */
final readonly class DeliveryOptionsFactory
{
    public static function createFromConfiguration(int $ttl, string $urgency): DeliveryOptions
    {
        return DeliveryOptions::createDefault()->withTtl($ttl)->withUrgency(Urgency::from($urgency));
    }
}
