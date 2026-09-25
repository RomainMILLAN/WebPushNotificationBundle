<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Bridge\Symfony\Security;

use RomainMillan\WebPushNotification\Domain\Subscription\SubscriberId;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Default resolver. Deliberately NO fallback on getUserIdentifier(): in most
 * applications it is the e-mail address — mutable, reusable, personal data.
 */
final readonly class WebPushSubscriberResolver implements SubscriberIdResolver
{
    public function resolveSubscriberId(UserInterface $user): SubscriberId
    {
        if (!$user instanceof WebPushSubscriber) {
            throw new \LogicException(\sprintf('Cannot resolve a web push subscriber id: "%s" must implement %s, or configure web_push_notification.subscriber.resolver.', $user::class, WebPushSubscriber::class));
        }

        return SubscriberId::fromString($user->getWebPushSubscriberId());
    }
}
