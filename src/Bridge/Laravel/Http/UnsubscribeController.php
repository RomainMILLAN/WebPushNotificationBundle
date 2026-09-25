<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Bridge\Laravel\Http;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Psr\Log\LoggerInterface;
use RomainMillan\WebPushNotification\Application\Http\HttpStatus;
use RomainMillan\WebPushNotification\Application\Http\JsonRequestBody;
use RomainMillan\WebPushNotification\Application\Http\UnsubscribeRequest;
use RomainMillan\WebPushNotification\Application\Unsubscribe;
use RomainMillan\WebPushNotification\Bridge\Laravel\Configuration\InvalidConfiguration;
use RomainMillan\WebPushNotification\Domain\Exception\WebPushNotificationException;

/**
 * POST {prefix}/subscriptions/unsubscribe, deliberately NOT behind AnonymousGate: the
 * auth secret proves the caller is the device (logout flow, anonymous visitors), and
 * the only thing it can do is remove its own subscription. Always 204.
 */
final readonly class UnsubscribeController
{
    public function __construct(
        private ClientRateLimit $clientRateLimit,
        private Unsubscribe $unsubscribe,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        try {
            $this->clientRateLimit->hit($request);

            $unsubscription = UnsubscribeRequest::fromBody(JsonRequestBody::fromStream($request->headers->get('Content-Type') ?? '', RequestStream::openBodyOf($request)));
            $this->unsubscribe->unsubscribe($unsubscription->endpoint(), $unsubscription->proof());
        } catch (InvalidConfiguration $misconfiguration) {
            throw $misconfiguration;
        } catch (WebPushNotificationException $rejected) {
            $this->logger->info('Web push unsubscription request rejected.', ['exception_class' => $rejected::class, 'rule' => $rejected->getMessage()]);

            return new Response('', HttpStatus::forFailure($rejected));
        }

        return new Response('', HttpStatus::NEUTRAL);
    }
}
