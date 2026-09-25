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
export declare function resolveClickPath(raw: unknown, base: string, origin: string, prefixes: readonly string[]): ClickPathResult;
export declare function isAllowedPathname(pathname: string, prefixes: readonly string[]): boolean;
