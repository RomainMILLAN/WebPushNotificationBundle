<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Application\Port;

use RomainMillan\WebPushNotification\Domain\Subscription\SubscriptionId;

interface SubscriptionIdGenerator
{
    public function generate(): SubscriptionId;
}
