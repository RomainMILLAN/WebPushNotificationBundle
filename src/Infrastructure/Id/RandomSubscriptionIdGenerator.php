<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Infrastructure\Id;

use RomainMillan\WebPushNotification\Application\Port\SubscriptionIdGenerator;
use RomainMillan\WebPushNotification\Domain\Subscription\SubscriptionId;

final readonly class RandomSubscriptionIdGenerator implements SubscriptionIdGenerator
{
    public function generate(): SubscriptionId
    {
        return SubscriptionId::fromString(bin2hex(random_bytes(16)));
    }
}
