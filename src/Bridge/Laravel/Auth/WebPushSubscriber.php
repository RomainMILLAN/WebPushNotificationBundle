<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Bridge\Laravel\Auth;

/**
 * Implemented by the authenticatable model (or a notifiable) to name its web push
 * identity explicitly, e.g. "user:42".
 *
 * It MUST be stable and never reused — never an e-mail address: a user changing
 * address and another one signing up with the old one would inherit each other's
 * devices.
 */
interface WebPushSubscriber
{
    public function getWebPushSubscriberId(): string;
}
