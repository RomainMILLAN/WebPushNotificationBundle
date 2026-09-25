# Symfony

The Symfony bridge is `RomainMillan\WebPushNotification\Bridge\Symfony\WebPushNotificationBundle`
(an `AbstractBundle`, configuration alias `web_push_notification`). It supports Symfony 6.4, 7.x and 8.x.

- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration reference](#configuration-reference)
- [Subscriber identity](#subscriber-identity)
- [Storage](#storage)
- [Lock](#lock)
- [Origin](#origin)
- [Sending notifications](#sending-notifications)
- [Asynchronous delivery with Messenger](#asynchronous-delivery-with-messenger)
- [Notifier channel `web_push`](#notifier-channel-web_push)
- [Signed action URLs](#signed-action-urls)
- [Extension points: outcome listeners and events](#extension-points-outcome-listeners-and-events)
- [HTTP routes](#http-routes)
- [Twig: `web_push_meta()`](#twig-web_push_meta)
- [Console commands](#console-commands)
- [Devices, revocation, account deletion](#devices-revocation-account-deletion)
- [Public services](#public-services)

## Requirements

| Package / setting | Why |
|---|---|
| `symfony/framework-bundle` | Always |
| `symfony/security-bundle` | The current user comes from `security.token_storage` |
| `framework.csrf_protection: true` | The subscribe/unsubscribe routes check a CSRF token (`security.csrf.token_manager`) |
| `doctrine/doctrine-bundle` | Default storage (`storage.type: doctrine`) |
| `symfony/twig-bundle` | Optional: `web_push_meta()` (the extension is removed without Twig) |
| `symfony/lock` + `framework.lock` | Optional but recommended: strict quota (see [Lock](#lock)) |
| `symfony/messenger` | Optional: `delivery.dispatcher: messenger` |
| `symfony/notifier` | Optional: the `web_push` channel (removed without Notifier) |
| `symfony/rate-limiter` | **Required**: the unsubscribe route is always rate limited (the bundle declares its default limiter itself), anonymous subscriptions too. The container refuses to build without it |

PHP extensions: `curl`, `sodium`, `json`, `openssl`, `mbstring`; `gmp` or `bcmath` recommended.

## Installation

```bash
composer require romainmillan/web-push-notification
```

```php
// config/bundles.php
return [
    // ...
    RomainMillan\WebPushNotification\Bridge\Symfony\WebPushNotificationBundle::class => ['all' => true],
];
```

Generate the VAPID key pair (printed, never written to disk):

```bash
php bin/console webpush:vapid:generate
php bin/console secrets:set VAPID_PRIVATE_KEY
php bin/console secrets:set VAPID_PUBLIC_KEY
```

Minimal configuration:

```yaml
# config/packages/web_push_notification.yaml
web_push_notification:
    vapid:
        public_key: '%env(VAPID_PUBLIC_KEY)%'
        private_key: '%env(VAPID_PRIVATE_KEY)%'
        subject: 'mailto:ops@example.com'
```

Routes:

```yaml
# config/routes/web_push_notification.yaml
web_push_notification:
    resource: '@WebPushNotificationBundle/config/routes.php'
```

Keep `/web-push-sw.js` **outside any prefix**: a service worker only controls the pages under its
own path. Then make your user a [`WebPushSubscriber`](#subscriber-identity), create the
[table](#storage), render [`web_push_meta()`](#twig-web_push_meta) and start the
[frontend](frontend.md).

## Configuration reference

Every key, with its default. Static values (hosts, quotas, service worker settings) are
validated when the container is built; secrets (VAPID keys, encryption keys) usually come
from env vars and are validated when their service is first instantiated: a malformed key
fails loudly on the first request, never with a 403 on every push.

```yaml
web_push_notification:
    vapid:                                  # required
        public_key: ~                       # required, base64url uncompressed P-256 point (65 bytes)
        private_key: ~                      # required, base64url of 32 bytes
        subject: ~                          # required, mailto:... or https://...
    origin: null                            # https://host[:port]; default: derived from framework.router.default_uri
    subscriber:
        resolver: null                      # service id implementing SubscriberIdResolver
    storage:
        type: doctrine                      # doctrine | custom
        connection: default                 # DBAL connection name (doctrine)
        repository: null                    # custom: service implementing the 3 storage ports
        transaction_boundary: null          # custom: service implementing TransactionBoundary
    encryption:
        current: null                       # "keyId:base64 of 32 bytes" enables encryption at rest
        previous: []                        # former keys, still accepted for reading
    max_subscriptions_per_subscriber: 16    # min 1
    retirement: deactivate                  # delete | deactivate
    anonymous:
        enabled: false
        rate_limiter: null                  # framework.rate_limiter name; required when enabled
        max_active: 10000                   # min 0
        stale_after: '90 days'
    push_services:
        extra_hosts: []                     # self-hosted push services (lowercase hostnames)
    delivery:
        dispatcher: immediate               # immediate | messenger
        timeout: 15                         # seconds, min 1
        dns_pinning: true
        ttl: 2419200                        # default TTL in seconds (0..2419200)
        urgency: normal                     # very-low | low | normal | high
    lock:
        factory: null                       # LockFactory service id; default lock.factory if available
    routes:
        csrf_token_id: web_push
        csrf_header: X-CSRF-Token
        unsubscribe_rate_limiter: null      # framework.rate_limiter name; default: web_push_unsubscribe (declared by the bundle)
    service_worker:
        fallback_title: Notification
        icon: ''                            # default icon: in-origin path or https URL
        badge: ''                           # default monochrome badge
        click_prefixes: ['/']
        asset_hosts: []
        state_cache: web-push-state
        prebuilt_path: null                 # defaults to the package assets/dist/web-push-sw.js
        register_url: null                  # script the page registers; default: the web_push_service_worker route
    purge:
        retired_after: '30 days'
```

| Key | Default | Meaning |
|---|---|---|
| `vapid.public_key` | required | Application server public key, base64url of the 65-byte uncompressed P-256 point. Rendered in the page. |
| `vapid.private_key` | required | Base64url of 32 bytes. A secret: use `secrets:set` or an env var. |
| `vapid.subject` | required | `mailto:` or `https:` URL, sent to push services as contact. |
| `origin` | `null` | The application [`Origin`](#origin) (`https://host[:port]`, or `http://localhost`). `null`: scheme, host and port of `framework.router.default_uri`. Validated on first use, never at container build. |
| `subscriber.resolver` | `null` | Service id of a `SubscriberIdResolver`. `null`: the user must implement `WebPushSubscriber`. |
| `storage.type` | `doctrine` | `doctrine` (DBAL adapter) or `custom`. |
| `storage.connection` | `default` | DBAL connection used by the adapter and its transactions. |
| `storage.repository` | `null` | `custom` only: service implementing `SubscriptionRepository`, `PurgeableSubscriptions` and `SubscriptionReadModel`. |
| `storage.transaction_boundary` | `null` | `custom` only: service implementing `TransactionBoundary`. |
| `encryption.current` | `null` | `keyId:base64key` (keyId 1–16 of `[a-zA-Z0-9_-]`, key exactly 32 bytes). Enables `AeadSubscriptionCipher`. |
| `encryption.previous` | `[]` | Former keys, same format, accepted for decryption during a rotation. |
| `max_subscriptions_per_subscriber` | `16` | Active devices per identified subscriber; the least recently registered is evicted beyond. |
| `retirement` | `deactivate` | What storage does with an expired or evicted subscription: `delete` the row, or keep it `deactivate`d (listed as expired, purged later). |
| `anonymous.enabled` | `false` | Accept subscriptions without an authenticated user. |
| `anonymous.rate_limiter` | `null` | Name of a `framework.rate_limiter` limiter. The container refuses to build when `enabled` without it. |
| `anonymous.max_active` | `10000` | Global cap of active anonymous subscriptions; beyond, registrations are refused (never evicted). Soft under concurrency. |
| `anonymous.stale_after` | `90 days` | Anonymous subscriptions not re-registered for this long are purged. |
| `push_services.extra_hosts` | `[]` | Extra allowed hosts (self-hosted push service). Lowercase hostnames, no IP, wildcard or port. Still subject to the public-IP check. |
| `delivery.dispatcher` | `immediate` | `PushDispatcher` implementation: send in-process, or one Messenger message per subscription. |
| `delivery.timeout` | `15` | HTTP timeout per push, in seconds (connect timeout: `min(5, timeout)`). |
| `delivery.dns_pinning` | `true` | Resolve, check and pin push service IPs. Disable only behind a mandatory egress proxy (which neutralises pinning anyway). |
| `delivery.ttl` | `2419200` | Default TTL in seconds (max 28 days), used by the Notifier channel and the `DeliveryOptions` service. |
| `delivery.urgency` | `normal` | Default urgency: `very-low`, `low`, `normal` or `high`. |
| `lock.factory` | `null` | `LockFactory` service id. `null`: `lock.factory` when it exists, otherwise no cross-process lock (soft quota). |
| `routes.csrf_token_id` | `web_push` | CSRF token id checked by the subscribe and unsubscribe routes. |
| `routes.csrf_header` | `X-CSRF-Token` | Header carrying the token. |
| `routes.unsubscribe_rate_limiter` | `null` | Name of a `framework.rate_limiter` limiter for the unsubscribe route. `null`: `web_push_unsubscribe`, a sliding window of 30 requests per minute per client network, prepended by the bundle (unless you declare a limiter with that name yourself). |
| `service_worker.fallback_title` | `Notification` | Title shown for a payload without title or not in contract v1 (1–120 characters). |
| `service_worker.icon` | `''` | Default notification icon. |
| `service_worker.badge` | `''` | Default monochrome badge. |
| `service_worker.click_prefixes` | `['/']` | In-origin path prefixes a click may navigate to. `/` itself is always allowed. Narrow it (e.g. `['/app/']`) so a payload cannot point at a GET route such as logout. |
| `service_worker.asset_hosts` | `[]` | Extra hosts (https) icons and badges may be loaded from. |
| `service_worker.state_cache` | `web-push-state` | Cache Storage name of the worker state (1–64 of `[a-z0-9-]`). |
| `service_worker.prebuilt_path` | `null` | File served by `/web-push-sw.js`. Point it to your own built worker to [customise the worker](frontend.md#using-your-own-service-worker). |
| `service_worker.register_url` | `null` | The script the page registers (the `serviceWorker` field of the meta tag). `null`: the `web_push_service_worker` route. Set it to your own worker (e.g. `/sw.js`) when it loads the package one with `importScripts('/web-push-sw.js')`. Must be a same-origin absolute path; the container refuses to build otherwise. |
| `purge.retired_after` | `30 days` | Retired subscriptions older than this are deleted by `webpush:purge`. |

The application defaults `delivery.ttl` and `delivery.urgency` are exposed as an autowirable
`Domain\Message\DeliveryOptions` service: inject it rather than calling
`DeliveryOptions::createDefault()` to honour the configuration.

## Subscriber identity

The subscriber id is the application identity devices are attached to, e.g. `user:42`
(1–191 printable ASCII characters, no whitespace).

It **must be stable and never reused**. It is never derived from `getUserIdentifier()`,
which is usually the e-mail address: a user changing address and someone else signing up
with the old one would inherit each other's devices.

Default: the user class implements `WebPushSubscriber`:

```php
use RomainMillan\WebPushNotification\Bridge\Symfony\Security\WebPushSubscriber;

final class User implements UserInterface, WebPushSubscriber
{
    public function getWebPushSubscriberId(): string
    {
        return 'user:'.$this->id;
    }
}
```

When the user class cannot implement it (several providers, third-party user class),
configure a resolver. Namespace the ids so that two providers never collide (`admin:1` vs `customer:1`):

```php
use RomainMillan\WebPushNotification\Bridge\Symfony\Security\SubscriberIdResolver;
use RomainMillan\WebPushNotification\Domain\Subscription\SubscriberId;
use Symfony\Component\Security\Core\User\UserInterface;

final readonly class AppSubscriberIdResolver implements SubscriberIdResolver
{
    public function resolveSubscriberId(UserInterface $user): SubscriberId
    {
        return match (true) {
            $user instanceof Admin => SubscriberId::fromString('admin:'.$user->getId()),
            $user instanceof Customer => SubscriberId::fromString('customer:'.$user->getId()),
            default => throw new \LogicException('Unsupported user.'),
        };
    }
}
```

```yaml
web_push_notification:
    subscriber:
        resolver: App\WebPush\AppSubscriberIdResolver
```

Without either, the first request of an authenticated user throws a `LogicException`.

## Storage

### Doctrine (default)

The adapter `DoctrineSubscriptionRepository` works on **DBAL** (not the ORM unit of work) with
the connection `storage.connection`. The bundle prepends an ORM XML mapping of a
persistence-only entity (`SubscriptionRecord`, never hydrated by the package) so that your
usual tooling creates the table:

```bash
php bin/console doctrine:migrations:diff
php bin/console doctrine:migrations:migrate
```

Reference SQL (MySQL 8, what the mapping produces):

```sql
CREATE TABLE web_push_subscription (
    id VARCHAR(32) NOT NULL,
    owner_type VARCHAR(16) NOT NULL,
    subscriber_id VARCHAR(191) DEFAULT NULL,
    endpoint LONGTEXT NOT NULL,
    endpoint_hash VARCHAR(64) NOT NULL,
    push_host VARCHAR(253) NOT NULL,
    p256dh LONGTEXT NOT NULL,
    auth LONGTEXT NOT NULL,
    content_encoding VARCHAR(16) NOT NULL,
    registered_at DATETIME NOT NULL,
    last_registered_at DATETIME NOT NULL,
    retired_at DATETIME DEFAULT NULL,
    retirement_reason VARCHAR(16) DEFAULT NULL,
    UNIQUE INDEX uniq_web_push_endpoint_hash (endpoint_hash),
    INDEX idx_web_push_subscriber (subscriber_id, retired_at),
    INDEX idx_web_push_owner_type (owner_type, retired_at),
    INDEX idx_web_push_last_registered (last_registered_at),
    PRIMARY KEY (id)
) DEFAULT CHARACTER SET utf8mb4;
```

| Column | Content |
|---|---|
| `id` | 32 lowercase hex characters, generated by the package (128 random bits) |
| `owner_type` | `identified` or `anonymous` |
| `subscriber_id` | The subscriber id, `NULL` for anonymous |
| `endpoint` | The endpoint, or `v1:<keyId>:<base64>` when encrypted |
| `endpoint_hash` | SHA-256 of the canonical endpoint: carries the unique index, lookups and logs |
| `push_host` | Push service host (not a secret), lets the read model name the service without decrypting |
| `p256dh`, `auth` | Browser keys, possibly encrypted |
| `content_encoding` | `aes128gcm` (or `aesgcm`, legacy) |
| `registered_at`, `last_registered_at` | First and latest registration (the loader re-registers at most daily) |
| `retired_at`, `retirement_reason` | Set when retired (`expired`, `evicted`) with `retirement: deactivate` |

`SubscriptionRecord` is never serialized and redacts itself in `__debugInfo()`.

### Custom storage

```yaml
web_push_notification:
    storage:
        type: custom
        repository: App\WebPush\MySubscriptionStorage          # SubscriptionRepository & PurgeableSubscriptions & SubscriptionReadModel
        transaction_boundary: App\WebPush\MyTransactionBoundary # TransactionBoundary
```

The contract your adapter must honour is documented on the interfaces
(`Domain\Subscription\SubscriptionRepository`, `Domain\Subscription\PurgeableSubscriptions`,
`Application\Port\SubscriptionReadModel`, `Application\Port\TransactionBoundary`):

- `save()` throws `SubscriptionAlreadyExists` when another row holds the same endpoint hash;
- lookups by fingerprint include retired subscriptions;
- `ownedBy(AnonymousOwner)` returns an empty collection;
- owner filters are part of the query (`getOwnedSubscription()`, `listFor()`, `deleteAllOwnedBy()`);
- `activeInBatches()` pages by id and never hydrates everything at once;
- `TransactionBoundary::afterCommit()` runs the callback once the **outermost** transaction commits, and never on rollback.

Persist rows through `Infrastructure\Persistence\SubscriptionRowMapper` to get the same schema
and the encryption at rest for free. The executable contract ships with the package:
extend `RomainMillan\WebPushNotification\Testing\SubscriptionRepositoryContract` in your test
suite (it needs `phpunit/phpunit`, a suggested dependency) and provide your adapter:

```php
use App\WebPush\MySubscriptionStorage;
use App\WebPush\MyTransactionBoundary;
use RomainMillan\WebPushNotification\Application\Port\TransactionBoundary;
use RomainMillan\WebPushNotification\Testing\SubscriptionRepositoryContract;

final class MySubscriptionStorageTest extends SubscriptionRepositoryContract
{
    private MySubscriptionStorage $storage;
    private MyTransactionBoundary $transactionBoundary;

    protected function setUp(): void
    {
        // Start every test from an empty table.
        $this->storage = new MySubscriptionStorage(/* ... */);
        $this->transactionBoundary = new MyTransactionBoundary(/* ... */);
    }

    protected function repository(): MySubscriptionStorage // SubscriptionRepository & PurgeableSubscriptions & SubscriptionReadModel
    {
        return $this->storage;
    }

    protected function transactionBoundary(): TransactionBoundary
    {
        return $this->transactionBoundary;
    }
}
```

`RomainMillan\WebPushNotification\Testing\TestBrowser` builds realistic subscriptions for your
own tests (`TestBrowser::chrome()`, `safari()`, `firefox()`, with real P-256 keys). The shipped
adapters pass the same contract on SQLite, MySQL 8.4 and PostgreSQL 17.

## Lock

Registrations are serialized per endpoint and per owner with `SubscriptionLock`:

| Situation | Implementation | Effect |
|---|---|---|
| `lock.factory` configured, or `framework.lock` enabled | `SymfonyLockSubscriptionLock` (TTL 30 s, waits up to 5 s, then 503) | Strict quota, no concurrent claim of one endpoint |
| No lock factory | `LocalSubscriptionLock` (no-op) | Soft quota (N+1 transiently), races fall back on the unique index + one replay |

Use a store shared by every web server and worker (Redis, database, `flock` on a single host):

```yaml
framework:
    lock: '%env(LOCK_DSN)%'
```

See [consistency.md](consistency.md).

## Origin

`Domain\Message\Origin` is the application origin (`https://host[:port]`): signed action URLs and
`ActionUrl::fromString()` are checked against it. The bundle registers it as a public,
autowirable service:

- with `origin` set, that value;
- otherwise scheme, host and port of `framework.router.default_uri` (the configured request
  context, never the live one, which follows the `Host` header of the current request).

It is built on first use: an invalid or non-https origin (other than `localhost`) fails
there, not when the container is built.

```yaml
framework:
    router:
        default_uri: 'https://app.example.com'
```

## Sending notifications

Two entry points, both autowirable:

| Service | Use |
|---|---|
| `Application\Port\PushDispatcher::dispatch(Audience, WebPushMessage, DeliveryOptions): void` | Everyday fire-and-forget; synchronous or queued depending on `delivery.dispatcher` |
| `Application\WebPushSender::send(Audience, WebPushMessage, DeliveryOptions): DeliveryReport` | Synchronous, when you need the outcomes (diagnostics, admin tools) |

```php
use RomainMillan\WebPushNotification\Application\Port\PushDispatcher;
use RomainMillan\WebPushNotification\Domain\Delivery\SubscriberAudience;
use RomainMillan\WebPushNotification\Domain\Message\Action\ActionLabel;
use RomainMillan\WebPushNotification\Domain\Message\Action\DismissAction;
use RomainMillan\WebPushNotification\Domain\Message\Action\NavigateAction;
use RomainMillan\WebPushNotification\Domain\Message\ClickPath;
use RomainMillan\WebPushNotification\Domain\Message\DeliveryOptions;
use RomainMillan\WebPushNotification\Domain\Message\Tag;
use RomainMillan\WebPushNotification\Domain\Message\Urgency;
use RomainMillan\WebPushNotification\Domain\Message\WebPushMessage;

final readonly class PaymentNotifier
{
    public function __construct(
        private PushDispatcher $pushDispatcher,
        private DeliveryOptions $deliveryOptions, // delivery.ttl / delivery.urgency
    ) {
    }

    public function paymentReceived(int $userId, int $paymentId): void
    {
        $message = WebPushMessage::createWithTitle('Payment received', '120 € from ACME')
            ->withClickPath(ClickPath::fromString('/app/payments/'.$paymentId))
            ->withTag(Tag::fromString('payment-'.$paymentId))
            ->withAction(new NavigateAction(ActionLabel::fromActionAndTitle('open', 'Open'), ClickPath::fromString('/app/payments/'.$paymentId)))
            ->withAction(new DismissAction(ActionLabel::fromActionAndTitle('close', 'Close')));

        $this->pushDispatcher->dispatch(
            SubscriberAudience::fromSubscriberId('user:'.$userId),
            $message,
            $this->deliveryOptions->withTtl(3600)->withUrgency(Urgency::High),
        );
    }
}
```

Audiences (`Domain\Delivery`):

| Audience | Targets |
|---|---|
| `SubscriberAudience::fromSubscriberId('user:42')` | Every active device of one subscriber |
| `new SubscriptionsAudience(list<SubscriptionId>, Owner $expectedOwner)` | Precise subscriptions, as long as they still belong to `$expectedOwner` |
| `new Everyone()` | Every active subscription, identified and anonymous (the only way to reach anonymous ones). In async mode: one message per subscription |

The message API, the size limit and what the service worker does with each field are
described in [payload-contract.md](payload-contract.md). A `WebPushSender` report:

```php
use RomainMillan\WebPushNotification\Domain\Delivery\DeliveryStatus;

$report = $webPushSender->send($audience, $message, $deliveryOptions);

$report->countWith(DeliveryStatus::Delivered); // Delivered | Expired | Transient | Permanent | Skipped
foreach ($report as $subscriptionId => $outcome) {
    $outcome->status;     // DeliveryStatus
    $outcome->category;   // FailureCategory (gone, vapid, payload, rate_limited, server, network, unsafe, ...)
    $outcome->httpStatus; // 0 when no response
}
```

| Push service answer | Outcome | Effect |
|---|---|---|
| 2xx | `Delivered` | – |
| 404 / 410 | `Expired` (`gone`) | The subscription is retired (`expire()` + retirement policy) |
| 429 | `Transient` (`rate_limited`) | Retried in async mode, honouring `Retry-After` (capped at 3600 s) |
| 5xx | `Transient` (`server`) | Same |
| No response (timeout, DNS, TLS) | `Transient` (`network`) | Same |
| 400 / 413 | `Permanent` (`payload`) | – |
| 401 / 403 | `Permanent` (`vapid`), logged as critical | Never expires the subscription (VAPID rotation) |
| Other | `Permanent` (`unexpected`) | – |
| Host resolving to a non-public IP | `Permanent` (`unsafe`) | Not sent |
| Retired, re-owned or no longer allowed host | `Skipped` (`not_active`, `owner_changed`, `host_not_allowed`) | Not sent |

## Asynchronous delivery with Messenger

```yaml
web_push_notification:
    delivery:
        dispatcher: messenger

framework:
    messenger:
        transports:
            web_push:
                dsn: '%env(MESSENGER_TRANSPORT_DSN)%'
                retry_strategy:
                    max_retries: 3
                    delay: 60000
                    multiplier: 2
        routing:
            RomainMillan\WebPushNotification\Bridge\Symfony\Messenger\SendWebPush: web_push
```

- `MessengerPushDispatcher` encodes the payload **once** (an oversized message fails the
  dispatch, not every queued message) and dispatches **one `SendWebPush` per subscription**
  on `messenger.default_bus`.
- `SendWebPush` holds scalars only: subscription id, expected subscriber id, the v1 JSON
  payload, TTL/urgency/topic. No endpoint, no key, no serialized object graph: a deployment
  never breaks pending messages. **The notification text travels in the broker.**
- `SendWebPushHandler` rebuilds a `SubscriptionsAudience` and goes through the same
  `DeliverPayload` as synchronous sends. A subscription retired, deleted or handed to another
  owner since the dispatch is **dropped without retry**.
- Only `Transient` outcomes throw `RecoverableMessageHandlingException`. The retry delay
  (`Retry-After`) is honoured by Messenger 7.2+; older versions use the transport retry strategy.
- Without routing, Messenger handles `SendWebPush` synchronously.
- `new Everyone()` in async mode produces one message per active subscription: mind the volume.

## Notifier channel `web_push`

Registered when `symfony/notifier` is installed.

```php
use RomainMillan\WebPushNotification\Bridge\Symfony\Notifier\WebPushRecipientInterface;

final readonly class UserRecipient implements WebPushRecipientInterface
{
    public function __construct(private User $user)
    {
    }

    public function getWebPushSubscriberId(): string
    {
        return $this->user->getWebPushSubscriberId();
    }
}
```

```php
use Symfony\Component\Notifier\Notification\Notification;

$notifier->send(new Notification('Payment received', ['web_push']), new UserRecipient($user));
```

Without further work the channel builds `WebPushMessage::createWithTitle($subject, $content)`.
To control the message, implement `WebPushNotificationInterface` on your notification:

```php
use RomainMillan\WebPushNotification\Bridge\Symfony\Notifier\WebPushNotificationInterface;
use RomainMillan\WebPushNotification\Bridge\Symfony\Notifier\WebPushRecipientInterface;
use RomainMillan\WebPushNotification\Domain\Message\ClickPath;
use RomainMillan\WebPushNotification\Domain\Message\WebPushMessage;
use Symfony\Component\Notifier\Notification\Notification;

final class PaymentReceived extends Notification implements WebPushNotificationInterface
{
    public function __construct(private readonly int $paymentId)
    {
        parent::__construct('Payment received', ['web_push']);
    }

    public function asWebPushMessage(WebPushRecipientInterface $recipient): WebPushMessage
    {
        return WebPushMessage::createWithTitle('Payment received', '120 € from ACME')
            ->withClickPath(ClickPath::fromString('/app/payments/'.$this->paymentId));
    }
}
```

Channel policies work as usual (`framework.notifier.channel_policy: { urgent: ['web_push'] }`).
The channel dispatches through `PushDispatcher` (sync or Messenger follows
`delivery.dispatcher`) with the configured `delivery.ttl` and `delivery.urgency`. To override them
for one notification, implement `WebPushNotificationOptionsInterface`; it receives the
application defaults:

```php
use RomainMillan\WebPushNotification\Bridge\Symfony\Notifier\WebPushNotificationOptionsInterface;
use RomainMillan\WebPushNotification\Domain\Message\DeliveryOptions;
use RomainMillan\WebPushNotification\Domain\Message\Urgency;
use Symfony\Component\Notifier\Notification\Notification;

final class PaymentFailed extends Notification implements WebPushNotificationOptionsInterface
{
    public function webPushOptions(DeliveryOptions $defaults): DeliveryOptions
    {
        return $defaults->withTtl(600)->withUrgency(Urgency::High)->withTopic('payment');
    }
}
```

**Why a dedicated channel rather than a transport on Notifier's `push` channel?** The `push`
channel has no notion of recipient: its transports send one message to a topic or device
token carried by the message itself. Web Push needs to know *whose* devices to reach, which
is exactly what a `RecipientInterface` provides.

## Signed action URLs

A `PostAction` makes the service worker POST to your route from the lock screen, with the
user's cookies and **no CSRF token**: the URL must carry its own short-lived authorization
(see [security.md](security.md#actionurl-must-carry-its-own-authorization)). The bundle provides
`Bridge\Symfony\Signing\SymfonyActionUrlSigner`, autowirable through the core port
`Application\Port\ActionUrlSigner`. It signs an absolute URL with the framework `UriSigner` and
returns an `ActionUrl` checked against the [`Origin`](#origin):

```php
use RomainMillan\WebPushNotification\Application\Port\ActionUrlSigner;
use RomainMillan\WebPushNotification\Domain\Message\Action\ActionLabel;
use RomainMillan\WebPushNotification\Domain\Message\Action\PostAction;

$message = $message->withAction(new PostAction(
    ActionLabel::fromActionAndTitle('ack', 'Acknowledge'),
    $actionUrlSigner->sign('alert_ack', ['id' => $alert->getId()], new \DateInterval('PT1H')),
));
```

The receiving controller **must** call `SymfonyActionUrlSigner::verify()` (inject the concrete
class): it checks the signature and the expiry, and refuses a URL signed without expiry.

```php
use RomainMillan\WebPushNotification\Bridge\Symfony\Signing\SymfonyActionUrlSigner;

#[Route('/alerts/{id}/ack', name: 'alert_ack', methods: ['POST'])]
public function acknowledge(int $id, Request $request, SymfonyActionUrlSigner $signer): Response
{
    if (!$signer->verify($request)) {
        throw $this->createAccessDeniedException();
    }

    // Idempotent: the worker may POST twice.
    // ...

    return new Response('', Response::HTTP_NO_CONTENT);
}
```

On Symfony 7.1+ the expiry uses the native `_expiration` parameter of `UriSigner`. Symfony 6.4 has
no expiry support, so the signer adds a signed `_web_push_expires` query parameter that
`verify()` checks. The 6.4 path is only exercised by the lowest-dependencies CI job.

## Extension points: outcome listeners and events

### `DeliveryOutcomeListener`

Called for every outcome, after the mandatory `RetireOnExpiry` step. Autoconfigured: implementing
the interface is enough. A listener failure is logged and never breaks the delivery.

```php
use RomainMillan\WebPushNotification\Application\Port\DeliveryOutcomeListener;
use RomainMillan\WebPushNotification\Domain\Delivery\DeliveryOutcome;
use RomainMillan\WebPushNotification\Domain\Subscription\SubscriptionId;

final readonly class PushMetrics implements DeliveryOutcomeListener
{
    public function __construct(private MetricsClient $metrics) // your own service
    {
    }

    public function onDeliveryOutcome(SubscriptionId $subscriptionId, DeliveryOutcome $outcome): void
    {
        $this->metrics->increment('web_push.'.$outcome->status->value, ['category' => $outcome->category->value]);
    }
}
```

### Domain events

Published on `event_dispatcher` **after the real commit** (see [consistency.md](consistency.md)):
`SubscriptionRegistered`, `SubscriptionRenewed`, `SubscriptionReassigned`,
`SubscriptionReactivated`, `SubscriptionExpired`, `SubscriptionEvicted`,
`SubscriptionUnsubscribed`, `SubscriptionsPurged` (namespace `Domain\Subscription\Event`).

```php
use RomainMillan\WebPushNotification\Domain\Subscription\Event\SubscriptionExpired;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
final readonly class WarnAboutExpiredDevice
{
    public function __construct(private DeviceMailer $mailer) // your own service
    {
    }

    public function __invoke(SubscriptionExpired $event): void
    {
        $event->owner->fold(
            fn ($subscriberId) => $this->mailer->deviceExpired($subscriberId->toString()),
            static fn () => null,
        );
    }
}
```

## HTTP routes

| Name | Method + path | Purpose |
|---|---|---|
| `web_push_subscribe` | `POST /web-push/subscriptions` | Register the current browser (body: `PushSubscription.toJSON()` + optional `contentEncoding`) |
| `web_push_unsubscribe` | `POST /web-push/subscriptions/unsubscribe` | Remove a subscription by proof of possession (`{endpoint, keys: {auth}}`) |
| `web_push_service_worker` | `GET /web-push-sw.js` | The prebuilt worker with its config injected; **stateless** route |

Processing order of the subscribe route: anonymous gate (403 before any byte of the body is
read, whatever your `access_control`) → CSRF header → rate limit (anonymous owners) →
`Content-Type: application/json` (415) → body read up to 4 096 bytes, chunked included (413) →
JSON depth 4 (400) → shape and value objects (400) → registration.

| Status | Meaning |
|---|---|
| 204 | Done, **or refused by the registration matrix** (the reason is logged at info level, never returned) |
| 400 | Malformed body (empty response) |
| 403 | Anonymous visitor while anonymous subscriptions are disabled, or invalid CSRF token |
| 413 / 415 | Body too large / not JSON |
| 429 | Rate limited |
| 503 | Lock not acquired in 5 s (retry later) |

The unsubscribe route is not behind the anonymous gate: holding the `auth` secret proves the
caller is the device (logout flow), and the only possible effect is removing that very
subscription. It always answers 204, whether the endpoint is unknown, belongs to someone else
or the proof is wrong. It is **always rate limited** (CSRF header → rate limit → body), by
`routes.unsubscribe_rate_limiter`, or by default by the `web_push_unsubscribe` limiter the
bundle declares (sliding window, 30 requests per minute per client network, IPv6 aggregated
per /64): the 31st request of the minute gets a 429.

The service worker response carries `Content-Type: text/javascript; charset=utf-8`,
`X-Content-Type-Options: nosniff`, `Cache-Control: no-cache`, and
`Service-Worker-Allowed: /` only when the route path is not at the root.

## Twig: `web_push_meta()`

```twig
<head>
    {{ web_push_meta() }}
</head>
```

Renders `<meta name="web-push-config" content="{json}">` with the VAPID public key, the
subscribe and unsubscribe URLs, the service worker URL to register (`service_worker.register_url`,
or the package route), the click prefixes, the state cache name, the CSRF header and token, and the
client state marker of the current user (empty for an anonymous visitor). No JavaScript global.

Because the tag carries per-user values, the function flags the request and
`PrivateResponseListener` marks the main response `private, no-store`: a reverse proxy or ESI
cache never serves it to someone else. If some of your pages are publicly cacheable, render the
tag only on the pages that need push.

## Console commands

| Command | Effect |
|---|---|
| `webpush:vapid:generate` | Prints a new `VAPID_PUBLIC_KEY` / `VAPID_PRIVATE_KEY` pair; writes nothing. Clear your shell history / CI logs if needed. |
| `webpush:test <subscriber>` | Sends a test notification (TTL 60 s, configured urgency) synchronously to every device of `<subscriber>` (e.g. `user:42`) and prints counts per status, never an endpoint. Fails when the subscriber has no subscription. |
| `webpush:purge` | Deletes retired subscriptions older than `purge.retired_after` and anonymous ones not re-registered for `anonymous.stale_after`. Emits one `SubscriptionsPurged`. |

Schedule the purge daily (GDPR storage limitation), with cron:

```cron
15 3 * * * php /srv/app/bin/console webpush:purge --no-interaction
```

or with Symfony Scheduler:

```php
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

#[AsSchedule]
final class MaintenanceSchedule implements ScheduleProviderInterface
{
    public function getSchedule(): Schedule
    {
        return (new Schedule())->add(RecurringMessage::cron('15 3 * * *', new RunCommandMessage('webpush:purge')));
    }
}
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
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

final class DevicesController extends AbstractController
{
    #[Route('/account/devices', methods: ['GET'])]
    public function list(ListSubscriptions $listSubscriptions, #[CurrentUser] User $user): Response
    {
        $devices = $listSubscriptions->listFor(SubscriberId::fromString($user->getWebPushSubscriberId()));

        // Each SubscriptionView: id, shortFingerprint, pushService ("apple", "google", ...),
        // period->registeredAt, period->lastRegisteredAt, period->status ("active", "expired",
        // "evicted"), period->retiredAt, period->isActive(). Never an endpoint or a key.
        return $this->render('account/devices.html.twig', ['devices' => $devices]);
    }

    #[Route('/account/devices/{id}/revoke', methods: ['POST'])]
    #[IsCsrfTokenValid('revoke-device')]
    public function revoke(string $id, RevokeSubscription $revokeSubscription, #[CurrentUser] User $user): Response
    {
        try {
            // The owner filter is part of the query: someone else's id behaves like an unknown one.
            $revokeSubscription->revoke(IdentifiedOwner::fromSubscriberId($user->getWebPushSubscriberId()), SubscriptionId::fromString($id));
        } catch (InvalidValue) {
            // Malformed id: same answer as an unknown one.
        }

        return $this->redirect('/account/devices');
    }
}
```

`#[IsCsrfTokenValid]` requires Symfony 7.1+. Account deletion (GDPR right to erasure), e.g. in
your account deletion handler:

```php
$removeAllSubscriptions->removeAllOf(SubscriberId::fromString($user->getWebPushSubscriberId()));
```

It emits one `SubscriptionUnsubscribed` (cause `account_removed`) per active device, then deletes
the retired rows of that subscriber in bulk.

## Public services

Autowirable by class: `PushDispatcher`, `WebPushSender`, `DeliverPayload`, `RegisterSubscription`,
`Unsubscribe`, `RevokeSubscription`, `RemoveAllSubscriptions`, `ListSubscriptions`,
`SubscriptionReadModel`, `Origin`, `ActionUrlSigner` (and its implementation
`SymfonyActionUrlSigner`, for `verify()`), `DeliveryOptions` (the configured defaults).
Everything else is private.
