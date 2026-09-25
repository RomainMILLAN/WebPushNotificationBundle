<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Domain\Delivery;

use RomainMillan\WebPushNotification\Domain\Subscription\IdentifiedOwner;
use RomainMillan\WebPushNotification\Domain\Subscription\SubscriberId;
use RomainMillan\WebPushNotification\Domain\Subscription\Subscription;
use RomainMillan\WebPushNotification\Domain\Subscription\SubscriptionRepository;

/** Every active device of one subscriber — the usual case. */
final readonly class SubscriberAudience implements Audience
{
    private IdentifiedOwner $owner;

    public function __construct(SubscriberId $subscriberId)
    {
        $this->owner = new IdentifiedOwner($subscriberId);
    }

    public static function fromSubscriberId(string $subscriberId): self
    {
        return new self(SubscriberId::fromString($subscriberId));
    }

    public function selectFrom(SubscriptionRepository $subscriptions, int $batchSize): iterable
    {
        /** @var list<Subscription> $owned */
        $owned = iterator_to_array($subscriptions->ownedBy($this->owner), false);

        yield from array_chunk($owned, $batchSize);
    }

    public function admits(Subscription $subscription): FailureCategory
    {
        return match (true) {
            !$subscription->isActive() => FailureCategory::NotActive,
            !$subscription->isOwnedBy($this->owner) => FailureCategory::OwnerChanged,
            default => FailureCategory::None,
        };
    }
}
