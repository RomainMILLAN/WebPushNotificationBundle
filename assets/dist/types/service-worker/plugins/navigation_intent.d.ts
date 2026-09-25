import type { WebPushPlugin } from '../plugin';
import type { StateStore } from '../state_store';
/**
 * Leaves a navigation intent in Cache Storage for a page to claim.
 *
 * It solves the one problem the worker's direct chain cannot: an iOS page frozen in the
 * background thaws at an unpredictable moment, so landing is EVENTUALLY CONSISTENT. No
 * timer anywhere: focus()/openWindow() spend the transient user activation that
 * notificationclick grants.
 *
 * The intent carries the marker announced by the pages (clientState plugin). Without
 * one, no intent is written — and the trail says so (`skipped-no-marker`).
 */
export declare function navigationIntent(): WebPushPlugin;
/** Also destroys a MALFORMED intent, which nobody could claim anymore. */
export declare function dropExpiredIntent(state: StateStore, now?: number): Promise<void>;
