<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Domain\Subscription\Event;

use RomainMillan\WebPushNotification\Domain\Subscription\EndpointFingerprint;
use RomainMillan\WebPushNotification\Domain\Subscription\Owner;
use RomainMillan\WebPushNotification\Domain\Subscription\SubscriptionId;

/**
 * A shared browser changed hands, after proof of possession of its auth secret.
 * Security-relevant: worth an audit log.
 */
final readonly class SubscriptionReassigned extends SubscriptionEvent
{
    public function __construct(
        SubscriptionId $subscriptionId,
        EndpointFingerprint $fingerprint,
        public Owner $previousOwner,
        Owner $owner,
        \DateTimeImmutable $occurredAt,
    ) {
        parent::__construct($subscriptionId, $fingerprint, $owner, $occurredAt);
    }
}
