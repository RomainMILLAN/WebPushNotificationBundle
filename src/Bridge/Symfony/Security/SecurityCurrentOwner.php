<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Bridge\Symfony\Security;

use RomainMillan\WebPushNotification\Application\Port\CurrentOwner;
use RomainMillan\WebPushNotification\Domain\Subscription\AnonymousOwner;
use RomainMillan\WebPushNotification\Domain\Subscription\IdentifiedOwner;
use RomainMillan\WebPushNotification\Domain\Subscription\Owner;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Who is behind the request, as the Security component sees it. It never decides
 * whether an anonymous visitor may subscribe: AnonymousGate does.
 */
final readonly class SecurityCurrentOwner implements CurrentOwner
{
    public function __construct(
        private TokenStorageInterface $tokenStorage,
        private SubscriberIdResolver $subscriberIdResolver,
    ) {
    }

    public function resolve(): Owner
    {
        $user = $this->tokenStorage->getToken()?->getUser();

        return $user instanceof UserInterface
            ? new IdentifiedOwner($this->subscriberIdResolver->resolveSubscriberId($user))
            : new AnonymousOwner();
    }
}
