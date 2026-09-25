<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Application\Port;

use RomainMillan\WebPushNotification\Domain\Delivery\Audience;
use RomainMillan\WebPushNotification\Domain\Message\DeliveryOptions;
use RomainMillan\WebPushNotification\Domain\Message\WebPushMessage;

/**
 * The everyday entry point: fire and forget, synchronous or queued depending on the
 * configuration (Immediate, Messenger, Laravel Queue). Use WebPushSender when you
 * need the DeliveryReport.
 */
interface PushDispatcher
{
    public function dispatch(Audience $audience, WebPushMessage $message, DeliveryOptions $options): void;
}
