<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Bridge\Symfony\Security;

use RomainMillan\WebPushNotification\Domain\Subscription\SubscriberId;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Configure your own (subscriber.resolver) when the user class cannot implement
 * WebPushSubscriber. It must return a stable, never reused identifier, namespaced so
 * that two user providers cannot collide ("admin:1" vs "customer:1").
 */
interface SubscriberIdResolver
{
    public function resolveSubscriberId(UserInterface $user): SubscriberId;
}
