# Payload contract v1

The payload is the **published language** between the PHP side (`Application\Contract\PayloadEncoder`)
and the service worker (`assets/src/service-worker/payload.ts`). It is described by a JSON Schema,
`src/Application/Contract/schema/v1.json`, and tested on both sides with the same fixtures.

- [Building a message](#building-a-message)
- [Fields](#fields)
- [Size limit: 2 819 bytes](#size-limit-2-819-bytes)
- [Actions](#actions)
- [Tag and attention](#tag-and-attention)
- [Click paths and URLs](#click-paths-and-urls)
- [Versioning](#versioning)
- [Shared fixtures](#shared-fixtures)

## Building a message

`Domain\Message\WebPushMessage` is immutable and built with withers; every value object refuses
invalid input with `Domain\Exception\InvalidValue` (whose message never repeats the value).

```php
use RomainMillan\WebPushNotification\Domain\Message\Action\ActionLabel;
use RomainMillan\WebPushNotification\Domain\Message\Action\NavigateAction;
use RomainMillan\WebPushNotification\Domain\Message\Action\PostAction;
use RomainMillan\WebPushNotification\Domain\Message\ActionUrl;
use RomainMillan\WebPushNotification\Domain\Message\AssetUrl;
use RomainMillan\WebPushNotification\Domain\Message\ClickPath;
use RomainMillan\WebPushNotification\Domain\Message\MessageData;
use RomainMillan\WebPushNotification\Domain\Message\Origin;
use RomainMillan\WebPushNotification\Domain\Message\Tag;
use RomainMillan\WebPushNotification\Domain\Message\WebPushMessage;

$origin = Origin::fromString('https://app.example.com');

$message = WebPushMessage::createWithTitle('Payment received', '120 € from ACME')
    ->withTag(Tag::fromString('payment-42'))
    ->insistent()
    ->withIcon(AssetUrl::fromString('/static/icon-192.png'))
    ->withBadge(AssetUrl::fromString('/static/badge-96.png'))
    ->withAction(new PostAction(ActionLabel::fromActionAndTitle('ack', 'Acknowledge'), ActionUrl::fromString('/alerts/42/ack?signature=abc', $origin)))
    ->withAction(new NavigateAction(ActionLabel::fromActionAndTitle('open', 'Open'), ClickPath::fromString('/app/payments/42')))
    ->withData(MessageData::fromEntries(['paymentId' => 42, 'currency' => 'EUR']))
    ->withClickPath(ClickPath::fromString('/app/payments/42'))
    ->withBadgeCount(3);
```

This is exactly the message of the `full.json` fixture.

| Wither | Effect |
|---|---|
| `createWithTitle(string $title, string $body = '')` | Random 16-hex id; default tag `wp-<id>` |
| `withClickPath(ClickPath)` | Where a click on the notification leads |
| `withAction(NotificationAction)` | Adds a button (2 at most) |
| `withBadgeCount(int)` | App icon badge (≥ 0; 0 clears it) |
| `withData(MessageData)` | Free flat data for your own worker code |
| `withIcon(AssetUrl)`, `withBadge(AssetUrl)` | Images; absent: the worker defaults |
| `withTag(Tag)` | Notifications sharing a tag replace each other |
| `silent()` | No sound nor vibration |
| `insistent()` | Stays until dismissed and alerts again when replacing; **requires an explicit tag** |

## Fields

The encoded JSON (`v` added by the encoder, `data` always an object):

| Field | Type | Rule (PHP side) | Worker behaviour |
|---|---|---|---|
| `v` | `1` | Constant | Anything else: fallback notification |
| `id` | string | 16 lowercase hex, random | Kept in `notification.data.id` |
| `title` | string | 1–120 characters, not blank, no control characters | Blank: `fallbackTitle` |
| `body` | string | ≤ 1 000 characters, line breaks allowed, no other control characters | Shown **on the lock screen** |
| `tag` | string | `^[A-Za-z0-9._:-]{1,64}$` | Replacement key |
| `silent` | bool | From `Attention` | |
| `requireInteraction` | bool | From `Attention` | |
| `renotify` | bool | From `Attention` | Only applied with a tag (Chrome throws otherwise) |
| `icon`, `badge` | string, optional | In-origin path or https URL, ≤ 2 048, no whitespace/control/backslash | Displayed only if same-origin or on `asset_hosts`; otherwise the configured default |
| `click` | string, optional | `ClickPath` | Re-validated: same origin + `click_prefixes` |
| `badgeCount` | int ≥ 0, optional | | `badge()` plugin |
| `actions` | array ≤ 2 | See [Actions](#actions) | Unknown types and duplicate names dropped |
| `data` | object ≤ 16 entries | Keys `^[a-zA-Z][a-zA-Z0-9_]{0,31}$`, scalar values, strings ≤ 256 characters | Scalars copied to `notification.data.app` |

The worker reads every field explicitly: a field outside this list never reaches
`showNotification()`. A malformed optional field is dropped rather than rejecting the payload.

## Size limit: 2 819 bytes

`PayloadEncoder::MAX_BYTES = 2819`: the encoded JSON may not exceed 2 819 bytes, or
`InvalidPayload` is thrown (at dispatch time in async mode, before anything is queued).

Why this number: `minishlink/web-push` pads every payload to its compatibility target
(`Encryption::MAX_COMPATIBILITY_PAYLOAD_LENGTH = 2820` bytes) and aes128gcm adds one delimiter
byte. With automatic padding kept on, **every payload that fits is encrypted to the same size**,
so the length of the ciphertext tells an observer nothing about the content, and the result fits
every push service (Web Push allows 4 096 bytes of ciphertext at most).

The character limits (120 / 1 000) do not guarantee the byte limit with multibyte text: a 1 000
character body of CJK characters alone is about 3 000 bytes. Keep bodies short.

## Actions

Browsers display two buttons at most (`Notification.maxActions`); a third `withAction()` throws.

| PHP class | `type` | `url` | Worker on click |
|---|---|---|---|
| `NavigateAction(ActionLabel, ClickPath)` | `navigate` | In-origin path | Same navigation as a click on the notification |
| `PostAction(ActionLabel, ActionUrl)` | `post` | Same-origin absolute URL | `fetch(url, {method: 'POST', credentials: 'same-origin'})` without opening the app |
| `DismissAction(ActionLabel)` | `dismiss` | – | Closes the notification |

`ActionLabel::fromActionAndTitle($action, $title)`: action `^[a-z][a-z0-9_-]{0,31}$`, title 1–40
characters. A `PostAction` URL **must carry its own authorization** (a short-lived signed URL,
idempotent endpoint): the request carries the user's cookies but no CSRF token, and it can be
fired from the lock screen. Build it with the `Application\Port\ActionUrlSigner` of your bridge.
See [security.md](security.md#actionurl-must-carry-its-own-authorization).

## Tag and attention

- The default tag is `wp-<message id>`. A duplicate delivery caused by a retry (Messenger,
  Laravel queue) therefore **replaces** the first notification instead of stacking a second one.
- Choose an explicit tag (`Tag::fromString('alert-42')`) to make related notifications replace
  each other ("3 new messages" updating itself).
- `Attention`: `Normal` (default), `Silent` (`silent: true`), `Insistent`
  (`requireInteraction: true`, `renotify: true`). `insistent()` refuses a default tag: renotify on
  a unique tag would never replace anything.

## Click paths and URLs

| Type | Accepts | Refuses |
|---|---|---|
| `ClickPath::fromString()` | An absolute in-origin path: `/app/x?y=1` (≤ 2 048) | Empty, `//host`, anything with `://`, backslashes, whitespace, control characters |
| `ActionUrl::fromString($url, Origin)` | A path (resolved against the origin) or an absolute URL of that origin (≤ 2 048) | Another origin, whitespace, control characters, backslashes |
| `AssetUrl::fromString()` | An in-origin path or an https URL | Anything else |
| `Origin::fromString()` | `https://host[:port]` (or `http://localhost`, `http://127.0.0.1`) | Paths, query, credentials |

The worker is the distrustful twin: it re-resolves the click path against its own origin,
refuses another origin or scheme, and only navigates to `/` or a path under one of the configured
`click_prefixes` (default `['/']`, i.e. any path: narrow it to keep payloads away from GET
routes such as logout). A refused destination focuses or opens `/`. The page re-checks a claimed
intent the same way before navigating.

Both bridges provide the application `Origin` as an autowirable service, resolved on first use:
on Symfony from the `origin` setting, else `framework.router.default_uri`; on Laravel from
`APP_URL`. The `ActionUrlSigner` port builds signed `PostAction` URLs already checked against it
(see [security.md](security.md#actionurl-must-carry-its-own-authorization)).

## Versioning

- `v` is the contract version, **1**. A worker receiving another version shows the fallback
  title instead of guessing.
- Queued messages carry the v1 JSON itself (`EncodedPayload::fromQueuedJson()` re-validates it),
  never a serialized PHP object: deploying a new version never breaks pending messages.
- `SW_VERSION` (currently `'1'`) is the worker's behaviour version, reported by `worker-ping`;
  it is independent of the payload version.

## Shared fixtures

`tests/Fixtures/payload/minimal.json` and `full.json` are the contract, executable:

- PHPUnit (`tests/Unit/Application/PayloadContractTest.php`) encodes the messages shown above and
  asserts the output is **byte for byte** the fixture (the random id is substituted);
- Vitest (`assets/tests/payload_contract.test.ts`) pushes the same files into the real worker code
  and asserts the resulting `showNotification()` call;
- `assets/tests/contract_sync.test.ts` checks the worker against the PHP side: payload version, action types and bounds of `schema/v1.json`, the worker and page configuration shapes.

A change of the contract therefore fails on both sides until the fixture, the schema and both
implementations agree.
