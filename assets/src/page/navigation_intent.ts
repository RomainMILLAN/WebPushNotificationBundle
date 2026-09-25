import { resolveClickPath } from '../click_path';
import { DEFAULT_STATE_CACHE, INTENT_KEY, INTENT_MAX_AGE_MS, parseMarker, type NavigationIntent } from '../contract';
import { readPageConfig } from '../client/config';

export interface ClaimOptions {
    /** Injected so the decision is testable without touching window.location. */
    navigate?: (path: string) => void;
    doc?: Document;
    /** Overrides the page config's stateCache. */
    stateCache?: string;
    caches?: CacheStorage;
    now?: () => number;
}

export type ClaimResult =
    | 'unsupported'
    | 'no-intent'
    | 'refused'
    | 'foreign'
    | 'expired'
    | 'hidden'
    | 'unsafe'
    | 'already-there'
    | 'lost-race'
    | 'navigated';

/**
 * The marker the server rendered for this page, re-read from the LIVE DOM on every call.
 * A Turbo preview shows a snapshot <head>, possibly a stale marker: abstain.
 */
export function readRenderedMarker(doc: Document = document): string | null {
    if (doc.documentElement.hasAttribute('data-turbo-preview')) {
        return null;
    }

    return parseMarker(readPageConfig(doc)?.clientState);
}

/**
 * The page's own customs check, twin of the worker's. The value is read back from
 * Cache Storage, writable by any script of the origin, and location.assign() of a
 * `javascript:` URL is an execution sink: the page has its own border.
 */
export function isSafeClickPath(clickPath: unknown, origin: string, prefixes: readonly string[]): boolean {
    if (typeof clickPath !== 'string' || clickPath === '') {
        return false;
    }

    return resolveClickPath(clickPath, origin, origin, prefixes).verdict === 'accepted';
}

/**
 * Claims the navigation intent left by the worker. THE ORDER OF THE DECISION IS THE POINT:
 * match → compare markers → delete → act only if delete() returned true. Two tabs may
 * both succeed the match; whoever wins the delete owns the click.
 */
export async function claimNavigationIntent(options: ClaimOptions = {}): Promise<ClaimResult> {
    const doc = options.doc ?? document;
    const view = doc.defaultView;
    const storage = options.caches ?? (view && 'caches' in view ? view.caches : undefined);

    if (!storage || !view) {
        return 'unsupported';
    }

    const config = readPageConfig(doc);
    const cache = await storage.open(options.stateCache ?? config?.stateCache ?? DEFAULT_STATE_CACHE);
    const stored = await cache.match(INTENT_KEY);

    if (!stored) {
        return 'no-intent';
    }

    const intent = await stored.json().catch(() => null) as Partial<NavigationIntent> | null;
    const owner = parseMarker(intent?.marker);
    const rendered = readRenderedMarker(doc);

    // REFUSING IS NOT DESTROYING. On a login page no marker is rendered: purging here
    // would destroy the intent of the user who is about to sign in. The TTL remains
    // the only guarantee of finiteness.
    if (owner === null || rendered === null) {
        return 'refused';
    }

    if (owner !== rendered) {
        // A DEMONSTRATED mismatch: this intent is not for this user.
        await cache.delete(INTENT_KEY);

        return 'foreign';
    }

    const now = options.now?.() ?? Date.now();

    if (typeof intent?.at !== 'number' || now - intent.at > INTENT_MAX_AGE_MS) {
        await cache.delete(INTENT_KEY);

        return 'expired';
    }

    // A hidden page must not teleport in front of nobody.
    if (doc.visibilityState === 'hidden') {
        return 'hidden';
    }

    const origin = view.location.origin;

    if (!isSafeClickPath(intent.clickPath, origin, config?.clickPrefixes ?? [])) {
        await cache.delete(INTENT_KEY);

        return 'unsafe';
    }

    const clickPath = intent.clickPath as string;

    if (view.location.pathname + view.location.search === clickPath) {
        await cache.delete(INTENT_KEY);

        return 'already-there';
    }

    // The atomic claim: whoever wins the delete owns the click.
    if (!await cache.delete(INTENT_KEY)) {
        return 'lost-race';
    }

    // A real navigation, not Turbo.visit: no dependency on Turbo's cache.
    (options.navigate ?? ((path: string) => view.location.assign(path)))(clickPath);

    return 'navigated';
}
