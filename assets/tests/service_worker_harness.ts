import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { createContext, runInContext } from 'node:vm';

import { INTENT_KEY, MARKER_KEY, TRAIL_KEY } from '../src/contract';
import { badge, clientState, diagnostics, installWebPush, navigationIntent, type WebPushPlugin } from '../src/service-worker/index';
import { normalizeServiceWorkerConfig, type ServiceWorkerConfig } from '../src/service-worker/config';

export const ORIGIN = 'https://app.example.com';
export const MARKER = 'a3f19c4e0b7d2851';

export const TEST_CONFIG: ServiceWorkerConfig = {
    fallbackTitle: 'Fallback title',
    icon: '/default-icon.png',
    badge: '/default-badge.png',
    clickPrefixes: ['/app/'],
    assetHosts: ['cdn.example.com'],
    stateCache: 'web-push-state',
};

type Listener = (event: any) => void;

export interface FakeClient {
    id: string;
    url: string;
    focused: boolean;
    visibilityState: string;
    focus: () => Promise<FakeClient>;
    navigate?: (path: string) => Promise<{ url: string } | null>;
    postMessage: (message: unknown) => void;
    readonly messages: unknown[];
}

export interface FakeClientOptions {
    url?: string;
    focused?: boolean;
    visibilityState?: string;
    /** The iOS no-op: navigate() resolves WITHOUT changing url. A faithful double lies like the real object. */
    navigateBehaviour?: 'moves' | 'no-op' | 'rejects' | 'absent';
    focusBehaviour?: 'resolves' | 'rejects';
}

export function fakeClient(id: string, options: FakeClientOptions = {}): FakeClient {
    const messages: unknown[] = [];
    const client: any = {
        id,
        url: options.url ?? `${ORIGIN}/app/dashboard`,
        focused: options.focused ?? false,
        visibilityState: options.visibilityState ?? 'hidden',
        messages,
        focus: () => (options.focusBehaviour === 'rejects'
            ? Promise.reject(new Error('focus refused'))
            : Promise.resolve(client)),
        postMessage: (message: unknown) => { messages.push(message); },
    };

    const behaviour = options.navigateBehaviour ?? 'moves';

    if (behaviour !== 'absent') {
        client.navigate = (path: string) => {
            if (behaviour === 'rejects') {
                return Promise.reject(new Error('navigate refused'));
            }

            if (behaviour === 'moves') {
                client.url = `${ORIGIN}${path}`;
            }

            return Promise.resolve({ url: client.url });
        };
    }

    return client as FakeClient;
}

/**
 * Cache Storage on Maps, with the delete() return semantics that carry the claim, and
 * key normalization to absolute URLs as the real one does.
 */
export function fakeCaches(origin = ORIGIN) {
    const stores = new Map<string, Map<string, Response>>();
    const keyOf = (key: unknown): string => new URL(typeof key === 'string' ? key : (key as { url: string }).url, origin).href;

    const open = async (name: string) => {
        if (!stores.has(name)) {
            stores.set(name, new Map());
        }

        const store = stores.get(name)!;

        return {
            // The real Cache Storage CLONES on every match: a body is read once.
            match: async (key: unknown) => store.get(keyOf(key))?.clone(),
            put: async (key: unknown, response: Response) => { store.set(keyOf(key), response); },
            delete: async (key: unknown) => store.delete(keyOf(key)),
        };
    };

    return {
        open,
        delete: async (name: string) => stores.delete(name),
        has: async (name: string) => stores.has(name),
        keys: async () => [...stores.keys()],
        stores,
    };
}

export interface HarnessOptions {
    /** Raw config given to the worker; TEST_CONFIG when the key is absent. */
    config?: unknown;
    plugins?: WebPushPlugin[];
    fetch?: (input: string, init?: RequestInit) => Promise<Response>;
    navigator?: Record<string, unknown>;
    /** Evaluate the BUILT dist/web-push-sw.js instead of calling installWebPush(). */
    standalone?: boolean;
    /**
     * Source of an APPLICATION worker, run as a classic script whose global object is
     * `self` (node:vm). Its importScripts(url) evaluates, in that same global scope,
     * the script `scripts[url]` returns. Takes precedence over `standalone`.
     */
    appWorker?: { source: string; scripts: Record<string, string> };
}

/** dist/web-push-sw.js as built. */
export function prebuiltWorker(): string {
    return readFileSync(resolve(import.meta.dirname, '../dist/web-push-sw.js'), 'utf8');
}

/** dist/web-push-sw.js as the PHP route serves it (ServiceWorkerScript::render()). */
export function servedWorker(config: unknown): string {
    return `self.__WEB_PUSH_CONFIG__ = ${JSON.stringify(config)};\n${prebuiltWorker()}`;
}

export function loadServiceWorker(clientsList: FakeClient[] = [], options: HarnessOptions = {}) {
    const listeners = new Map<string, Listener[]>();
    const openedWindows: string[] = [];
    const shownNotifications: Array<{ title: string; options: any }> = [];
    const fetchCalls: Array<{ url: string; init: RequestInit | undefined }> = [];
    const cacheStorage = fakeCaches();
    const config = 'config' in options ? options.config : TEST_CONFIG;
    const stateCache = normalizeServiceWorkerConfig(config).stateCache;
    let skipWaitingCalls = 0;
    let claimCalls = 0;

    const self: any = {
        addEventListener: (type: string, listener: Listener) => {
            listeners.set(type, [...(listeners.get(type) ?? []), listener]);
        },
        skipWaiting: () => { skipWaitingCalls += 1; return Promise.resolve(); },
        location: { origin: ORIGIN },
        registration: {
            scope: `${ORIGIN}/`,
            showNotification: (title: string, notificationOptions: any) => {
                shownNotifications.push({ title, options: notificationOptions });

                return Promise.resolve();
            },
        },
        clients: {
            claim: () => { claimCalls += 1; return Promise.resolve(); },
            matchAll: () => Promise.resolve(clientsList),
            openWindow: (path: string) => {
                openedWindows.push(path);

                return Promise.resolve(null);
            },
        },
        caches: cacheStorage,
        navigator: options.navigator ?? {},
        fetch: (url: string, init?: RequestInit) => {
            fetchCalls.push({ url, init });

            return options.fetch ? options.fetch(url, init) : Promise.resolve(new Response(null, { status: 204 }));
        },
    };

    if (options.appWorker) {
        const { source, scripts } = options.appWorker;
        const imported: string[] = [];

        self.self = self;
        self.URL = URL;
        self.Response = Response;
        self.console = console;
        self.importedScripts = imported;
        self.importScripts = (...urls: string[]) => {
            for (const url of urls) {
                const script = scripts[url];

                if (script === undefined) {
                    throw new Error(`importScripts: no script at ${url}`);
                }

                imported.push(url);
                // A classic script: runInContext() throws a SyntaxError on import/export.
                runInContext(script, self, { filename: url });
            }
        };
        createContext(self);
        runInContext(source, self, { filename: '/sw.js' });
    } else if (options.standalone) {
        self.__WEB_PUSH_CONFIG__ = config;
        // eslint-disable-next-line no-new-func
        new Function('self', prebuiltWorker())(self);
    } else {
        installWebPush(self, {
            config,
            plugins: options.plugins ?? [clientState(), navigationIntent(), diagnostics(), badge()],
        });
    }

    const readJson = async (key: string) => {
        const cache = await cacheStorage.open(stateCache);
        const stored = await cache.match(key);

        return stored ? stored.json() : null;
    };

    /** Returns the promises given to event.waitUntil(): every assertion waits for the handler. */
    const dispatch = async (type: string, event: any = {}) => {
        const waits: Array<Promise<unknown>> = [];
        const enriched = { ...event, waitUntil: (promise: Promise<unknown>) => { waits.push(promise); } };

        for (const listener of listeners.get(type) ?? []) {
            listener(enriched);
        }

        await Promise.all(waits);
    };

    return {
        self,
        dispatch,
        clientsList,
        openedWindows,
        shownNotifications,
        fetchCalls,
        caches: cacheStorage,
        stateCache,
        get skipWaitingCalls() { return skipWaitingCalls; },
        get claimCalls() { return claimCalls; },
        listenerCount: (type: string) => listeners.get(type)?.length ?? 0,
        readIntent: () => readJson(INTENT_KEY),
        readMarker: () => readJson(MARKER_KEY),
        readTrail: async () => (await readJson(TRAIL_KEY)) ?? [],
        writeIntent: async (intent: unknown) => {
            const cache = await cacheStorage.open(stateCache);

            await cache.put(INTENT_KEY, new Response(JSON.stringify(intent)));
        },
        /** What a page does on load: announce its client state marker. */
        announceMarker: (marker: string | null = MARKER) => dispatch('message', { data: { type: 'client-state', marker } }),
        push: (payload: unknown) => dispatch('push', { data: pushData(payload) }),
        click: (data: unknown, action = '') => dispatch('notificationclick', notificationClickEvent(data, action)),
        /** Sends a message through a MessageChannel-like port and returns the replies. */
        ask: async (data: unknown) => {
            const replies: unknown[] = [];

            await dispatch('message', { data, ports: [{ postMessage: (message: unknown) => { replies.push(message); } }] });

            return replies;
        },
    };
}

export function pushData(payload: unknown): { json: () => unknown; text: () => string } | null {
    if (payload === null) {
        return null;
    }

    const text = typeof payload === 'string' ? payload : JSON.stringify(payload);

    return { json: () => JSON.parse(text), text: () => text };
}

export function notificationClickEvent(data: unknown, action = ''): any {
    let closed = 0;

    return {
        action,
        notification: { data, close: () => { closed += 1; }, get closed() { return closed; } },
    };
}

/** Pushes a payload and returns the data the notification carries, ready to be clicked. */
export async function showAndGetData(sw: ReturnType<typeof loadServiceWorker>, payload: unknown): Promise<any> {
    await sw.push(payload);

    return sw.shownNotifications.at(-1)!.options.data;
}
