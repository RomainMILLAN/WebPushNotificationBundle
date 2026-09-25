import { DEFAULT_STATE_CACHE, parseMarker } from '../contract';

export const CONFIG_META_NAME = 'web-push-config';

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
export function readPageConfig(doc: Document = document): PageConfig | null {
    const meta = doc.querySelector(`meta[name="${CONFIG_META_NAME}"]`);
    const content = meta?.getAttribute('content');

    if (!content) {
        return null;
    }

    let raw: unknown;

    try {
        raw = JSON.parse(content);
    } catch {
        return null;
    }

    if (typeof raw !== 'object' || raw === null || Array.isArray(raw)) {
        return null;
    }

    const source = raw as Record<string, unknown>;
    const publicKey = stringOf(source.publicKey);
    const serviceWorker = stringOf(source.serviceWorker);
    const subscribe = stringOf(source.subscribe);
    const unsubscribe = stringOf(source.unsubscribe);

    if (publicKey === '' || serviceWorker === '' || subscribe === '' || unsubscribe === '') {
        return null;
    }

    return {
        publicKey,
        serviceWorker,
        subscribe,
        unsubscribe,
        clickPrefixes: Array.isArray(source.clickPrefixes)
            ? source.clickPrefixes.filter((prefix): prefix is string => typeof prefix === 'string' && prefix.startsWith('/') && !prefix.startsWith('//'))
            : [],
        csrfHeader: stringOf(source.csrfHeader),
        csrfToken: stringOf(source.csrfToken),
        clientState: parseMarker(source.clientState) ?? '',
        stateCache: stringOf(source.stateCache) || DEFAULT_STATE_CACHE,
    };
}

function stringOf(value: unknown): string {
    return typeof value === 'string' ? value : '';
}
