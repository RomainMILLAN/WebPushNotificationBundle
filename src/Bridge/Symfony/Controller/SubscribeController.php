<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Bridge\Symfony\Controller;

use Psr\Log\LoggerInterface;
use RomainMillan\WebPushNotification\Application\AnonymousGate;
use RomainMillan\WebPushNotification\Application\Http\HttpStatus;
use RomainMillan\WebPushNotification\Application\Http\JsonRequestBody;
use RomainMillan\WebPushNotification\Application\Http\SubscribeRequest;
use RomainMillan\WebPushNotification\Application\RegisterSubscription;
use RomainMillan\WebPushNotification\Bridge\Symfony\Http\RequestGuard;
use RomainMillan\WebPushNotification\Domain\Exception\WebPushNotificationException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST — registers the browser of the current owner. Always 204, even when the
 * matrix refuses: the reason is logged, never told to the client.
 *
 * The anonymous gate runs FIRST, before the body is read, whatever the
 * application's access_control says about a route it did not write.
 */
final readonly class SubscribeController
{
    public function __construct(
        private AnonymousGate $anonymousGate,
        private RequestGuard $requestGuard,
        private RegisterSubscription $registerSubscription,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        try {
            $owner = $this->anonymousGate->resolve();
            $this->requestGuard->assertAcceptable($request, $owner);

            $body = JsonRequestBody::fromStream($request->headers->get('Content-Type', ''), $request->getContent(true));
            $outcome = $this->registerSubscription->register($owner, SubscribeRequest::fromBody($body)->address());

            if ($outcome->isRefused()) {
                $this->logger->info('Web push registration refused.', ['reason' => $outcome->value]);
            }

            return new Response('', HttpStatus::NEUTRAL);
        } catch (WebPushNotificationException $failure) {
            return new Response('', HttpStatus::forFailure($failure));
        }
    }
}
