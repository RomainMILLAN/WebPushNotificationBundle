<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Domain\Delivery;

use RomainMillan\WebPushNotification\Domain\Exception\SubscriptionNotFound;
use RomainMillan\WebPushNotification\Domain\Subscription\SubscriptionId;

/**
 * Outcomes by subscription id.
 *
 * @implements \IteratorAggregate<string, DeliveryOutcome>
 */
final readonly class DeliveryReport implements \IteratorAggregate, \Countable
{
    /**
     * @param array<string, DeliveryOutcome> $outcomes keyed by subscription id
     */
    private function __construct(
        private array $outcomes,
    ) {
    }

    public static function empty(): self
    {
        return new self([]);
    }

    public function recordOutcome(SubscriptionId $id, DeliveryOutcome $outcome): self
    {
        return new self([...$this->outcomes, $id->toString() => $outcome]);
    }

    public function merge(self $other): self
    {
        return new self([...$this->outcomes, ...$other->outcomes]);
    }

    public function hasOutcomeFor(SubscriptionId $id): bool
    {
        return isset($this->outcomes[$id->toString()]);
    }

    /**
     * @throws SubscriptionNotFound
     */
    public function outcomeFor(SubscriptionId $id): DeliveryOutcome
    {
        return $this->outcomes[$id->toString()] ?? throw SubscriptionNotFound::withId($id->toString());
    }

    public function countWith(DeliveryStatus $status): int
    {
        return \count(array_filter($this->outcomes, static fn (DeliveryOutcome $outcome): bool => $outcome->status === $status));
    }

    public function hasRetryableFailure(): bool
    {
        return 0 < $this->countWith(DeliveryStatus::Transient);
    }

    /** The longest Retry-After among retryable failures, in seconds. */
    public function retryAfterSeconds(): int
    {
        $retryAfter = 0;
        foreach ($this->outcomes as $outcome) {
            if ($outcome->shouldRetry()) {
                $retryAfter = max($retryAfter, $outcome->retryAfterSeconds);
            }
        }

        return $retryAfter;
    }

    public function count(): int
    {
        return \count($this->outcomes);
    }

    /**
     * @return \ArrayIterator<string, DeliveryOutcome>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->outcomes);
    }
}
