<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Bridge\Symfony\Http;

use RomainMillan\WebPushNotification\Application\Http\RateLimitKey;
use RomainMillan\WebPushNotification\Application\Http\TooManyRequests;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Rate limiting keyed by the package (IPv6 aggregated per /64). Mandatory when
 * anonymous subscriptions are enabled — the container refuses to build otherwise —
 * and always on for unsubscriptions.
 */
final readonly class RequestRateLimit
{
    /**
     * @param list<RateLimiterFactory> $rateLimiterFactory zero (disabled) or one
     */
    public function __construct(
        private array $rateLimiterFactory,
    ) {
    }

    public function consume(Request $request): void
    {
        foreach ($this->rateLimiterFactory as $factory) {
            if (!$factory->create(RateLimitKey::fromClientIp($request->getClientIp() ?? '')->toString())->consume()->isAccepted()) {
                throw TooManyRequests::create();
            }
        }
    }
}
