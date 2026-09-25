export interface ClaimOptions {
    /** Injected so the decision is testable without touching window.location. */
    navigate?: (path: string) => void;
    doc?: Document;
    /** Overrides the page config's stateCache. */
    stateCache?: string;
    caches?: CacheStorage;
    now?: () => number;
}
export type ClaimResult = 'unsupported' | 'no-intent' | 'refused' | 'foreign' | 'expired' | 'hidden' | 'unsafe' | 'already-there' | 'lost-race' | 'navigated';
/**
 * The marker the server rendered for this page, re-read from the LIVE DOM on every call.
 * A Turbo preview shows a snapshot <head>, possibly a stale marker: abstain.
 */
export declare function readRenderedMarker(doc?: Document): string | null;
/**
 * The page's own customs check, twin of the worker's. The value is read back from
 * Cache Storage, writable by any script of the origin, and location.assign() of a
 * `javascript:` URL is an execution sink: the page has its own border.
 */
export declare function isSafeClickPath(clickPath: unknown, origin: string, prefixes: readonly string[]): boolean;
/**
 * Claims the navigation intent left by the worker. THE ORDER OF THE DECISION IS THE POINT:
 * match → compare markers → delete → act only if delete() returned true. Two tabs may
 * both succeed the match; whoever wins the delete owns the click.
 */
export declare function claimNavigationIntent(options?: ClaimOptions): Promise<ClaimResult>;
