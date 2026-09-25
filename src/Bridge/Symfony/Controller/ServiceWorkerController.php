<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Bridge\Symfony\Controller;

use RomainMillan\WebPushNotification\Application\ServiceWorker\ServiceWorkerConfig;
use RomainMillan\WebPushNotification\Application\ServiceWorker\ServiceWorkerScript;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

use function Symfony\Component\String\u;

/**
 * GET — the prebuilt service worker with the configuration injected. Stateless (the
 * route is marked so): the script never depends on who asks for it.
 */
final readonly class ServiceWorkerController
{
    public function __construct(
        private ServiceWorkerScript $serviceWorkerScript,
        private ServiceWorkerConfig $serviceWorkerConfig,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $response = new Response($this->serviceWorkerScript->render($this->serviceWorkerConfig), Response::HTTP_OK, [
            'Content-Type' => ServiceWorkerScript::CONTENT_TYPE,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-cache',
        ]);

        // A worker's maximum scope is its own directory: widen it only when needed.
        if (u($request->getPathInfo())->trimStart('/')->containsAny('/')) {
            $response->headers->set('Service-Worker-Allowed', '/');
        }

        return $response;
    }
}
