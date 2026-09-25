import type { WebPushPlugin } from '../plugin';
import type { StateStore } from '../state_store';
/**
 * Remembers which client state marker the pages of this device announce, and forgets
 * everything at logout.
 *
 * The marker is announced by the page (`client-state` message, from the
 * <meta name="web-push-config"> the server rendered for the signed-in user). An
 * anonymous page announces no marker, which ERASES the stored one: an intent written
 * afterwards is not attributed to the previous user.
 *
 * `forget-client-state` purges the whole state cache. Clear-Site-Data is not an
 * alternative: its "storage" directive unregisters the worker and destroys the push
 * subscription on Chrome.
 */
export declare function clientState(): WebPushPlugin;
export declare function readStoredMarker(state: StateStore): Promise<string | null>;
