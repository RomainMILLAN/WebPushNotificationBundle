//#region src/click_path.ts
/**
* The ONLY function that sees a raw click destination, shared by the worker and the page.
*
* Both naive versions fail: `raw.startsWith('/')` lets `//evil.example/x` through (it
* resolves off-origin), and `new URL(raw)` without a base throws on every relative
* path, i.e. on the normal case.
*
* Origin validation alone is not enough: it would allow a GET logout route, turning a
* notification into a one-click denial of service. Hence the prefix allowlist; `/` is
* always accepted as the neutral landing page.
*/
function resolveClickPath(raw, base, origin, prefixes) {
	if (raw === void 0 || raw === null || String(raw) === "") return {
		clickPath: "/",
		verdict: "no-destination"
	};
	let url;
	try {
		url = new URL(String(raw), base);
	} catch {
		return {
			clickPath: "/",
			verdict: "rejected-parse"
		};
	}
	if (url.protocol !== "https:" && url.protocol !== "http:") return {
		clickPath: "/",
		verdict: "rejected-scheme"
	};
	if (url.origin !== origin) return {
		clickPath: "/",
		verdict: "rejected-cross-origin"
	};
	if (!isAllowedPathname(url.pathname, prefixes)) return {
		clickPath: "/",
		verdict: "rejected-prefix"
	};
	return {
		clickPath: url.pathname + url.search,
		verdict: "accepted"
	};
}
function isAllowedPathname(pathname, prefixes) {
	return pathname === "/" || prefixes.some((prefix) => prefix !== "" && pathname.startsWith(prefix));
}
//#endregion
//#region src/contract.ts
/**
* The contract shared by the page and the service worker.
*
* Both sides are bundled from this very module, so they cannot drift apart: the page
* bundle and the prebuilt worker (dist/web-push-sw.js) embed the same values. What
* CAN drift is a device running a stale worker next to a fresh page bundle, which is
* exactly what the `worker-ping` / SW_VERSION diagnostic reveals.
*/
/**
* Bump on every behavioural change of the service worker. The page compares the
* version the running worker reports with the one it was built with.
*/
var SW_VERSION = "1";
/** The payload contract version the worker accepts (see src/Application/Contract/schema/v1.json). */
var PAYLOAD_VERSION = 1;
/** Default Cache Storage name holding the worker state (intent, trail, marker). */
var DEFAULT_STATE_CACHE = "web-push-state";
/** `{ clickPath, at, marker }`, written by the worker before navigating, claimed by a page. */
var INTENT_KEY = "/__web-push/navigation-intent";
/** `[{ at, outcome, verdict, clientCount, intent }]`, newest first, never any path. */
var TRAIL_KEY = "/__web-push/click-trail";
/** `{ marker }`: the client state marker last announced by a page. */
var MARKER_KEY = "/__web-push/client-state";
/**
* Retention of a navigation intent. It covers both the unpredictable thaw of a
* frozen iOS page and the time a user needs to sign in before the target is forgotten.
*/
var INTENT_MAX_AGE_MS = 3e5;
/** Message types exchanged between the page and the worker. */
var MessageType = {
	workerPing: "worker-ping",
	workerPong: "worker-pong",
	clientState: "client-state",
	forgetClientState: "forget-client-state",
	readClickTrail: "read-click-trail",
	clickTrail: "click-trail",
	claimNavigationIntent: "claim-navigation-intent"
};
/**
* Client state marker domain primitive: 16 lowercase hexadecimal characters, or null.
* Two absences are not an identity: a missing marker never equals another missing one.
*/
function parseMarker(raw) {
	return typeof raw === "string" && /^[0-9a-f]{16}$/.test(raw) ? raw : null;
}
//#endregion
//#region src/service-worker/config.ts
var DEFAULT_FALLBACK_TITLE = "Notification";
/**
* Normalizes an untrusted config object: unknown keys are dropped, wrong types fall
* back to defaults. The worker must start even with a broken or missing config: a push
* that is not displayed costs the subscription on some browsers.
*/
function normalizeServiceWorkerConfig(raw) {
	const source = isRecord(raw) ? raw : {};
	return {
		fallbackTitle: nonEmptyString(source.fallbackTitle) ?? "Notification",
		icon: typeof source.icon === "string" ? source.icon : "",
		badge: typeof source.badge === "string" ? source.badge : "",
		clickPrefixes: stringList(source.clickPrefixes).filter((prefix) => prefix.startsWith("/") && !prefix.startsWith("//")),
		assetHosts: stringList(source.assetHosts).map((host) => host.toLowerCase()),
		stateCache: nonEmptyString(source.stateCache) ?? "web-push-state"
	};
}
function isRecord(value) {
	return typeof value === "object" && value !== null && !Array.isArray(value);
}
function nonEmptyString(value) {
	return typeof value === "string" && value.trim() !== "" ? value : null;
}
function stringList(value) {
	return Array.isArray(value) ? value.filter((item) => typeof item === "string" && item !== "") : [];
}
//#endregion
//#region src/service-worker/payload.ts
var ACTION_TYPES = [
	"navigate",
	"post",
	"dismiss"
];
/**
* Parses an untrusted push message into a v1 payload, or null.
*
* Every field is read explicitly: an unknown field never reaches showNotification(),
* whatever the sender slipped into the parcel. Malformed optional fields are dropped
* rather than rejecting the whole payload, so a notification is still shown.
*/
function parsePayload(raw) {
	if (!isRecord(raw) || raw.v !== 1) return null;
	const payload = {
		v: 1,
		id: typeof raw.id === "string" ? raw.id : "",
		title: typeof raw.title === "string" ? raw.title : "",
		body: typeof raw.body === "string" ? raw.body : "",
		tag: typeof raw.tag === "string" ? raw.tag : "",
		silent: raw.silent === true,
		requireInteraction: raw.requireInteraction === true,
		renotify: raw.renotify === true,
		actions: parseActions(raw.actions),
		data: parseData(raw.data)
	};
	if (typeof raw.icon === "string" && raw.icon !== "") payload.icon = raw.icon;
	if (typeof raw.badge === "string" && raw.badge !== "") payload.badge = raw.badge;
	if (typeof raw.click === "string" && raw.click !== "") payload.click = raw.click;
	if (typeof raw.badgeCount === "number" && Number.isInteger(raw.badgeCount) && raw.badgeCount >= 0) payload.badgeCount = raw.badgeCount;
	return payload;
}
/**
* Builds the notification from an allowlist. A null payload (unreadable, or not v1)
* still yields a notification: a push that shows nothing is punished by browsers
* (Safari revokes the subscription), so the fallback title is displayed.
*/
function buildNotification(payload, config, origin) {
	const options = {};
	const data = {
		id: "",
		click: null,
		actions: {},
		app: {}
	};
	if (payload !== null) {
		if (payload.body !== "") options.body = payload.body;
		if (payload.tag !== "") {
			options.tag = payload.tag;
			if (payload.renotify) options.renotify = true;
		}
		if (payload.requireInteraction) options.requireInteraction = true;
		if (payload.silent) options.silent = true;
		const actions = [];
		for (const action of payload.actions) {
			actions.push({
				action: action.action,
				title: action.title
			});
			data.actions[action.action] = {
				type: action.type,
				url: action.url ?? null
			};
		}
		if (actions.length > 0) options.actions = actions;
		data.id = payload.id;
		data.click = payload.click ?? null;
		data.app = payload.data;
	}
	const icon = acceptAssetUrl(payload?.icon, origin, config.assetHosts) ?? nonEmpty(config.icon);
	const badge = acceptAssetUrl(payload?.badge, origin, config.assetHosts) ?? nonEmpty(config.badge);
	if (icon !== null) options.icon = icon;
	if (badge !== null) options.badge = badge;
	options.data = data;
	return {
		title: payload !== null && payload.title.trim() !== "" ? payload.title : config.fallbackTitle,
		options
	};
}
/**
* An image URL is displayed only when same-origin, or https on an allowed host. Anything
* else would let a payload make the device fetch an arbitrary URL (a tracking pixel).
*/
function acceptAssetUrl(raw, origin, assetHosts) {
	if (raw === void 0 || raw === "") return null;
	let url;
	try {
		url = new URL(raw, origin);
	} catch {
		return null;
	}
	if (url.origin === origin && (url.protocol === "https:" || url.protocol === "http:")) return url.pathname + url.search;
	if (url.protocol === "https:" && assetHosts.includes(url.hostname.toLowerCase()) && url.username === "" && url.password === "") return url.href;
	return null;
}
function parseActions(raw) {
	if (!Array.isArray(raw)) return [];
	const actions = [];
	const seen = /* @__PURE__ */ new Set();
	for (const item of raw) {
		if (actions.length >= 2) break;
		if (!isRecord(item)) continue;
		const { action, title, type, url } = item;
		if (typeof action !== "string" || !/^[a-z][a-z0-9_-]{0,31}$/.test(action) || seen.has(action)) continue;
		if (typeof title !== "string" || title === "") continue;
		if (typeof type !== "string" || !ACTION_TYPES.includes(type)) continue;
		const parsed = {
			action,
			title,
			type
		};
		if (typeof url === "string" && url !== "") parsed.url = url;
		seen.add(action);
		actions.push(parsed);
	}
	return actions;
}
function parseData(raw) {
	const data = {};
	if (!isRecord(raw)) return data;
	for (const [key, value] of Object.entries(raw)) {
		if (Object.keys(data).length >= 16) break;
		if (typeof value === "string" || typeof value === "boolean" || typeof value === "number" && Number.isFinite(value)) data[key] = value;
	}
	return data;
}
function nonEmpty(value) {
	return value === "" ? null : value;
}
//#endregion
//#region src/service-worker/state_store.ts
/**
* JSON documents in the worker's Cache Storage state cache.
*
* localStorage does not exist in a worker, so Cache Storage is the store. It is shared
* by ORIGIN, not by user: nothing stored here may identify what a previous user did
* (the click trail therefore never holds a path).
*
* Every method degrades instead of throwing: a full or unavailable cache must never
* break a push display or a click.
*/
var StateStore = class {
	caches;
	name;
	constructor(caches, name) {
		this.caches = caches;
		this.name = name;
	}
	async read(key) {
		try {
			const stored = await (await this.caches.open(this.name)).match(key);
			return stored ? await stored.json() : null;
		} catch {
			return null;
		}
	}
	async write(key, value) {
		try {
			await (await this.caches.open(this.name)).put(key, new Response(JSON.stringify(value), { headers: { "content-type": "application/json" } }));
		} catch {}
	}
	async remove(key) {
		try {
			return await (await this.caches.open(this.name)).delete(key);
		} catch {
			return false;
		}
	}
	/** Drops the whole state cache: the logout path. */
	async purge() {
		try {
			await this.caches.delete(this.name);
		} catch {}
	}
};
//#endregion
//#region src/service-worker/install.ts
/**
* Marks the installation ON the scope, under a registry symbol shared by every copy of
* this code. A module-local WeakMap is not enough: `importScripts('/web-push-sw.js')`
* run twice evaluates the IIFE twice, and an app worker may also bundle the ES build;
* each copy would get its own map and install every handler again.
*/
var INSTALLED = Symbol.for("romainmillan.web-push.installed");
/**
* Wires the push, notificationclick and message handlers on a worker scope.
*
* Idempotent per scope, across bundles: a second call (a script imported twice, or
* the ES build next to the prebuilt worker) returns the first
* installation instead of registering every handler twice, which would display every
* notification twice.
*/
function installWebPush(scope, options = {}) {
	const existing = scope[INSTALLED];
	if (existing) return existing;
	const config = normalizeServiceWorkerConfig(options.config);
	const plugins = [...options.plugins ?? []];
	const context = {
		scope,
		config,
		state: new StateStore(scope.caches, config.stateCache)
	};
	const result = {
		config,
		context
	};
	Object.defineProperty(scope, INSTALLED, {
		value: result,
		configurable: true
	});
	if (options.manageLifecycle ?? true) scope.addEventListener("install", () => {
		Promise.resolve(scope.skipWaiting()).catch(() => {});
	});
	scope.addEventListener("activate", (event) => {
		const claim = options.manageLifecycle ?? true ? scope.clients.claim().catch(() => {}) : Promise.resolve();
		event.waitUntil(Promise.all([claim, notify(plugins, (plugin) => plugin.onActivate?.(context))]));
	});
	scope.addEventListener("push", (event) => {
		event.waitUntil(handlePush(event, context, plugins));
	});
	scope.addEventListener("notificationclick", (event) => {
		event.notification.close();
		event.waitUntil(handleClick(event, context, plugins));
	});
	scope.addEventListener("message", (event) => {
		const message = event.data;
		if (!isRecord(message) || typeof message.type !== "string") return;
		const reply = replyTo(event);
		const typed = message;
		event.waitUntil(notify(plugins, (plugin) => plugin.onMessage?.(typed, reply, context)));
	});
	return result;
}
/**
* The plugins NEVER condition the display: a rejection reaching waitUntil() makes iOS
* show its generic "this site has been updated in the background" notification, or
* nothing at all.
*/
async function handlePush(event, context, plugins) {
	const payload = readPayload(event);
	const { title, options } = buildNotification(payload, context.config, context.scope.location.origin);
	await Promise.all([context.scope.registration.showNotification(title, options), notify(plugins, (plugin) => plugin.onPush?.(payload, context))]);
}
function readPayload(event) {
	if (!event.data) return null;
	try {
		return parsePayload(event.data.json());
	} catch {
		return null;
	}
}
/**
* The order IS the design: resolve the action, validate the destination, write the
* intent BEFORE any attempt (covers a worker killed mid-flight and a frozen page), then
* try to land.
*/
async function handleClick(event, context, plugins) {
	const data = readNotificationData(event.notification.data);
	const action = event.action !== "" ? data.actions[event.action] : void 0;
	if (action?.type === "dismiss") return;
	if (action?.type === "post") {
		await handlePost(action.url, context, plugins);
		return;
	}
	await navigateTo(action?.type === "navigate" ? action.url : data.click, context, plugins);
}
/**
* A `post` action calls a URL carrying its own authorization (signed), same-origin only:
* the worker holds the session cookies of the origin, it must not send them elsewhere.
*/
async function handlePost(url, context, plugins) {
	const origin = context.scope.location.origin;
	let target = null;
	try {
		target = url === null ? null : new URL(url, origin);
	} catch {
		target = null;
	}
	if (target === null || target.origin !== origin) {
		await record(plugins, context, {
			outcome: "rejected",
			verdict: target === null ? "rejected-parse" : "rejected-cross-origin",
			clientCount: null,
			intent: "not-applicable"
		});
		return;
	}
	let outcome = "post-failed";
	try {
		outcome = (await context.scope.fetch(target.href, {
			method: "POST",
			credentials: "same-origin"
		})).ok ? "post-sent" : "post-failed";
	} catch {
		outcome = "post-failed";
	}
	await record(plugins, context, {
		outcome,
		verdict: "accepted",
		clientCount: null,
		intent: "not-applicable"
	});
}
async function navigateTo(raw, context, plugins) {
	const { scope, config } = context;
	const { clickPath, verdict } = resolveClickPath(raw, scope.registration.scope, scope.location.origin, config.clickPrefixes);
	if (verdict !== "accepted") {
		await record(plugins, context, {
			outcome: verdict === "no-destination" ? "focus-only" : "rejected",
			verdict,
			clientCount: null,
			intent: "not-applicable"
		});
		await focusOrOpen(scope, "/");
		return;
	}
	const intent = await beforeNavigate(plugins, context, clickPath);
	const clients = await matchWindows(scope);
	const candidate = bestCandidate(clients, clickPath, config.clickPrefixes);
	if (!candidate) {
		await scope.clients.openWindow(clickPath).catch(() => null);
		await record(plugins, context, {
			outcome: "new-window",
			verdict,
			clientCount: clients.length,
			intent
		});
		return;
	}
	for (const attempt of ATTEMPTS) {
		let outcome = null;
		try {
			outcome = await attempt(candidate, clickPath);
		} catch {
			outcome = null;
		}
		if (outcome) {
			await notify(plugins, (plugin) => plugin.onNavigated?.(clickPath, context));
			await record(plugins, context, {
				outcome,
				verdict,
				clientCount: clients.length,
				intent
			});
			return;
		}
	}
	try {
		candidate.postMessage({ type: MessageType.claimNavigationIntent });
	} catch {}
	await record(plugins, context, {
		outcome: "nudged",
		verdict,
		clientCount: clients.length,
		intent
	});
}
/** Chain of responsibility: adding a way to land is one more entry. */
var ATTEMPTS = [async function focusIfAlreadyThere(client, clickPath) {
	if (!samePath(client.url, clickPath)) return null;
	await tryFocus(client);
	return "already-there";
}, async function navigateOwnWindow(client, clickPath) {
	const before = client.url;
	await tryFocus(client);
	if (typeof client.navigate !== "function") return null;
	return ((await client.navigate(clickPath))?.url ?? client.url) !== before ? "navigated" : null;
}];
/**
* The ranking order matters: includeUncontrolled may put a non-navigable client first,
* so taking the first focusable one is not enough.
*/
function bestCandidate(clients, clickPath, prefixes) {
	return clients.map((client) => ({
		client,
		rank: rankOf(client, clickPath, prefixes)
	})).sort((a, b) => a.rank - b.rank)[0]?.client ?? null;
}
function rankOf(client, clickPath, prefixes) {
	if (samePath(client.url, clickPath)) return 0;
	const pathname = pathnameOf(client.url);
	if (pathname !== "/" && pathname !== "" && isAllowedPathname(pathname, prefixes)) return 1;
	if (client.focused) return 2;
	if (client.visibilityState === "visible") return 3;
	return 4;
}
/**
* The failure path, the one that must hold best: a focus() rejection must not skip
* openWindow(), or the click leads nowhere.
*/
async function focusOrOpen(scope, path) {
	const first = (await matchWindows(scope))[0];
	if (first && await tryFocus(first)) return;
	await scope.clients.openWindow(path).catch(() => null);
}
async function tryFocus(client) {
	if (typeof client.focus !== "function") return false;
	try {
		await client.focus();
		return true;
	} catch {
		return false;
	}
}
async function matchWindows(scope) {
	try {
		return await scope.clients.matchAll({
			type: "window",
			includeUncontrolled: true
		});
	} catch {
		return [];
	}
}
async function beforeNavigate(plugins, context, clickPath) {
	let status = "disabled";
	for (const plugin of plugins) {
		if (!plugin.onBeforeNavigate) continue;
		try {
			const result = await plugin.onBeforeNavigate(clickPath, context);
			if (result && status === "disabled") status = result;
		} catch {}
	}
	return status;
}
function record(plugins, context, entry) {
	return notify(plugins, (plugin) => plugin.onClick?.(entry, context));
}
/** Runs one hook on every plugin, sequentially, swallowing every failure. */
async function notify(plugins, hook) {
	for (const plugin of plugins) try {
		await hook(plugin);
	} catch {}
}
function replyTo(event) {
	return (message) => {
		const port = event.ports?.[0];
		if (port) {
			port.postMessage(message);
			return;
		}
		event.source?.postMessage(message);
	};
}
function readNotificationData(raw) {
	const data = isRecord(raw) ? raw : {};
	const actions = {};
	if (isRecord(data.actions)) {
		for (const [name, value] of Object.entries(data.actions)) if (isRecord(value) && typeof value.type === "string" && ACTION_TYPES.includes(value.type)) actions[name] = {
			type: value.type,
			url: typeof value.url === "string" ? value.url : null
		};
	}
	return {
		click: typeof data.click === "string" ? data.click : null,
		actions
	};
}
function pathnameOf(rawUrl) {
	try {
		return new URL(rawUrl).pathname;
	} catch {
		return "";
	}
}
function samePath(rawUrl, clickPath) {
	try {
		const url = new URL(rawUrl);
		return url.pathname + url.search === clickPath;
	} catch {
		return false;
	}
}
//#endregion
//#region src/service-worker/plugins/badge.ts
/**
* Puts the payload's `badgeCount` on the app icon (Badging API, installed PWAs).
*
* The count was computed when the notification was sent: the worker cannot read the
* current badge nor ask the server. Any drift is corrected by the page next time.
*/
function badge() {
	return {
		name: "badge",
		async onPush(payload, { scope }) {
			const count = payload?.badgeCount;
			const navigator = scope.navigator;
			if (typeof count !== "number" || !navigator) return;
			if (count > 0 && typeof navigator.setAppBadge === "function") {
				await navigator.setAppBadge(count);
				return;
			}
			if (count === 0 && typeof navigator.clearAppBadge === "function") await navigator.clearAppBadge();
		}
	};
}
//#endregion
//#region src/service-worker/plugins/client_state.ts
/**
* Remembers which client state marker the pages of this device announce, and forgets
* everything at logout.
*
* The marker is announced by the page (`client-state` message, from the
* <meta name="web-push-config"> the server rendered for the signed-in user). An
* anonymous page announces no marker, which ERASES the stored one: an intent written
* afterwards is not attributed to the previous user.
*
* `forget-client-state` purges the whole state cache. Clear-Site-Data is not an
* alternative: its "storage" directive unregisters the worker and destroys the push
* subscription on Chrome.
*/
function clientState() {
	return {
		name: "client-state",
		async onMessage(message, _reply, { state }) {
			if (message.type === MessageType.clientState) {
				const marker = parseMarker(message.marker);
				if (marker === null) await state.remove(MARKER_KEY);
				else await state.write(MARKER_KEY, { marker });
				return;
			}
			if (message.type === MessageType.forgetClientState) await state.purge();
		}
	};
}
async function readStoredMarker(state) {
	return parseMarker((await state.read(MARKER_KEY))?.marker);
}
//#endregion
//#region src/service-worker/plugins/diagnostics.ts
/**
* Answers `worker-ping` with the running version, and keeps a trail of the last clicks.
*
* There are no devtools on an iPhone without a Mac: "is the new worker running?" and
* "what did my click do?" are unanswerable without this.
*
* The trail NEVER stores a path: it lives in a store shared by origin, so user B would
* read what user A clicked. Diagnostic only, never a source of truth.
*/
function diagnostics() {
	return {
		name: "diagnostics",
		async onClick(record, { state }) {
			const trail = await readTrail(state);
			trail.unshift({
				at: Date.now(),
				outcome: record.outcome,
				verdict: record.verdict,
				clientCount: record.clientCount,
				intent: record.intent
			});
			await state.write(TRAIL_KEY, trail.slice(0, 10));
		},
		async onMessage(message, reply, { state }) {
			if (message.type === MessageType.workerPing) {
				reply({
					type: MessageType.workerPong,
					version: "1"
				});
				return;
			}
			if (message.type === MessageType.readClickTrail) reply({
				type: MessageType.clickTrail,
				trail: await readTrail(state)
			});
		}
	};
}
async function readTrail(state) {
	const trail = await state.read(TRAIL_KEY);
	return Array.isArray(trail) ? trail : [];
}
//#endregion
//#region src/service-worker/plugins/navigation_intent.ts
/**
* Leaves a navigation intent in Cache Storage for a page to claim.
*
* It solves the one problem the worker's direct chain cannot: an iOS page frozen in the
* background thaws at an unpredictable moment, so landing is EVENTUALLY CONSISTENT. No
* timer anywhere: focus()/openWindow() spend the transient user activation that
* notificationclick grants.
*
* The intent carries the marker announced by the pages (clientState plugin). Without
* one, no intent is written — and the trail says so (`skipped-no-marker`).
*/
function navigationIntent() {
	return {
		name: "navigation-intent",
		async onBeforeNavigate(clickPath, { state }) {
			const marker = await readStoredMarker(state);
			if (marker === null) return "skipped-no-marker";
			const intent = {
				clickPath,
				at: Date.now(),
				marker
			};
			await state.write(INTENT_KEY, intent);
			return "written";
		},
		async onNavigated(_clickPath, { state }) {
			await state.remove(INTENT_KEY);
		},
		async onPush(_payload, { state }) {
			await dropExpiredIntent(state);
		},
		async onActivate({ state }) {
			await dropExpiredIntent(state);
		}
	};
}
/** Also destroys a MALFORMED intent, which nobody could claim anymore. */
async function dropExpiredIntent(state, now = Date.now()) {
	const intent = await state.read(INTENT_KEY);
	if (intent === null) return;
	if (typeof intent.at !== "number" || now - intent.at > 3e5 || parseMarker(intent.marker) === null) await state.remove(INTENT_KEY);
}
//#endregion
export { DEFAULT_FALLBACK_TITLE, DEFAULT_STATE_CACHE, INTENT_KEY, INTENT_MAX_AGE_MS, MARKER_KEY, MessageType, PAYLOAD_VERSION, SW_VERSION, StateStore, TRAIL_KEY, acceptAssetUrl, badge, buildNotification, clientState, diagnostics, installWebPush, navigationIntent, normalizeServiceWorkerConfig, parsePayload };
