<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Application;

use Psr\Clock\ClockInterface;
use RomainMillan\WebPushNotification\Domain\Delivery\DeliveryOutcome;
use RomainMillan\WebPushNotification\Domain\Exception\SubscriptionNotFound;
use RomainMillan\WebPushNotification\Domain\Subscription\LockKey;
use RomainMillan\WebPushNotification\Domain\Subscription\SubscriptionId;
use RomainMillan\WebPushNotification\Domain\Subscription\SubscriptionRepository;

/**
 * Mandatory step of every delivery — not an extension, not configurable: a push
 * service answering 404/410 means the subscription is gone, and dead subscriptions
 * must leave service (GDPR, and every later send would hit them again).
 *
 * Opens its own locked transaction: it also runs inside async handlers, outside any
 * application transaction. Events are published after commit.
 */
final readonly class RetireOnExpiry
{
    public function __construct(
        private SubscriptionTransaction $subscriptionTransaction,
        private RetirementPolicy $retirementPolicy,
        private ClockInterface $clock,
    ) {
    }

    public function onDeliveryOutcome(SubscriptionId $subscriptionId, DeliveryOutcome $outcome): void
    {
        if (!$outcome->isExpired()) {
            return;
        }

        try {
            $endpointKey = $this->subscriptionTransaction->read(
                static fn (SubscriptionRepository $subscriptions): LockKey => $subscriptions->get($subscriptionId)->fingerprint()->lockKey(),
            );
        } catch (SubscriptionNotFound) {
            return;
        }

        $this->subscriptionTransaction->locked([$endpointKey], fn () => $this->subscriptionTransaction->transactional(
            function (SubscriptionSession $session) use ($subscriptionId): void {
                try {
                    $subscription = $session->get($subscriptionId);
                } catch (SubscriptionNotFound) {
                    return;
                }

                // Re-registered (or already retired) since the send: nothing to retire.
                if (!$subscription->isActive()) {
                    return;
                }

                $subscription->expire($this->clock->now());
                $this->retirementPolicy->store($subscription, $session);
            },
        ));
    }
}
