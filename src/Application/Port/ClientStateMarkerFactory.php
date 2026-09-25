<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Application\Port;

use RomainMillan\WebPushNotification\Domain\ClientState\ClientStateMarker;
use RomainMillan\WebPushNotification\Domain\Subscription\SubscriberId;

/**
 * Derives the client state marker from the IDENTITY, never the session: a re-login of
 * the same user must not wipe a pending navigation intent — that is exactly the case
 * the intent exists for. Anonymous visitors get no marker.
 */
interface ClientStateMarkerFactory
{
    public function createForSubscriber(SubscriberId $subscriberId): ClientStateMarker;
}
