import { type PageConfig } from './config';
/** Re-POST an existing subscription at most this often (keeps `lastSeen` fresh server side). */
export declare const SYNC_INTERVAL_MS: number;
export type ContentEncoding = 'aes128gcm' | 'aesgcm';
export type PermissionState = NotificationPermission | 'unsupported';
export type SyncResult = 'unsupported' | 'not-granted' | 'no-service-worker' | 'no-subscription' | 'fresh' | 'synced' | 'rotated' | 'failed';
export interface NotificationApi {
    readonly permission: NotificationPermission;
    requestPermission(callback?: (permission: NotificationPermission) => void): Promise<NotificationPermission> | void;
}
/** The browser capabilities the client uses, injectable for tests. */
export interface ClientEnvironment {
    serviceWorker: ServiceWorkerContainer | undefined;
    notification: NotificationApi | undefined;
    /** The PushManager constructor (for its static supportedContentEncodings). */
    pushManager: {
        readonly supportedContentEncodings?: readonly string[];
    } | undefined;
    fetch: typeof fetch;
    storage: () => Storage | null;
    now: () => number;
}
export declare function browserEnvironment(): ClientEnvironment;
/**
 * The page side of Web Push: permission, subscription, and its registration with the
 * server. It never registers the worker itself: startWebPush() is the single
 * registration point, two would race for the same scope.
 */
export declare class WebPushClient {
    readonly config: PageConfig;
    private readonly env;
    constructor(config: PageConfig, env?: Partial<ClientEnvironment>);
    /** Throws when the page carries no <meta name="web-push-config">. */
    static fromDocument(doc?: Document, env?: Partial<ClientEnvironment>): WebPushClient;
    isSupported(): boolean;
    permission(): PermissionState;
    /**
     * Asks the permission and subscribes this device.
     *
     * MUST be called synchronously from a user gesture (click handler): iOS refuses
     * the permission prompt otherwise, and requestPermission() is deliberately the
     * first asynchronous step so that the transient activation is still valid.
     */
    subscribe(): Promise<PushSubscription>;
    /**
     * Unregisters the subscription from the server, then from the browser — the latter
     * whatever the server answered. Never throws. Resolves false when there was nothing
     * to unsubscribe.
     */
    unsubscribe(): Promise<boolean>;
    /**
     * Keeps an existing subscription known to the server: re-POSTed at most once per
     * 24 h (or at once when the signed-in user changed), and recreated when the VAPID
     * key was rotated. Never throws.
     */
    sync(): Promise<SyncResult>;
    /** The body POSTed to the subscribe endpoint. */
    subscriptionBody(subscription: PushSubscription): Record<string, unknown>;
    contentEncoding(): ContentEncoding;
    /** Supports both the promise form and the legacy callback form (old Safari). */
    private requestPermission;
    private ensureSubscription;
    /** This device's current subscription, without asking anything. Never throws. */
    getSubscription(): Promise<PushSubscription | null>;
    private postUnsubscribe;
    private post;
    /** `ready` never rejects and never resolves without an active worker: it is bounded. */
    private readyRegistration;
    /** Resolves at once, active worker or not: enough to read a subscription. */
    private existingRegistration;
    private isSyncDue;
    private readSync;
    private rememberSync;
    private forgetSync;
}
/** Converts a base64url VAPID key to the Uint8Array Safari requires. */
export declare function urlBase64ToUint8Array(base64String: string): Uint8Array<ArrayBuffer>;
/** Compares the key a subscription was created with against the configured one. */
export declare function sameApplicationServerKey(subscription: PushSubscription, applicationServerKey: Uint8Array): boolean;
