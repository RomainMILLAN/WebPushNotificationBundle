# Laravel

The Laravel bridge is `RomainMillan\WebPushNotification\Bridge\Laravel\WebPushNotificationServiceProvider`,
auto-discovered (`extra.laravel.providers`). It supports Laravel 11 and 12.

- [Installation](#installation)
- [Boot-time validation](#boot-time-validation)
- [Configuration reference](#configuration-reference)
- [Subscriber identity](#subscriber-identity)
- [Storage and migration](#storage-and-migration)
- [Lock](#lock)
- [Sending notifications](#sending-notifications)
- [Notifications channel `web-push`](#notifications-channel-web-push)
- [Queued delivery](#queued-delivery)
- [Signed action URLs](#signed-action-urls)
- [Extension points: outcome listeners and events](#extension-points-outcome-listeners-and-events)
- [HTTP routes and middleware](#http-routes-and-middleware)
- [Blade: `@webPushMeta`](#blade-webpushmeta)
- [Artisan commands](#artisan-commands)
- [Devices, revocation, account deletion](#devices-revocation-account-deletion)
- [Overriding services](#overriding-services)

## Installation

```bash
composer require romainmillan/web-push-notification
php artisan web-push:vapid
```

`.env` (never commit the private key):

```dotenv
VAPID_PUBLIC_KEY=BK...
VAPID_PRIVATE_KEY=...
VAPID_SUBJECT=mailto:ops@example.com
# optional
WEB_PUSH_ENCRYPTION_KEY=
WEB_PUSH_DB_CONNECTION=
```

Publish and run the migration, optionally publish the configuration:

```bash
php artisan vendor:publish --tag=web-push-migrations
php artisan migrate
php artisan vendor:publish --tag=web-push-config   # config/web-push.php
```

Available publish tags: `web-push-config`, `web-push-migrations`, `web-push-views`.

Then render [`@webPushMeta`](#blade-webpushmeta) in your layout and start the [frontend](frontend.md).

## Boot-time validation

The whole `web-push` configuration is validated when the application boots (HTTP, queue
workers and console alike) and a bad value throws `Configuration\InvalidConfiguration`: the
deployment fails, not the first push in production. Checked at boot:

- VAPID keys and the effective subject: `VAPID_SUBJECT` when set (then `APP_URL` is not read at
  all), otherwise `APP_URL`, which must then be https;
- extra push hosts, encryption keys, quota, retirement policy, purge durations;
- `anonymous.rate_limiter` when `anonymous.enabled` (fail-closed);
- urgency, TTL, timeout (1–120 s), dispatcher, storage, route and service worker settings;
- `APP_KEY` (the client state marker is keyed by a purpose sub-key of it);
- the subscriber resolver (class exists and implements the interface).

**Exception**: in the console, while no VAPID key is set at all, validation is skipped so that
`composer require` (which runs `package:discover`) and `php artisan web-push:vapid` work before
`.env` is filled. Resolving any web push service still fails loudly in that state.

### The application `Origin`

`Domain\Message\Origin` comes from **`APP_URL`**: `https://host[:port]` without path, query or
credentials; plain `http` is only accepted for `localhost` and `127.0.0.1`. It is resolved
**lazily**, the first time something needs it (signed action URLs, `ActionUrl::fromString()`,
the VAPID subject fallback), never at boot.

An application on plain http with a custom domain (`APP_URL=http://myapp.test`) and a
`VAPID_SUBJECT` therefore boots, serves the worker and answers on the subscription routes; only
resolving `Origin` fails, with a clear `InvalidConfiguration` ("Cannot resolve the web push
origin from APP_URL ..."). Push requires a secure context anyway: use `https`,
`http://localhost` or `http://127.0.0.1` to actually receive notifications.

## Configuration reference

`config/web-push.php`, with its defaults:

| Key | Default | Meaning |
|---|---|---|
| `vapid.public_key` | `env('VAPID_PUBLIC_KEY', '')` | Base64url uncompressed P-256 point (65 bytes) |
| `vapid.private_key` | `env('VAPID_PRIVATE_KEY', '')` | Base64url of 32 bytes; a secret |
| `vapid.subject` | `env('VAPID_SUBJECT', '')` | `mailto:` or `https:`; empty: `APP_URL` if it is https |
| `subscriber.resolver` | `null` | `null` (default resolver), the class name of a `SubscriberIdResolver`, or a static callable `[Class::class, 'method']` (no closure: it breaks `config:cache`) |
| `subscriber.guard` | `null` | Auth guard; `null`: the default guard |
| `storage` | `'eloquent'` | `eloquent`, or the class name of your adapter implementing `SubscriptionRepository`, `PurgeableSubscriptions` and `SubscriptionReadModel` |
| `connection` | `env('WEB_PUSH_DB_CONNECTION')` | Database connection; `null`: the default one |
| `encryption.current` | `env('WEB_PUSH_ENCRYPTION_KEY')` | `keyId:base64 of 32 bytes`, enables encryption at rest; never `APP_KEY` |
| `encryption.previous` | `[]` | Former keys accepted for reading during a rotation (requires `current`) |
| `max_subscriptions_per_subscriber` | `16` | Active devices per subscriber; the least recently registered is evicted beyond |
| `retirement` | `'deactivate'` | `delete` or `deactivate` expired/evicted subscriptions |
| `anonymous.enabled` | `false` | Accept subscriptions without an authenticated user |
| `anonymous.rate_limiter` | `null` | Name of a `RateLimiter::for()` limiter; **required** when enabled |
| `anonymous.max_active` | `10000` | Global cap of active anonymous subscriptions (soft) |
| `anonymous.stale_after` | `'90 days'` | Anonymous subscriptions not re-registered for this long are purged |
| `push_services.extra_hosts` | `[]` | Self-hosted push services (lowercase hostnames, no IP, wildcard or port) |
| `delivery.dispatcher` | `'immediate'` | `immediate` or `queue` |
| `delivery.queue.connection` | `null` | Queue connection of `SendWebPushJob`; `null`: default |
| `delivery.queue.name` | `null` | Queue name; `null`: the connection's default |
| `delivery.timeout` | `15` | HTTP timeout per push, 1–120 s |
| `delivery.ttl` | `2419200` | Default TTL (seconds, max 28 days) used by the channel and `web-push:test` |
| `delivery.urgency` | `'normal'` | Default urgency: `very-low`, `low`, `normal`, `high` |
| `delivery.dns_pinning` | `true` | Resolve, check and pin push service IPs; disable only behind a mandatory egress proxy |
| `routes.enabled` | `true` | Load the package routes |
| `routes.prefix` | `'web-push'` | Prefix of the subscribe/unsubscribe routes |
| `routes.middleware` | `['web']` | Middleware of those routes (session + CSRF) |
| `routes.unsubscribe_rate_limiter` | `null` | `RateLimiter::for()` name for the unsubscribe route; `null`: 30 requests per minute per client network |
| `service_worker.path` | `'/web-push-sw.js'` | Path of the stateless service worker route |
| `service_worker.register_url` | `null` | The script the page registers (the `serviceWorker` field of the meta tag); `null`: `service_worker.path`. Set it to your own worker (e.g. `'/sw.js'`) when it loads the package one with `importScripts('/web-push-sw.js')`. Must be a same-origin absolute path |
| `service_worker.prebuilt_path` | `null` | File served by that route; `null`: the package `assets/dist/web-push-sw.js` |
| `service_worker.fallback_title` | `env('APP_NAME', 'Laravel')` | Title of a push without title or not in contract v1 |
| `service_worker.icon` | `''` | Default icon (in-origin path or https URL) |
| `service_worker.badge` | `''` | Default monochrome badge |
| `service_worker.click_prefixes` | `['/']` | Path prefixes a click may navigate to (`/` always allowed); narrow it, e.g. `['/app/']` |
| `service_worker.asset_hosts` | `[]` | Extra https hosts for icons and badges |
| `service_worker.state_cache` | `'web-push-state'` | Cache Storage name of the worker state |
| `purge.retired_after` | `'30 days'` | Retired subscriptions older than this are purged |
| `lock.store` | `null` | Cache store used for locks; `null`: the default store. Must support locks |

## Subscriber identity

The subscriber id (e.g. `user:42`, 1–191 printable ASCII characters) **must be stable and
never reused**, never an e-mail address. Resolution, in order:

1. the authenticatable implements `Bridge\Laravel\Auth\WebPushSubscriber`;
2. otherwise `getAuthIdentifier()` prefixed by the morph alias of the model
   (`Relation::getMorphAlias()`), or by its class name: `user:42` with a morph map,
   `App\Models\User:42` without.

```php
use RomainMillan\WebPushNotification\Bridge\Laravel\Auth\WebPushSubscriber;

final class User extends Authenticatable implements WebPushSubscriber
{
    public function getWebPushSubscriberId(): string
    {
        return 'user:'.$this->getKey();
    }
}
```

Implementing the interface is recommended: renaming the model class would otherwise change the
ids of every device. A custom resolver:

```php
use Illuminate\Contracts\Auth\Authenticatable;
use RomainMillan\WebPushNotification\Bridge\Laravel\Auth\SubscriberIdResolver;
use RomainMillan\WebPushNotification\Domain\Subscription\SubscriberId;

final class AppSubscriberIdResolver implements SubscriberIdResolver
{
    public function resolveSubscriberId(Authenticatable $user): SubscriberId
    {
        return SubscriberId::fromString('member:'.$user->getAuthIdentifier());
    }
}
```

```php
// config/web-push.php
'subscriber' => ['resolver' => App\WebPush\AppSubscriberIdResolver::class, 'guard' => null],
```

A resolver failure throws `UnresolvableSubscriber`: a misconfiguration, surfaced as a server
error, never a neutral answer.

## Storage and migration

The migration (`2026_01_01_000000_create_web_push_subscriptions_table.php`) creates the same
`web_push_subscription` table as the Symfony bridge (see
[symfony.md](symfony.md#storage) for the column meanings): `char(32)` primary key,
`endpoint_hash` `char(64)` unique, indexes `(subscriber_id, retired_at)`,
`(owner_type, retired_at)` and `last_registered_at`.

`EloquentSubscriptionRepository` goes through the core `SubscriptionRowMapper` (encryption at
rest included). Its `SubscriptionRecord` model has a strict `$fillable`, hides `endpoint`,
`p256dh` and `auth` from `toArray()`/`toJson()`, and redacts itself in `__debugInfo()`.

Custom storage: set `storage` to your class name (resolved from the container), or bind the
ports yourself (see [Overriding services](#overriding-services)).

## Lock

`CacheLockSubscriptionLock` uses `Cache::lock()` on `lock.store` (TTL 30 s, waits up to 5 s,
then answers 503). The store must implement `LockProvider` (redis, database, memcached,
dynamodb, file, array), otherwise boot fails. A store not shared between servers (`array`,
`file` on several hosts) makes the quota **soft**: see [consistency.md](consistency.md).

## Sending notifications

Resolve `PushDispatcher` (fire and forget, sync or queued per `delivery.dispatcher`) or
`WebPushSender` (synchronous, returns a `DeliveryReport`):

```php
use RomainMillan\WebPushNotification\Application\Port\PushDispatcher;
use RomainMillan\WebPushNotification\Domain\Delivery\SubscriberAudience;
use RomainMillan\WebPushNotification\Domain\Message\ClickPath;
use RomainMillan\WebPushNotification\Domain\Message\DeliveryOptions;
use RomainMillan\WebPushNotification\Domain\Message\WebPushMessage;

app(PushDispatcher::class)->dispatch(
    SubscriberAudience::fromSubscriberId('user:42'),
    WebPushMessage::createWithTitle('Deployment finished', 'api v2.3.1 is live')
        ->withClickPath(ClickPath::fromString('/deployments/231')),
    app(DeliveryOptions::class), // the configured default TTL and urgency
);
```

The container binds `DeliveryOptions` (from `delivery.ttl` / `delivery.urgency`), `Origin`
(from `APP_URL`, resolved on first use) and `ActionUrlSigner` as singletons. Audiences, outcomes and message options are described in
[symfony.md](symfony.md#sending-notifications) and [payload-contract.md](payload-contract.md): they
are the same core classes.

## Notifications channel `web-push`

```php
use Illuminate\Notifications\Notification;
use RomainMillan\WebPushNotification\Bridge\Laravel\Notifications\WebPushChannel;
use RomainMillan\WebPushNotification\Bridge\Laravel\Notifications\WebPushNotification;
use RomainMillan\WebPushNotification\Bridge\Laravel\Notifications\WebPushNotificationOptions;
use RomainMillan\WebPushNotification\Domain\Message\ClickPath;
use RomainMillan\WebPushNotification\Domain\Message\DeliveryOptions;
use RomainMillan\WebPushNotification\Domain\Message\Tag;
use RomainMillan\WebPushNotification\Domain\Message\Urgency;
use RomainMillan\WebPushNotification\Domain\Message\WebPushMessage;

final class AlertRaised extends Notification implements WebPushNotification, WebPushNotificationOptions
{
    public function __construct(private readonly int $alertId)
    {
    }

    public function via(object $notifiable): array
    {
        return [WebPushChannel::class]; // or 'web-push'
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        return WebPushMessage::createWithTitle('Disk almost full', 'db-1: 92 % used')
            ->withClickPath(ClickPath::fromString('/alerts/'.$this->alertId))
            ->withTag(Tag::fromString('alert-'.$this->alertId))
            ->insistent();
    }

    // Optional: overrides delivery.ttl / delivery.urgency for this notification.
    public function toWebPushOptions(object $notifiable): DeliveryOptions
    {
        return DeliveryOptions::createDefault()->withTtl(600)->withUrgency(Urgency::High);
    }
}
```

The notifiable's subscriber id is taken, in order, from `WebPushSubscriber`, then
`routeNotificationForWebPush()` / `Notification::route('web-push', 'user:42')`, then the same
resolver as the subscribe route for authenticatable models: the id a device was registered
under is the id it is notified under. A notifiable without any id is skipped (Laravel
convention). A notification that does not implement `WebPushNotification` throws.

```php
Notification::route('web-push', 'user:42')->notify(new AlertRaised(7));
```

## Queued delivery

```php
// config/web-push.php
'delivery' => ['dispatcher' => 'queue', 'queue' => ['connection' => 'redis', 'name' => 'web-push'], /* ... */],
```

- `QueuePushDispatcher` dispatches **one `SendWebPushJob` per subscription**, `afterCommit()`:
  a notification sent inside a rolled back transaction is never queued.
- The job holds scalars only (subscription id, expected subscriber id, v1 JSON payload,
  TTL/urgency/topic): no model, no endpoint, so `failed_jobs` holds no capability URL.
  **The notification text sits in the queue backend.**
- The job goes through the same `DeliverPayload` as synchronous sends: a subscription retired,
  deleted or handed to another owner since the dispatch is dropped without retry.
- `$tries = 3`. Only a `Transient` outcome releases the job again, after the push service's
  `Retry-After` or 60 s.
- The Notifications channel follows `delivery.dispatcher`: your notification does not need
  `ShouldQueue` for the push itself to be queued.

## Signed action URLs

A `PostAction` makes the service worker POST to your route from the lock screen with the user's
cookies and no CSRF token: the URL must carry its own short-lived authorization (see
[security.md](security.md#actionurl-must-carry-its-own-authorization)). Resolve the core port `Application\Port\ActionUrlSigner`,
bound to `Bridge\Laravel\Signing\LaravelActionUrlSigner`: it builds an absolute
`URL::temporarySignedRoute()` and returns an `ActionUrl` checked against the application
`Origin`.

```php
use RomainMillan\WebPushNotification\Application\Port\ActionUrlSigner;
use RomainMillan\WebPushNotification\Domain\Message\Action\ActionLabel;
use RomainMillan\WebPushNotification\Domain\Message\Action\PostAction;

$message = $message->withAction(new PostAction(
    ActionLabel::fromActionAndTitle('ack', 'Acknowledge'),
    app(ActionUrlSigner::class)->sign('alerts.ack', ['alert' => $alert->id], new \DateInterval('PT1H')),
));
```

The receiving route verifies signature and expiry with the `signed` middleware; exclude it from
CSRF verification, since the signature is its authorization:

```php
// routes/web.php
Route::post('/alerts/{alert}/ack', AcknowledgeAlert::class)
    ->name('alerts.ack')
    ->middleware(['signed', 'throttle:30,1']);

// bootstrap/app.php: the worker sends no CSRF token
->withMiddleware(function (Middleware $middleware): void {
    $middleware->validateCsrfTokens(except: ['alerts/*/ack']);
})
```

The `signed` middleware checks the URL of the incoming request: its scheme and host must match the
`APP_URL` origin the URL was signed with. Behind a reverse proxy or a TLS terminator, configure
`TrustProxies` so that Laravel sees the public `https://` host, otherwise every signed URL is
refused. A negative validity is refused.

## Extension points: outcome listeners and events

`DeliveryOutcomeListener` implementations are collected by tag. Tag them in the `register()`
method of one of your providers:

```php
use RomainMillan\WebPushNotification\Application\Port\DeliveryOutcomeListener;

public function register(): void
{
    $this->app->tag([App\WebPush\PushMetrics::class], DeliveryOutcomeListener::class);
}
```

They run after the mandatory `RetireOnExpiry` step; a failure is logged and never breaks the
delivery. Domain events (`SubscriptionRegistered`, `SubscriptionRenewed`,
`SubscriptionReassigned`, `SubscriptionReactivated`, `SubscriptionExpired`,
`SubscriptionEvicted`, `SubscriptionUnsubscribed`, `SubscriptionsPurged`) are dispatched on
Laravel's event dispatcher after the real commit (`DB::afterCommit()` semantics):

```php
use Illuminate\Support\Facades\Event;
use RomainMillan\WebPushNotification\Domain\Subscription\Event\SubscriptionReassigned;

Event::listen(function (SubscriptionReassigned $event): void {
    Log::notice('A browser changed hands.', ['subscription' => $event->subscriptionId->toString()]);
});
```

## HTTP routes and middleware

| Name | Method + path | Middleware |
|---|---|---|
| `web-push.subscribe` | `POST /{prefix}/subscriptions` | `routes.middleware` (`web`) |
| `web-push.unsubscribe` | `POST /{prefix}/subscriptions/unsubscribe` | `routes.middleware` (`web`) |
| `web-push.service-worker` | `GET {service_worker.path}` | **none**: no session, no cookie |

Subscribe, in order: CSRF (the `web` group's `VerifyCsrfToken`, header `X-CSRF-TOKEN`) →
anonymous gate (403 before the body is read) → rate limit for anonymous owners (keyed by the
package per IPv4 address or IPv6 /64, whatever `->by()` your limiter says) → media type (415) →
body read up to 4 096 bytes (413) → JSON depth 4 (400) → shape (400) → registration, answered
204 even when the matrix refuses. Unsubscribe is not behind the anonymous gate (proof of
possession), is always rate limited (30/min by default), and always answers 204.

> **Caveat: the 4 KiB bound comes after Laravel's CSRF middleware.** `VerifyCsrfToken` reads
> `$request->input('_token')`, which parses the whole JSON body *before* the controller applies
> its bounded read. The effective size limit of these routes is therefore PHP's
> `post_max_size` and your web server's (`client_max_body_size`, `LimitRequestBody`): keep them
> reasonable.

`MarkWebPushResponsePrivate` is appended to the `web` middleware group by the provider (through
the HTTP kernel). When a view rendered `@webPushMeta`, the response is made `private` and its
`s-maxage` directive removed, whatever cache headers the application set.

Status codes are the same as on Symfony (204, 400, 403, 413, 415, 429, 503): see
[symfony.md](symfony.md#http-routes).

## Blade: `@webPushMeta`

```blade
<head>
    @webPushMeta
    {{-- or --}}
    <x-web-push::meta />
</head>
```

Renders `<meta name="web-push-config" content="{json}">`: VAPID public key, service worker URL to
register (`service_worker.register_url`, or `service_worker.path`), subscribe/unsubscribe URLs, click prefixes, state cache, CSRF header (`X-CSRF-TOKEN`) and the
session token, and the client state marker of the current user (none for guests). Rendering it
decides nothing: the subscribe route applies the anonymous gate.

## Artisan commands

| Command | Effect |
|---|---|
| `web-push:vapid` | Prints a new key pair; writes nothing |
| `web-push:test {subscriber}` | Sends a test notification synchronously (configured TTL/urgency) to every device of the subscriber, prints counts per status; succeeds only if at least one device received it |
| `web-push:purge` | Deletes retired subscriptions older than `purge.retired_after` and stale anonymous ones; one `SubscriptionsPurged` event |

Schedule the purge daily:

```php
// routes/console.php
use Illuminate\Support\Facades\Schedule;

Schedule::command('web-push:purge')->daily();
```

## Devices, revocation, account deletion

```php
use RomainMillan\WebPushNotification\Application\ReadModel\ListSubscriptions;
use RomainMillan\WebPushNotification\Application\RemoveAllSubscriptions;
use RomainMillan\WebPushNotification\Application\RevokeSubscription;
use RomainMillan\WebPushNotification\Domain\Exception\InvalidValue;
use RomainMillan\WebPushNotification\Domain\Subscription\IdentifiedOwner;
use RomainMillan\WebPushNotification\Domain\Subscription\SubscriberId;
use RomainMillan\WebPushNotification\Domain\Subscription\SubscriptionId;

final class DeviceController
{
    public function index(Request $request, ListSubscriptions $listSubscriptions)
    {
        return view('account.devices', [
            'devices' => $listSubscriptions->listFor(SubscriberId::fromString($request->user()->getWebPushSubscriberId())),
        ]);
    }

    public function destroy(Request $request, string $id, RevokeSubscription $revokeSubscription)
    {
        try {
            $revokeSubscription->revoke(IdentifiedOwner::fromSubscriberId($request->user()->getWebPushSubscriberId()), SubscriptionId::fromString($id));
        } catch (InvalidValue) {
            // malformed id: same as unknown
        }

        return back();
    }
}

// Account deletion
app(RemoveAllSubscriptions::class)->removeAllOf(SubscriberId::fromString($user->getWebPushSubscriberId()));
```

## Overriding services

Every core service is a lazy singleton. Bind a port in your own provider (registered after the
package's) to replace it, e.g. `SubscriptionRepository`, `TransactionBoundary`,
`SubscriptionLock`, `PushTransport`, `CurrentOwner` or `ClientStateMarkerFactory`. When
replacing `SubscriptionRepository`, the object must also implement `PurgeableSubscriptions` and
`SubscriptionReadModel`.
