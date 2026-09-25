<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Application\Port;

use RomainMillan\WebPushNotification\Application\Contract\EncodedPayload;
use RomainMillan\WebPushNotification\Domain\Delivery\DeliveryReport;
use RomainMillan\WebPushNotification\Domain\Delivery\DeliveryTarget;
use RomainMillan\WebPushNotification\Domain\Message\DeliveryOptions;

/**
 * Talks the Web Push protocol. Never throws for a delivery failure: every target
 * gets a classified outcome in the report.
 */
interface PushTransport
{
    /**
     * @param list<DeliveryTarget> $targets
     */
    public function deliver(array $targets, EncodedPayload $payload, DeliveryOptions $options): DeliveryReport;
}
