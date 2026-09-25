<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Application\ReadModel;

use RomainMillan\WebPushNotification\Application\Port\SubscriptionReadModel;
use RomainMillan\WebPushNotification\Domain\Subscription\IdentifiedOwner;
use RomainMillan\WebPushNotification\Domain\Subscription\SubscriberId;

/** "My devices" — the reason the package stores subscriptions at all. */
final readonly class ListSubscriptions
{
    public function __construct(
        private SubscriptionReadModel $subscriptionReadModel,
    ) {
    }

    /**
     * @return list<SubscriptionView>
     */
    public function listFor(SubscriberId $subscriberId): array
    {
        return $this->subscriptionReadModel->listFor(new IdentifiedOwner($subscriberId));
    }
}
