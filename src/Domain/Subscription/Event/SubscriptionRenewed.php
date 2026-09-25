<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Domain\Subscription\Event;

/** A known device registered again (key rotation, daily sync). */
final readonly class SubscriptionRenewed extends SubscriptionEvent
{
}
