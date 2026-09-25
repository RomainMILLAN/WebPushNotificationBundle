# Security

What the package defends against, how, and what remains **your** responsibility. Read it before
going to production.

- [Trust boundaries](#trust-boundaries)
- [The endpoint is a capability URL](#the-endpoint-is-a-capability-url)
- [SSRF: the endpoint is an outbound URL chosen by the client](#ssrf-the-endpoint-is-an-outbound-url-chosen-by-the-client)
- [Registration: who may claim a browser](#registration-who-may-claim-a-browser)
- [Subscriber identity](#subscriber-identity)
- [HTTP endpoints](#http-endpoints)
- [ActionUrl must carry its own authorization](#actionurl-must-carry-its-own-authorization)
- [What the notification reveals](#what-the-notification-reveals)
- [Encryption at rest](#encryption-at-rest)
- [Client state marker and private responses](#client-state-marker-and-private-responses)
- [Service worker](#service-worker)
- [Queues](#queues)
- [VAPID keys](#vapid-keys)
- [Supply chain](#supply-chain)
- [GDPR](#gdpr)
- [Your checklist](#your-checklist)

## Trust boundaries

| Boundary | Untrusted input | Controls |
|---|---|---|
| Browser → subscribe/unsubscribe routes | Endpoint, keys, content encoding, request size | Anonymous gate, CSRF, rate limit, media type, bounded body read, JSON depth, strict shape, value objects, allowlist, registration matrix, uniform 204 |
| Server → push service | The stored endpoint (outbound URL) | Allowlist (at registration **and** at delivery), canonical form, public-IP policy, DNS pinning, https only, no redirects, timeouts |
| Push service → server | HTTP status, `Retry-After` | Single classifier; reason text never read; `Retry-After` capped at 3 600 s |
| Database → package | Rows (possibly tampered or restored from a backup) | Endpoint shape re-validated at reconstitution; AEAD with row-bound associated data when encryption is on |
| Application → package | Message text, click paths, action URLs, subscriber ids | Value objects with bounded, validated content |
| Push payload → service worker | Every field | Field allowlist, click path re-validation (origin + prefixes), asset host allowlist, same-origin POST only |
| Cache Storage → page | Navigation intent (writable by any script of the origin) | Marker equality, TTL, the page's own click-path check before `location.assign()` |
| Queue / broker | Message content | Scalars only, no endpoint or key; payload re-validated as contract v1 |

## The endpoint is a capability URL

The endpoint identifies one browser installation, and together with your VAPID key pair (and
the subscription keys, to encrypt a payload) it is all it takes to reach that device. Treat it as
a secret: it must not leak, and it must never be the weak link if another secret does.

- `DeliveryOutcome` carries a status, a category and an HTTP status, **never** the push
  service's reason text or an exception message: both embed the request URL.
- The Minishlink transport never calls `MessageSentReport::getReason()`; Guzzle/Minishlink
  exceptions are reduced to their **class name** in logs.
- Logs carry `subscription_id`, `push_service_host` and a 12-character `fingerprint` (prefix of
  the endpoint's SHA-256): enough to correlate, useless to push.
- Value objects never repeat the rejected value in exception messages; parameters holding an
  endpoint, a key or a secret are `#[\SensitiveParameter]`.
- `__debugInfo()` redacts `Subscription`, `PushAddress`, `PushEndpoint`, `SubscriptionKeys`,
  `SubscriptionSnapshot`, `DeliveryTarget`, `VapidCredentials`, `EncryptionKey`,
  `PossessionProof` and both persistence records. The Eloquent record hides `endpoint`,
  `p256dh`, `auth` from `toArray()`/`toJson()`.
- Commands (`webpush:test`, `web-push:test`, purge) print counts, never an endpoint.
- The read model (`SubscriptionView`) exposes no endpoint and no key.

Your part: never log `PushSubscription.toJSON()` in the browser nor the raw request body on the
server. The Stimulus controller's `web-push:subscribed` event only carries
`{ subscribed: true }`: the endpoint is not copied into DOM events.

## SSRF: the endpoint is an outbound URL chosen by the client

Layers, each of which would stop a different attack:

1. **Shape** (`PushEndpoint`): ≤ 512 characters (checked before any parsing); whitespace,
   control characters and backslashes refused before parsing (parser differentials);
   `FILTER_VALIDATE_URL`; `https` only; no userinfo (`https://fcm.googleapis.com@evil.example/`);
   no explicit port; host not an IP literal.
2. **Canonical form, refused not rewritten**: the URL is rebuilt (`https://` + lowercase host +
   path + query) *to compare*; any difference is refused. A silently "fixed" endpoint would be
   unknown to the push service and would expire; refusing also removes a class of
   parser-confusion bypasses.
3. **Allowlist** (`AllowedPushServices`): Apple `push.apple.com`, Google `fcm.googleapis.com`
   and `android.googleapis.com`, Mozilla `updates.push.services.mozilla.com`, Microsoft
   `notify.windows.com`, plus `extra_hosts`. Exact match or suffix anchored on a dot
   (`web.push.apple.com` yes, `notpush.apple.com` and `fcm.googleapis.com.evil.example` no).
   Checked at registration **and again at delivery**: removing a host from the configuration
   stops deliveries at once, while existing rows stay readable and purgeable.
4. **Public IP policy** (`PublicIpPolicy`): every resolved address must be globally routable
   (`FILTER_FLAG_GLOBAL_RANGE`: refuses 127/8, 10/8, 172.16/12, 192.168/16, 169.254/16, the
   100.64/10 CGNAT range, fc00::/7...), after unwrapping IPv4-mapped and IPv4-compatible IPv6
   (`::ffff:169.254.169.254`). A host with **one** non-public address is refused (mixed answers
   are what rebinding looks like). `extra_hosts` do not bypass this check.
5. **DNS pinning** (`ResolvedHostPinning`): resolve once, check, then force cURL to connect to
   the checked address with `CURLOPT_RESOLVE` (the hostname still drives `Host` and TLS SNI).
   The Guzzle **cURL handler is imposed** (`ext-curl` is a hard requirement): the stream handler
   would silently ignore the pins.
6. **Transport options**: `allow_redirects: false`, `CURLOPT_PROTOCOLS` and
   `CURLOPT_REDIR_PROTOCOLS` = https, TLS verification always on, timeout (15 s) and connect
   timeout (≤ 5 s).

**Environment proxies neutralise pinning.** Guzzle honours `HTTPS_PROXY` / `HTTP_PROXY`
(`NO_PROXY`): cURL then connects to the proxy, which resolves the push host itself, so the
checked address is no longer the one reached. If your egress goes through a mandatory proxy,
enforce the destination policy on the proxy and set `dns_pinning: false` knowingly.

## Registration: who may claim a browser

The registration matrix lives in `Subscription::claim()`, the only entry point; the private
transitions guard their invariants again (`InvariantViolated`), so an application loading the
aggregate cannot bypass them.

| Known endpoint? | State | Claimant vs owner | `auth` presented | Result |
|---|---|---|---|---|
| – | – | – | – | Host not allowed → refused (neutral) |
| no | – | – | – | Registered, then quota |
| yes | Active | same identified owner | any | Renewed (key rotation, e.g. Safari) |
| yes | Active | same **anonymous** owner | same | Renewed |
| yes | Active | same anonymous owner | different | Refused: "same anonymous owner" proves nothing |
| yes | Active | different (anonymous → identified included) | same | Reassigned, then quota of the new owner |
| yes | Active | different | different | Refused (the client must unsubscribe, then subscribe) |
| yes | Active | identified → anonymous | – | Refused: no downgrade |
| yes | Retired | same | (as above) | Reactivated, then quota |
| yes | Retired | different | same | Reactivated for the new owner (+ reassigned), then quota |
| yes | Retired | different | different | Refused |
| yes | Retired | identified → anonymous | – | Refused |

Proof of possession is the `auth` secret, compared in constant time (`hash_equals`). Refusals are
answered **204** like successes; the reason (`refused_host_not_allowed`, `refused_auth_mismatch`,
`refused_downgrade`, `refused_anonymous_cap`) is only logged. `SubscriptionReassigned` is
security-relevant: audit it.

## Subscriber identity

The subscriber id must be **stable and never reused**. Never an e-mail address or a username: a
user changing address, and someone signing up with the old one, would inherit each other's
devices. The bridges never fall back on `getUserIdentifier()`; the Laravel default prefixes the
auth identifier with the morph alias or model class. Namespace ids when several user providers
exist (`admin:1`, `customer:1`). Without a valid resolver, Symfony throws on the first
authenticated request and Laravel fails at boot.

## HTTP endpoints

- **Deny by default.** `AnonymousGate` (owned by the package, not by your `access_control` or
  middleware) answers **403 before reading the body** when there is no authenticated user and
  anonymous subscriptions are disabled.
- **Anonymous opt-in is fail-closed**: `anonymous.enabled` without `anonymous.rate_limiter`
  refuses to boot. The rate-limit key is computed by the package (IPv4 address, IPv6 /64: an
  IPv6 client owns 2^64 addresses). A global cap (`max_active`, default 10 000) bounds the table;
  beyond it registrations are refused, never evicted (evicting a stranger's device would let
  anyone flush the table). Anonymous subscriptions are only reachable through `Everyone`.
- **CSRF** on both JSON routes: Symfony header `X-CSRF-Token` (token id `web_push`), Laravel the
  `web` group (`X-CSRF-TOKEN`).
- **Input**, in order: `application/json` only (415) → the body **actually read** is bounded to
  4 096 bytes, chunked requests included (413) → `json_decode` depth 4 (400) → exact shape
  (unknown fields refused) → value objects. Only package exceptions are caught; anything else
  surfaces as a bug. Bodies of error responses are empty. On Laravel, `VerifyCsrfToken` parses
  the body before this bound: PHP and web server limits apply first.
- **Unsubscribe is always rate limited**, on both bridges: it is public (no anonymous gate) and
  hashes a proof on every call. Default 30 requests per minute per client network (IPv6 /64):
  the `web_push_unsubscribe` sliding window the Symfony bundle declares (hence
  `symfony/rate-limiter` being required), a package limiter on Laravel. Override it with
  `routes.unsubscribe_rate_limiter`.
- **No oracle**: unsubscribe and revoke answer the same way for an unknown endpoint, somebody
  else's and a wrong proof. Revoke and owner-based lookups filter on the owner **in the query**
  (no find-then-check).
- **Unsubscribe by possession** is accepted for any owner and without the anonymous gate: holding
  `auth` proves you are the device, and the only possible effect is removing that subscription.

## ActionUrl must carry its own authorization

A `PostAction` makes the service worker `POST` to a same-origin URL **with the user's cookies,
without a CSRF token, possibly from the lock screen**, i.e. from whoever holds the phone. The
endpoint must therefore:

- carry its own authorization: a **signed, short-lived URL** bound to the resource;
- be **idempotent** (a retried push may show the button twice);
- do nothing more than the button says (acknowledge, snooze).

Both bridges ship a signer behind the core port `Application\Port\ActionUrlSigner`
(`sign(string $route, array $parameters, \DateInterval $validity): ActionUrl`). The URL is
absolute and checked against the application `Origin`: a signer can never produce a
`PostAction` pointing to another origin.

Symfony (`SymfonyActionUrlSigner`: router + `UriSigner` + `Origin`; set
`framework.router.default_uri` so absolute URLs work in CLI and workers):

```php
use RomainMillan\WebPushNotification\Application\Port\ActionUrlSigner;
use RomainMillan\WebPushNotification\Bridge\Symfony\Signing\SymfonyActionUrlSigner;
use RomainMillan\WebPushNotification\Domain\Message\Action\ActionLabel;
use RomainMillan\WebPushNotification\Domain\Message\Action\PostAction;

$message = $message->withAction(new PostAction(
    ActionLabel::fromActionAndTitle('ack', 'Acknowledge'),
    $actionUrlSigner->sign('alert_ack', ['id' => $alert->id], new \DateInterval('PT1H')),
));

// In the controller of alert_ack (POST, excluded from your CSRF checks),
// with SymfonyActionUrlSigner $signer injected:
if (!$signer->verify($request)) {
    throw $this->createAccessDeniedException();
}
```

`verify()` checks the signature **and** the expiry, and refuses a URL signed without expiry. On
Symfony 7.1+ the expiry is the native `_expiration` parameter of `UriSigner`; on 6.4, which has
none, it travels as a signed `_web_push_expires` parameter checked by `verify()` (never call
`UriSigner::checkRequest()` alone: on 6.4 it ignores the expiry). This 6.4 path is only exercised
by the lowest-dependencies CI job.

Laravel (`LaravelActionUrlSigner`, on `URL::temporarySignedRoute()`):

```php
use RomainMillan\WebPushNotification\Application\Port\ActionUrlSigner;
use RomainMillan\WebPushNotification\Domain\Message\Action\ActionLabel;
use RomainMillan\WebPushNotification\Domain\Message\Action\PostAction;

$message = $message->withAction(new PostAction(
    ActionLabel::fromActionAndTitle('ack', 'Acknowledge'),
    app(ActionUrlSigner::class)->sign('alerts.ack', ['alert' => $alert->id], new \DateInterval('PT1H')),
));

// routes/web.php
Route::post('/alerts/{alert}/ack', AcknowledgeAlert::class)->name('alerts.ack')->middleware('signed');

// bootstrap/app.php: the worker sends no CSRF token
->withMiddleware(function (Middleware $middleware): void {
    $middleware->validateCsrfTokens(except: ['alerts/*/ack']);
})
```

The `signed` middleware validates the URL of the incoming request: its scheme and host must be
those of the `APP_URL` origin. Behind a reverse proxy, configure `TrustProxies` so the request is
seen as `https://` on the public host, or every signed URL is refused.

## What the notification reveals

- The **title and body are displayed on the lock screen**: never put a secret, an amount the
  user would not want shown, a one-time code or personal data of a third party in them.
- The payload is end-to-end encrypted (RFC 8291) between your server and the browser; the push
  service sees only its size, which the padding makes constant (see
  [payload-contract.md](payload-contract.md#size-limit-2-819-bytes)).
- Icons and badges are displayed only from your origin or `asset_hosts`: an arbitrary image URL
  in a payload would be a tracking pixel fired on every display.

## Encryption at rest

Off by default. Without it, **endpoints and `auth` secrets are plaintext secrets in your
database and in every backup**: a dump combined with a leaked VAPID key lets anyone push to
your users' devices, and the endpoints identify their browsers.

```yaml
web_push_notification:
    encryption:
        current: '%env(WEB_PUSH_ENCRYPTION_KEY)%'   # "k2:<base64 of 32 bytes>"
        previous: ['%env(WEB_PUSH_ENCRYPTION_KEY_PREVIOUS)%']
```

```bash
php -r 'echo "k1:".base64_encode(random_bytes(32)), PHP_EOL;'
```

- XChaCha20-Poly1305 (libsodium), a random 24-byte nonce per value, stored as
  `v1:<keyId>:<base64(nonce . ciphertext)>` in `endpoint`, `p256dh` and `auth`.
- **Associated data** = endpoint fingerprint + column name: a ciphertext copied onto another row
  or column fails to decrypt.
- Key format `keyId:base64`: keyId 1–16 of `[a-zA-Z0-9_-]`, key exactly 32 bytes, validated.
  Dedicated: never derived from `kernel.secret`/`APP_KEY`, never the HMAC secret of the marker.
- **Rotation**: set the new key as `current`, move the old one to `previous`. Values are
  re-encrypted with the current key when their row is next saved (registration renewals happen
  at most daily per active device); remove the old key once no row uses it.
- `endpoint_hash` and `push_host` stay in clear (lookups, unique index, device list).

## Client state marker and private responses

- The marker tells the page and the worker **which account** the local worker state (navigation
  intent) belongs to, so a new user on a shared browser never inherits the previous user's click.
  It is an equality tag, not an authorization.
- `HMAC-SHA256(purposeKey, "client-state:" + subscriberId)` truncated to 16 hex characters, where
  `purposeKey = HMAC-SHA256(appSecret, "web-push-client-state-v1")` (`kernel.secret`, or the
  decoded `APP_KEY`). A purpose sub-key, never the root secret; derived from the identity, not the
  session (a re-login must not wipe a pending intent). Anonymous visitors get none.
- The meta tag holds a CSRF token and that marker: the page is marked **private**
  (`PrivateResponseListener` on Symfony adds `private, no-store`; `MarkWebPushResponsePrivate` on
  Laravel sets `private` and drops `s-maxage`). The JSON is encoded with
  `JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT` then HTML-attribute escaped.

## Service worker

The worker is the most privileged script of the origin.

- `/web-push-sw.js` is **stateless** (Symfony `stateless()`, Laravel outside any middleware
  group: no session, no cookie); its content never depends on who fetches it.
- Configuration is injected as a JSON literal escaped for any context (`</script>`, U+2028,
  quotes); headers `text/javascript; charset=utf-8`, `nosniff`, `Cache-Control: no-cache`
  (fixes reach browsers on the next update check); `Service-Worker-Allowed: /` only when the
  route is below the root.
- The worker accepts allowlisted payload fields only, re-validates click paths (origin +
  `click_prefixes`), POSTs only same-origin, displays images only from allowed hosts.
- The click trail kept for diagnostics never stores a path (Cache Storage is shared by origin,
  not by user). Logout purges the worker state; `Clear-Site-Data: "storage"` is not used because
  it unregisters the worker and destroys the subscription on Chrome.

## Queues

Messenger messages and Laravel jobs hold scalars only: subscription id, expected subscriber id,
the v1 payload, TTL/urgency/topic. **No endpoint, no key**, so failure transports and
`failed_jobs` hold no capability URL. But **the notification text (title, body, click path,
action URLs) sits in the broker** until consumed or failed: protect and expire your transports
accordingly. A message whose subscription changed hands meanwhile is dropped, never delivered to
the new owner.

## VAPID keys

- Generate with `webpush:vapid:generate` / `web-push:vapid`: printed, never written. The output
  may remain in shell history or CI logs.
- Store the private key as a secret (Symfony secrets, vault, environment); **never commit it**.
  Only the public key reaches the page.
- Validated at boot/first use: public key 65-byte uncompressed P-256 point, private key 32 bytes,
  subject `mailto:` or `https:`.
- **Rotation invalidates every subscription** until each browser comes back: push services
  answer 401/403, classified `Permanent(vapid)` and logged as **critical**, deliberately **not**
  treated as expiry (a mis-deployed key must not wipe your table). The page client detects the
  key change (`sameApplicationServerKey`) on its next sync, unsubscribes the old subscription and
  creates a new one. Devices that never come back keep failing until you remove them.
- Only one key pair is supported.

## Supply chain

- `assets/dist` (the prebuilt worker and ESM bundles) is committed; CI rebuilds it from the
  sources with `yarn install --immutable` and fails on any `git diff` of `dist/`.
- GitHub Actions are pinned by commit SHA, the workflow has `permissions: contents: read`, no
  `pull_request_target`.
- `composer audit` and `yarn npm audit --all --recursive --severity high` run in CI.
- The JS client is not published on npm: it ships in `assets/dist` inside the Composer archive,
  so there is a single artifact to trust. Recommended for maintainers: enable 2FA on Packagist
  and GitHub (see [contributing.md](contributing.md#releasing)).
- Minimal dependencies: `minishlink/web-push`, Guzzle, PSR interfaces, `symfony/string`.

## GDPR

- **Storage limitation**: schedule `webpush:purge` / `web-push:purge` daily: retired
  subscriptions go after `purge.retired_after` (30 days), anonymous ones after
  `anonymous.stale_after` (90 days) without re-registration.
- **Right to erasure**: call `RemoveAllSubscriptions::removeAllOf()` when deleting an account.
- **Minimisation**: no user agent, device label or IP is stored; the device list shows the push
  service and dates only. Expired subscriptions are retired automatically.
- The endpoint is personal data (it identifies a browser installation); encryption at rest is
  recommended.

## Your checklist

- [ ] `WebPushSubscriber` (or a resolver) returns a stable, non-reusable, namespaced id.
- [ ] VAPID private key in a secret store, not in git.
- [ ] Encryption at rest enabled with a dedicated key.
- [ ] A shared lock store (Symfony `framework.lock`, Laravel non-`array` cache).
- [ ] `click_prefixes` narrowed to your application paths.
- [ ] Every `PostAction` URL signed, short-lived, idempotent; the route exempted from CSRF only because it is signed.
- [ ] No sensitive data in titles and bodies.
- [ ] Logout forms marked `data-web-push-logout`.
- [ ] Purge scheduled daily; `RemoveAllSubscriptions` wired to account deletion.
- [ ] Anonymous subscriptions left disabled unless needed (then a rate limiter and a sensible `max_active`).
- [ ] Queue transports protected (they carry the notification text).
- [ ] No environment proxy silently bypassing DNS pinning.
