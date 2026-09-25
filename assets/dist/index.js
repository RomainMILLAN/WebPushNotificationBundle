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
/** `{ clickPath, at, marker }`, written by the worker before navigating, claimed by a page. */
var INTENT_KEY = "/__web-push/navigation-intent";
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
//#region src/client/config.ts
var CONFIG_META_NAME = "web-push-config";
/**
* Reads the config from the LIVE DOM, on every call: Turbo replaces the <head>.
* Returns null when the meta is absent or its content is not a usable config.
*/
function readPageConfig(doc = document) {
	const content = doc.querySelector(`meta[name="${CONFIG_META_NAME}"]`)?.getAttribute("content");
	if (!content) return null;
	let raw;
	try {
		raw = JSON.parse(content);
	} catch {
		return null;
	}
	if (typeof raw !== "object" || raw === null || Array.isArray(raw)) return null;
	const source = raw;
	const publicKey = stringOf(source.publicKey);
	const serviceWorker = stringOf(source.serviceWorker);
	const subscribe = stringOf(source.subscribe);
	const unsubscribe = stringOf(source.unsubscribe);
	if (publicKey === "" || serviceWorker === "" || subscribe === "" || unsubscribe === "") return null;
	return {
		publicKey,
		serviceWorker,
		subscribe,
		unsubscribe,
		clickPrefixes: Array.isArray(source.clickPrefixes) ? source.clickPrefixes.filter((prefix) => typeof prefix === "string" && prefix.startsWith("/") && !prefix.startsWith("//")) : [],
		csrfHeader: stringOf(source.csrfHeader),
		csrfToken: stringOf(source.csrfToken),
		clientState: parseMarker(source.clientState) ?? "",
		stateCache: stringOf(source.stateCache) || "web-push-state"
	};
}
function stringOf(value) {
	return typeof value === "string" ? value : "";
}
//#endregion
//#region src/client/errors.ts
var WebPushError = class extends Error {
	code;
	constructor(code, message, options) {
		super(message, options);
		this.code = code;
		this.name = "WebPushError";
	}
};
/** The browser lacks Service Worker, Push API or Notification support. */
var WebPushUnsupportedError = class extends WebPushError {
	constructor(message = "Web Push is not supported by this browser.") {
		super("unsupported", message);
		this.name = "WebPushUnsupportedError";
	}
};
/** The user refused ('denied') or dismissed ('default') the permission prompt. */
var WebPushPermissionError = class extends WebPushError {
	permission;
	constructor(permission) {
		super(permission === "denied" ? "permission-denied" : "permission-dismissed", `Notification permission is "${permission}".`);
		this.permission = permission;
		this.name = "WebPushPermissionError";
	}
};
/** The server answered a non-2xx status to a subscription request. */
var WebPushRequestError = class extends WebPushError {
	status;
	constructor(status) {
		super(codeForStatus(status), `The subscription endpoint answered HTTP ${status}.`);
		this.status = status;
		this.name = "WebPushRequestError";
	}
};
function codeForStatus(status) {
	switch (status) {
		case 403: return "anonymous-disabled";
		case 400: return "invalid-request";
		case 413: return "payload-too-large";
		case 415: return "unsupported-media-type";
		case 503: return "service-unavailable";
		default: return "http-error";
	}
}
//#endregion
//#region src/client/web_push_client.ts
/** Re-POST an existing subscription at most this often (keeps `lastSeen` fresh server side). */
var SYNC_INTERVAL_MS = 864e5;
var SYNC_STORAGE_KEY = "web-push:last-sync";
/** subscribe() may wait for a first install of the worker. */
var SUBSCRIBE_READY_TIMEOUT_MS = 15e3;
function browserEnvironment() {
	const scope = globalThis;
	return {
		serviceWorker: scope.navigator && "serviceWorker" in scope.navigator ? scope.navigator.serviceWorker : void 0,
		notification: scope.Notification,
		pushManager: scope.PushManager,
		fetch: (input, init) => globalThis.fetch(input, init),
		storage: () => {
			try {
				return globalThis.localStorage ?? null;
			} catch {
				return null;
			}
		},
		now: () => Date.now()
	};
}
/**
* The page side of Web Push: permission, subscription, and its registration with the
* server. It never registers the worker itself: startWebPush() is the single
* registration point, two would race for the same scope.
*/
var WebPushClient = class WebPushClient {
	config;
	env;
	constructor(config, env = {}) {
		this.config = config;
		this.env = {
			...browserEnvironment(),
			...env
		};
	}
	/** Throws when the page carries no <meta name="web-push-config">. */
	static fromDocument(doc = document, env = {}) {
		const config = readPageConfig(doc);
		if (config === null) throw new WebPushError("unsupported", "The page has no usable <meta name=\"web-push-config\">.");
		return new WebPushClient(config, env);
	}
	isSupported() {
		return this.env.serviceWorker !== void 0 && this.env.pushManager !== void 0 && this.env.notification !== void 0;
	}
	permission() {
		return this.env.notification?.permission ?? "unsupported";
	}
	/**
	* Asks the permission and subscribes this device.
	*
	* MUST be called synchronously from a user gesture (click handler): iOS refuses
	* the permission prompt otherwise, and requestPermission() is deliberately the
	* first asynchronous step so that the transient activation is still valid.
	*/
	async subscribe() {
		if (!this.isSupported()) throw new WebPushUnsupportedError();
		const permission = await this.requestPermission();
		if (permission !== "granted") throw new WebPushPermissionError(permission);
		const registration = await this.readyRegistration(SUBSCRIBE_READY_TIMEOUT_MS);
		if (registration === null) throw new WebPushError("no-service-worker", "No active service worker: was startWebPush() called on this page?");
		let subscription;
		try {
			subscription = await this.ensureSubscription(registration);
		} catch (error) {
			throw new WebPushError("subscription-failed", "The browser refused to create a push subscription.", { cause: error });
		}
		await this.post(this.config.subscribe, this.subscriptionBody(subscription), false);
		this.rememberSync();
		return subscription;
	}
	/**
	* Unregisters the subscription from the server, then from the browser — the latter
	* whatever the server answered. Never throws. Resolves false when there was nothing
	* to unsubscribe.
	*/
	async unsubscribe() {
		this.forgetSync();
		const subscription = await this.getSubscription();
		if (subscription === null) return false;
		await this.postUnsubscribe(subscription);
		try {
			await subscription.unsubscribe();
		} catch {}
		return true;
	}
	/**
	* Keeps an existing subscription known to the server: re-POSTed at most once per
	* 24 h (or at once when the signed-in user changed), and recreated when the VAPID
	* key was rotated. Never throws.
	*/
	async sync() {
		if (!this.isSupported()) return "unsupported";
		if (this.permission() !== "granted") return "not-granted";
		const registration = await this.existingRegistration();
		if (registration === null) return "no-service-worker";
		try {
			const existing = await registration.pushManager.getSubscription();
			if (existing === null) return "no-subscription";
			const key = urlBase64ToUint8Array(this.config.publicKey);
			if (!sameApplicationServerKey(existing, key)) {
				await this.postUnsubscribe(existing);
				await existing.unsubscribe().catch(() => false);
				const fresh = await registration.pushManager.subscribe({
					userVisibleOnly: true,
					applicationServerKey: key
				});
				await this.post(this.config.subscribe, this.subscriptionBody(fresh), false);
				this.rememberSync();
				return "rotated";
			}
			if (!this.isSyncDue()) return "fresh";
			await this.post(this.config.subscribe, this.subscriptionBody(existing), false);
			this.rememberSync();
			return "synced";
		} catch {
			return "failed";
		}
	}
	/** The body POSTed to the subscribe endpoint. */
	subscriptionBody(subscription) {
		return {
			...subscription.toJSON(),
			contentEncoding: this.contentEncoding()
		};
	}
	contentEncoding() {
		const supported = this.env.pushManager?.supportedContentEncodings;
		return Array.isArray(supported) && supported.includes("aes128gcm") ? "aes128gcm" : "aesgcm";
	}
	/** Supports both the promise form and the legacy callback form (old Safari). */
	requestPermission() {
		const notification = this.env.notification;
		return new Promise((resolve) => {
			let returned;
			try {
				returned = notification.requestPermission(resolve);
			} catch {
				resolve(notification.permission);
				return;
			}
			if (returned && typeof returned.then === "function") returned.then(resolve, () => resolve(notification.permission));
		});
	}
	async ensureSubscription(registration) {
		const key = urlBase64ToUint8Array(this.config.publicKey);
		const existing = await registration.pushManager.getSubscription();
		if (existing !== null) {
			if (sameApplicationServerKey(existing, key)) return existing;
			await this.postUnsubscribe(existing);
			await existing.unsubscribe().catch(() => false);
		}
		return registration.pushManager.subscribe({
			userVisibleOnly: true,
			applicationServerKey: key
		});
	}
	/** This device's current subscription, without asking anything. Never throws. */
	async getSubscription() {
		if (this.env.serviceWorker === void 0 || this.env.pushManager === void 0) return null;
		const registration = await this.existingRegistration();
		if (registration === null) return null;
		try {
			return await registration.pushManager.getSubscription();
		} catch {
			return null;
		}
	}
	async postUnsubscribe(subscription) {
		const json = subscription.toJSON();
		try {
			await this.post(this.config.unsubscribe, {
				endpoint: subscription.endpoint,
				keys: { auth: json.keys?.auth ?? "" }
			}, true);
		} catch {}
	}
	async post(url, body, keepalive) {
		const headers = { "Content-Type": "application/json" };
		if (this.config.csrfHeader !== "") headers[this.config.csrfHeader] = this.config.csrfToken;
		const init = {
			method: "POST",
			credentials: "same-origin",
			headers,
			body: JSON.stringify(body)
		};
		if (keepalive) init.keepalive = true;
		let response;
		try {
			response = await this.env.fetch(url, init);
		} catch (error) {
			throw new WebPushError("network", `Could not reach ${url}.`, { cause: error });
		}
		if (!response.ok) throw new WebPushRequestError(response.status);
	}
	/** `ready` never rejects and never resolves without an active worker: it is bounded. */
	async readyRegistration(timeoutMs) {
		const container = this.env.serviceWorker;
		if (container === void 0) return null;
		let timer;
		const timeout = new Promise((resolve) => {
			timer = setTimeout(() => resolve(null), timeoutMs);
		});
		try {
			return await Promise.race([container.ready.catch(() => null), timeout]);
		} finally {
			clearTimeout(timer);
		}
	}
	/** Resolves at once, active worker or not: enough to read a subscription. */
	async existingRegistration() {
		try {
			return await this.env.serviceWorker?.getRegistration("/") ?? null;
		} catch {
			return null;
		}
	}
	isSyncDue() {
		const stored = this.readSync();
		return stored === null || stored.clientState !== this.config.clientState || this.env.now() - stored.at >= 864e5;
	}
	readSync() {
		try {
			const raw = this.env.storage()?.getItem(SYNC_STORAGE_KEY);
			const parsed = raw ? JSON.parse(raw) : null;
			if (typeof parsed === "object" && parsed !== null && typeof parsed.at === "number" && typeof parsed.clientState === "string") return parsed;
		} catch {}
		return null;
	}
	rememberSync() {
		try {
			this.env.storage()?.setItem(SYNC_STORAGE_KEY, JSON.stringify({
				at: this.env.now(),
				clientState: this.config.clientState
			}));
		} catch {}
	}
	forgetSync() {
		try {
			this.env.storage()?.removeItem(SYNC_STORAGE_KEY);
		} catch {}
	}
};
/** Converts a base64url VAPID key to the Uint8Array Safari requires. */
function urlBase64ToUint8Array(base64String) {
	const base64 = (base64String + "=".repeat((4 - base64String.length % 4) % 4)).replace(/-/g, "+").replace(/_/g, "/");
	const raw = globalThis.atob(base64);
	const key = new Uint8Array(new ArrayBuffer(raw.length));
	for (let i = 0; i < raw.length; i += 1) key[i] = raw.charCodeAt(i);
	return key;
}
/** Compares the key a subscription was created with against the configured one. */
function sameApplicationServerKey(subscription, applicationServerKey) {
	const current = subscription.options?.applicationServerKey;
	if (!current) return false;
	const currentBytes = new Uint8Array(current);
	if (currentBytes.length !== applicationServerKey.length) return false;
	return currentBytes.every((byte, i) => byte === applicationServerKey[i]);
}
//#endregion
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
//#region src/page/navigation_intent.ts
/**
* The marker the server rendered for this page, re-read from the LIVE DOM on every call.
* A Turbo preview shows a snapshot <head>, possibly a stale marker: abstain.
*/
function readRenderedMarker(doc = document) {
	if (doc.documentElement.hasAttribute("data-turbo-preview")) return null;
	return parseMarker(readPageConfig(doc)?.clientState);
}
/**
* The page's own customs check, twin of the worker's. The value is read back from
* Cache Storage, writable by any script of the origin, and location.assign() of a
* `javascript:` URL is an execution sink: the page has its own border.
*/
function isSafeClickPath(clickPath, origin, prefixes) {
	if (typeof clickPath !== "string" || clickPath === "") return false;
	return resolveClickPath(clickPath, origin, origin, prefixes).verdict === "accepted";
}
/**
* Claims the navigation intent left by the worker. THE ORDER OF THE DECISION IS THE POINT:
* match → compare markers → delete → act only if delete() returned true. Two tabs may
* both succeed the match; whoever wins the delete owns the click.
*/
async function claimNavigationIntent(options = {}) {
	const doc = options.doc ?? document;
	const view = doc.defaultView;
	const storage = options.caches ?? (view && "caches" in view ? view.caches : void 0);
	if (!storage || !view) return "unsupported";
	const config = readPageConfig(doc);
	const cache = await storage.open(options.stateCache ?? config?.stateCache ?? "web-push-state");
	const stored = await cache.match(INTENT_KEY);
	if (!stored) return "no-intent";
	const intent = await stored.json().catch(() => null);
	const owner = parseMarker(intent?.marker);
	const rendered = readRenderedMarker(doc);
	if (owner === null || rendered === null) return "refused";
	if (owner !== rendered) {
		await cache.delete(INTENT_KEY);
		return "foreign";
	}
	const now = options.now?.() ?? Date.now();
	if (typeof intent?.at !== "number" || now - intent.at > 3e5) {
		await cache.delete(INTENT_KEY);
		return "expired";
	}
	if (doc.visibilityState === "hidden") return "hidden";
	const origin = view.location.origin;
	if (!isSafeClickPath(intent.clickPath, origin, config?.clickPrefixes ?? [])) {
		await cache.delete(INTENT_KEY);
		return "unsafe";
	}
	const clickPath = intent.clickPath;
	if (view.location.pathname + view.location.search === clickPath) {
		await cache.delete(INTENT_KEY);
		return "already-there";
	}
	if (!await cache.delete("/__web-push/navigation-intent")) return "lost-race";
	(options.navigate ?? ((path) => view.location.assign(path)))(clickPath);
	return "navigated";
}
//#endregion
//#region src/page/start.ts
/** An installed iOS PWA almost never navigates: ask for a fresh script when coming back, at most this often. */
var UPDATE_THROTTLE_MS = 6e4;
var DEFAULT_LOGOUT_SELECTOR = "form[data-web-push-logout]";
/** A logout must never be held back longer than this by the unsubscription. */
var DEFAULT_LOGOUT_TIMEOUT_MS = 1e3;
/** Pure decision, testable without mocking Date nor observing a request. */
function shouldCheckForUpdate(lastCheckAt, now) {
	return lastCheckAt === null || now - lastCheckAt >= 6e4;
}
/**
* Returns `candidate` normalized to path + query when it is a root-relative path of
* `origin`, else null. A protocol-relative ('//host') or backslash form ('/\\host') is
* refused before resolution: browsers read both as another host.
*/
function sameOriginPath(candidate, origin) {
	if (typeof candidate !== "string" || !candidate.startsWith("/") || /^\/[\/\\]/.test(candidate) || /[\u0000-\u001f\\]/.test(candidate)) return null;
	let resolved;
	try {
		resolved = new URL(candidate, origin);
	} catch {
		return null;
	}
	return resolved.origin === origin ? `${resolved.pathname}${resolved.search}` : null;
}
var started = /* @__PURE__ */ new WeakMap();
/**
* Starts Web Push on a page: the SINGLE registration point of the worker, the claim of
* navigation intents, and the logout hook. Idempotent per document. Returns null when
* the page carries no <meta name="web-push-config">.
*/
function startWebPush(doc = document, options = {}) {
	const existing = started.get(doc);
	if (existing) return existing;
	const config = readPageConfig(doc);
	const view = doc.defaultView;
	if (config === null || view === null) return null;
	const client = options.client ?? new WebPushClient(config);
	const workerUrl = resolveWorkerUrl(options.serviceWorkerUrl, config.serviceWorker, view.location.origin);
	const container = options.serviceWorker ?? ("serviceWorker" in view.navigator ? view.navigator.serviceWorker : void 0);
	const cleanups = [];
	const listen = (target, type, listener, capture = false) => {
		target.addEventListener(type, listener, capture);
		cleanups.push(() => target.removeEventListener(type, listener, capture));
	};
	let knownRegistration = null;
	let lastUpdateCheckAt = null;
	let pendingClaim = null;
	const activeWorker = () => container?.controller ?? knownRegistration?.active ?? null;
	/** One sequence at a time: pageshow, focus and visibilitychange fire together. */
	const claim = () => {
		if (pendingClaim) return pendingClaim;
		pendingClaim = claimNavigationIntent({
			doc,
			navigate: options.navigate
		}).catch(() => "unsupported").finally(() => {
			pendingClaim = null;
		});
		return pendingClaim;
	};
	const sendClientState = () => {
		if (doc.documentElement.hasAttribute("data-turbo-preview")) return;
		try {
			activeWorker()?.postMessage({
				type: MessageType.clientState,
				marker: readRenderedMarker(doc)
			});
		} catch {}
	};
	const forgetClientState = () => {
		try {
			activeWorker()?.postMessage({ type: MessageType.forgetClientState });
		} catch {}
	};
	const checkForUpdate = () => {
		const now = Date.now();
		if (knownRegistration === null || !shouldCheckForUpdate(lastUpdateCheckAt, now)) return;
		lastUpdateCheckAt = now;
		knownRegistration.update().catch(() => {});
	};
	const registration = container === void 0 ? Promise.resolve(null) : container.register(workerUrl, {
		scope: "/",
		updateViaCache: "none"
	}).then((registered) => {
		knownRegistration = registered;
		lastUpdateCheckAt = Date.now();
		sendClientState();
		if (options.sync ?? true) client.sync();
		return registered;
	}).catch((error) => {
		console.warn("[web-push] Service worker registration failed:", error);
		return null;
	});
	if (container !== void 0) {
		listen(container, "message", ((event) => {
			if (event.data && event.data.type === MessageType.claimNavigationIntent) claim();
		}));
		container.startMessages();
		listen(container, "controllerchange", () => sendClientState());
	}
	listen(view, "pageshow", () => {
		claim();
	});
	listen(view, "focus", () => {
		claim();
	});
	listen(doc, "turbo:load", () => {
		sendClientState();
		claim();
	});
	listen(doc, "visibilitychange", () => {
		if (doc.visibilityState !== "visible") return;
		claim();
		sendClientState();
		checkForUpdate();
	});
	installLogoutHook(doc, listen, {
		selector: options.logoutSelector ?? "form[data-web-push-logout]",
		timeoutMs: options.logoutTimeoutMs ?? 1e3,
		unsubscribe: options.unsubscribeOnLogout ?? true ? () => client.unsubscribe() : null,
		forget: forgetClientState
	});
	claim();
	const page = {
		client,
		registration,
		claim,
		forgetClientState,
		stop: () => {
			cleanups.splice(0).forEach((cleanup) => cleanup());
			started.delete(doc);
		}
	};
	started.set(doc, page);
	return page;
}
function resolveWorkerUrl(override, fromMeta, origin) {
	if (override === void 0) return fromMeta;
	const path = sameOriginPath(override, origin);
	if (path === null) {
		console.warn("[web-push] Ignoring serviceWorkerUrl: not a same-origin path.", override);
		return fromMeta;
	}
	return path;
}
/**
* Delegated on the document, on `submit`: any logout form marked with the selector is
* covered, and no route path is compared here.
*
* With unsubscription, the submit is held while the session is still valid (the
* unsubscribe endpoint needs it), bounded by timeoutMs, then replayed. If this code
* never runs, the form still logs out: the purge is a bonus, never a gate.
*/
function installLogoutHook(doc, listen, hook) {
	const released = /* @__PURE__ */ new WeakSet();
	const pending = /* @__PURE__ */ new WeakSet();
	listen(doc, "submit", ((event) => {
		const form = event.target?.closest?.(hook.selector);
		if (!form) return;
		if (hook.unsubscribe === null || released.has(form) || event.defaultPrevented) {
			released.delete(form);
			hook.forget();
			return;
		}
		event.preventDefault();
		if (pending.has(form)) return;
		pending.add(form);
		const submitter = event.submitter;
		withTimeout(hook.unsubscribe(), hook.timeoutMs).finally(() => {
			pending.delete(form);
			released.add(form);
			if (typeof form.requestSubmit === "function") form.requestSubmit(submitter instanceof HTMLElement && submitter.closest("form") === form ? submitter : void 0);
			else {
				released.delete(form);
				hook.forget();
				HTMLFormElement.prototype.submit.call(form);
			}
		});
	}), true);
}
function withTimeout(promise, timeoutMs) {
	return new Promise((resolve) => {
		const timer = setTimeout(resolve, timeoutMs);
		promise.catch(() => {}).finally(() => {
			clearTimeout(timer);
			resolve();
		});
	});
}
//#endregion
//#region src/page/diagnostics.ts
/** Local patience, not a contract constant: the worker has no symmetric timeout. */
var WORKER_PING_TIMEOUT_MS = 2e3;
/** Bounds `serviceWorker.ready`, which never settles without an active registration. */
var WORKER_READY_TIMEOUT_MS = 300;
/**
* Ping/pong with the active worker: without it there is no way to know whether the new
* worker actually runs on a device — and no devtools on an iPhone without a Mac.
*/
async function readWorkerDiagnostics(container = defaultContainer()) {
	const reply = await askWorker(container, { type: MessageType.workerPing }, MessageType.workerPong);
	const version = typeof reply?.version === "string" && /^[0-9A-Za-z.-]{1,32}$/.test(reply.version) ? reply.version : null;
	return {
		version,
		expectedVersion: "1",
		upToDate: version === "1"
	};
}
/** The last clicks the worker recorded, newest first. Diagnostic only, never a source of truth. */
async function readClickTrail(container = defaultContainer()) {
	const reply = await askWorker(container, { type: MessageType.readClickTrail }, MessageType.clickTrail);
	return Array.isArray(reply?.trail) ? reply.trail : [];
}
async function askWorker(container, message, expectedType) {
	const worker = await activeWorker(container);
	if (!worker) return null;
	return new Promise((resolve) => {
		const channel = new MessageChannel();
		const timer = setTimeout(() => {
			channel.port1.close();
			resolve(null);
		}, WORKER_PING_TIMEOUT_MS);
		channel.port1.onmessage = (event) => {
			clearTimeout(timer);
			channel.port1.close();
			resolve(event.data && event.data.type === expectedType ? event.data : null);
		};
		try {
			worker.postMessage(message, [channel.port2]);
		} catch {
			clearTimeout(timer);
			resolve(null);
		}
	});
}
async function activeWorker(container) {
	if (!container) return null;
	if (container.controller) return container.controller;
	let timer;
	const timeout = new Promise((resolve) => {
		timer = setTimeout(() => resolve(null), WORKER_READY_TIMEOUT_MS);
	});
	try {
		return (await Promise.race([container.ready.catch(() => null), timeout]))?.active ?? null;
	} finally {
		clearTimeout(timer);
	}
}
function defaultContainer() {
	return typeof navigator !== "undefined" && "serviceWorker" in navigator ? navigator.serviceWorker : void 0;
}
//#endregion
export { CONFIG_META_NAME, DEFAULT_LOGOUT_SELECTOR, DEFAULT_LOGOUT_TIMEOUT_MS, INTENT_MAX_AGE_MS, MessageType, SW_VERSION, SYNC_INTERVAL_MS, UPDATE_THROTTLE_MS, WebPushClient, WebPushError, WebPushPermissionError, WebPushRequestError, WebPushUnsupportedError, browserEnvironment, claimNavigationIntent, isSafeClickPath, parseMarker, readClickTrail, readPageConfig, readRenderedMarker, readWorkerDiagnostics, sameApplicationServerKey, shouldCheckForUpdate, startWebPush, urlBase64ToUint8Array };
