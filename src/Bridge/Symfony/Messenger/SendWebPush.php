<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Bridge\Symfony\Messenger;

use RomainMillan\WebPushNotification\Application\Queue\QueuedDelivery;

/**
 * One device, scalars only (see QueuedDelivery). Route it to an async transport in
 * framework.messenger.routing; the notification text travels in the broker.
 */
final readonly class SendWebPush
{
    /**
     * @param array{subscription_id: string, expected_subscriber_id: string, payload: string, options: array{ttl: int, urgency: string, topic: string}} $delivery
     */
    public function __construct(
        public array $delivery,
    ) {
    }

    public static function createForDelivery(QueuedDelivery $delivery): self
    {
        return new self($delivery->toArray());
    }

    public function queuedDelivery(): QueuedDelivery
    {
        return QueuedDelivery::fromArray($this->delivery);
    }
}
