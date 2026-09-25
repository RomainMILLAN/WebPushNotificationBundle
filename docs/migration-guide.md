# Migration guide

> **This is a guide, not the migration itself.** It maps two existing hand-written Web Push
> implementations onto the package, as a starting point. Each actual migration is separate work,
> planned and tested in its own project.

- [General approach](#general-approach)
- [Romain-MILLAN-Finance (Symfony 7.4)](#romain-millan-finance-symfony-74)
- [romainmillan-notifier (Laravel 12)](#romainmillan-notifier-laravel-12)
- [Checklist](#checklist)

## General approach

1. Install the package, keep the **same VAPID key pair** (existing subscriptions stay valid).
2. Create the `web_push_subscription` table and copy the existing subscriptions into it
   (SQL, or through `RegisterSubscription` which validates each row).
3. Replace sending code with `PushDispatcher` / `WebPushSender` and a `WebPushMessage`.
4. Replace the page code with `startWebPush()` + the Stimulus controller (or `WebPushClient`),
   and the service worker with the prebuilt one or a worker importing `installWebPush()`.
5. Deploy; old workers are replaced by the new one on the next update check (`no-cache`,
   `updateViaCache: 'none'`), and the scope `/` registration keeps its push subscription.
6. Drop the old table / columns once the new path has run for a while.

Copying through the application (recommended when rows may be non-canonical, when encryption at
rest is enabled, or to get events and quotas applied):

```php
use RomainMillan\WebPushNotification\Application\RegisterSubscription;
use RomainMillan\WebPushNotification\Domain\Exception\WebPushNotificationException;
use RomainMillan\WebPushNotification\Domain\Subscription\ContentEncoding;
use RomainMillan\WebPushNotification\Domain\Subscription\IdentifiedOwner;
use RomainMillan\WebPushNotification\Domain\Subscription\PushAddress;
use RomainMillan\WebPushNotification\Domain\Subscription\PushEndpoint;
use RomainMillan\WebPushNotification\Domain\Subscription\SubscriptionKeys;

foreach ($legacyRows as $row) {
    try {
        $outcome = $registerSubscription->register(
            IdentifiedOwner::fromSubscriberId('user:'.$row['user_id']),
            new PushAddress(
                PushEndpoint::fromString($row['endpoint']),
                SubscriptionKeys::fromStrings($row['p256dh'], $row['auth']),
                ContentEncoding::Aes128Gcm,
            ),
        );
        // $outcome->value: registered, renewed, reassigned, refused_*
    } catch (WebPushNotificationException $invalid) {
        // Non-canonical or invalid endpoint/keys: the browser re-subscribes on its next visit.
    }
}
```

`RegisterSubscription` is a public service on both frameworks. Its timestamps are "now"
(`registered_at` = `last_registered_at` = migration time).

## Romain-MILLAN-Finance (Symfony 7.4)

### What exists

| Current code | Role |
|---|---|
| `src/Entity/PushNotification.php` (+ `PushNotificationRepository`) | One device, `ManyToOne User`, `endpoint`, `endpoint_hash`, embedded keys, Gedmo timestamps; unique `(user_id, endpoint_hash)` |
| `src/Model/Notification/Push/PushEndpoint.php`, `PushSubscriptionKeys.php`, `src/Enum/Notification/PushService.php`, `src/Exception/Notification/InvalidPushEndpointException.php` | SSRF guard by allowlist, key validation |
| `User::registerPushDevice()`, `evictOldestPushDeviceIfFull()`, `MAX_PUSH_DEVICES = 16`, `$pushNotifications` | Upsert + quota with eviction of the oldest |
| `RegisterPushNotification` + `RegisterPushNotificationHandler`, `Controller/App/Notification/RegisterController.php` | Registration route `/notification_push/register` |
| `SendPushNotification` + `SendPushNotificationHandler` | Synchronous delivery with `allow_redirects: false`, removal on expiry |
| `src/Service/Notification/PushPayloadFactory.php` | Payload `{title, options: {body, icon, badge, tag, data: {ownerMarker, actionPath}, actions}}` |
| `src/Model/Notification/ClickTarget.php`, `TransactionClickTargets.php`, `AbsoluteUrls.php` | Click destination (also used by Signal) |
| `src/Security/ClientOwnership/ClientOwnerMarker(Factory).php`, `Twig/Extensions/ClientOwnerMarkerExtension.php` | Owner marker rendered in `<meta name="client-owner-marker">` |
| `assets/controllers/notification_controller.ts` | Permission + subscribe + POST |
| `assets/pwa/loader.ts`, `navigation_intent.ts`, `sw_contract.ts`, `assets/controllers/pwa_diagnostics_controller.ts` | Worker registration, intent claim, diagnostics |
| `assets/pwa/service_worker.js` (copied to `/service_worker.js` by Encore) | Push, click (intent, trail), **offline `fetch` handler** |
| `templates/layout/partials/account-dropdown.html.twig` | Logout form marked `data-logout-form` |

### Mapping

| Finance | Package |
|---|---|
| `PushNotification` entity, repository, `User::$pushNotifications` | `web_push_subscription` table, `DoctrineSubscriptionRepository` (delete the entity and the relation) |
| `PushEndpoint`, `PushSubscriptionKeys`, `PushService`, `InvalidPushEndpointException` | `Domain\Subscription\PushEndpoint`, `SubscriptionKeys`, `AllowedPushServices` (same four services, same anchored suffix rule), plus canonical form, public-IP check and DNS pinning |
| `User::registerPushDevice()` + `MAX_PUSH_DEVICES` | `Subscription::claim()` + `max_subscriptions_per_subscriber: 16` (least recently registered evicted) |
| `RegisterController` + `RegisterPushNotification(Handler)` | Route `web_push_subscribe` (CSRF header `X-CSRF-Token` instead of the `_token` body field) |
| `SendPushNotificationHandler` | `PushDispatcher::dispatch(SubscriberAudience::fromSubscriberId('user:'.$user->getId()), $message, $deliveryOptions)` (the `DeliveryOptions` service: `delivery.ttl` / `delivery.urgency`) with `delivery.dispatcher: immediate` (the handlers were synchronous) |
| Removal on `isSubscriptionExpired()` | `RetireOnExpiry` (mandatory) + `retirement: delete` to keep the old behaviour, or `deactivate` to list expired devices |
| `PushPayloadFactory` | A factory returning a `WebPushMessage` (keeps the branding and translations) |
| `NotificationSubject::collapseTag()` | `->withTag(Tag::fromString($subject->collapseTag()))` (same charset `[A-Za-z0-9._:-]`, keep it ≤ 64) |
| `data.actionPath` / `ClickTarget` | `->withClickPath(ClickPath::fromString($target->toString()))` when `$target->isDestination()`; keep `ClickTarget` for Signal's `AbsoluteUrls` |
| Actions `open` / `close` | `NavigateAction(ActionLabel::fromActionAndTitle('open', …), ClickPath)` + `DismissAction(ActionLabel::fromActionAndTitle('close', …))` |
| `icon` / `badge` from `Packages` | `->withIcon(AssetUrl::fromString($packages->getUrl('static/pwa/icons/icon-192.png')))`, or `service_worker.icon` / `badge` |
| `ownerMarker` in the payload + `ClientOwnerMarker` meta | Client state marker in `web_push_meta()`, announced by the page to the worker (the payload no longer carries it) |
| `notification_controller.ts` | Stimulus controller `romainmillan/web-push-notification/web-push` |
| `loader.ts`, `navigation_intent.ts`, `sw_contract.ts` | `startWebPush()` |
| `pwa_diagnostics_controller.ts` | `readWorkerDiagnostics()`, `readClickTrail()` |
| `data-logout-form` | `data-web-push-logout` on the same form |
| `User` | `implements WebPushSubscriber`, `getWebPushSubscriberId(): 'user:'.$this->id` |

The marker derivation changes (`pwa-client-owner-v1` / `client-owner:<id>` → package purpose
`web-push-client-state-v1` / `client-state:user:<id>`): navigation intents pending at deployment
time (5-minute TTL) are dropped, nothing else.

### Service worker

Finance's worker has an offline `fetch` handler, which the package does not provide. Keep one
worker that installs the package handlers and keeps the offline part, build it as its own entry
(a single self-contained script: no runtime chunk, no split chunks, no content hash in its file
name), and serve it through the package route:

```js
// assets/pwa/service_worker.js
import { installWebPush, clientState, navigationIntent, diagnostics, badge } from '@romainmillan/web-push-notification/service-worker';

installWebPush(self, { config: self.__WEB_PUSH_CONFIG__, plugins: [clientState(), navigationIntent(), diagnostics(), badge()] });

self.addEventListener('fetch', (event) => { /* existing offline strategy */ });
```

```yaml
web_push_notification:
    service_worker:
        prebuilt_path: '%kernel.project_dir%/public/build/sw.js' # the built file, at a stable (non-hashed) path
        click_prefixes: ['/app/']
        icon: '/build/static/pwa/icons/icon-192.png'
```

The page then registers `/web-push-sw.js` instead of `/service_worker.js`: same scope `/`, so the
registration (and its push subscription) is updated, not duplicated. Remove `/service_worker.js`
once no client uses it (or keep serving the new script there during the transition).

Alternative that keeps the registered URL and needs no build of the worker: keep
`/service_worker.js` as your own worker, replace its push/click/message parts with
`importScripts('/web-push-sw.js');`, and set `service_worker.register_url: '/service_worker.js'`
so the meta tag announces it (see [frontend.md](frontend.md#using-your-own-service-worker)).

### Data migration (MySQL 8)

With encryption at rest **disabled** (otherwise use the PHP loop above), after creating the table:

```sql
INSERT INTO web_push_subscription
    (id, owner_type, subscriber_id, endpoint, endpoint_hash, push_host, p256dh, auth,
     content_encoding, registered_at, last_registered_at, retired_at, retirement_reason)
SELECT
    LOWER(HEX(RANDOM_BYTES(16))),
    'identified',
    CONCAT('user:', p.user_id),
    p.endpoint,
    SHA2(p.endpoint, 256),
    LOWER(SUBSTRING_INDEX(SUBSTRING_INDEX(p.endpoint, '/', 3), '/', -1)),
    p.p256dh,
    p.auth,
    'aes128gcm',
    p.created_at,
    p.updated_at,
    NULL,
    NULL
FROM push_notification p
-- Finance is unique on (user_id, endpoint_hash); the package on endpoint_hash alone:
-- keep the most recently registered row of an endpoint shared by two users.
WHERE p.id = (
    SELECT p2.id FROM push_notification p2
    WHERE p2.endpoint_hash = p.endpoint_hash
    ORDER BY p2.updated_at DESC, p2.id DESC
    LIMIT 1
);
```

- `endpoint_hash` is `SHA2(endpoint, 256)`, identical to Finance's `hash('sha256', $url)` and to
  the package fingerprint, provided the endpoint is canonical.
- Rows are reconstituted through `PushEndpoint::fromString()` on read: a non-canonical endpoint
  (upper-case host, explicit port...) would make its row unreadable. Real push-service endpoints
  are canonical; check before dropping the old table, e.g. by sending `webpush:test user:<id>`
  to a few users, or run the PHP loop instead.
- Then drop `push_notification` (and its foreign key) in a later migration.

### Classes to delete

`Entity/PushNotification`, `Repository/PushNotificationRepository`,
`Model/Notification/Push/PushEndpoint`, `Model/Notification/Push/PushSubscriptionKeys`,
`Enum/Notification/PushService`, `Exception/Notification/InvalidPushEndpointException`,
`Message/Command/Notification/RegisterPushNotification` (+ handler),
`Controller/App/Notification/RegisterController`, `Security/ClientOwnership/*`,
`Twig/Extensions/ClientOwnerMarkerExtension`, `assets/controllers/notification_controller.ts`,
`assets/controllers/pwa_diagnostics_controller.ts`, `assets/pwa/loader.ts`,
`assets/pwa/navigation_intent.ts`, `assets/pwa/sw_contract.ts`, the push/click/message parts of
`assets/pwa/service_worker.js`, and the push part of `User`. `SendPushNotificationHandler` and
`PushPayloadFactory` become a thin handler building a `WebPushMessage`.

## romainmillan-notifier (Laravel 12)

### What exists

| Current code | Role |
|---|---|
| `NotificationChannel` of type `ChannelType::WebPush`, `config` = `{endpoint, keys}` cast `encrypted:array` | One channel **per device**, with quiet hours, escalation, `is_active`, `deactivated_reason` |
| `app/Services/Channels/WebPushChannel.php`, `app/Support/OutboundUrlGuard.php` | Delivery with `CURLOPT_RESOLVE` pinning, `allow_redirects: false`, `DeliveryOutcome` |
| `app/Jobs/SendNotificationJob.php` | One job per channel, 3 tries, badge count computed at send time |
| `app/Actions/HandleExpiredSubscription.php` | Deactivates the channel on 404/410 (not deletes) and e-mails the owner |
| `app/Support/BadgeCount.php`, `PushSetupStatus.php` | Badge value; push setup read model (fingerprints per channel) |
| `public/sw.js` (static, outside Vite), `resources/js/service-worker.js`, `webpush.js`, `push-environment.js` | Worker (levels, ack action, intent, user marker, trail), page side, key rotation |

### Mapping

| Notifier | Package |
|---|---|
| `config.endpoint` / `config.keys` | A row of `web_push_subscription`; the channel keeps only a reference to it |
| `OutboundUrlGuard` + `curlResolveOption()` | Allowlist + `PublicIpPolicy` + `ResolvedHostPinning` (built in) |
| `WebPushChannel::send()` | `WebPushSender::send(new SubscriptionsAudience([$subscriptionId], $owner), $message, $options)` and `$report->outcomeFor($subscriptionId)` |
| `DeliveryOutcome::delivered / transient / permanent / subscriptionExpired` | `DeliveryStatus::Delivered / Transient / Permanent / Expired` (+ `Skipped`) |
| `HandleExpiredSubscription` on expiry | A `DeliveryOutcomeListener` (below), after the package's own `RetireOnExpiry` |
| `title`, `body` | `WebPushMessage::createWithTitle($level->prefix().$title, $body)` |
| `url` (in-app path) | `->withClickPath(ClickPath::fromString($path))` |
| `tag` | `->withTag(Tag::fromString($tag))` (same charset, already validated by `normaliseTag()`) |
| `level` (`info`/`warning`/`urgent`), `requireInteraction`, `renotify` | `->insistent()` for levels that require interaction (needs an explicit tag), `Urgency::High` in `DeliveryOptions`, title prefix server side. **Per-level vibration patterns are not in contract v1** |
| `ackUrl` (signed route `alerts.ack`) | `->withAction(new PostAction(ActionLabel::fromActionAndTitle('ack', 'Acknowledge'), app(ActionUrlSigner::class)->sign('alerts.ack', ['alert' => $alert->id], new \DateInterval('PT1H'))))`; the route already has `signed` + throttle |
| `badgeCount` (`BadgeCount::forUser()`) | `->withBadgeCount($count)` |
| `icon: /static/icons/icon-192.png` | `service_worker.icon` |
| `public/sw.js` + worker contract | Prebuilt worker; set `service_worker.path` to `'/sw.js'` to keep the registered URL, and delete `public/sw.js` (a static file would shadow the route) |
| User marker (`/__user-marker`), intent, trail, ping/pong | `clientState()`, `navigationIntent()`, `diagnostics()` plugins |
| `sameApplicationServerKey()` rotation in `webpush.js` | Built into `WebPushClient::sync()` |
| `PushSetupStatus::fingerprints()` | `ListSubscriptions::listFor()` (`shortFingerprint`, service, dates, status) |
| Subscriber | `User implements WebPushSubscriber` returning `'user:'.$this->getKey()` (otherwise the default id is `App\Models\User:<id>`) |

### Keeping the channel semantics

A channel stays the unit users configure (quiet hours, escalation); it now points at a
subscription. Add a plain column (the `config` JSON is encrypted, hence not queryable):

```php
Schema::table('notification_channels', function (Blueprint $table): void {
    $table->char('web_push_subscription_id', 32)->nullable()->index();
});
```

Move each active channel's subscription (the model cast decrypts `config`):

```php
use App\Enums\ChannelType;
use App\Models\NotificationChannel;
use RomainMillan\WebPushNotification\Application\ReadModel\ListSubscriptions;
use RomainMillan\WebPushNotification\Application\RegisterSubscription;
use RomainMillan\WebPushNotification\Domain\Subscription\IdentifiedOwner;
use RomainMillan\WebPushNotification\Domain\Subscription\PushAddress;
use RomainMillan\WebPushNotification\Domain\Subscription\PushEndpoint;
use RomainMillan\WebPushNotification\Domain\Subscription\SubscriberId;
use RomainMillan\WebPushNotification\Domain\Subscription\SubscriptionKeys;

$register = app(RegisterSubscription::class);
$list = app(ListSubscriptions::class);

NotificationChannel::query()->where('type', ChannelType::WebPush)->where('is_active', true)->each(
    function (NotificationChannel $channel) use ($register, $list): void {
        $endpoint = PushEndpoint::fromString($channel->config['endpoint']);
        $subscriberId = 'user:'.$channel->user_id;

        $register->register(
            IdentifiedOwner::fromSubscriberId($subscriberId),
            new PushAddress($endpoint, SubscriptionKeys::fromStrings($channel->config['keys']['p256dh'], $channel->config['keys']['auth'])),
        );

        foreach ($list->listFor(SubscriberId::fromString($subscriberId)) as $view) {
            if ($view->shortFingerprint === $endpoint->fingerprint()->short()) {
                $channel->forceFill(['web_push_subscription_id' => $view->id, 'config' => []])->save();
            }
        }
    },
);
```

Mind the quota: a user with more than 16 web push channels would see the oldest evicted (raise
`max_subscriptions_per_subscriber` if needed). Deactivated channels (`SubscriptionExpired`) are
skipped: their browser must subscribe again anyway.

New channels: the channel form subscribes the browser through the package route
(`client.subscribe()`), then links the channel to the subscription whose `shortFingerprint`
matches the fingerprint of the browser's endpoint (the page already computes one in
`push-environment.js`; the package uses the first 12 hex characters of the SHA-256 of the endpoint).

The expiry e-mail, as an outcome listener:

```php
namespace App\WebPush;

use App\Actions\HandleExpiredSubscription;
use App\Models\NotificationChannel;
use RomainMillan\WebPushNotification\Application\Port\DeliveryOutcomeListener;
use RomainMillan\WebPushNotification\Domain\Delivery\DeliveryOutcome;
use RomainMillan\WebPushNotification\Domain\Subscription\SubscriptionId;

final readonly class DeactivateExpiredChannel implements DeliveryOutcomeListener
{
    public function __construct(private HandleExpiredSubscription $handleExpiredSubscription)
    {
    }

    public function onDeliveryOutcome(SubscriptionId $subscriptionId, DeliveryOutcome $outcome): void
    {
        if (!$outcome->isExpired()) {
            return;
        }

        NotificationChannel::query()
            ->where('web_push_subscription_id', $subscriptionId->toString())
            ->each(fn (NotificationChannel $channel) => $this->handleExpiredSubscription->execute($channel));
    }
}
```

```php
// AppServiceProvider::register()
$this->app->tag([DeactivateExpiredChannel::class], DeliveryOutcomeListener::class);
```

With `retirement: deactivate` the subscription row stays (status `expired`), mirroring the
channel's `deactivated_reason`; the "resubscribe" flow re-registers the browser, which
**reactivates** the same row, then `reactivateAfterResubscribe()` the channel. Listening to
`SubscriptionExpired` (`Event::listen`) is an alternative that runs after the commit.

`SendNotificationJob` keeps its role (quiet hours, statuses, retries) and delivers synchronously
through `WebPushSender` with `delivery.dispatcher: immediate`:

```php
$report = app(WebPushSender::class)->send(
    new SubscriptionsAudience([SubscriptionId::fromString($channel->web_push_subscription_id)], IdentifiedOwner::fromSubscriberId('user:'.$channel->user_id)),
    $message,
    app(DeliveryOptions::class),
);
$outcome = $report->outcomeFor(SubscriptionId::fromString($channel->web_push_subscription_id));
// Delivered → Sent; Transient → throw (retry); Expired → handled by the listener; other → Failed
```

## Checklist

- [ ] Same VAPID key pair configured in the package.
- [ ] `User implements WebPushSubscriber` with `user:<id>` (the id used in the data migration).
- [ ] `web_push_subscription` created (Doctrine migration / published Laravel migration).
- [ ] Existing subscriptions copied; duplicates by endpoint resolved; a few `webpush:test` / `web-push:test` sent.
- [ ] Encryption at rest decided (then copy through PHP, not SQL).
- [ ] Sending code replaced by `PushDispatcher` / `WebPushSender`; payload factory builds a `WebPushMessage`.
- [ ] Tags, click paths, actions, icons, badge count mapped; lock-screen text reviewed.
- [ ] `PostAction` URLs signed (Finance: none today; Notifier: `alerts.ack` already signed).
- [ ] Frontend: `startWebPush()`, Stimulus controller or `WebPushClient`; `web_push_meta()` / `@webPushMeta` in the layout.
- [ ] Worker: prebuilt, own worker via `service_worker.prebuilt_path`, or own worker calling `importScripts('/web-push-sw.js')` with `service_worker.register_url` (Finance keeps its offline handler).
- [ ] Logout forms marked `data-web-push-logout`.
- [ ] `click_prefixes` narrowed (`/app/` for Finance).
- [ ] Shared lock store configured.
- [ ] Purge scheduled daily; account deletion calls `RemoveAllSubscriptions`.
- [ ] Old entity/table, controllers, handlers and scripts removed.
