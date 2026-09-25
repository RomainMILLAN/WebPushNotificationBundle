<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Application;

use RomainMillan\WebPushNotification\Application\Exception\AnonymousSubscriptionsDisabled;
use RomainMillan\WebPushNotification\Application\Port\CurrentOwner;
use RomainMillan\WebPushNotification\Domain\Subscription\Owner;
use RomainMillan\WebPushNotification\Domain\Subscription\SubscriberId;

/**
 * Deny by default, owned by the package — not by the application's access_control or
 * middleware, which would not know about routes it did not write. Controllers call it
 * BEFORE reading the request body.
 */
final readonly class AnonymousGate implements CurrentOwner
{
    public function __construct(
        private CurrentOwner $currentOwner,
        private bool $anonymousAllowed,
    ) {
    }

    /**
     * @throws AnonymousSubscriptionsDisabled
     */
    public function resolve(): Owner
    {
        $owner = $this->currentOwner->resolve();

        return $owner->fold(
            static fn (SubscriberId $id): Owner => $owner,
            fn (): Owner => $this->anonymousAllowed ? $owner : throw AnonymousSubscriptionsDisabled::create(),
        );
    }
}
