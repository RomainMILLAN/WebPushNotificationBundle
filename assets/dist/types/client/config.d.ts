export declare const CONFIG_META_NAME = "web-push-config";
/**
 * What the server renders in <meta name="web-push-config" content="{json}">
 * (ClientConfiguration::renderMetaTag()). Per user: it holds a CSRF token and the
 * client state marker.
 */
export interface PageConfig {
    publicKey: string;
    serviceWorker: string;
    subscribe: string;
    unsubscribe: string;
    clickPrefixes: string[];
    /** '' disables the header. */
    csrfHeader: string;
    csrfToken: string;
    /** '' for an anonymous visitor, else 16 lowercase hex characters. */
    clientState: string;
    /** Optional: must match the worker's `stateCache` when it is not the default. */
    stateCache: string;
}
/**
 * Reads the config from the LIVE DOM, on every call: Turbo replaces the <head>.
 * Returns null when the meta is absent or its content is not a usable config.
 */
export declare function readPageConfig(doc?: Document): PageConfig | null;
