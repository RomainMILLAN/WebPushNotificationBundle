<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Bridge\Laravel\Configuration;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Arr;
use RomainMillan\WebPushNotification\Application\Port\SubscriptionCipher;
use RomainMillan\WebPushNotification\Application\PurgeWindows;
use RomainMillan\WebPushNotification\Application\RetirementPolicy;
use RomainMillan\WebPushNotification\Application\ServiceWorker\ServiceWorkerConfig;
use RomainMillan\WebPushNotification\Application\ServiceWorker\ServiceWorkerScript;
use RomainMillan\WebPushNotification\Application\SubscriptionQuota;
use RomainMillan\WebPushNotification\Domain\Exception\WebPushNotificationException;
use RomainMillan\WebPushNotification\Domain\Message\DeliveryOptions;
use RomainMillan\WebPushNotification\Domain\Message\Origin;
use RomainMillan\WebPushNotification\Domain\Message\Urgency;
use RomainMillan\WebPushNotification\Domain\Subscription\AllowedPushServices;
use RomainMillan\WebPushNotification\Infrastructure\Crypto\AeadSubscriptionCipher;
use RomainMillan\WebPushNotification\Infrastructure\Crypto\EncryptionKey;
use RomainMillan\WebPushNotification\Infrastructure\Crypto\NullSubscriptionCipher;
use RomainMillan\WebPushNotification\Infrastructure\Vapid\VapidCredentials;

use function Symfony\Component\String\u;

/**
 * The "web-push" configuration array, read with types and turned into validated core
 * objects. Every accessor throws InvalidConfiguration: validate() calls them all at
 * boot (origin() aside), so a bad value fails the deployment instead of a push in
 * production.
 */
final readonly class WebPushConfiguration
{
    /**
     * @param array<mixed> $config the "web-push" array
     * @param array<mixed> $app    the "app" array (url, key)
     */
    public function __construct(
        private array $config,
        private array $app,
    ) {
    }

    public static function fromRepository(Repository $repository): self
    {
        $config = $repository->get('web-push', []);
        $app = $repository->get('app', []);

        return new self(\is_array($config) ? $config : [], \is_array($app) ? $app : []);
    }

    /** Fails loudly on the first invalid value. */
    public function validate(): void
    {
        // Not origin(): APP_URL is only read when something needs it (see origin()).
        $this->vapidCredentials();
        $this->allowedPushServices();
        $this->subscriptionCipher();
        $this->subscriptionQuota();
        $this->retirementPolicy();
        $this->purgeWindows();
        $this->anonymousRateLimiter();
        $this->unsubscribeRateLimiter();
        $this->deliveryOptions();
        $this->deliveryTimeout();
        $this->dnsPinning();
        $this->usesQueue();
        $this->queueConnection();
        $this->queueName();
        $this->storage();
        $this->databaseConnection();
        $this->lockStore();
        $this->routesEnabled();
        $this->routeMiddleware();
        $this->serviceWorkerConfig();
        $this->serviceWorkerScript();
        $this->serviceWorkerRegisterUrl();
        $this->clientSettings();
        $this->clientStateSecret();
        $this->subscriberResolver();
        $this->subscriberGuard();
    }

    /**
     * No key at all means "not installed yet" (composer require runs package:discover
     * before anyone could fill .env) — not the same as a malformed key.
     */
    public function isVapidConfigured(): bool
    {
        return '' !== $this->string('vapid.public_key') || '' !== $this->string('vapid.private_key');
    }

    public function vapidCredentials(): VapidCredentials
    {
        $subject = $this->string('vapid.subject');

        // APP_URL is read only when no subject is set.
        if ('' === $subject) {
            $subject = u($this->appString('url'))->startsWith('https://')
                ? $this->origin()->toString()
                : throw InvalidConfiguration::because('Cannot derive a VAPID subject from a non-https APP_URL: set web-push.vapid.subject (VAPID_SUBJECT) to a mailto: or https: URL.');
        }

        return $this->guard(fn (): VapidCredentials => VapidCredentials::fromKeys($this->string('vapid.public_key'), $this->string('vapid.private_key'), $subject));
    }

    /**
     * Resolved on first use (signed action URLs, VAPID subject fallback), never at
     * boot: an application on http://myapp.test with a VAPID_SUBJECT still boots.
     * Push requires a secure context, hence the refusal of http outside localhost.
     */
    public function origin(): Origin
    {
        $appUrl = u($this->appString('url'))->trimEnd('/')->toString();

        try {
            return Origin::fromString($appUrl);
        } catch (WebPushNotificationException $invalid) {
            throw InvalidConfiguration::because(\sprintf('Cannot resolve the web push origin from APP_URL "%s": an https://host[:port] URL is expected (http only for localhost), as push requires a secure context.', $appUrl), $invalid);
        }
    }

    public function allowedPushServices(): AllowedPushServices
    {
        return $this->guard(fn (): AllowedPushServices => AllowedPushServices::createWithKnownServices($this->stringList('push_services.extra_hosts')));
    }

    public function subscriptionCipher(): SubscriptionCipher
    {
        $current = $this->string('encryption.current');
        $previous = $this->stringList('encryption.previous');

        if ('' === $current) {
            return [] === $previous ? new NullSubscriptionCipher() : throw InvalidConfiguration::because('Cannot keep previous encryption keys without a current one (web-push.encryption.current).');
        }

        return $this->guard(static fn (): AeadSubscriptionCipher => new AeadSubscriptionCipher(
            EncryptionKey::fromConfiguration($current),
            array_map(EncryptionKey::fromConfiguration(...), $previous),
        ));
    }

    public function subscriptionQuota(): SubscriptionQuota
    {
        return $this->guard(fn (): SubscriptionQuota => SubscriptionQuota::fromLimits($this->int('max_subscriptions_per_subscriber', 16), $this->int('anonymous.max_active', 10000)));
    }

    public function retirementPolicy(): RetirementPolicy
    {
        return RetirementPolicy::tryFrom($this->string('retirement', 'deactivate'))
            ?? throw InvalidConfiguration::because('Cannot accept a web-push.retirement other than "delete" or "deactivate".');
    }

    public function purgeWindows(): PurgeWindows
    {
        return $this->guard(fn (): PurgeWindows => PurgeWindows::fromDurations($this->string('purge.retired_after', '30 days'), $this->string('anonymous.stale_after', '90 days')));
    }

    public function anonymousAllowed(): bool
    {
        return $this->bool('anonymous.enabled', false);
    }

    /** Fail-closed: anonymous subscriptions are never accepted without a rate limiter. */
    public function anonymousRateLimiter(): string
    {
        $limiter = $this->string('anonymous.rate_limiter');

        if ($this->anonymousAllowed() && '' === $limiter) {
            throw InvalidConfiguration::because('Cannot enable anonymous web push subscriptions without web-push.anonymous.rate_limiter (the name of a RateLimiter::for() limiter).');
        }

        return $limiter;
    }

    /** '' means the package default (30 per minute per client network). */
    public function unsubscribeRateLimiter(): string
    {
        return $this->string('routes.unsubscribe_rate_limiter');
    }

    public function deliveryOptions(): DeliveryOptions
    {
        $urgency = Urgency::tryFrom($this->string('delivery.urgency', 'normal'))
            ?? throw InvalidConfiguration::because('Cannot accept a web-push.delivery.urgency other than very-low, low, normal or high.');

        return $this->guard(fn (): DeliveryOptions => DeliveryOptions::createDefault()->withTtl($this->int('delivery.ttl', DeliveryOptions::MAX_TTL))->withUrgency($urgency));
    }

    public function deliveryTimeout(): int
    {
        $timeout = $this->int('delivery.timeout', 15);

        return $timeout >= 1 && $timeout <= 120 ? $timeout : throw InvalidConfiguration::because('Cannot accept a web-push.delivery.timeout outside 1..120 seconds.');
    }

    public function dnsPinning(): bool
    {
        return $this->bool('delivery.dns_pinning', true);
    }

    public function usesQueue(): bool
    {
        return match ($this->string('delivery.dispatcher', 'immediate')) {
            'immediate' => false,
            'queue' => true,
            default => throw InvalidConfiguration::because('Cannot accept a web-push.delivery.dispatcher other than "immediate" or "queue".'),
        };
    }

    public function queueConnection(): string
    {
        return $this->string('delivery.queue.connection');
    }

    public function queueName(): string
    {
        return $this->string('delivery.queue.name');
    }

    /** "eloquent", or the class name of a custom adapter. */
    public function storage(): string
    {
        $storage = $this->string('storage', 'eloquent');

        return 'eloquent' === $storage || class_exists($storage) ? $storage : throw InvalidConfiguration::because('Cannot accept a web-push.storage other than "eloquent" or an existing class name.');
    }

    /** '' means the default connection. */
    public function databaseConnection(): string
    {
        return $this->string('connection');
    }

    /** '' means the default cache store. */
    public function lockStore(): string
    {
        return $this->string('lock.store');
    }

    public function routesEnabled(): bool
    {
        return $this->bool('routes.enabled', true);
    }

    public function routePrefix(): string
    {
        return u($this->string('routes.prefix', 'web-push'))->trim('/')->toString();
    }

    /**
     * @return list<string>
     */
    public function routeMiddleware(): array
    {
        return $this->stringList('routes.middleware', ['web']);
    }

    public function serviceWorkerPath(): string
    {
        $path = $this->string('service_worker.path', '/web-push-sw.js');

        return u($path)->startsWith('/') && !u($path)->startsWith('//') && !u($path)->containsAny(['?', '#', '\\', ' '])
            ? $path
            : throw InvalidConfiguration::because('Cannot accept a web-push.service_worker.path that is not an absolute path such as "/web-push-sw.js".');
    }

    /**
     * The script the page registers: the package route by default, or the
     * application's own worker that importScripts() the package one.
     */
    public function serviceWorkerRegisterUrl(): string
    {
        $registerUrl = $this->string('service_worker.register_url');

        if ('' === $registerUrl) {
            return $this->serviceWorkerPath();
        }

        return u($registerUrl)->startsWith('/') && !u($registerUrl)->startsWith('//') && !u($registerUrl)->containsAny(['#', '\\', ' '])
            ? $registerUrl
            : throw InvalidConfiguration::because('Cannot accept a web-push.service_worker.register_url that is not a same-origin absolute path such as "/sw.js".');
    }

    public function serviceWorkerConfig(): ServiceWorkerConfig
    {
        return $this->guard(fn (): ServiceWorkerConfig => ServiceWorkerConfig::fromSettings(
            $this->string('service_worker.fallback_title', 'Laravel'),
            $this->string('service_worker.icon'),
            $this->string('service_worker.badge'),
            $this->stringList('service_worker.click_prefixes', ['/']),
            $this->stringList('service_worker.asset_hosts'),
            $this->string('service_worker.state_cache', 'web-push-state'),
        ));
    }

    /** The prebuilt script may be built later: its existence is checked when served. */
    public function serviceWorkerScript(): ServiceWorkerScript
    {
        $path = $this->string('service_worker.prebuilt_path');

        return '' === $path ? ServiceWorkerScript::createFromPackageDist() : new ServiceWorkerScript($path);
    }

    /**
     * @return array{publicKey: string, serviceWorker: string, subscribe: string, unsubscribe: string, clickPrefixes: list<string>, stateCache: string}
     */
    public function clientSettings(): array
    {
        $base = '/'.$this->routePrefix().'/subscriptions';

        return [
            'publicKey' => $this->vapidCredentials()->publicKey(),
            'serviceWorker' => $this->serviceWorkerRegisterUrl(),
            'subscribe' => $base,
            'unsubscribe' => $base.'/unsubscribe',
            'clickPrefixes' => $this->stringList('service_worker.click_prefixes', ['/']),
            // The page claims navigation intents from the worker's Cache Storage.
            'stateCache' => $this->string('service_worker.state_cache', 'web-push-state'),
        ];
    }

    /** The client state marker HMAC is keyed by a purpose sub-key of APP_KEY. */
    public function clientStateSecret(): string
    {
        $key = $this->appString('key');

        if (u($key)->startsWith('base64:')) {
            $decoded = base64_decode(u($key)->after('base64:')->toString(), true);
            $key = false !== $decoded ? $decoded : '';
        }

        return '' !== $key ? $key : throw InvalidConfiguration::because('Cannot derive the web push client state marker without an application key (APP_KEY).');
    }

    /**
     * @return string|array<mixed>|null null: the default resolver
     */
    public function subscriberResolver(): string|array|null
    {
        $resolver = Arr::get($this->config, 'subscriber.resolver');

        return match (true) {
            null === $resolver, '' === $resolver => null,
            \is_string($resolver), \is_array($resolver) => $resolver,
            default => throw InvalidConfiguration::because('Cannot accept a web-push.subscriber.resolver that is neither a class name nor a static callable (closures break config:cache).'),
        };
    }

    /** '' means the default guard. */
    public function subscriberGuard(): string
    {
        return $this->string('subscriber.guard');
    }

    /**
     * @template T
     *
     * @param callable(): T $build
     *
     * @return T
     */
    private function guard(callable $build): mixed
    {
        try {
            return $build();
        } catch (WebPushNotificationException $invalid) {
            throw $invalid instanceof InvalidConfiguration ? $invalid : InvalidConfiguration::because('Cannot boot web push: '.$invalid->getMessage(), $invalid);
        }
    }

    private function string(string $path, string $default = ''): string
    {
        $value = Arr::get($this->config, $path);

        return match (true) {
            null === $value => $default,
            \is_string($value) => $value,
            default => throw InvalidConfiguration::because(\sprintf('Cannot accept web-push.%s: a string is expected.', $path)),
        };
    }

    private function int(string $path, int $default): int
    {
        $value = Arr::get($this->config, $path);

        return match (true) {
            null === $value => $default,
            \is_int($value) => $value,
            \is_string($value) && 1 === preg_match('/^\d{1,9}$/D', $value) => (int) $value,
            default => throw InvalidConfiguration::because(\sprintf('Cannot accept web-push.%s: an integer is expected.', $path)),
        };
    }

    private function bool(string $path, bool $default): bool
    {
        $value = Arr::get($this->config, $path);

        return match (true) {
            null === $value => $default,
            \is_bool($value) => $value,
            default => throw InvalidConfiguration::because(\sprintf('Cannot accept web-push.%s: a boolean is expected.', $path)),
        };
    }

    /**
     * @param list<string> $default
     *
     * @return list<string>
     */
    private function stringList(string $path, array $default = []): array
    {
        $value = Arr::get($this->config, $path);

        if (null === $value) {
            return $default;
        }

        if (!\is_array($value) || !array_is_list($value) || [] !== array_filter($value, static fn (mixed $item): bool => !\is_string($item))) {
            throw InvalidConfiguration::because(\sprintf('Cannot accept web-push.%s: a list of strings is expected.', $path));
        }

        /** @var list<string> $value */
        return $value;
    }

    private function appString(string $key): string
    {
        $value = $this->app[$key] ?? null;

        return \is_string($value) ? $value : '';
    }
}
