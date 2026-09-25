<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Bridge\Laravel\Http;

use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use RomainMillan\WebPushNotification\Application\Http\RateLimitKey;
use RomainMillan\WebPushNotification\Application\Http\TooManyRequests;
use RomainMillan\WebPushNotification\Bridge\Laravel\Configuration\InvalidConfiguration;

/**
 * Applies a RateLimiter::for() limiter's limits, but keyed by the package: by client
 * network (IPv4 address, IPv6 /64), whatever ->by() the application wrote — an IPv6
 * client owns 2^64 addresses.
 */
final readonly class ClientRateLimit
{
    private const DEFAULT_PER_MINUTE = 30;

    /**
     * @param string $limiterName '' for the package default (30 per minute)
     */
    public function __construct(
        private RateLimiter $rateLimiter,
        private string $scope,
        private string $limiterName,
    ) {
    }

    /**
     * @throws TooManyRequests
     */
    public function hit(Request $request): void
    {
        $client = RateLimitKey::fromClientIp($request->ip() ?? '')->toString();

        foreach ($this->limitsFor($request) as $index => $limit) {
            $key = $client.':'.$this->scope.':'.$this->limiterName.':'.$index;

            if ($this->rateLimiter->tooManyAttempts($key, $limit->maxAttempts)) {
                throw TooManyRequests::create();
            }

            $this->rateLimiter->hit($key, $limit->decaySeconds);
        }
    }

    /**
     * @return list<Limit>
     */
    private function limitsFor(Request $request): array
    {
        if ('' === $this->limiterName) {
            return [Limit::perMinute(self::DEFAULT_PER_MINUTE)];
        }

        $limiter = $this->rateLimiter->limiter($this->limiterName);

        if (!\is_callable($limiter)) {
            throw InvalidConfiguration::because(\sprintf('There is no rate limiter named "%s": define it with RateLimiter::for().', $this->limiterName));
        }

        $limits = $limiter($request);

        return array_values(array_filter(\is_array($limits) ? $limits : [$limits], static fn (mixed $limit): bool => $limit instanceof Limit));
    }
}
