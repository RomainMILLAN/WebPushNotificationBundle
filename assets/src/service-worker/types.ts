/**
 * The slice of ServiceWorkerGlobalScope this package touches.
 *
 * Declared here instead of pulling the "WebWorker" lib, which conflicts with "DOM" in a
 * single compilation. It is also the test seam: every capability (caches, fetch,
 * clients, navigator) is read from the scope passed to installWebPush(), never from a
 * global, so a synthetic scope exercises the real code.
 */

export interface WorkerExtendableEvent {
    waitUntil(promise: Promise<unknown>): void;
}

export interface WorkerPushMessageData {
    json(): unknown;
    text(): string;
}

export interface WorkerPushEvent extends WorkerExtendableEvent {
    readonly data: WorkerPushMessageData | null;
}

export interface WorkerNotification {
    readonly data: unknown;
    readonly tag?: string;
    close(): void;
}

export interface WorkerNotificationEvent extends WorkerExtendableEvent {
    readonly action: string;
    readonly notification: WorkerNotification;
}

export interface WorkerMessagePort {
    postMessage(message: unknown): void;
}

export interface WorkerMessageEvent extends WorkerExtendableEvent {
    readonly data: unknown;
    readonly ports?: readonly WorkerMessagePort[];
    readonly source?: { postMessage(message: unknown): void } | null;
}

export interface WorkerWindowClient {
    readonly id?: string;
    readonly url: string;
    readonly focused?: boolean;
    readonly visibilityState?: string;
    focus?(): Promise<unknown>;
    navigate?(url: string): Promise<{ url: string } | null>;
    postMessage(message: unknown): void;
}

export interface WorkerClients {
    matchAll(options: { type: 'window'; includeUncontrolled: boolean }): Promise<readonly WorkerWindowClient[]>;
    openWindow(url: string): Promise<unknown>;
    claim(): Promise<void>;
}

export interface WorkerNotificationAction {
    action: string;
    title: string;
}

export interface WorkerNotificationOptions {
    body?: string;
    tag?: string;
    renotify?: boolean;
    requireInteraction?: boolean;
    silent?: boolean;
    icon?: string;
    badge?: string;
    actions?: WorkerNotificationAction[];
    data?: unknown;
}

export interface WorkerRegistration {
    readonly scope: string;
    showNotification(title: string, options?: WorkerNotificationOptions): Promise<void>;
}

export interface WorkerCache {
    match(key: string): Promise<Response | undefined>;
    put(key: string, response: Response): Promise<void>;
    delete(key: string): Promise<boolean>;
}

export interface WorkerCacheStorage {
    open(name: string): Promise<WorkerCache>;
    delete(name: string): Promise<boolean>;
}

export interface WorkerNavigator {
    setAppBadge?(count?: number): Promise<void>;
    clearAppBadge?(): Promise<void>;
}

export type WorkerEventMap = {
    install: WorkerExtendableEvent;
    activate: WorkerExtendableEvent;
    push: WorkerPushEvent;
    notificationclick: WorkerNotificationEvent;
    message: WorkerMessageEvent;
};

export interface WorkerScope {
    readonly location: { readonly origin: string };
    readonly registration: WorkerRegistration;
    readonly clients: WorkerClients;
    readonly caches: WorkerCacheStorage;
    readonly navigator?: WorkerNavigator;
    fetch(input: string, init?: RequestInit): Promise<Response>;
    skipWaiting(): Promise<void> | void;
    addEventListener<K extends keyof WorkerEventMap>(type: K, listener: (event: WorkerEventMap[K]) => void): void;
}
