<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Domain\Delivery;

use RomainMillan\WebPushNotification\Domain\Subscription\Owner;
use RomainMillan\WebPushNotification\Domain\Subscription\Subscription;
use RomainMillan\WebPushNotification\Domain\Subscription\SubscriptionId;
use RomainMillan\WebPushNotification\Domain\Subscription\SubscriptionRepository;

/**
 * Precise subscriptions, as long as they still belong to the owner they were
 * selected for. This is what an async message rebuilds: if the device changed hands
 * between dispatch and consumption, the message is dropped, never delivered to the
 * new owner.
 */
final readonly class SubscriptionsAudience implements Audience
{
    /**
     * @param list<SubscriptionId> $ids
     */
    public function __construct(
        private array $ids,
        private Owner $expectedOwner,
    ) {
    }

    public function selectFrom(SubscriptionRepository $subscriptions, int $batchSize): iterable
    {
        foreach (array_chunk($this->ids, $batchSize) as $ids) {
            yield $subscriptions->getMany($ids);
        }
    }

    public function admits(Subscription $subscription): FailureCategory
    {
        return match (true) {
            !$subscription->isActive() => FailureCategory::NotActive,
            !$subscription->isOwnedBy($this->expectedOwner) => FailureCategory::OwnerChanged,
            default => FailureCategory::None,
        };
    }
}
