<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Application\Port;

use RomainMillan\WebPushNotification\Application\ReadModel\SubscriptionView;
use RomainMillan\WebPushNotification\Domain\Subscription\Owner;

/** Read side (light CQRS): the devices of an owner, without endpoint nor keys. */
interface SubscriptionReadModel
{
    /**
     * @return list<SubscriptionView> active and retired, most recently registered first
     */
    public function listFor(Owner $owner): array;
}
