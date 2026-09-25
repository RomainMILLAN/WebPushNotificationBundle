<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Application;

use RomainMillan\WebPushNotification\Domain\Subscription\AllowedPushServices;
use RomainMillan\WebPushNotification\Domain\Subscription\Owner;
use RomainMillan\WebPushNotification\Domain\Subscription\PushAddress;
use RomainMillan\WebPushNotification\Domain\Subscription\Subscription;
use RomainMillan\WebPushNotification\Domain\Subscription\SubscriptionRepository;

/**
 * The configured rules a registration must satisfy: allowed push services, quota,
 * and what eviction does in storage.
 */
final readonly class RegistrationRules
{
    public function __construct(
        private AllowedPushServices $allowedPushServices,
        private SubscriptionQuota $subscriptionQuota,
        private RetirementPolicy $retirementPolicy,
    ) {
    }

    public function allows(PushAddress $presented): bool
    {
        return $this->allowedPushServices->permits($presented->endpoint());
    }

    /**
     * @return bool false when the limit refuses (anonymous cap reached)
     */
    public function makeRoomFor(Subscription $candidate, Owner $owner, SubscriptionRepository $subscriptions, \DateTimeImmutable $at): bool
    {
        $decision = $this->subscriptionQuota->limitFor($owner)->makeRoomFor($candidate, $subscriptions);

        if ($decision->isRefused()) {
            return false;
        }

        foreach ($decision->evictions() as $evicted) {
            $evicted->evict($at);
            $this->retirementPolicy->store($evicted, $subscriptions);
        }

        return true;
    }
}
