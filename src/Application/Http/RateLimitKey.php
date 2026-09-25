<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Application\Http;

use function Symfony\Component\String\b;

/**
 * The rate-limit key of a client address, computed by the package — not left to the
 * integrator: an IPv6 client owns at least a /64, so limiting per address would give
 * it 2^64 buckets.
 */
final readonly class RateLimitKey
{
    private function __construct(
        private string $value,
    ) {
    }

    public static function fromClientIp(string $ip): self
    {
        $packed = @inet_pton($ip);

        if (false === $packed) {
            return new self('web-push:unknown');
        }

        if (16 === b($packed)->length()) {
            $prefix = inet_ntop(b($packed)->slice(0, 8)->toString().str_repeat("\x00", 8));

            return new self('web-push:'.(false !== $prefix ? $prefix : 'unknown').'/64');
        }

        return new self('web-push:'.$ip);
    }

    public function toString(): string
    {
        return $this->value;
    }
}
