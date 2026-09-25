<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Bridge\Laravel\View;

use Illuminate\Http\Request;
use RomainMillan\WebPushNotification\Application\ClientConfiguration;
use RomainMillan\WebPushNotification\Application\Port\CurrentOwner;
use RomainMillan\WebPushNotification\Bridge\Laravel\Http\MarkWebPushResponsePrivate;

/**
 * @webPushMeta and <x-web-push::meta/>: <meta name="web-push-config"> for the page
 * client. The owner is resolved WITHOUT AnonymousGate: rendering the tag decides
 * nothing, the subscribe route does.
 */
final readonly class WebPushMetaTag
{
    public const CSRF_HEADER = 'X-CSRF-TOKEN';

    public function __construct(
        private ClientConfiguration $clientConfiguration,
        private CurrentOwner $currentOwner,
    ) {
    }

    public function renderFor(Request $request): string
    {
        $request->attributes->set(MarkWebPushResponsePrivate::REQUEST_ATTRIBUTE, true);
        $csrfToken = $request->hasSession() ? $request->session()->token() : '';

        return $this->clientConfiguration->renderMetaTag($this->currentOwner->resolve(), self::CSRF_HEADER, $csrfToken);
    }
}
