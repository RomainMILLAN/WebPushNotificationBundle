<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Application;

use RomainMillan\WebPushNotification\Application\Port\ClientStateMarkerFactory;
use RomainMillan\WebPushNotification\Application\ServiceWorker\ServiceWorkerScript;
use RomainMillan\WebPushNotification\Domain\Subscription\Owner;
use RomainMillan\WebPushNotification\Domain\Subscription\SubscriberId;

/**
 * What the page-side client needs, rendered as <meta name="web-push-config"> —
 * no JS global. It holds a CSRF token and the client state marker, both per user: the
 * bridges mark the response private so no shared cache serves them to someone else.
 */
final readonly class ClientConfiguration
{
    /**
     * @param array{publicKey: string, serviceWorker: string, subscribe: string, unsubscribe: string, clickPrefixes: list<string>, stateCache: string} $settings
     */
    public function __construct(
        private array $settings,
        private ClientStateMarkerFactory $clientStateMarkerFactory,
    ) {
    }

    public function renderMetaTag(Owner $owner, string $csrfHeader, string $csrfToken): string
    {
        $config = $this->settings + [
            'csrfHeader' => $csrfHeader,
            'csrfToken' => $csrfToken,
            'clientState' => $owner->fold(
                fn (SubscriberId $id): string => $this->clientStateMarkerFactory->createForSubscriber($id)->toString(),
                static fn (): string => '',
            ),
        ];

        $json = ServiceWorkerScript::encodeForScript($config);

        return '<meta name="web-push-config" content="'.htmlspecialchars($json, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8').'">';
    }
}
