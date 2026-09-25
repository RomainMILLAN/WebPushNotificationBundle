<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Domain\Subscription\Event;

/** The owner's quota made room for a newer device. */
final readonly class SubscriptionEvicted extends SubscriptionEvent
{
}
