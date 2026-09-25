<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Domain\Subscription\Event;

/**
 * A bulk purge ran. Aggregated on purpose: a purge deletes rows in bulk, bypassing
 * the aggregates, and emits NO per-subscription event.
 */
final readonly class SubscriptionsPurged
{
    public function __construct(
        public int $retired,
        public int $abandonedAnonymous,
        public \DateTimeImmutable $occurredAt,
    ) {
    }
}
