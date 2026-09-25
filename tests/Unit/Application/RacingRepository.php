<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Tests\Unit\Application;

use RomainMillan\WebPushNotification\Domain\Exception\SubscriptionAlreadyExists;
use RomainMillan\WebPushNotification\Domain\Subscription\EndpointFingerprint;
use RomainMillan\WebPushNotification\Domain\Subscription\Owner;
use RomainMillan\WebPushNotification\Domain\Subscription\OwnerSubscriptions;
use RomainMillan\WebPushNotification\Domain\Subscription\Subscription;
use RomainMillan\WebPushNotification\Domain\Subscription\SubscriptionId;
use RomainMillan\WebPushNotification\Domain\Subscription\SubscriptionRepository;

/**
 * The first save loses the race: a concurrent registration inserts the same endpoint
 * right before it, and the unique index answers.
 */
final class RacingRepository implements SubscriptionRepository
{
    public int $saveAttempts = 0;

    public function __construct(
        private readonly SubscriptionRepository $subscriptionRepository,
        private readonly Subscription $concurrentlyInserted,
    ) {
    }

    public function save(Subscription $subscription): void
    {
        ++$this->saveAttempts;

        if (1 === $this->saveAttempts) {
            $this->subscriptionRepository->save($this->concurrentlyInserted);

            throw SubscriptionAlreadyExists::forFingerprint($subscription->fingerprint()->short());
        }

        $this->subscriptionRepository->save($subscription);
    }

    public function get(SubscriptionId $id): Subscription
    {
        return $this->subscriptionRepository->get($id);
    }

    public function hasFingerprint(EndpointFingerprint $fingerprint): bool
    {
        return $this->subscriptionRepository->hasFingerprint($fingerprint);
    }

    public function getByFingerprint(EndpointFingerprint $fingerprint): Subscription
    {
        return $this->subscriptionRepository->getByFingerprint($fingerprint);
    }

    public function ownedBy(Owner $owner): OwnerSubscriptions
    {
        return $this->subscriptionRepository->ownedBy($owner);
    }

    public function getOwnedSubscription(Owner $owner, SubscriptionId $id): Subscription
    {
        return $this->subscriptionRepository->getOwnedSubscription($owner, $id);
    }

    public function getMany(array $ids): array
    {
        return $this->subscriptionRepository->getMany($ids);
    }

    public function activeInBatches(int $size): iterable
    {
        return $this->subscriptionRepository->activeInBatches($size);
    }

    public function countActiveAnonymous(): int
    {
        return $this->subscriptionRepository->countActiveAnonymous();
    }

    public function remove(Subscription $subscription): void
    {
        $this->subscriptionRepository->remove($subscription);
    }
}
