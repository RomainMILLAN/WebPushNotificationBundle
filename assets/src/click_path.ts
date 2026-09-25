import type { ClickVerdict } from './contract';

export interface ClickPathResult {
    /** Path + query to navigate to; `/` whenever the verdict is not `accepted`. */
    clickPath: string;
    verdict: ClickVerdict;
}

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
export function resolveClickPath(raw: unknown, base: string, origin: string, prefixes: readonly string[]): ClickPathResult {
    if (raw === undefined || raw === null || String(raw) === '') {
        return { clickPath: '/', verdict: 'no-destination' };
    }

    let url: URL;

    try {
        url = new URL(String(raw), base);
    } catch {
        return { clickPath: '/', verdict: 'rejected-parse' };
    }

    if (url.protocol !== 'https:' && url.protocol !== 'http:') {
        return { clickPath: '/', verdict: 'rejected-scheme' };
    }

    if (url.origin !== origin) {
        return { clickPath: '/', verdict: 'rejected-cross-origin' };
    }

    if (!isAllowedPathname(url.pathname, prefixes)) {
        return { clickPath: '/', verdict: 'rejected-prefix' };
    }

    return { clickPath: url.pathname + url.search, verdict: 'accepted' };
}

export function isAllowedPathname(pathname: string, prefixes: readonly string[]): boolean {
    return pathname === '/' || prefixes.some((prefix) => prefix !== '' && pathname.startsWith(prefix));
}
