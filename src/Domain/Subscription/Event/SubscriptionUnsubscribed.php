<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Domain\Subscription\Event;

use RomainMillan\WebPushNotification\Domain\Subscription\EndpointFingerprint;
use RomainMillan\WebPushNotification\Domain\Subscription\Owner;
use RomainMillan\WebPushNotification\Domain\Subscription\SubscriptionId;
use RomainMillan\WebPushNotification\Domain\Subscription\UnsubscribeCause;

/** The subscription was deleted on purpose — one event per subscription. */
final readonly class SubscriptionUnsubscribed extends SubscriptionEvent
{
    public function __construct(
        SubscriptionId $subscriptionId,
        EndpointFingerprint $fingerprint,
        Owner $owner,
        public UnsubscribeCause $cause,
        \DateTimeImmutable $occurredAt,
    ) {
        parent::__construct($subscriptionId, $fingerprint, $owner, $occurredAt);
    }
}
