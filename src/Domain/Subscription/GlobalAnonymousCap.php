<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Domain\Subscription;

/**
 * Anonymous subscriptions have no owner to bound them: a global cap does.
 *
 * Refuses instead of evicting — evicting a stranger's device to make room for a new
 * anonymous visitor would let anyone flush the table. The cap is soft under
 * concurrency (no global lock), which is acceptable for a resource bound.
 */
final readonly class GlobalAnonymousCap implements SubscriptionLimit
{
    public function __construct(
        private int $max,
    ) {
    }

    public function makeRoomFor(Subscription $candidate, SubscriptionRepository $subscriptions): QuotaDecision
    {
        return $subscriptions->countActiveAnonymous() >= $this->max
            ? QuotaDecision::refuse()
            : QuotaDecision::evict([]);
    }
}
