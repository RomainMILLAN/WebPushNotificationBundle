# Frontend

The browser side ships **inside the Composer package**, prebuilt in
`vendor/romainmillan/web-push-notification/assets/dist` (ESM, TypeScript declarations included),
as the local package **`@romainmillan/web-push-notification`**. It is not published on the npm
registry: install it from the vendor directory with a `file:` dependency, the Symfony UX
convention. Its version therefore always matches the PHP package, and the service worker
served by `/web-push-sw.js` needs no JavaScript tooling at all.

| Entry point | Content |
|---|---|
| `@romainmillan/web-push-notification` | `startWebPush()`, `WebPushClient`, errors, diagnostics, navigation intent helpers |
| `@romainmillan/web-push-notification/controllers/web_push_controller` | The Stimulus controller (peer dependency `@hotwired/stimulus` ^3, optional) |
| `@romainmillan/web-push-notification/service-worker` | `installWebPush()` and the worker plugins, to bundle into your own worker |
| `@romainmillan/web-push-notification/web-push-sw.js` | The prebuilt standalone worker (a classic script, no module syntax) |

- [How the pieces fit](#how-the-pieces-fit)
- [Installation per build tool](#installation-per-build-tool)
- [The meta configuration](#the-meta-configuration)
- [`startWebPush()`](#startwebpush)
- [`WebPushClient`](#webpushclient)
- [Stimulus controller](#stimulus-controller)
- [The prebuilt service worker](#the-prebuilt-service-worker)
- [Using your own service worker](#using-your-own-service-worker)
- [iOS and iPadOS](#ios-and-ipados)
- [Logout](#logout)
- [Diagnostics](#diagnostics)

## How the pieces fit

1. The server renders `<meta name="web-push-config">` (`{{ web_push_meta() }}` / `@webPushMeta`).
2. `startWebPush()` reads it, registers the service worker URL it names (`/web-push-sw.js` by
   default, or [your own worker](#using-your-own-service-worker); scope `/`,
   `updateViaCache: 'none'`), keeps an existing subscription in sync, claims navigation intents
   left by the worker and hooks the logout forms.
3. A button (yours, or the Stimulus controller) calls `client.subscribe()` inside a click
   handler: permission prompt, `pushManager.subscribe()`, `POST` to the subscribe route.
4. The PHP route `/web-push-sw.js` serves the prebuilt worker with its configuration injected:
   **no build step is needed for the worker**, whatever your build tool.

## Installation per build tool

### Symfony + Webpack Encore (`@symfony/stimulus-bridge`)

Install the package from the vendor directory:

```bash
yarn add @romainmillan/web-push-notification@file:vendor/romainmillan/web-push-notification/assets
```

The package declares its controller under the `symfony` key of its `package.json`, like any
Symfony UX package. Enable it in `assets/controllers.json`:

```json
{
    "controllers": {
        "@romainmillan/web-push-notification": {
            "web-push": {
                "enabled": true,
                "fetch": "eager"
            }
        }
    },
    "entrypoints": []
}
```

Start Web Push once per page, in your entry point:

```js
// assets/app.js
import './bootstrap.js';
import { startWebPush } from '@romainmillan/web-push-notification';

startWebPush();
```

### Symfony + Vite (`vite-plugin-symfony`)

```json
// package.json
{
    "dependencies": {
        "@romainmillan/web-push-notification": "file:vendor/romainmillan/web-push-notification/assets"
    }
}
```

```js
// vite.config.js
import { defineConfig } from 'vite';
import symfonyPlugin from 'vite-plugin-symfony';

export default defineConfig({
    plugins: [symfonyPlugin({ stimulus: true })],
    build: { rollupOptions: { input: { app: './assets/app.js' } } },
});
```

Use the same `assets/controllers.json` as above; `startStimulusApp()` of
`vite-plugin-symfony/stimulus/helpers` loads the controllers it declares. Call `startWebPush()`
in `assets/app.js`.

### Symfony + AssetMapper

The controller and `index.js` are self-contained ES modules whose only import is
`@hotwired/stimulus`, so they work unbundled. With `symfony/stimulus-bundle`, enable the
controller in `assets/controllers.json` (as above): StimulusBundle reads
`vendor/romainmillan/web-push-notification/assets/package.json` like any UX package. Map the
page client for your own imports:

```php
// importmap.php
return [
    // ...
    '@romainmillan/web-push-notification' => [
        'path' => './vendor/romainmillan/web-push-notification/assets/dist/index.js',
    ],
];
```

```js
// assets/app.js
import { startWebPush } from '@romainmillan/web-push-notification';

startWebPush();
```

### Laravel + Vite

```bash
yarn add @romainmillan/web-push-notification@file:vendor/romainmillan/web-push-notification/assets
```

```js
// resources/js/app.js
import { startWebPush, WebPushError } from '@romainmillan/web-push-notification';

const webPush = startWebPush();

document.querySelector('[data-enable-push]')?.addEventListener('click', async () => {
    try {
        await webPush?.client.subscribe();
    } catch (error) {
        if (error instanceof WebPushError) {
            console.warn(error.code); // e.g. 'permission-denied'
        }
    }
});
```

```blade
<head>
    @webPushMeta
    @vite('resources/js/app.js')
</head>
```

## The meta configuration

`<meta name="web-push-config" content="{json}">`, read from the **live** DOM on every call
(Turbo replaces the `<head>`), never from a JavaScript global:

| Field | Content |
|---|---|
| `publicKey` | VAPID public key (base64url) |
| `serviceWorker` | URL of the worker to register: `service_worker.register_url` when set, else the package route (`/web-push-sw.js`) |
| `subscribe`, `unsubscribe` | URLs of the two JSON routes |
| `clickPrefixes` | Allowed click path prefixes |
| `stateCache` | Cache Storage name shared with the worker |
| `csrfHeader`, `csrfToken` | Header name (`X-CSRF-Token` on Symfony, `X-CSRF-TOKEN` on Laravel) and token |
| `clientState` | Client state marker of the signed-in user (16 hex), `''` for a guest |

`readPageConfig(document)` returns it parsed, or `null` when the tag is missing or unusable.

## `startWebPush()`

```ts
startWebPush(doc?: Document, options?: StartOptions): WebPushPage | null
```

Idempotent per document; returns `null` when the page has no usable meta tag.

| Option | Default | Meaning |
|---|---|---|
| `unsubscribeOnLogout` | `true` | Unsubscribe this device when a logout form is submitted |
| `logoutSelector` | `'form[data-web-push-logout]'` | Logout forms to hook |
| `logoutTimeoutMs` | `1000` | Maximum time the logout submit is held back |
| `sync` | `true` | Run `client.sync()` once the worker is registered |
| `navigate` | `location.assign()` | Navigation used when claiming an intent (tests) |
| `client` | `new WebPushClient(config)` | Custom client |
| `serviceWorker` | `navigator.serviceWorker` | Custom container (tests) |
| `serviceWorkerUrl` | the meta `serviceWorker` | URL of the worker to register, e.g. `'/sw.js'` for an application worker that calls `importScripts('/web-push-sw.js')`. Takes precedence over the meta field. Must be a same-origin, root-relative path: anything else (`//host`, another origin, backslashes) is ignored with a console warning and the meta value is used |

`WebPushPage`:

| Member | |
|---|---|
| `client` | The `WebPushClient` |
| `registration` | `Promise<ServiceWorkerRegistration \| null>` (null when registration failed) |
| `claim()` | Claims a pending navigation intent now (serialized) |
| `forgetClientState()` | Posts `forget-client-state` to the worker |
| `stop()` | Removes every listener |

It also claims intents on `pageshow`, `focus`, `visibilitychange` and `turbo:load`, announces the
client state marker to the worker, and asks for a worker update when the page becomes visible
again (at most once per minute; an installed iOS PWA almost never navigates).

## `WebPushClient`

```ts
import { WebPushClient } from '@romainmillan/web-push-notification';

const client = WebPushClient.fromDocument(); // throws WebPushError('unsupported') without meta
```

| Method | Returns | Notes |
|---|---|---|
| `isSupported()` | `boolean` | Service Worker, Push API and Notification available |
| `permission()` | `'default' \| 'granted' \| 'denied' \| 'unsupported'` | |
| `subscribe()` | `Promise<PushSubscription>` | **Call synchronously from a user gesture.** Asks the permission first thing (iOS drops the transient activation after any other `await`), waits up to 15 s for an active worker (`startWebPush()` must run on the page), recreates a subscription made with another VAPID key, POSTs it |
| `unsubscribe()` | `Promise<boolean>` | Server first (proof of possession, `keepalive`), then the browser, whatever the server said. Never throws; `false` when there was nothing to remove |
| `sync()` | `Promise<SyncResult>` | Re-POSTs an existing subscription at most every 24 h (or at once when the signed-in user changed); recreates it after a VAPID key rotation (`'rotated'`). Never throws |
| `getSubscription()` | `Promise<PushSubscription \| null>` | Current subscription, without prompting |
| `contentEncoding()` | `'aes128gcm' \| 'aesgcm'` | From `PushManager.supportedContentEncodings` |

`SyncResult`: `'unsupported'`, `'not-granted'`, `'no-service-worker'`, `'no-subscription'`,
`'fresh'`, `'synced'`, `'rotated'`, `'failed'`.

Errors (all extend `WebPushError`, with a `code`):

| Class | `code` |
|---|---|
| `WebPushUnsupportedError` | `unsupported` |
| `WebPushPermissionError` (`permission`) | `permission-denied`, `permission-dismissed` |
| `WebPushRequestError` (`status`) | `anonymous-disabled` (403, also an invalid CSRF token), `invalid-request` (400), `payload-too-large` (413), `unsupported-media-type` (415), `service-unavailable` (503), `http-error` |
| `WebPushError` | `no-service-worker`, `subscription-failed`, `network` |

Other exports: `urlBase64ToUint8Array()` (Safari needs a `Uint8Array` key),
`sameApplicationServerKey()`, `SYNC_INTERVAL_MS`, `browserEnvironment()`.

## Stimulus controller

The controller renders no HTML. It sets `data-web-push-state` on its element
(`unavailable`, `unsupported`, `denied`, `subscribed`, `unsubscribed`, `busy`), the text of the
`status` target, and hides/disables the buttons.

| | Name | |
|---|---|---|
| Targets | `status`, `subscribeButton`, `unsubscribeButton` | |
| Values | `labels` (Object) | Overrides the status texts per state |
| Actions | `subscribe`, `unsubscribe` | `subscribe` must be bound to a click |
| Events | `web-push:subscribed` (`detail.subscribed`, always `true`), `web-push:unsubscribed` (`detail.unsubscribed`), `web-push:error` (`detail.code`, `detail.error`) | Dispatched on the controller element |

Through Symfony UX the identifier is `romainmillan--web-push-notification--web-push`; the
Twig helpers take the package path:

```twig
<div {{ stimulus_controller('romainmillan/web-push-notification/web-push', {
        labels: { subscribed: 'Notifications are on', unsubscribed: 'Notifications are off' }
    }) }}>
    <p {{ stimulus_target('romainmillan/web-push-notification/web-push', 'status') }}></p>
    <button {{ stimulus_target('romainmillan/web-push-notification/web-push', 'subscribeButton') }}
            {{ stimulus_action('romainmillan/web-push-notification/web-push', 'subscribe') }}>Enable</button>
    <button {{ stimulus_target('romainmillan/web-push-notification/web-push', 'unsubscribeButton') }}
            {{ stimulus_action('romainmillan/web-push-notification/web-push', 'unsubscribe') }}>Disable</button>
</div>
```

Registered by hand under a short name:

```js
import { Application } from '@hotwired/stimulus';
import WebPushController from '@romainmillan/web-push-notification/controllers/web_push_controller';

Application.start().register('web-push', WebPushController);
```

```html
<div data-controller="web-push">
    <p data-web-push-target="status"></p>
    <button data-web-push-target="subscribeButton" data-action="web-push#subscribe">Enable</button>
    <button data-web-push-target="unsubscribeButton" data-action="web-push#unsubscribe">Disable</button>
</div>
```

The controller does not register the worker: keep `startWebPush()` in your entry point.

The `web-push:subscribed` event deliberately carries `{ subscribed: true }` and not the
endpoint: the endpoint is a capability URL (whoever holds it can push to the device) and has no
business in DOM events that any listener or analytics script can read.

## The prebuilt service worker

`GET /web-push-sw.js` (Symfony route `web_push_service_worker`, Laravel `service_worker.path`)
serves `assets/dist/web-push-sw.js` prefixed with `self.__WEB_PUSH_CONFIG__ = {...};`
(fallback title, default icon and badge, click prefixes, asset hosts, state cache). Headers:
`text/javascript; charset=utf-8`, `nosniff`, `Cache-Control: no-cache`. The route is stateless.

It installs every plugin: `clientState()`, `navigationIntent()`, `diagnostics()`, `badge()`.

- **push**: parses the payload against contract v1, keeps allowlisted fields only, shows the
  notification. An unreadable or non-v1 payload still shows `fallbackTitle` (browsers punish a
  push that displays nothing). Plugins never condition the display.
- **notificationclick**: `dismiss` closes; `post` POSTs (same-origin only, `credentials: same-origin`)
  to the action URL; otherwise the click path (or `navigate` action URL) is re-validated
  (same origin, `click_prefixes`), a navigation intent is written, then the worker tries to focus
  a window already there, to navigate the best window, or opens a new one; if nothing moved, it
  nudges a page to claim the intent.
- **lifecycle**: `skipWaiting()` on install, `clients.claim()` on activate.

It does not handle `fetch`: offline caching is out of scope.

## Using your own service worker

Only one worker can control a scope, and `startWebPush()` registers a single URL: the meta
`serviceWorker` field (the package route by default) or its `serviceWorkerUrl` option. Two ways
to combine Web Push with your own worker logic:

**1. `importScripts('/web-push-sw.js')` from your own worker (recommended when you already have
one).** The prebuilt script is a classic script that works inside `importScripts()`: the package
route prepends `self.__WEB_PUSH_CONFIG__`, so the configuration is set in the same global scope.
Point `register_url` at your worker so the meta tag announces it:

```js
// public/sw.js
importScripts('/web-push-sw.js');

self.addEventListener('fetch', (event) => {
    // your offline strategy
});
```

```yaml
# Symfony
web_push_notification:
    service_worker:
        register_url: '/sw.js'
```

```php
// Laravel, config/web-push.php
'service_worker' => ['register_url' => '/sw.js', /* ... */],
```

The package route keeps serving `/web-push-sw.js`; the page now registers `/sw.js`. Alternatively,
leave the configuration alone and call `startWebPush(document, { serviceWorkerUrl: '/sw.js' })`.

- The handlers are installed **once** per worker scope, even if the script is imported twice or
  the ES build is bundled next to it: the installation is marked on the scope under
  `Symbol.for('romainmillan.web-push.installed')`, shared by every copy of the code, so a
  notification is never displayed twice.
- Your own handlers (`fetch`, other `message` types) keep working next to the package ones.
- The prebuilt script calls `skipWaiting()` on install and `clients.claim()` on activate. If
  your worker needs another lifecycle policy, use option 2 with `manageLifecycle: false`.

**2. Bundle it, and let the package route serve it.** Build a worker that installs the handlers
itself, and point `service_worker.prebuilt_path` (Symfony and Laravel) at the built file. The
route still injects `self.__WEB_PUSH_CONFIG__` before your code:

```js
// assets/sw.js, built to e.g. public/build/sw.js as a single classic script
import { installWebPush, clientState, navigationIntent, diagnostics, badge } from '@romainmillan/web-push-notification/service-worker';

installWebPush(self, {
    config: self.__WEB_PUSH_CONFIG__,
    plugins: [clientState(), navigationIntent(), diagnostics(), badge()],
    manageLifecycle: true, // false when your code owns skipWaiting()/clients.claim()
});

self.addEventListener('fetch', (event) => {
    // your offline strategy
});
```

```yaml
web_push_notification:
    service_worker:
        prebuilt_path: '%kernel.project_dir%/public/build/sw.js'
```

`installWebPush()` is idempotent per scope, so importing it twice never shows a notification twice.

Writing a plugin (`WebPushPlugin`): every hook is optional and isolated (a throwing hook is
swallowed): `onPush(payload, ctx)`, `onBeforeNavigate(clickPath, ctx)`,
`onNavigated(clickPath, ctx)`, `onClick(record, ctx)`, `onMessage(message, reply, ctx)`,
`onActivate(ctx)`. `ctx` exposes `scope`, the normalized `config` and a `StateStore`.

| Plugin | Role |
|---|---|
| `clientState()` | Stores the client state marker announced by pages; an anonymous page erases it; `forget-client-state` purges the whole state cache |
| `navigationIntent()` | Writes `{clickPath, at, marker}` before navigating (only with a stored marker), removes it once landed, drops expired ones (5 min) |
| `diagnostics()` | Answers `worker-ping` with `SW_VERSION`, keeps the last 10 clicks (outcome and verdict only, never a path) |
| `badge()` | Applies `badgeCount` with the Badging API (`setAppBadge`, `clearAppBadge` for 0) |

## iOS and iPadOS

- Web Push requires iOS/iPadOS **16.4+** and a PWA **added to the home screen** (a web app
  manifest with `display: standalone`). In Safari tabs, `isSupported()` is false.
- The permission prompt must be triggered by a user gesture: call `client.subscribe()`
  synchronously in the click handler (the Stimulus controller does).
- An installed PWA frozen in the background thaws whenever it wants: `navigate()` may resolve
  without moving the page. The navigation intent (written before any attempt, claimed by the page
  on `pageshow`/`focus`/`visibilitychange`, atomic across tabs) makes the click land eventually.
  An intent is only claimed by a page rendering the **same** client state marker.
- Safari rotates subscription keys on reinstall: the server treats it as a renewal.
- A push that shows nothing can cost the subscription: the worker always shows something.

## Logout

Mark your logout forms:

```html
<form method="post" action="/logout" data-web-push-logout>
    <button>Log out</button>
</form>
```

On submit, while the session is still valid, the page (bounded by `logoutTimeoutMs`) sends the
unsubscription with the proof of possession, calls `pushManager.unsubscribe()` whatever happens,
posts `forget-client-state` to the worker, then replays the submit. If the script never runs, the
form still logs out. Set `unsubscribeOnLogout: false` to keep the device subscribed across
sessions (the worker state is still forgotten).

## Diagnostics

There are no devtools on an iPhone without a Mac:

```js
import { readWorkerDiagnostics, readClickTrail } from '@romainmillan/web-push-notification';

const { version, expectedVersion, upToDate } = await readWorkerDiagnostics();
const trail = await readClickTrail(); // [{ at, outcome, verdict, clientCount, intent }], newest first
```

`outcome` is one of `already-there`, `navigated`, `new-window`, `nudged`, `focus-only`,
`rejected`, `post-sent`, `post-failed`; `verdict` explains a rejected click path
(`rejected-cross-origin`, `rejected-prefix`, ...); `intent` is `written`, `skipped-no-marker`,
`not-applicable` or `disabled`.
