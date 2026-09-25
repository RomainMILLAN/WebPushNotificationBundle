/**
 * The contract shared by the page and the service worker.
 *
 * Both sides are bundled from this very module, so they cannot drift apart: the page
 * bundle and the prebuilt worker (dist/web-push-sw.js) embed the same values. What
 * CAN drift is a device running a stale worker next to a fresh page bundle, which is
 * exactly what the `worker-ping` / SW_VERSION diagnostic reveals.
 */

/**
 * Bump on every behavioural change of the service worker. The page compares the
 * version the running worker reports with the one it was built with.
 */
export const SW_VERSION = '1';

/** The payload contract version the worker accepts (see src/Application/Contract/schema/v1.json). */
export const PAYLOAD_VERSION = 1;

/** Default Cache Storage name holding the worker state (intent, trail, marker). */
export const DEFAULT_STATE_CACHE = 'web-push-state';

/** `{ clickPath, at, marker }`, written by the worker before navigating, claimed by a page. */
export const INTENT_KEY = '/__web-push/navigation-intent';

/** `[{ at, outcome, verdict, clientCount, intent }]`, newest first, never any path. */
export const TRAIL_KEY = '/__web-push/click-trail';

/** `{ marker }`: the client state marker last announced by a page. */
export const MARKER_KEY = '/__web-push/client-state';

/**
 * Retention of a navigation intent. It covers both the unpredictable thaw of a
 * frozen iOS page and the time a user needs to sign in before the target is forgotten.
 */
export const INTENT_MAX_AGE_MS = 300_000;

/** How many clicks the diagnostic trail remembers. */
export const TRAIL_MAX_ENTRIES = 10;

/** Message types exchanged between the page and the worker. */
export const MessageType = {
    workerPing: 'worker-ping',
    workerPong: 'worker-pong',
    clientState: 'client-state',
    forgetClientState: 'forget-client-state',
    readClickTrail: 'read-click-trail',
    clickTrail: 'click-trail',
    claimNavigationIntent: 'claim-navigation-intent',
} as const;

export type MessageTypeName = typeof MessageType[keyof typeof MessageType];

export interface NavigationIntent {
    clickPath: string;
    at: number;
    marker: string;
}

export type ClickVerdict =
    | 'no-destination'
    | 'rejected-parse'
    | 'rejected-scheme'
    | 'rejected-cross-origin'
    | 'rejected-prefix'
    | 'accepted';

export interface ClickTrailEntry {
    at: number;
    outcome: string;
    verdict: string;
    clientCount: number | null;
    intent: string;
}

/**
 * Client state marker domain primitive: 16 lowercase hexadecimal characters, or null.
 * Two absences are not an identity: a missing marker never equals another missing one.
 */
export function parseMarker(raw: unknown): string | null {
    return typeof raw === 'string' && /^[0-9a-f]{16}$/.test(raw) ? raw : null;
}
