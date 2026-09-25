<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Application\Proof;

use RomainMillan\WebPushNotification\Domain\Subscription\Subscription;
use RomainMillan\WebPushNotification\Domain\Subscription\UnsubscribeCause;

/** What entitles the caller to delete a subscription. */
interface UnsubscribeProof
{
    public function entitles(Subscription $subscription): bool;

    public function cause(): UnsubscribeCause;
}
