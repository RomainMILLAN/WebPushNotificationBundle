<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Domain\Subscription;

final readonly class PerOwnerLimit implements SubscriptionLimit
{
    public function __construct(
        private Owner $owner,
        private int $max,
    ) {
    }

    public function makeRoomFor(Subscription $candidate, SubscriptionRepository $subscriptions): QuotaDecision
    {
        $others = $subscriptions->ownedBy($this->owner)->without($candidate->id());

        return QuotaDecision::evict(DeviceQuota::createAllowing($this->max)->evictionsToFitOneMore($others));
    }
}
