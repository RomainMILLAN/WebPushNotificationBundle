<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Bridge\Symfony\Http;

use RomainMillan\WebPushNotification\Domain\Subscription\Owner;
use Symfony\Component\HttpFoundation\Request;

/**
 * CSRF, then rate limiting — one limiter for anonymous subscriptions, another for
 * every unsubscription (always on: the endpoint is public and hashes a proof).
 */
final readonly class RequestGuard
{
    public function __construct(
        private CsrfHeader $csrfHeader,
        private RequestRateLimit $anonymousRequestRateLimit,
        private RequestRateLimit $unsubscribeRequestRateLimit,
    ) {
    }

    public function assertAcceptable(Request $request, Owner $owner): void
    {
        $this->csrfHeader->assertValid($request);

        if ($owner->fold(static fn (): bool => false, static fn (): bool => true)) {
            $this->anonymousRequestRateLimit->consume($request);
        }
    }

    public function assertAcceptableWithoutOwner(Request $request): void
    {
        $this->csrfHeader->assertValid($request);
        $this->unsubscribeRequestRateLimit->consume($request);
    }
}
