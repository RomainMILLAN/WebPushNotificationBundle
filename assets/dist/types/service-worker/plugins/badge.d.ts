import type { WebPushPlugin } from '../plugin';
/**
 * Puts the payload's `badgeCount` on the app icon (Badging API, installed PWAs).
 *
 * The count was computed when the notification was sent: the worker cannot read the
 * current badge nor ask the server. Any drift is corrected by the page next time.
 */
export declare function badge(): WebPushPlugin;
