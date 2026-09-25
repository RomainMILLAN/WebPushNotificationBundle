# Changelog

All notable changes to `romainmillan/web-push-notification` (Composer) and
`@romainmillan/web-push-notification` (npm) are documented here. The project follows
[Semantic Versioning](https://semver.org/).

## [Unreleased] / 0.1.0

Initial release.

### Core

- `WebPushMessage` and its value objects (`Content`, `Appearance`, `Attention`, `Tag`,
  `ClickPath`, `ActionUrl`, `AssetUrl`, `MessageData`, `NavigateAction` / `PostAction` /
  `DismissAction`, `DeliveryOptions` with TTL, urgency and topic).
- Payload contract v1 (`PayloadEncoder`, JSON Schema `schema/v1.json`, 2 819-byte limit matching
  Minishlink's padding target), with fixtures shared by PHPUnit and Vitest.
- `Subscription` aggregate: registration matrix in `claim()` (renew, reassign on proof of
  possession, reactivate, no identified → anonymous downgrade), expiry, eviction, unsubscription;
  domain events published after the real commit.
- Per-subscriber quota (16 by default, least recently registered evicted), opt-in anonymous
  subscriptions with a global cap, `delete` / `deactivate` retirement policy, purge.
- Single delivery path (`DeliverPayload`) with classified outcomes, mandatory `RetireOnExpiry`,
  `DeliveryOutcomeListener` extension point; `PushDispatcher` (immediate) and `WebPushSender`.
- Minishlink transport as an anticorruption layer: push-service allowlist, canonical endpoints,
  public-IP policy (CGNAT, IPv4-mapped IPv6), DNS pinning with the cURL handler imposed, no
  redirects, https only, no capability URL in logs or outcomes.
- Optional encryption at rest (XChaCha20-Poly1305 with row-bound associated data, key rotation).
- Client state marker (HMAC purpose sub-key), read model without secrets, account erasure.
- `Application\Port\ActionUrlSigner` port: signed, short-lived `PostAction` URLs checked against
  the application `Origin`.
- `Testing\SubscriptionRepositoryContract` and `Testing\TestBrowser` shipped in `src/Testing`, so
  third-party storage adapters run the same contract suite (`phpunit/phpunit` suggested). The
  contract passes on SQLite, MySQL 8.4 and PostgreSQL 17.

### Symfony bridge

- `WebPushNotificationBundle` (Symfony 6.4, 7.x, 8.x) with validated configuration.
- `origin` setting and public `Origin` service, derived by default from
  `framework.router.default_uri`, built on first use (`OriginFactory`).
- `delivery.ttl` / `delivery.urgency` defaults, exposed as a `DeliveryOptions` service used by
  the Notifier channel and `webpush:test`; `Notifier\WebPushNotificationOptionsInterface` to
  override them per notification.
- `Signing\SymfonyActionUrlSigner` (`UriSigner`): native `_expiration` on Symfony 7.1+, signed
  `_web_push_expires` parameter on 6.4, both checked by `verify(Request)`.
- The unsubscribe route is always rate limited: `routes.unsubscribe_rate_limiter`, by default a
  `web_push_unsubscribe` sliding window (30 per minute) declared by the bundle.
  `symfony/rate-limiter` is required.
- `service_worker.register_url`: the worker URL announced in the meta tag (e.g. an application
  `/sw.js` that calls `importScripts('/web-push-sw.js')`).
- Doctrine DBAL storage (schema through `doctrine:migrations:diff`), after-commit DBAL middleware,
  Symfony Lock, Messenger dispatcher and handler, Notifier channel `web_push`.
- Routes: subscribe, unsubscribe (proof of possession), stateless `/web-push-sw.js`.
- Twig `web_push_meta()` with private responses; commands `webpush:vapid:generate`,
  `webpush:test`, `webpush:purge`.

### Laravel bridge

- Auto-discovered `WebPushNotificationServiceProvider` (Laravel 12) with boot-time validation.
  `APP_URL` is not read at boot when `VAPID_SUBJECT` is set: the `Origin` is resolved on first use,
  with a clear error for a non-https `APP_URL` (localhost aside).
- `Signing\LaravelActionUrlSigner` (`URL::temporarySignedRoute`, verified by the `signed`
  middleware).
- `web-push.service_worker.register_url`, same role as on Symfony.
- Eloquent storage and publishable migration, `Cache::lock`, queued `SendWebPushJob`
  (after commit), Notifications channel `web-push`, `@webPushMeta` / `<x-web-push::meta/>`,
  `MarkWebPushResponsePrivate` on the `web` group; commands `web-push:vapid`, `web-push:test`,
  `web-push:purge`.

### Frontend (npm 0.1.0)

- `startWebPush()` page loader (single worker registration, daily sync, VAPID key rotation,
  navigation intent claim, logout unsubscription), `WebPushClient`, typed errors, diagnostics.
  Option `serviceWorkerUrl` (same-origin path, takes precedence over the meta tag).
- Stimulus controller for Symfony UX (Encore, Vite, AssetMapper). `web-push:subscribed` carries
  `{ subscribed: true }`, never the endpoint.
- Prebuilt standalone service worker and `installWebPush()` with plugins `clientState()`,
  `navigationIntent()`, `diagnostics()`, `badge()`. The prebuilt worker also works through
  `importScripts('/web-push-sw.js')` from an application worker, and installs once per scope
  (guard under `Symbol.for('romainmillan.web-push.installed')`).

### Development

- PostgreSQL 17 in `compose.yaml` (service `postgres`, `WEB_PUSH_TEST_PGSQL_DSN`) and in CI,
  next to MySQL 8.4.
