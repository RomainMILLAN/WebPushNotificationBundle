import { vi } from 'vitest';

import type { PageConfig } from '../src/client/config';
import { WebPushClient, type ClientEnvironment, type NotificationApi } from '../src/client/web_push_client';
import { BASE_CONFIG, fakeContainer, fakePushManager, type FakeSubscription } from './page_harness';

export interface ClientHarnessOptions {
    config?: Partial<PageConfig>;
    existing?: FakeSubscription | null;
    permission?: NotificationPermission;
    /** What requestPermission() resolves to. */
    grant?: NotificationPermission;
    /** Legacy Safari: requestPermission only calls its callback. */
    callbackOnly?: boolean;
    encodings?: string[] | undefined;
    status?: number;
    fetchError?: Error;
    storage?: Storage | null;
    now?: () => number;
}

export function memoryStorage(): Storage {
    const map = new Map<string, string>();

    return {
        get length() { return map.size; },
        clear: () => map.clear(),
        getItem: (key) => map.get(key) ?? null,
        key: (index) => [...map.keys()][index] ?? null,
        removeItem: (key) => { map.delete(key); },
        setItem: (key, value) => { map.set(key, String(value)); },
    };
}

export function clientHarness(options: ClientHarnessOptions = {}) {
    const push = fakePushManager(options.existing ?? null);
    const container = fakeContainer(push.pushManager as never);
    const requests: Array<{ url: string; init: RequestInit }> = [];
    const notification: NotificationApi & { permission: NotificationPermission; requestCalls: number } = {
        permission: options.permission ?? 'default',
        requestCalls: 0,
        requestPermission(callback) {
            notification.requestCalls += 1;
            notification.permission = options.grant ?? 'granted';

            if (options.callbackOnly) {
                callback?.(notification.permission);

                return undefined;
            }

            return Promise.resolve(notification.permission);
        },
    };
    const fetch = vi.fn(async (url: string | URL | Request, init?: RequestInit) => {
        requests.push({ url: String(url), init: init ?? {} });

        if (options.fetchError) {
            throw options.fetchError;
        }

        return new Response(null, { status: options.status ?? 204 });
    });
    const storage = options.storage === undefined ? memoryStorage() : options.storage;
    const env: Partial<ClientEnvironment> = {
        serviceWorker: container.container,
        notification,
        pushManager: { supportedContentEncodings: 'encodings' in options ? options.encodings : ['aes128gcm', 'aesgcm'] },
        fetch: fetch as unknown as typeof globalThis.fetch,
        storage: () => storage,
        now: options.now ?? (() => 1_700_000_000_000),
    };
    const client = new WebPushClient({ ...BASE_CONFIG, ...options.config }, env);

    return { client, push, container, requests, notification, fetch, storage };
}

export function bodyOf(request: { init: RequestInit }): any {
    return JSON.parse(String(request.init.body));
}
