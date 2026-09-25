<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Tests\Support;

use RomainMillan\WebPushNotification\Application\Port\SubscriptionIdGenerator;
use RomainMillan\WebPushNotification\Domain\Subscription\SubscriptionId;

final class SequentialIdGenerator implements SubscriptionIdGenerator
{
    private int $next = 1;

    public function generate(): SubscriptionId
    {
        return SubscriptionId::fromString(str_pad(dechex($this->next++), 32, '0', \STR_PAD_LEFT));
    }
}
