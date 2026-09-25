<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Application\Port;

use RomainMillan\WebPushNotification\Domain\Subscription\Owner;

/**
 * Who is registering: the authenticated subscriber, or an anonymous visitor.
 *
 * Implemented by each bridge (Symfony Security, Laravel Auth) and always decorated by
 * AnonymousGate, which owns the "anonymous allowed?" rule — never the controllers.
 */
interface CurrentOwner
{
    public function resolve(): Owner;
}
