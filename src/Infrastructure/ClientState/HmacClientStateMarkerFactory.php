<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Infrastructure\ClientState;

use RomainMillan\WebPushNotification\Application\Port\ClientStateMarkerFactory;
use RomainMillan\WebPushNotification\Domain\ClientState\ClientStateMarker;
use RomainMillan\WebPushNotification\Domain\Subscription\SubscriberId;

use function Symfony\Component\String\u;

final readonly class HmacClientStateMarkerFactory implements ClientStateMarkerFactory
{
    /**
     * The versioned purpose string is not decorative: it is the ONLY way to rotate an
     * otherwise lifelong pseudonymous identifier.
     */
    private const PURPOSE = 'web-push-client-state-v1';

    public function __construct(
        #[\SensitiveParameter]
        private string $applicationSecret,
    ) {
    }

    public function createForSubscriber(SubscriberId $subscriberId): ClientStateMarker
    {
        // A purpose sub-key, never the root secret: the application secret also signs
        // cookies, and publishing (known message, tag) pairs under it in every page
        // would spend a margin nobody needs to spend.
        $purposeKey = hash_hmac('sha256', self::PURPOSE, $this->applicationSecret, true);
        $digest = hash_hmac('sha256', 'client-state:'.$subscriberId->toString(), $purposeKey);

        return ClientStateMarker::fromDigest(u($digest)->slice(0, 16)->toString());
    }
}
