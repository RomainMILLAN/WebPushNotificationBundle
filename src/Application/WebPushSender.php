<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Application;

use RomainMillan\WebPushNotification\Application\Contract\PayloadEncoder;
use RomainMillan\WebPushNotification\Domain\Delivery\Audience;
use RomainMillan\WebPushNotification\Domain\Delivery\DeliveryReport;
use RomainMillan\WebPushNotification\Domain\Message\DeliveryOptions;
use RomainMillan\WebPushNotification\Domain\Message\WebPushMessage;

/**
 * Synchronous send with a report — for the test command, diagnostics, or when the
 * caller needs to know. For everyday fire-and-forget, prefer PushDispatcher.
 */
final readonly class WebPushSender
{
    public function __construct(
        private PayloadEncoder $payloadEncoder,
        private DeliverPayload $deliverPayload,
    ) {
    }

    public function send(Audience $audience, WebPushMessage $message, DeliveryOptions $options): DeliveryReport
    {
        return $this->deliverPayload->deliver($audience, $this->payloadEncoder->encode($message), $options);
    }
}
