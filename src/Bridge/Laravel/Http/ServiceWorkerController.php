<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Bridge\Laravel\Http;

use Illuminate\Http\Response;
use RomainMillan\WebPushNotification\Application\ServiceWorker\ServiceWorkerConfig;
use RomainMillan\WebPushNotification\Application\ServiceWorker\ServiceWorkerScript;

use function Symfony\Component\String\u;

/**
 * GET {service_worker.path}: the prebuilt service worker with the configuration
 * injected. Routed outside any middleware group — no session, no cookie.
 */
final readonly class ServiceWorkerController
{
    public function __construct(
        private ServiceWorkerScript $serviceWorkerScript,
        private ServiceWorkerConfig $serviceWorkerConfig,
        private string $path,
    ) {
    }

    public function __invoke(): Response
    {
        $headers = [
            'Content-Type' => ServiceWorkerScript::CONTENT_TYPE,
            'X-Content-Type-Options' => 'nosniff',
            // Revalidated on every update check: a fixed script must reach browsers now.
            'Cache-Control' => 'no-cache',
        ];

        // A script below the root may only control its own directory unless allowed.
        if ('' !== u($this->path)->beforeLast('/')->toString()) {
            $headers['Service-Worker-Allowed'] = '/';
        }

        return new Response($this->serviceWorkerScript->render($this->serviceWorkerConfig), 200, $headers);
    }
}
