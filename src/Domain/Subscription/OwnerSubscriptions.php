<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Domain\Subscription;

/**
 * The active subscriptions of one owner — a first-class collection.
 *
 * @implements \IteratorAggregate<int, Subscription>
 */
final readonly class OwnerSubscriptions implements \IteratorAggregate, \Countable
{
    /**
     * @param list<Subscription> $subscriptions
     */
    public function __construct(
        private array $subscriptions,
    ) {
    }

    public function without(SubscriptionId $id): self
    {
        return new self(array_values(array_filter(
            $this->subscriptions,
            static fn (Subscription $subscription): bool => !$subscription->id()->equals($id),
        )));
    }

    /**
     * @return list<Subscription>
     */
    public function leastRecentlyRegistered(int $count): array
    {
        $sorted = $this->subscriptions;
        usort($sorted, static fn (Subscription $left, Subscription $right): int => match (true) {
            $left->isRegisteredBefore($right) => -1,
            $right->isRegisteredBefore($left) => 1,
            default => 0,
        });

        return \array_slice($sorted, 0, max(0, $count));
    }

    public function count(): int
    {
        return \count($this->subscriptions);
    }

    /**
     * @return \ArrayIterator<int, Subscription>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->subscriptions);
    }
}
