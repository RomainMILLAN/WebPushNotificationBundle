<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Bridge\Laravel\Notifications;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Notifications\Notification;
use RomainMillan\WebPushNotification\Application\Port\PushDispatcher;
use RomainMillan\WebPushNotification\Bridge\Laravel\Auth\SubscriberIdResolver;
use RomainMillan\WebPushNotification\Bridge\Laravel\Auth\WebPushSubscriber;
use RomainMillan\WebPushNotification\Domain\Delivery\SubscriberAudience;
use RomainMillan\WebPushNotification\Domain\Message\DeliveryOptions;

/**
 * Laravel Notifications channel: every active device of the notifiable's subscriber,
 * through PushDispatcher — synchronous or queued as configured.
 *
 * The notifiable is identified, in order, by WebPushSubscriber,
 * routeNotificationForWebPush() (or Notification::route('web-push', 'user:42')), then
 * the same resolver as the subscribe route for authenticatable models — so the id a
 * device was registered under is the id it is notified under.
 */
final readonly class WebPushChannel
{
    public const NAME = 'web-push';

    public function __construct(
        private PushDispatcher $pushDispatcher,
        private SubscriberIdResolver $subscriberIdResolver,
        private DeliveryOptions $deliveryOptions,
    ) {
    }

    public function send(object $notifiable, Notification $notification): void
    {
        if (!$notification instanceof WebPushNotification) {
            throw new \InvalidArgumentException(\sprintf('Cannot send %s through the web push channel: it does not implement %s.', $notification::class, WebPushNotification::class));
        }

        $subscriberId = $this->subscriberIdOf($notifiable, $notification);

        // No route, no send: the Laravel convention for a notifiable without address.
        if ('' === $subscriberId) {
            return;
        }

        $this->pushDispatcher->dispatch(
            SubscriberAudience::fromSubscriberId($subscriberId),
            $notification->toWebPush($notifiable),
            $notification instanceof WebPushNotificationOptions ? $notification->toWebPushOptions($notifiable) : $this->deliveryOptions,
        );
    }

    private function subscriberIdOf(object $notifiable, Notification $notification): string
    {
        if ($notifiable instanceof WebPushSubscriber) {
            return $notifiable->getWebPushSubscriberId();
        }

        $route = method_exists($notifiable, 'routeNotificationFor') ? $notifiable->routeNotificationFor(self::NAME, $notification) : null;

        if (\is_string($route) && '' !== $route) {
            return $route;
        }

        return $notifiable instanceof Authenticatable ? $this->subscriberIdResolver->resolveSubscriberId($notifiable)->toString() : '';
    }
}
