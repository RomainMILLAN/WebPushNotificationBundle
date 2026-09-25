import type { PageConfig } from '../src/client/config';
import { urlBase64ToUint8Array } from '../src/client/web_push_client';

/** A valid P-256 public key shape (65 bytes, base64url). Only the bytes matter here. */
export const PUBLIC_KEY = 'BEl62iUYgUivxIkv69yViEuiBIa-Ib9-SkvMeAtA3LFgDzkrxZJjSgSnfckjBJuBkr3qBUYIHBQFLXYp5Nksh8U';
export const ROTATED_KEY = 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Ts1XbjhazAkj7I99e8QcYP7DkM';

export const BASE_CONFIG: PageConfig = {
    publicKey: PUBLIC_KEY,
    serviceWorker: '/web-push-sw.js',
    subscribe: '/web-push/subscribe',
    unsubscribe: '/web-push/unsubscribe',
    clickPrefixes: ['/app/'],
    csrfHeader: 'X-CSRF-Token',
    csrfToken: 'tok-123',
    clientState: '',
    stateCache: 'web-push-state',
};

/** Renders <meta name="web-push-config"> as the PHP ClientConfiguration does. */
export function renderConfig(overrides: Partial<PageConfig> = {}, doc: Document = document): PageConfig {
    const config = { ...BASE_CONFIG, ...overrides };
    const meta = doc.createElement('meta');

    meta.setAttribute('name', 'web-push-config');
    meta.setAttribute('content', JSON.stringify(config));
    doc.head.replaceChildren(meta);

    return config;
}

export interface FakeSubscription {
    endpoint: string;
    options: { applicationServerKey: ArrayBuffer | null; userVisibleOnly: boolean };
    unsubscribed: boolean;
    toJSON(): { endpoint: string; expirationTime: null; keys: { p256dh: string; auth: string } };
    unsubscribe(): Promise<boolean>;
}

export function fakeSubscription(publicKey: string | null, endpoint = 'https://push.example.net/send/abc'): FakeSubscription {
    const subscription: FakeSubscription = {
        endpoint,
        options: { applicationServerKey: publicKey === null ? null : urlBase64ToUint8Array(publicKey).buffer, userVisibleOnly: true },
        unsubscribed: false,
        toJSON: () => ({ endpoint, expirationTime: null, keys: { p256dh: 'p256dh-value', auth: 'auth-value' } }),
        unsubscribe: async () => {
            subscription.unsubscribed = true;

            return true;
        },
    };

    return subscription;
}

export function fakePushManager(existing: FakeSubscription | null = null) {
    const subscribeCalls: Array<{ userVisibleOnly: boolean; applicationServerKey: Uint8Array }> = [];
    let current: FakeSubscription | null = existing;

    return {
        subscribeCalls,
        get current() { return current; },
        pushManager: {
            getSubscription: async () => (current?.unsubscribed ? null : current),
            subscribe: async (options: { userVisibleOnly: boolean; applicationServerKey: Uint8Array }) => {
                subscribeCalls.push(options);
                current = fakeSubscription(null, 'https://push.example.net/send/fresh');
                current.options.applicationServerKey = options.applicationServerKey.buffer as ArrayBuffer;

                return current;
            },
        },
    };
}

/** A ServiceWorkerContainer double: registration, controller, messages. */
export function fakeContainer(pushManager = fakePushManager().pushManager) {
    const posted: unknown[] = [];
    const target = new EventTarget();
    const state = {
        registerCalls: [] as Array<{ url: string; options: unknown }>,
        updateCalls: 0,
        onPost: (_message: unknown): void => {},
    };
    const worker = {
        postMessage: (message: unknown) => {
            posted.push(message);
            state.onPost(message);
        },
    };
    const registration = {
        active: worker,
        pushManager,
        update: async () => { state.updateCalls += 1; },
    };
    const container: any = {
        controller: worker,
        ready: Promise.resolve(registration),
        register: async (url: string, options: unknown) => {
            state.registerCalls.push({ url, options });

            return registration;
        },
        getRegistration: async () => registration,
        startMessages: () => {},
        addEventListener: target.addEventListener.bind(target),
        removeEventListener: target.removeEventListener.bind(target),
        dispatchEvent: target.dispatchEvent.bind(target),
    };

    return {
        container,
        registration,
        posted,
        get registerCalls() { return state.registerCalls; },
        get updateCalls() { return state.updateCalls; },
        set onPost(listener: (message: unknown) => void) { state.onPost = listener; },
    };
}

/** Cache Storage on a Map, with the delete() return value that carries the atomic claim. */
export function fakeWindowCaches() {
    const store = new Map<string, Response>();
    const caches = {
        open: async () => ({
            match: async (key: string) => store.get(key)?.clone(),
            put: async (key: string, response: Response) => { store.set(key, response); },
            delete: async (key: string) => store.delete(key),
        }),
    };

    return { store, caches: caches as unknown as CacheStorage };
}
