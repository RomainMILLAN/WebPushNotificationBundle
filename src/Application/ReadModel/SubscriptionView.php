<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Application\ReadModel;

/**
 * One device, as listed to its owner. Deliberately without endpoint nor keys, and
 * without user agent or label: nothing is stored that is not needed (GDPR
 * minimisation).
 */
final readonly class SubscriptionView
{
    public function __construct(
        public string $id,
        public string $shortFingerprint,
        public string $pushService,
        public SubscriptionPeriod $period,
    ) {
    }
}
