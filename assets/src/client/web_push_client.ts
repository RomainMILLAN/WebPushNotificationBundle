import { readPageConfig, type PageConfig } from './config';
import { WebPushError, WebPushPermissionError, WebPushRequestError, WebPushUnsupportedError } from './errors';

/** Re-POST an existing subscription at most this often (keeps `lastSeen` fresh server side). */
export const SYNC_INTERVAL_MS = 24 * 60 * 60 * 1000;

const SYNC_STORAGE_KEY = 'web-push:last-sync';

/** subscribe() may wait for a first install of the worker. */
const SUBSCRIBE_READY_TIMEOUT_MS = 15_000;

export type ContentEncoding = 'aes128gcm' | 'aesgcm';

export type PermissionState = NotificationPermission | 'unsupported';

export type SyncResult =
    | 'unsupported'
    | 'not-granted'
    | 'no-service-worker'
    | 'no-subscription'
    | 'fresh'
    | 'synced'
    | 'rotated'
    | 'failed';

export interface NotificationApi {
    readonly permission: NotificationPermission;
    requestPermission(callback?: (permission: NotificationPermission) => void): Promise<NotificationPermission> | void;
}

/** The browser capabilities the client uses, injectable for tests. */
export interface ClientEnvironment {
    serviceWorker: ServiceWorkerContainer | undefined;
    notification: NotificationApi | undefined;
    /** The PushManager constructor (for its static supportedContentEncodings). */
    pushManager: { readonly supportedContentEncodings?: readonly string[] } | undefined;
    fetch: typeof fetch;
    storage: () => Storage | null;
    now: () => number;
}

export function browserEnvironment(): ClientEnvironment {
    const scope = globalThis as unknown as Record<string, unknown> & { navigator?: Navigator };

    return {
        serviceWorker: scope.navigator && 'serviceWorker' in scope.navigator ? scope.navigator.serviceWorker : undefined,
        notification: scope.Notification as NotificationApi | undefined,
        pushManager: scope.PushManager as ClientEnvironment['pushManager'],
        fetch: (input, init) => globalThis.fetch(input, init),
        storage: () => {
            try {
                return globalThis.localStorage ?? null;
            } catch {
                // Safari private mode, blocked storage.
                return null;
            }
        },
        now: () => Date.now(),
    };
}

/**
 * The page side of Web Push: permission, subscription, and its registration with the
 * server. It never registers the worker itself: startWebPush() is the single
 * registration point, two would race for the same scope.
 */
export class WebPushClient {
    private readonly env: ClientEnvironment;

    constructor(
        readonly config: PageConfig,
        env: Partial<ClientEnvironment> = {},
    ) {
        this.env = { ...browserEnvironment(), ...env };
    }

    /** Throws when the page carries no <meta name="web-push-config">. */
    static fromDocument(doc: Document = document, env: Partial<ClientEnvironment> = {}): WebPushClient {
        const config = readPageConfig(doc);

        if (config === null) {
            throw new WebPushError('unsupported', 'The page has no usable <meta name="web-push-config">.');
        }

        return new WebPushClient(config, env);
    }

    isSupported(): boolean {
        return this.env.serviceWorker !== undefined && this.env.pushManager !== undefined && this.env.notification !== undefined;
    }

    permission(): PermissionState {
        return this.env.notification?.permission ?? 'unsupported';
    }

    /**
     * Asks the permission and subscribes this device.
     *
     * MUST be called synchronously from a user gesture (click handler): iOS refuses
     * the permission prompt otherwise, and requestPermission() is deliberately the
     * first asynchronous step so that the transient activation is still valid.
     */
    async subscribe(): Promise<PushSubscription> {
        if (!this.isSupported()) {
            throw new WebPushUnsupportedError();
        }

        const permission = await this.requestPermission();

        if (permission !== 'granted') {
            throw new WebPushPermissionError(permission);
        }

        const registration = await this.readyRegistration(SUBSCRIBE_READY_TIMEOUT_MS);

        if (registration === null) {
            throw new WebPushError('no-service-worker', 'No active service worker: was startWebPush() called on this page?');
        }

        let subscription: PushSubscription;

        try {
            subscription = await this.ensureSubscription(registration);
        } catch (error) {
            throw new WebPushError('subscription-failed', 'The browser refused to create a push subscription.', { cause: error });
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
    async unsubscribe(): Promise<boolean> {
        this.forgetSync();

        const subscription = await this.getSubscription();

        if (subscription === null) {
            return false;
        }

        await this.postUnsubscribe(subscription);

        try {
            await subscription.unsubscribe();
        } catch {
            // The server side is already done; the browser will retry nothing.
        }

        return true;
    }

    /**
     * Keeps an existing subscription known to the server: re-POSTed at most once per
     * 24 h (or at once when the signed-in user changed), and recreated when the VAPID
     * key was rotated. Never throws.
     */
    async sync(): Promise<SyncResult> {
        if (!this.isSupported()) {
            return 'unsupported';
        }

        if (this.permission() !== 'granted') {
            return 'not-granted';
        }

        const registration = await this.existingRegistration();

        if (registration === null) {
            return 'no-service-worker';
        }

        try {
            const existing = await registration.pushManager.getSubscription();

            if (existing === null) {
                return 'no-subscription';
            }

            const key = urlBase64ToUint8Array(this.config.publicKey);

            if (!sameApplicationServerKey(existing, key)) {
                await this.postUnsubscribe(existing);
                await existing.unsubscribe().catch(() => false);

                const fresh = await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key });

                await this.post(this.config.subscribe, this.subscriptionBody(fresh), false);
                this.rememberSync();

                return 'rotated';
            }

            if (!this.isSyncDue()) {
                return 'fresh';
            }

            await this.post(this.config.subscribe, this.subscriptionBody(existing), false);
            this.rememberSync();

            return 'synced';
        } catch {
            return 'failed';
        }
    }

    /** The body POSTed to the subscribe endpoint. */
    subscriptionBody(subscription: PushSubscription): Record<string, unknown> {
        return { ...subscription.toJSON(), contentEncoding: this.contentEncoding() };
    }

    contentEncoding(): ContentEncoding {
        const supported = this.env.pushManager?.supportedContentEncodings;

        return Array.isArray(supported) && supported.includes('aes128gcm') ? 'aes128gcm' : 'aesgcm';
    }

    /** Supports both the promise form and the legacy callback form (old Safari). */
    private requestPermission(): Promise<NotificationPermission> {
        const notification = this.env.notification!;

        return new Promise<NotificationPermission>((resolve) => {
            let returned: Promise<NotificationPermission> | void;

            try {
                returned = notification.requestPermission(resolve);
            } catch {
                resolve(notification.permission);

                return;
            }

            if (returned && typeof returned.then === 'function') {
                returned.then(resolve, () => resolve(notification.permission));
            }
        });
    }

    private async ensureSubscription(registration: ServiceWorkerRegistration): Promise<PushSubscription> {
        const key = urlBase64ToUint8Array(this.config.publicKey);
        const existing = await registration.pushManager.getSubscription();

        if (existing !== null) {
            if (sameApplicationServerKey(existing, key)) {
                return existing;
            }

            // Subscribing with another key while one exists throws InvalidStateError:
            // the stale one is dropped first, which is what survives a key rotation.
            await this.postUnsubscribe(existing);
            await existing.unsubscribe().catch(() => false);
        }

        // A Uint8Array, not the base64url string: Safari only accepts a BufferSource.
        return registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key });
    }

    /** This device's current subscription, without asking anything. Never throws. */
    async getSubscription(): Promise<PushSubscription | null> {
        if (this.env.serviceWorker === undefined || this.env.pushManager === undefined) {
            return null;
        }

        const registration = await this.existingRegistration();

        if (registration === null) {
            return null;
        }

        try {
            return await registration.pushManager.getSubscription();
        } catch {
            return null;
        }
    }

    private async postUnsubscribe(subscription: PushSubscription): Promise<void> {
        const json = subscription.toJSON();

        try {
            await this.post(this.config.unsubscribe, { endpoint: subscription.endpoint, keys: { auth: json.keys?.auth ?? '' } }, true);
        } catch {
            // Unsubscribing never fails for the caller: the browser side goes on.
        }
    }

    private async post(url: string, body: unknown, keepalive: boolean): Promise<void> {
        const headers: Record<string, string> = { 'Content-Type': 'application/json' };

        if (this.config.csrfHeader !== '') {
            headers[this.config.csrfHeader] = this.config.csrfToken;
        }

        const init: RequestInit = {
            method: 'POST',
            credentials: 'same-origin',
            headers,
            body: JSON.stringify(body),
        };

        if (keepalive) {
            init.keepalive = true;
        }

        let response: Response;

        try {
            response = await this.env.fetch(url, init);
        } catch (error) {
            throw new WebPushError('network', `Could not reach ${url}.`, { cause: error });
        }

        if (!response.ok) {
            throw new WebPushRequestError(response.status);
        }
    }

    /** `ready` never rejects and never resolves without an active worker: it is bounded. */
    private async readyRegistration(timeoutMs: number): Promise<ServiceWorkerRegistration | null> {
        const container = this.env.serviceWorker;

        if (container === undefined) {
            return null;
        }

        let timer: ReturnType<typeof setTimeout> | undefined;
        const timeout = new Promise<null>((resolve) => {
            timer = setTimeout(() => resolve(null), timeoutMs);
        });

        try {
            return await Promise.race([container.ready.catch(() => null), timeout]);
        } finally {
            clearTimeout(timer);
        }
    }

    /** Resolves at once, active worker or not: enough to read a subscription. */
    private async existingRegistration(): Promise<ServiceWorkerRegistration | null> {
        try {
            return (await this.env.serviceWorker?.getRegistration('/')) ?? null;
        } catch {
            return null;
        }
    }

    private isSyncDue(): boolean {
        const stored = this.readSync();

        return stored === null
            || stored.clientState !== this.config.clientState
            || this.env.now() - stored.at >= SYNC_INTERVAL_MS;
    }

    private readSync(): { at: number; clientState: string } | null {
        try {
            const raw = this.env.storage()?.getItem(SYNC_STORAGE_KEY);
            const parsed: unknown = raw ? JSON.parse(raw) : null;

            if (typeof parsed === 'object' && parsed !== null
                && typeof (parsed as { at?: unknown }).at === 'number'
                && typeof (parsed as { clientState?: unknown }).clientState === 'string') {
                return parsed as { at: number; clientState: string };
            }
        } catch {
            // Unreadable storage: sync is simply due.
        }

        return null;
    }

    private rememberSync(): void {
        try {
            this.env.storage()?.setItem(SYNC_STORAGE_KEY, JSON.stringify({ at: this.env.now(), clientState: this.config.clientState }));
        } catch {
            // Quota or private mode: the next page load syncs again, harmlessly.
        }
    }

    private forgetSync(): void {
        try {
            this.env.storage()?.removeItem(SYNC_STORAGE_KEY);
        } catch {
            // Same as above.
        }
    }
}

/** Converts a base64url VAPID key to the Uint8Array Safari requires. */
export function urlBase64ToUint8Array(base64String: string): Uint8Array<ArrayBuffer> {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const raw = globalThis.atob(base64);
    const key = new Uint8Array(new ArrayBuffer(raw.length));

    for (let i = 0; i < raw.length; i += 1) {
        key[i] = raw.charCodeAt(i);
    }

    return key;
}

/** Compares the key a subscription was created with against the configured one. */
export function sameApplicationServerKey(subscription: PushSubscription, applicationServerKey: Uint8Array): boolean {
    const current = subscription.options?.applicationServerKey;

    if (!current) {
        return false;
    }

    const currentBytes = new Uint8Array(current);

    if (currentBytes.length !== applicationServerKey.length) {
        return false;
    }

    return currentBytes.every((byte, i) => byte === applicationServerKey[i]);
}
