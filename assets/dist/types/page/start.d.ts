import { WebPushClient } from '../client/web_push_client';
import { type ClaimResult } from './navigation_intent';
/** An installed iOS PWA almost never navigates: ask for a fresh script when coming back, at most this often. */
export declare const UPDATE_THROTTLE_MS = 60000;
export declare const DEFAULT_LOGOUT_SELECTOR = "form[data-web-push-logout]";
/** A logout must never be held back longer than this by the unsubscription. */
export declare const DEFAULT_LOGOUT_TIMEOUT_MS = 1000;
export interface StartOptions {
    /** Unsubscribe this device when a logout form is submitted. Default true. */
    unsubscribeOnLogout?: boolean;
    logoutSelector?: string;
    logoutTimeoutMs?: number;
    /** Run client.sync() once the worker is registered. Default true. */
    sync?: boolean;
    /** Injected navigation (tests); defaults to location.assign(). */
    navigate?: (path: string) => void;
    client?: WebPushClient;
    serviceWorker?: ServiceWorkerContainer;
    /**
     * URL of an application worker that pulls the package in with
     * `importScripts('/web-push-sw.js')`. Takes precedence over the meta `serviceWorker`
     * field. Must be a same-origin, root-relative path (e.g. '/sw.js'); anything else is
     * ignored and the meta value is used.
     */
    serviceWorkerUrl?: string;
}
export interface WebPushPage {
    readonly client: WebPushClient;
    /** Resolves to null when the worker could not be registered. */
    readonly registration: Promise<ServiceWorkerRegistration | null>;
    /** Serialized claim of the navigation intent. */
    claim(): Promise<ClaimResult>;
    /** Posts `forget-client-state` to the worker. Synchronous on purpose. */
    forgetClientState(): void;
    /** Removes every listener (tests, teardown). */
    stop(): void;
}
/** Pure decision, testable without mocking Date nor observing a request. */
export declare function shouldCheckForUpdate(lastCheckAt: number | null, now: number): boolean;
/**
 * Returns `candidate` normalized to path + query when it is a root-relative path of
 * `origin`, else null. A protocol-relative ('//host') or backslash form ('/\\host') is
 * refused before resolution: browsers read both as another host.
 */
export declare function sameOriginPath(candidate: unknown, origin: string): string | null;
/**
 * Starts Web Push on a page: the SINGLE registration point of the worker, the claim of
 * navigation intents, and the logout hook. Idempotent per document. Returns null when
 * the page carries no <meta name="web-push-config">.
 */
export declare function startWebPush(doc?: Document, options?: StartOptions): WebPushPage | null;
