import type { WebPushPlugin } from '../plugin';

/**
 * Puts the payload's `badgeCount` on the app icon (Badging API, installed PWAs).
 *
 * The count was computed when the notification was sent: the worker cannot read the
 * current badge nor ask the server. Any drift is corrected by the page next time.
 */
export function badge(): WebPushPlugin {
    return {
        name: 'badge',

        async onPush(payload, { scope }) {
            const count = payload?.badgeCount;
            const navigator = scope.navigator;

            if (typeof count !== 'number' || !navigator) {
                return;
            }

            if (count > 0 && typeof navigator.setAppBadge === 'function') {
                await navigator.setAppBadge(count);

                return;
            }

            if (count === 0 && typeof navigator.clearAppBadge === 'function') {
                await navigator.clearAppBadge();
            }
        },
    };
}
