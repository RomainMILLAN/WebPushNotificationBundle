<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Infrastructure\Dispatch;

use RomainMillan\WebPushNotification\Application\Port\PushDispatcher;
use RomainMillan\WebPushNotification\Application\WebPushSender;
use RomainMillan\WebPushNotification\Domain\Delivery\Audience;
use RomainMillan\WebPushNotification\Domain\Message\DeliveryOptions;
use RomainMillan\WebPushNotification\Domain\Message\WebPushMessage;

/** delivery.dispatcher: immediate — sends within the current process. */
final readonly class ImmediatePushDispatcher implements PushDispatcher
{
    public function __construct(
        private WebPushSender $webPushSender,
    ) {
    }

    public function dispatch(Audience $audience, WebPushMessage $message, DeliveryOptions $options): void
    {
        $this->webPushSender->send($audience, $message, $options);
    }
}
