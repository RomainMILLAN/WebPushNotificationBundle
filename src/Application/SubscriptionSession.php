<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Application;

use RomainMillan\WebPushNotification\Domain\Subscription\EndpointFingerprint;
use RomainMillan\WebPushNotification\Domain\Subscription\Owner;
use RomainMillan\WebPushNotification\Domain\Subscription\OwnerSubscriptions;
use RomainMillan\WebPushNotification\Domain\Subscription\Subscription;
use RomainMillan\WebPushNotification\Domain\Subscription\SubscriptionId;
use RomainMillan\WebPushNotification\Domain\Subscription\SubscriptionRepository;

/**
 * The repository as seen from inside one SubscriptionTransaction: every aggregate
 * saved or removed hands its recorded events over, to be published after commit.
 */
final class SubscriptionSession implements SubscriptionRepository
{
    /** @var list<object> */
    private array $events = [];

    public function __construct(
        private readonly SubscriptionRepository $subscriptionRepository,
    ) {
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

    public function save(Subscription $subscription): void
    {
        $this->subscriptionRepository->save($subscription);
        $this->collectEventsOf($subscription);
    }

    public function remove(Subscription $subscription): void
    {
        $this->subscriptionRepository->remove($subscription);
        $this->collectEventsOf($subscription);
    }

    /**
     * @return list<object>
     */
    public function releaseEvents(): array
    {
        $events = $this->events;
        $this->events = [];

        return $events;
    }

    private function collectEventsOf(Subscription $subscription): void
    {
        foreach ($subscription->releaseEvents() as $event) {
            $this->events[] = $event;
        }
    }
}
