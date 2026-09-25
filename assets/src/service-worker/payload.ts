import { PAYLOAD_VERSION } from '../contract';
import { isRecord, type ServiceWorkerConfig } from './config';
import type { WorkerNotificationAction, WorkerNotificationOptions } from './types';

export type ActionType = 'navigate' | 'post' | 'dismiss';

export const ACTION_TYPES: readonly ActionType[] = ['navigate', 'post', 'dismiss'];

export const MAX_ACTIONS = 2;

export const MAX_DATA_PROPERTIES = 16;

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
    actions: Record<string, { type: ActionType; url: string | null }>;
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
export function parsePayload(raw: unknown): PushPayload | null {
    if (!isRecord(raw) || raw.v !== PAYLOAD_VERSION) {
        return null;
    }

    const payload: PushPayload = {
        v: PAYLOAD_VERSION,
        id: typeof raw.id === 'string' ? raw.id : '',
        title: typeof raw.title === 'string' ? raw.title : '',
        body: typeof raw.body === 'string' ? raw.body : '',
        tag: typeof raw.tag === 'string' ? raw.tag : '',
        silent: raw.silent === true,
        requireInteraction: raw.requireInteraction === true,
        renotify: raw.renotify === true,
        actions: parseActions(raw.actions),
        data: parseData(raw.data),
    };

    if (typeof raw.icon === 'string' && raw.icon !== '') {
        payload.icon = raw.icon;
    }

    if (typeof raw.badge === 'string' && raw.badge !== '') {
        payload.badge = raw.badge;
    }

    if (typeof raw.click === 'string' && raw.click !== '') {
        payload.click = raw.click;
    }

    if (typeof raw.badgeCount === 'number' && Number.isInteger(raw.badgeCount) && raw.badgeCount >= 0) {
        payload.badgeCount = raw.badgeCount;
    }

    return payload;
}

/**
 * Builds the notification from an allowlist. A null payload (unreadable, or not v1)
 * still yields a notification: a push that shows nothing is punished by browsers
 * (Safari revokes the subscription), so the fallback title is displayed.
 */
export function buildNotification(payload: PushPayload | null, config: ServiceWorkerConfig, origin: string): NotificationSpec {
    const options: WorkerNotificationOptions = {};
    const data: NotificationData = { id: '', click: null, actions: {}, app: {} };

    if (payload !== null) {
        if (payload.body !== '') {
            options.body = payload.body;
        }

        if (payload.tag !== '') {
            options.tag = payload.tag;
            // renotify without a tag throws a TypeError in Chrome: the display would fail.
            if (payload.renotify) {
                options.renotify = true;
            }
        }

        if (payload.requireInteraction) {
            options.requireInteraction = true;
        }

        if (payload.silent) {
            options.silent = true;
        }

        const actions: WorkerNotificationAction[] = [];

        for (const action of payload.actions) {
            actions.push({ action: action.action, title: action.title });
            data.actions[action.action] = { type: action.type, url: action.url ?? null };
        }

        if (actions.length > 0) {
            options.actions = actions;
        }

        data.id = payload.id;
        data.click = payload.click ?? null;
        data.app = payload.data;
    }

    const icon = acceptAssetUrl(payload?.icon, origin, config.assetHosts) ?? nonEmpty(config.icon);
    const badge = acceptAssetUrl(payload?.badge, origin, config.assetHosts) ?? nonEmpty(config.badge);

    if (icon !== null) {
        options.icon = icon;
    }

    if (badge !== null) {
        options.badge = badge;
    }

    options.data = data;

    const title = payload !== null && payload.title.trim() !== '' ? payload.title : config.fallbackTitle;

    return { title, options };
}

/**
 * An image URL is displayed only when same-origin, or https on an allowed host. Anything
 * else would let a payload make the device fetch an arbitrary URL (a tracking pixel).
 */
export function acceptAssetUrl(raw: string | undefined, origin: string, assetHosts: readonly string[]): string | null {
    if (raw === undefined || raw === '') {
        return null;
    }

    let url: URL;

    try {
        url = new URL(raw, origin);
    } catch {
        return null;
    }

    if (url.origin === origin && (url.protocol === 'https:' || url.protocol === 'http:')) {
        // Re-serialized from the parsed URL, never echoed raw.
        return url.pathname + url.search;
    }

    if (url.protocol === 'https:' && assetHosts.includes(url.hostname.toLowerCase()) && url.username === '' && url.password === '') {
        return url.href;
    }

    return null;
}

function parseActions(raw: unknown): PayloadAction[] {
    if (!Array.isArray(raw)) {
        return [];
    }

    const actions: PayloadAction[] = [];
    const seen = new Set<string>();

    for (const item of raw) {
        if (actions.length >= MAX_ACTIONS) {
            break;
        }

        if (!isRecord(item)) {
            continue;
        }

        const { action, title, type, url } = item;

        if (typeof action !== 'string' || !/^[a-z][a-z0-9_-]{0,31}$/.test(action) || seen.has(action)) {
            continue;
        }

        if (typeof title !== 'string' || title === '') {
            continue;
        }

        if (typeof type !== 'string' || !(ACTION_TYPES as readonly string[]).includes(type)) {
            continue;
        }

        const parsed: PayloadAction = { action, title, type: type as ActionType };

        if (typeof url === 'string' && url !== '') {
            parsed.url = url;
        }

        seen.add(action);
        actions.push(parsed);
    }

    return actions;
}

function parseData(raw: unknown): Record<string, string | number | boolean> {
    const data: Record<string, string | number | boolean> = {};

    if (!isRecord(raw)) {
        return data;
    }

    for (const [key, value] of Object.entries(raw)) {
        if (Object.keys(data).length >= MAX_DATA_PROPERTIES) {
            break;
        }

        if (typeof value === 'string' || typeof value === 'boolean' || (typeof value === 'number' && Number.isFinite(value))) {
            data[key] = value;
        }
    }

    return data;
}

function nonEmpty(value: string): string | null {
    return value === '' ? null : value;
}
