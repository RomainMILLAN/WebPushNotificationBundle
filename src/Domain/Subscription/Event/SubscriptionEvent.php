<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Domain\Subscription\Event;

use RomainMillan\WebPushNotification\Domain\Subscription\EndpointFingerprint;
use RomainMillan\WebPushNotification\Domain\Subscription\Owner;
use RomainMillan\WebPushNotification\Domain\Subscription\SubscriptionId;

/**
 * A business fact about one subscription, independent of the storage strategy
 * (delete vs deactivate). Never carries the endpoint: only its fingerprint.
 *
 * Published after the real commit, never from inside the transaction.
 */
abstract readonly class SubscriptionEvent
{
    public function __construct(
        public SubscriptionId $subscriptionId,
        public EndpointFingerprint $fingerprint,
        public Owner $owner,
        public \DateTimeImmutable $occurredAt,
    ) {
    }
}
