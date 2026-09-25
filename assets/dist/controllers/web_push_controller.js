import { Controller } from "@hotwired/stimulus";
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
//#region src/controllers/web_push_controller.ts
var DEFAULT_LABELS = {
	unavailable: "Notifications are not configured on this page.",
	unsupported: "This browser does not support push notifications.",
	denied: "Notifications are blocked for this site.",
	subscribed: "Notifications are enabled on this device.",
	unsubscribed: "Notifications are disabled on this device.",
	busy: "…"
};
/**
* Subscribe / unsubscribe buttons for Web Push.
*
* Renders no HTML: it sets `data-web-push-state` on its element, the text of the
* `status` target (labels overridable with the `labels` value), and the `hidden` /
* `disabled` state of the button targets. Events: `web-push:subscribed` (detail:
* { subscribed: true }), `web-push:unsubscribed` (detail: { unsubscribed }), `web-push:error` (detail: { code, error }).
*
*     <div data-controller="web-push">
*         <p data-web-push-target="status"></p>
*         <button data-web-push-target="subscribeButton" data-action="web-push#subscribe">Enable</button>
*         <button data-web-push-target="unsubscribeButton" data-action="web-push#unsubscribe">Disable</button>
*     </div>
*/
var WebPushController = class extends Controller {
	static targets = [
		"status",
		"subscribeButton",
		"unsubscribeButton"
	];
	static values = { labels: Object };
	client = null;
	connect() {
		const config = readPageConfig(this.element.ownerDocument);
		this.client = config === null ? null : new WebPushClient(config);
		this.refresh();
	}
	/** Must stay bound to a user gesture: the client asks the permission first thing. */
	async subscribe(event) {
		event?.preventDefault();
		const client = this.client;
		if (client === null) return;
		const pending = client.subscribe();
		this.render("busy");
		try {
			await pending;
			this.dispatch("subscribed", {
				prefix: "web-push",
				detail: { subscribed: true }
			});
		} catch (error) {
			this.dispatch("error", {
				prefix: "web-push",
				detail: {
					code: error instanceof WebPushError ? error.code : "subscription-failed",
					error
				}
			});
		}
		await this.refresh();
	}
	async unsubscribe(event) {
		event?.preventDefault();
		const client = this.client;
		if (client === null) return;
		this.render("busy");
		const done = await client.unsubscribe();
		this.dispatch("unsubscribed", {
			prefix: "web-push",
			detail: { unsubscribed: done }
		});
		await this.refresh();
	}
	async refresh() {
		this.render(await this.currentState());
	}
	async currentState() {
		const client = this.client;
		if (client === null) return "unavailable";
		if (!client.isSupported()) return "unsupported";
		if (client.permission() === "denied") return "denied";
		return (client.permission() === "granted" ? await client.getSubscription() : null) === null ? "unsubscribed" : "subscribed";
	}
	render(state) {
		this.element.setAttribute("data-web-push-state", state);
		if (this.hasStatusTarget) this.statusTarget.textContent = this.labelsValue[state] ?? DEFAULT_LABELS[state];
		const busy = state === "busy";
		for (const button of this.subscribeButtonTargets) {
			if (!busy) button.hidden = state !== "unsubscribed";
			toggleDisabled(button, busy);
		}
		for (const button of this.unsubscribeButtonTargets) {
			if (!busy) button.hidden = state !== "subscribed";
			toggleDisabled(button, busy);
		}
	}
};
function toggleDisabled(element, disabled) {
	if ("disabled" in element) element.disabled = disabled;
}
//#endregion
export { WebPushController as default };
