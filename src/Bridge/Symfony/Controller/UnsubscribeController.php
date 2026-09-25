<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Bridge\Symfony\Controller;

use RomainMillan\WebPushNotification\Application\Http\HttpStatus;
use RomainMillan\WebPushNotification\Application\Http\JsonRequestBody;
use RomainMillan\WebPushNotification\Application\Http\UnsubscribeRequest;
use RomainMillan\WebPushNotification\Application\Unsubscribe;
use RomainMillan\WebPushNotification\Bridge\Symfony\Http\RequestGuard;
use RomainMillan\WebPushNotification\Domain\Exception\WebPushNotificationException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST — the device removes itself by proving possession of its auth secret. Not
 * behind the anonymous gate: it must work for any owner, even at logout. Always 204:
 * unknown endpoint, someone else's, wrong proof — all look the same.
 */
final readonly class UnsubscribeController
{
    public function __construct(
        private RequestGuard $requestGuard,
        private Unsubscribe $unsubscribe,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        try {
            $this->requestGuard->assertAcceptableWithoutOwner($request);

            $unsubscription = UnsubscribeRequest::fromBody(JsonRequestBody::fromStream($request->headers->get('Content-Type', ''), $request->getContent(true)));
            $this->unsubscribe->unsubscribe($unsubscription->endpoint(), $unsubscription->proof());

            return new Response('', HttpStatus::NEUTRAL);
        } catch (WebPushNotificationException $failure) {
            return new Response('', HttpStatus::forFailure($failure));
        }
    }
}
