import { DEFAULT_STATE_CACHE } from '../contract';

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

export const DEFAULT_FALLBACK_TITLE = 'Notification';

/**
 * Normalizes an untrusted config object: unknown keys are dropped, wrong types fall
 * back to defaults. The worker must start even with a broken or missing config: a push
 * that is not displayed costs the subscription on some browsers.
 */
export function normalizeServiceWorkerConfig(raw: unknown): ServiceWorkerConfig {
    const source = isRecord(raw) ? raw : {};

    return {
        fallbackTitle: nonEmptyString(source.fallbackTitle) ?? DEFAULT_FALLBACK_TITLE,
        icon: typeof source.icon === 'string' ? source.icon : '',
        badge: typeof source.badge === 'string' ? source.badge : '',
        clickPrefixes: stringList(source.clickPrefixes).filter((prefix) => prefix.startsWith('/') && !prefix.startsWith('//')),
        assetHosts: stringList(source.assetHosts).map((host) => host.toLowerCase()),
        stateCache: nonEmptyString(source.stateCache) ?? DEFAULT_STATE_CACHE,
    };
}

export function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function nonEmptyString(value: unknown): string | null {
    return typeof value === 'string' && value.trim() !== '' ? value : null;
}

function stringList(value: unknown): string[] {
    return Array.isArray(value) ? value.filter((item): item is string => typeof item === 'string' && item !== '') : [];
}
