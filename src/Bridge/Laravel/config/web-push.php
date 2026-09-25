<?php

declare(strict_types=1);

/*
 * Web push notifications (romainmillan/web-push-notification).
 *
 * Every value is validated when the application boots: a malformed key or an unsafe
 * combination (anonymous subscriptions without a rate limiter...) fails loudly at
 * deployment rather than on the first push in production.
 */
return [
    'vapid' => [
        // php artisan web-push:vapid — never commit these values.
        'public_key' => env('VAPID_PUBLIC_KEY', ''),
        'private_key' => env('VAPID_PRIVATE_KEY', ''),
        // mailto: or https:. Defaults to APP_URL when it is https.
        'subject' => env('VAPID_SUBJECT', ''),
    ],

    'subscriber' => [
        // null: the authenticated user implements Auth\WebPushSubscriber, or its
        // getAuthIdentifier() is prefixed by its morph alias (or model class):
        // "user:42" / "App\Models\User:42". Otherwise the class name of an
        // Auth\SubscriberIdResolver, or a static callable [Class::class, 'method'].
        'resolver' => null,
        // null: the default guard.
        'guard' => null,
    ],

    // "eloquent", or the class name of your own adapter implementing
    // SubscriptionRepository, PurgeableSubscriptions and SubscriptionReadModel.
    'storage' => 'eloquent',

    // null: the default database connection.
    'connection' => env('WEB_PUSH_DB_CONNECTION'),

    'encryption' => [
        // "keyId:base64 of 32 bytes" — encrypts endpoints and keys at rest. Dedicated:
        // never APP_KEY. Generate with: php -r 'echo "k1:".base64_encode(random_bytes(32));'
        'current' => env('WEB_PUSH_ENCRYPTION_KEY'),
        // Former keys, still accepted for reading during a rotation.
        'previous' => [],
    ],

    'max_subscriptions_per_subscriber' => 16,

    // What happens in storage to an expired or evicted subscription: delete | deactivate.
    'retirement' => 'deactivate',

    'anonymous' => [
        'enabled' => false,
        // Name of a RateLimiter::for() limiter — required when enabled. The package
        // keys it by client network (IPv4 address, IPv6 /64) itself.
        'rate_limiter' => null,
        'max_active' => 10000,
        'stale_after' => '90 days',
    ],

    'push_services' => [
        // Self-hosted push services, as lowercase hostnames (no IP, wildcard nor port).
        'extra_hosts' => [],
    ],

    'delivery' => [
        // immediate | queue
        'dispatcher' => 'immediate',
        'queue' => [
            'connection' => null,
            'name' => null,
        ],
        'timeout' => 15,
        'ttl' => 2419200,
        // very-low | low | normal | high
        'urgency' => 'normal',
        // Disable only behind a mandatory egress proxy, which neutralises pinning anyway.
        'dns_pinning' => true,
    ],

    'routes' => [
        'enabled' => true,
        'prefix' => 'web-push',
        // The web group brings the session (current user) and CSRF protection.
        'middleware' => ['web'],
        // Name of a RateLimiter::for() limiter for the unsubscribe route; null: 30
        // requests per minute per client network.
        'unsubscribe_rate_limiter' => null,
    ],

    'service_worker' => [
        // Served statelessly (no session, no cookie).
        'path' => '/web-push-sw.js',
        // The script the page registers (the "serviceWorker" of the meta tag). null:
        // the path above. Point it to your own worker (e.g. "/sw.js") when it loads
        // the package one with importScripts('/web-push-sw.js').
        'register_url' => null,
        // null: the prebuilt script shipped in assets/dist.
        'prebuilt_path' => null,
        'fallback_title' => env('APP_NAME', 'Laravel'),
        'icon' => '',
        'badge' => '',
        'click_prefixes' => ['/'],
        'asset_hosts' => [],
        'state_cache' => 'web-push-state',
    ],

    'purge' => [
        'retired_after' => '30 days',
    ],

    'lock' => [
        // Cache store serializing concurrent registrations. null: the default store.
        // A store that is not shared between servers ("array", "file" on several
        // hosts) makes the subscription quota soft — see docs/consistency.md.
        'store' => null,
    ],
];
