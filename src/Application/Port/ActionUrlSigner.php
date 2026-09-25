<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Application\Port;

use RomainMillan\WebPushNotification\Domain\Message\ActionUrl;

/**
 * Builds the URL of a PostAction (e.g. "acknowledge"): the service worker POSTs to it
 * from the lock screen with the user's cookies and no CSRF token, so it must carry its
 * own short-lived authorization. Implementations sign with the framework's native
 * mechanism (Symfony UriSigner, Laravel temporarySignedRoute); the receiving route
 * verifies it the same way.
 */
interface ActionUrlSigner
{
    /**
     * @param array<string, scalar> $parameters route parameters
     */
    public function sign(string $route, array $parameters, \DateInterval $validity): ActionUrl;
}
