<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Domain\Subscription\Event;

/** The push service reported the subscription gone (404/410). */
final readonly class SubscriptionExpired extends SubscriptionEvent
{
}
