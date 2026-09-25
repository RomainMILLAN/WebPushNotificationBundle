<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Bridge\Laravel\Http;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Psr\Log\LoggerInterface;
use RomainMillan\WebPushNotification\Application\AnonymousGate;
use RomainMillan\WebPushNotification\Application\Http\HttpStatus;
use RomainMillan\WebPushNotification\Application\Http\JsonRequestBody;
use RomainMillan\WebPushNotification\Application\Http\SubscribeRequest;
use RomainMillan\WebPushNotification\Application\RegisterSubscription;
use RomainMillan\WebPushNotification\Bridge\Laravel\Auth\UnresolvableSubscriber;
use RomainMillan\WebPushNotification\Bridge\Laravel\Configuration\InvalidConfiguration;
use RomainMillan\WebPushNotification\Domain\Exception\WebPushNotificationException;
use RomainMillan\WebPushNotification\Domain\Subscription\Owner;

/**
 * POST {prefix}/subscriptions. The order IS the check:
 * 1. AnonymousGate — 403 before a single byte of the body is read, whatever the
 *    application's middleware;
 * 2. rate limit, for anonymous visitors;
 * 3. media type, real size, JSON depth, shape;
 * 4. registration — always answered 204: a refusal says nothing (no oracle).
 */
final readonly class SubscribeController
{
    public function __construct(
        private AnonymousGate $anonymousGate,
        private ClientRateLimit $clientRateLimit,
        private RegisterSubscription $registerSubscription,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        try {
            $owner = $this->anonymousGate->resolve();
            $this->limitAnonymous($owner, $request);

            $body = JsonRequestBody::fromStream($request->headers->get('Content-Type') ?? '', RequestStream::openBodyOf($request));
            $outcome = $this->registerSubscription->register($owner, SubscribeRequest::fromBody($body)->address());
        } catch (InvalidConfiguration|UnresolvableSubscriber $misconfiguration) {
            // A deployment bug, not a client error: it must surface as one.
            throw $misconfiguration;
        } catch (WebPushNotificationException $rejected) {
            $this->logger->info('Web push subscription request rejected.', ['exception_class' => $rejected::class, 'rule' => $rejected->getMessage()]);

            return new Response('', HttpStatus::forFailure($rejected));
        }

        if ($outcome->isRefused()) {
            $this->logger->info('Web push subscription refused.', ['reason' => $outcome->value]);
        }

        return new Response('', HttpStatus::NEUTRAL);
    }

    private function limitAnonymous(Owner $owner, Request $request): void
    {
        $owner->fold(
            static fn (): bool => true,
            function () use ($request): bool {
                $this->clientRateLimit->hit($request);

                return true;
            },
        );
    }
}
