import { type ServiceWorkerConfig } from './config';
import type { WorkerNotificationOptions } from './types';
export type ActionType = 'navigate' | 'post' | 'dismiss';
export declare const ACTION_TYPES: readonly ActionType[];
export declare const MAX_ACTIONS = 2;
export declare const MAX_DATA_PROPERTIES = 16;
export interface PayloadAction {
    action: string;
    title: string;
    type: ActionType;
    url?: string;
}
/** A payload of contract v1, reduced to the fields the worker knows. */
export interface PushPayload {
    v: 1;
    id: string;
    title: string;
    body: string;
    tag: string;
    silent: boolean;
    requireInteraction: boolean;
    renotify: boolean;
    icon?: string;
    badge?: string;
    click?: string;
    badgeCount?: number;
    actions: PayloadAction[];
    data: Record<string, string | number | boolean>;
}
/** What a notification carries in `notification.data`: everything a click needs. */
export interface NotificationData {
    id: string;
    click: string | null;
    actions: Record<string, {
        type: ActionType;
        url: string | null;
    }>;
    app: Record<string, string | number | boolean>;
}
export interface NotificationSpec {
    title: string;
    options: WorkerNotificationOptions;
}
/**
 * Parses an untrusted push message into a v1 payload, or null.
 *
 * Every field is read explicitly: an unknown field never reaches showNotification(),
 * whatever the sender slipped into the parcel. Malformed optional fields are dropped
 * rather than rejecting the whole payload, so a notification is still shown.
 */
export declare function parsePayload(raw: unknown): PushPayload | null;
/**
 * Builds the notification from an allowlist. A null payload (unreadable, or not v1)
 * still yields a notification: a push that shows nothing is punished by browsers
 * (Safari revokes the subscription), so the fallback title is displayed.
 */
export declare function buildNotification(payload: PushPayload | null, config: ServiceWorkerConfig, origin: string): NotificationSpec;
/**
 * An image URL is displayed only when same-origin, or https on an allowed host. Anything
 * else would let a payload make the device fetch an arbitrary URL (a tracking pixel).
 */
export declare function acceptAssetUrl(raw: string | undefined, origin: string, assetHosts: readonly string[]): string | null;
