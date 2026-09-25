<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Application;

use Psr\Clock\ClockInterface;
use RomainMillan\WebPushNotification\Domain\Exception\SubscriptionNotFound;
use RomainMillan\WebPushNotification\Domain\Subscription\Owner;
use RomainMillan\WebPushNotification\Domain\Subscription\SubscriptionId;
use RomainMillan\WebPushNotification\Domain\Subscription\SubscriptionRepository;
use RomainMillan\WebPushNotification\Domain\Subscription\UnsubscribeCause;

/**
 * "Remove this device" from a device list. The owner filter is part of the query:
 * revoking somebody else's subscription behaves exactly like revoking an unknown one.
 */
final readonly class RevokeSubscription
{
    public function __construct(
        private SubscriptionTransaction $subscriptionTransaction,
        private ClockInterface $clock,
    ) {
    }

    public function revoke(Owner $owner, SubscriptionId $id): void
    {
        try {
            $keys = $this->subscriptionTransaction->read(
                static fn (SubscriptionRepository $subscriptions): array => $subscriptions->getOwnedSubscription($owner, $id)->lockKeys(),
            );
        } catch (SubscriptionNotFound) {
            return;
        }

        $this->subscriptionTransaction->locked($keys, fn () => $this->subscriptionTransaction->transactional(
            function (SubscriptionSession $session) use ($owner, $id): void {
                try {
                    $subscription = $session->getOwnedSubscription($owner, $id);
                } catch (SubscriptionNotFound) {
                    return;
                }

                $subscription->unsubscribe(UnsubscribeCause::Revoked, $this->clock->now());
                $session->remove($subscription);
            },
        ));
    }
}
