import { INTENT_KEY, INTENT_MAX_AGE_MS, parseMarker } from '../../contract';
import type { NavigationIntent } from '../../contract';
import type { WebPushPlugin } from '../plugin';
import type { StateStore } from '../state_store';
import { readStoredMarker } from './client_state';

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
export function navigationIntent(): WebPushPlugin {
    return {
        name: 'navigation-intent',

        async onBeforeNavigate(clickPath, { state }) {
            const marker = await readStoredMarker(state);

            if (marker === null) {
                return 'skipped-no-marker';
            }

            const intent: NavigationIntent = { clickPath, at: Date.now(), marker };

            await state.write(INTENT_KEY, intent);

            return 'written';
        },

        async onNavigated(_clickPath, { state }) {
            // Without this, `already-there` leaves a live intent that teleports the user
            // minutes later.
            await state.remove(INTENT_KEY);
        },

        // The TTL is a property of the STORE, not of the claimer: an intent kept by the
        // openWindow/nudge paths would otherwise sleep forever.
        async onPush(_payload, { state }) {
            await dropExpiredIntent(state);
        },

        async onActivate({ state }) {
            await dropExpiredIntent(state);
        },
    };
}

/** Also destroys a MALFORMED intent, which nobody could claim anymore. */
export async function dropExpiredIntent(state: StateStore, now: number = Date.now()): Promise<void> {
    const intent = await state.read<Partial<NavigationIntent>>(INTENT_KEY);

    if (intent === null) {
        return;
    }

    if (typeof intent.at !== 'number' || now - intent.at > INTENT_MAX_AGE_MS || parseMarker(intent.marker) === null) {
        await state.remove(INTENT_KEY);
    }
}
