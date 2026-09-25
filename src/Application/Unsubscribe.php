<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Application;

use Psr\Clock\ClockInterface;
use RomainMillan\WebPushNotification\Application\Proof\UnsubscribeProof;
use RomainMillan\WebPushNotification\Domain\Subscription\PushEndpoint;

/**
 * Deletes a subscription when the proof entitles it — and says nothing either way:
 * unknown endpoint, somebody else's endpoint and invalid proof all look the same to
 * the caller (uniform 204, no oracle).
 */
final readonly class Unsubscribe
{
    public function __construct(
        private SubscriptionTransaction $subscriptionTransaction,
        private ClockInterface $clock,
    ) {
    }

    public function unsubscribe(PushEndpoint $endpoint, UnsubscribeProof $proof): void
    {
        $fingerprint = $endpoint->fingerprint();

        $this->subscriptionTransaction->locked([$fingerprint->lockKey()], fn () => $this->subscriptionTransaction->transactional(
            function (SubscriptionSession $session) use ($fingerprint, $proof): void {
                if (!$session->hasFingerprint($fingerprint)) {
                    return;
                }

                $subscription = $session->getByFingerprint($fingerprint);

                if (!$proof->entitles($subscription)) {
                    return;
                }

                $subscription->unsubscribe($proof->cause(), $this->clock->now());
                $session->remove($subscription);
            },
        ));
    }
}
