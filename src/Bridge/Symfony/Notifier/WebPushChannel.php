<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Bridge\Symfony\Notifier;

use RomainMillan\WebPushNotification\Application\Port\PushDispatcher;
use RomainMillan\WebPushNotification\Domain\Delivery\SubscriberAudience;
use RomainMillan\WebPushNotification\Domain\Message\DeliveryOptions;
use RomainMillan\WebPushNotification\Domain\Message\WebPushMessage;
use Symfony\Component\Notifier\Channel\ChannelInterface;
use Symfony\Component\Notifier\Notification\Notification;
use Symfony\Component\Notifier\Recipient\RecipientInterface;

/**
 * The "web_push" channel: new Notification('Payment received', ['web_push']).
 *
 * A dedicated channel rather than a transport on Notifier's "push" channel, because
 * the "push" channel has no notion of recipient — it cannot know whose devices to
 * reach. Sync or queued follows delivery.dispatcher, not the channel. Push headers
 * default to delivery.ttl / delivery.urgency (see WebPushNotificationOptionsInterface).
 */
final readonly class WebPushChannel implements ChannelInterface
{
    public function __construct(
        private PushDispatcher $pushDispatcher,
        private DeliveryOptions $deliveryOptions,
    ) {
    }

    public function notify(Notification $notification, RecipientInterface $recipient, ?string $transportName = null): void
    {
        if (!$recipient instanceof WebPushRecipientInterface) {
            return;
        }

        $message = $notification instanceof WebPushNotificationInterface
            ? $notification->asWebPushMessage($recipient)
            : WebPushMessage::createWithTitle($notification->getSubject(), $notification->getContent());

        $options = $notification instanceof WebPushNotificationOptionsInterface
            ? $notification->webPushOptions($this->deliveryOptions)
            : $this->deliveryOptions;

        $this->pushDispatcher->dispatch(SubscriberAudience::fromSubscriberId($recipient->getWebPushSubscriberId()), $message, $options);
    }

    public function supports(Notification $notification, RecipientInterface $recipient): bool
    {
        return $recipient instanceof WebPushRecipientInterface;
    }
}
