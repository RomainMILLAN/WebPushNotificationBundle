/**
 * Static configuration of the worker, injected by the PHP route as
 * `self.__WEB_PUSH_CONFIG__` (see ServiceWorkerConfig::toArray()).
 */
export interface ServiceWorkerConfig {
    /** Title shown when a payload has none, or when it is not a valid v1 payload. */
    fallbackTitle: string;
    /** Default icon; '' for none. */
    icon: string;
    /** Default monochrome badge; '' for none. */
    badge: string;
    /** In-origin path prefixes a click may navigate to (besides `/`). */
    clickPrefixes: string[];
    /** Extra hostnames (https only) icons and badges may be loaded from. */
    assetHosts: string[];
    /** Cache Storage name of the worker state. */
    stateCache: string;
}
export declare const DEFAULT_FALLBACK_TITLE = "Notification";
/**
 * Normalizes an untrusted config object: unknown keys are dropped, wrong types fall
 * back to defaults. The worker must start even with a broken or missing config: a push
 * that is not displayed costs the subscription on some browsers.
 */
export declare function normalizeServiceWorkerConfig(raw: unknown): ServiceWorkerConfig;
export declare function isRecord(value: unknown): value is Record<string, unknown>;
