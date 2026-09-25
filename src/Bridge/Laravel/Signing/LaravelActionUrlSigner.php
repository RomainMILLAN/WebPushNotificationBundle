<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Bridge\Laravel\Signing;

use Illuminate\Contracts\Routing\UrlGenerator;
use RomainMillan\WebPushNotification\Application\Port\ActionUrlSigner;
use RomainMillan\WebPushNotification\Domain\Exception\InvalidValue;
use RomainMillan\WebPushNotification\Domain\Message\ActionUrl;
use RomainMillan\WebPushNotification\Domain\Message\Origin;

/**
 * Laravel's temporary signed routes: the receiving route verifies the signature and
 * the expiry with the "signed" middleware. The URL is absolute (the service worker
 * POSTs to it from the lock screen) and must stay within the application origin.
 */
final readonly class LaravelActionUrlSigner implements ActionUrlSigner
{
    public function __construct(
        private UrlGenerator $urlGenerator,
        private Origin $origin,
    ) {
    }

    public function sign(string $route, array $parameters, \DateInterval $validity): ActionUrl
    {
        if (1 === $validity->invert) {
            throw InvalidValue::because('Cannot sign an action URL with a negative validity.');
        }

        return ActionUrl::fromString($this->urlGenerator->temporarySignedRoute($route, $validity, $parameters), $this->origin);
    }
}
