<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Bridge\Symfony;

use RomainMillan\WebPushNotification\Application\ClientConfiguration;
use RomainMillan\WebPushNotification\Application\Port\ClientStateMarkerFactory;
use RomainMillan\WebPushNotification\Infrastructure\Vapid\VapidCredentials;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** URLs are generated at runtime: the router is not available at container build. */
final readonly class ClientConfigurationFactory
{
    /**
     * @param array{clickPrefixes: list<string>, stateCache: string, registerUrl: string} $serviceWorker clickPrefixes and stateCache are shared with the worker (the page claims intents from the same Cache Storage); an empty registerUrl means the package route
     */
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private VapidCredentials $vapidCredentials,
        private ClientStateMarkerFactory $clientStateMarkerFactory,
        private array $serviceWorker,
    ) {
    }

    public function createClientConfiguration(): ClientConfiguration
    {
        return new ClientConfiguration([
            'publicKey' => $this->vapidCredentials->publicKey(),
            'serviceWorker' => '' === $this->serviceWorker['registerUrl'] ? $this->urlGenerator->generate('web_push_service_worker') : $this->serviceWorker['registerUrl'],
            'subscribe' => $this->urlGenerator->generate('web_push_subscribe'),
            'unsubscribe' => $this->urlGenerator->generate('web_push_unsubscribe'),
            'clickPrefixes' => $this->serviceWorker['clickPrefixes'],
            'stateCache' => $this->serviceWorker['stateCache'],
        ], $this->clientStateMarkerFactory);
    }
}
