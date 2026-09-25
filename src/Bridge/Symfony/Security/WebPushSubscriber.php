<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Bridge\Symfony\Security;

/**
 * Implemented by the application's user: a STABLE, never reused identifier —
 * typically "user:".$this->id. Never the e-mail: a user changing address and another
 * signing up with the old one would inherit each other's devices.
 */
interface WebPushSubscriber
{
    public function getWebPushSubscriberId(): string;
}
