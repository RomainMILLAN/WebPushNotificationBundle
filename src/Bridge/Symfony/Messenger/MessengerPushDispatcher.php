<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Bridge\Symfony\Messenger;

use RomainMillan\WebPushNotification\Application\Port\PushDispatcher;
use RomainMillan\WebPushNotification\Application\Queue\QueuedDeliveryPlanner;
use RomainMillan\WebPushNotification\Domain\Delivery\Audience;
use RomainMillan\WebPushNotification\Domain\Message\DeliveryOptions;
use RomainMillan\WebPushNotification\Domain\Message\WebPushMessage;
use Symfony\Component\Messenger\MessageBusInterface;

/** delivery.dispatcher: messenger — one SendWebPush per subscription. */
final readonly class MessengerPushDispatcher implements PushDispatcher
{
    public function __construct(
        private QueuedDeliveryPlanner $queuedDeliveryPlanner,
        private MessageBusInterface $messageBus,
    ) {
    }

    public function dispatch(Audience $audience, WebPushMessage $message, DeliveryOptions $options): void
    {
        foreach ($this->queuedDeliveryPlanner->plan($audience, $message, $options) as $delivery) {
            $this->messageBus->dispatch(SendWebPush::createForDelivery($delivery));
        }
    }
}
