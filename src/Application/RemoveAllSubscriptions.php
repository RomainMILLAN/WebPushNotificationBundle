<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Application;

use Psr\Clock\ClockInterface;
use RomainMillan\WebPushNotification\Domain\Subscription\IdentifiedOwner;
use RomainMillan\WebPushNotification\Domain\Subscription\PurgeableSubscriptions;
use RomainMillan\WebPushNotification\Domain\Subscription\SubscriberId;
use RomainMillan\WebPushNotification\Domain\Subscription\UnsubscribeCause;

/**
 * Account deletion (GDPR right to erasure): one SubscriptionUnsubscribed per active
 * device, then the retired leftovers are deleted in bulk.
 */
final readonly class RemoveAllSubscriptions
{
    public function __construct(
        private SubscriptionTransaction $subscriptionTransaction,
        private PurgeableSubscriptions $purgeableSubscriptions,
        private ClockInterface $clock,
    ) {
    }

    public function removeAllOf(SubscriberId $subscriberId): void
    {
        $owner = new IdentifiedOwner($subscriberId);

        $this->subscriptionTransaction->locked($owner->lockKeys(), fn () => $this->subscriptionTransaction->transactional(
            function (SubscriptionSession $session) use ($owner): void {
                foreach ($session->ownedBy($owner) as $subscription) {
                    $subscription->unsubscribe(UnsubscribeCause::AccountRemoved, $this->clock->now());
                    $session->remove($subscription);
                }

                $this->purgeableSubscriptions->deleteAllOwnedBy($owner);
            },
        ));
    }
}
