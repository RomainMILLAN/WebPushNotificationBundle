import { resolveClickPath, isAllowedPathname } from '../click_path';
import { MessageType } from '../contract';
import { isRecord, normalizeServiceWorkerConfig, type ServiceWorkerConfig } from './config';
import { ACTION_TYPES, buildNotification, parsePayload, type ActionType, type NotificationData, type PushPayload } from './payload';
import type { ClickOutcome, ClickRecord, IntentStatus, PluginContext, Reply, WebPushPlugin } from './plugin';
import { StateStore } from './state_store';
import type { WorkerMessageEvent, WorkerNotificationEvent, WorkerPushEvent, WorkerScope, WorkerWindowClient } from './types';

export interface InstallOptions {
    /** Raw or normalized config; normalized again, so untrusted input is fine. */
    config?: Partial<ServiceWorkerConfig> | unknown;
    plugins?: readonly WebPushPlugin[];
    /**
     * skipWaiting() on install and clients.claim() on activate. Turn it off when an
     * application worker imports this one and owns its own lifecycle policy.
     */
    manageLifecycle?: boolean;
}

export interface InstalledWebPush {
    readonly config: ServiceWorkerConfig;
    readonly context: PluginContext;
}

/**
 * Marks the installation ON the scope, under a registry symbol shared by every copy of
 * this code. A module-local WeakMap is not enough: `importScripts('/web-push-sw.js')`
 * run twice evaluates the IIFE twice, and an app worker may also bundle the ES build;
 * each copy would get its own map and install every handler again.
 */
const INSTALLED = Symbol.for('romainmillan.web-push.installed');

type MarkedScope = { [INSTALLED]?: InstalledWebPush };

/**
 * Wires the push, notificationclick and message handlers on a worker scope.
 *
 * Idempotent per scope, across bundles: a second call (a script imported twice, or
 * the ES build next to the prebuilt worker) returns the first
 * installation instead of registering every handler twice, which would display every
 * notification twice.
 */
export function installWebPush(scope: WorkerScope, options: InstallOptions = {}): InstalledWebPush {
    const existing = (scope as unknown as MarkedScope)[INSTALLED];

    if (existing) {
        return existing;
    }

    const config = normalizeServiceWorkerConfig(options.config);
    const plugins = [...(options.plugins ?? [])];
    const context: PluginContext = { scope, config, state: new StateStore(scope.caches, config.stateCache) };
    const result: InstalledWebPush = { config, context };

    // Non-enumerable: a spread copy of the scope is a different scope.
    Object.defineProperty(scope, INSTALLED, { value: result, configurable: true });

    if (options.manageLifecycle ?? true) {
        scope.addEventListener('install', () => {
            void Promise.resolve(scope.skipWaiting()).catch(() => {});
        });
    }

    scope.addEventListener('activate', (event) => {
        const claim = (options.manageLifecycle ?? true) ? scope.clients.claim().catch(() => {}) : Promise.resolve();

        event.waitUntil(Promise.all([claim, notify(plugins, (plugin) => plugin.onActivate?.(context))]));
    });

    scope.addEventListener('push', (event) => {
        event.waitUntil(handlePush(event, context, plugins));
    });

    scope.addEventListener('notificationclick', (event) => {
        event.notification.close();
        event.waitUntil(handleClick(event, context, plugins));
    });

    scope.addEventListener('message', (event) => {
        const message = event.data;

        if (!isRecord(message) || typeof message.type !== 'string') {
            return;
        }

        const reply = replyTo(event);
        const typed = message as { type: string; [key: string]: unknown };

        event.waitUntil(notify(plugins, (plugin) => plugin.onMessage?.(typed, reply, context)));
    });

    return result;
}

/**
 * The plugins NEVER condition the display: a rejection reaching waitUntil() makes iOS
 * show its generic "this site has been updated in the background" notification, or
 * nothing at all.
 */
async function handlePush(event: WorkerPushEvent, context: PluginContext, plugins: readonly WebPushPlugin[]): Promise<void> {
    const payload = readPayload(event);
    const { title, options } = buildNotification(payload, context.config, context.scope.location.origin);

    await Promise.all([
        context.scope.registration.showNotification(title, options),
        notify(plugins, (plugin) => plugin.onPush?.(payload, context)),
    ]);
}

function readPayload(event: WorkerPushEvent): PushPayload | null {
    if (!event.data) {
        return null;
    }

    try {
        return parsePayload(event.data.json());
    } catch {
        return null;
    }
}

interface ClickAction {
    type: ActionType;
    url: string | null;
}

/**
 * The order IS the design: resolve the action, validate the destination, write the
 * intent BEFORE any attempt (covers a worker killed mid-flight and a frozen page), then
 * try to land.
 */
async function handleClick(event: WorkerNotificationEvent, context: PluginContext, plugins: readonly WebPushPlugin[]): Promise<void> {
    // Without this guard a notification without data throws SYNCHRONOUSLY: iOS brings
    // the app to the foreground without navigating, and leaves no trace.
    const data = readNotificationData(event.notification.data);
    const action = event.action !== '' ? data.actions[event.action] : undefined;

    if (action?.type === 'dismiss') {
        return;
    }

    if (action?.type === 'post') {
        await handlePost(action.url, context, plugins);

        return;
    }

    const raw = action?.type === 'navigate' ? action.url : data.click;

    await navigateTo(raw, context, plugins);
}

/**
 * A `post` action calls a URL carrying its own authorization (signed), same-origin only:
 * the worker holds the session cookies of the origin, it must not send them elsewhere.
 */
async function handlePost(url: string | null, context: PluginContext, plugins: readonly WebPushPlugin[]): Promise<void> {
    const origin = context.scope.location.origin;
    let target: URL | null = null;

    try {
        target = url === null ? null : new URL(url, origin);
    } catch {
        target = null;
    }

    if (target === null || target.origin !== origin) {
        await record(plugins, context, { outcome: 'rejected', verdict: target === null ? 'rejected-parse' : 'rejected-cross-origin', clientCount: null, intent: 'not-applicable' });

        return;
    }

    let outcome: ClickOutcome = 'post-failed';

    try {
        const response = await context.scope.fetch(target.href, { method: 'POST', credentials: 'same-origin' });

        outcome = response.ok ? 'post-sent' : 'post-failed';
    } catch {
        outcome = 'post-failed';
    }

    await record(plugins, context, { outcome, verdict: 'accepted', clientCount: null, intent: 'not-applicable' });
}

async function navigateTo(raw: string | null, context: PluginContext, plugins: readonly WebPushPlugin[]): Promise<void> {
    const { scope, config } = context;
    const { clickPath, verdict } = resolveClickPath(raw, scope.registration.scope, scope.location.origin, config.clickPrefixes);

    // The rejection path is TERMINAL: no intent, a single trail entry.
    if (verdict !== 'accepted') {
        const outcome: ClickOutcome = verdict === 'no-destination' ? 'focus-only' : 'rejected';

        await record(plugins, context, { outcome, verdict, clientCount: null, intent: 'not-applicable' });
        await focusOrOpen(scope, '/');

        return;
    }

    const intent = await beforeNavigate(plugins, context, clickPath);
    const clients = await matchWindows(scope);
    const candidate = bestCandidate(clients, clickPath, config.clickPrefixes);

    if (!candidate) {
        // openWindow() may not navigate an installed iOS app: the intent is KEPT.
        await scope.clients.openWindow(clickPath).catch(() => null);
        await record(plugins, context, { outcome: 'new-window', verdict, clientCount: clients.length, intent });

        return;
    }

    for (const attempt of ATTEMPTS) {
        let outcome: ClickOutcome | null = null;

        try {
            outcome = await attempt(candidate, clickPath);
        } catch {
            // An exception means "I could not", never "stop": the chain goes on.
            outcome = null;
        }

        if (outcome) {
            await notify(plugins, (plugin) => plugin.onNavigated?.(clickPath, context));
            await record(plugins, context, { outcome, verdict, clientCount: clients.length, intent });

            return;
        }
    }

    // The nudge is NOT an attempt: it would always answer "yes". It carries no payload
    // (claim check): the page fetches the destination from the authoritative store.
    try {
        candidate.postMessage({ type: MessageType.claimNavigationIntent });
    } catch {
        // A detached client: the intent stays for the next page to claim it.
    }

    await record(plugins, context, { outcome: 'nudged', verdict, clientCount: clients.length, intent });
}

type Attempt = (client: WorkerWindowClient, clickPath: string) => Promise<ClickOutcome | null>;

/** Chain of responsibility: adding a way to land is one more entry. */
const ATTEMPTS: readonly Attempt[] = [
    async function focusIfAlreadyThere(client, clickPath) {
        if (!samePath(client.url, clickPath)) {
            return null;
        }

        // We ARE on the target: a refused focus does not change that fact.
        await tryFocus(client);

        return 'already-there';
    },

    async function navigateOwnWindow(client, clickPath) {
        const before = client.url;

        // focus() and navigate() are independent capabilities of the same client.
        await tryFocus(client);

        if (typeof client.navigate !== 'function') {
            return null;
        }

        const navigated = await client.navigate(clickPath);
        const after = navigated?.url ?? client.url;

        // Postcondition by OBSERVATION: on iOS standalone navigate() may resolve without
        // moving the page. "The URL changed", not "it equals the target", so that a
        // server redirect does not pass for a failure.
        return after !== before ? 'navigated' : null;
    },
];

/**
 * The ranking order matters: includeUncontrolled may put a non-navigable client first,
 * so taking the first focusable one is not enough.
 */
function bestCandidate(clients: readonly WorkerWindowClient[], clickPath: string, prefixes: readonly string[]): WorkerWindowClient | null {
    const ranked = clients
        .map((client) => ({ client, rank: rankOf(client, clickPath, prefixes) }))
        .sort((a, b) => a.rank - b.rank);

    return ranked[0]?.client ?? null;
}

function rankOf(client: WorkerWindowClient, clickPath: string, prefixes: readonly string[]): number {
    if (samePath(client.url, clickPath)) {
        return 0;
    }

    // Under a click prefix: it loads the application, so it can honour a nudge.
    const pathname = pathnameOf(client.url);

    if (pathname !== '/' && pathname !== '' && isAllowedPathname(pathname, prefixes)) {
        return 1;
    }

    if (client.focused) {
        return 2;
    }

    if (client.visibilityState === 'visible') {
        return 3;
    }

    return 4;
}

/**
 * The failure path, the one that must hold best: a focus() rejection must not skip
 * openWindow(), or the click leads nowhere.
 */
async function focusOrOpen(scope: WorkerScope, path: string): Promise<void> {
    const clients = await matchWindows(scope);
    const first = clients[0];

    if (first && await tryFocus(first)) {
        return;
    }

    await scope.clients.openWindow(path).catch(() => null);
}

async function tryFocus(client: WorkerWindowClient): Promise<boolean> {
    if (typeof client.focus !== 'function') {
        return false;
    }

    try {
        await client.focus();

        return true;
    } catch {
        return false;
    }
}

async function matchWindows(scope: WorkerScope): Promise<readonly WorkerWindowClient[]> {
    try {
        return await scope.clients.matchAll({ type: 'window', includeUncontrolled: true });
    } catch {
        return [];
    }
}

async function beforeNavigate(plugins: readonly WebPushPlugin[], context: PluginContext, clickPath: string): Promise<IntentStatus> {
    let status: IntentStatus = 'disabled';

    for (const plugin of plugins) {
        if (!plugin.onBeforeNavigate) {
            continue;
        }

        try {
            const result = await plugin.onBeforeNavigate(clickPath, context);

            if (result && status === 'disabled') {
                status = result;
            }
        } catch {
            // A failed intent write degrades the frozen-page recovery, not the click.
        }
    }

    return status;
}

function record(plugins: readonly WebPushPlugin[], context: PluginContext, entry: ClickRecord): Promise<void> {
    return notify(plugins, (plugin) => plugin.onClick?.(entry, context));
}

/** Runs one hook on every plugin, sequentially, swallowing every failure. */
async function notify(plugins: readonly WebPushPlugin[], hook: (plugin: WebPushPlugin) => unknown): Promise<void> {
    for (const plugin of plugins) {
        try {
            await hook(plugin);
        } catch {
            // Isolation: one plugin never breaks the others nor the core.
        }
    }
}

function replyTo(event: WorkerMessageEvent): Reply {
    return (message) => {
        const port = event.ports?.[0];

        if (port) {
            port.postMessage(message);

            return;
        }

        event.source?.postMessage(message);
    };
}

function readNotificationData(raw: unknown): { click: string | null; actions: Record<string, ClickAction> } {
    const data: Partial<NotificationData> = isRecord(raw) ? raw : {};
    const actions: Record<string, ClickAction> = {};

    if (isRecord(data.actions)) {
        for (const [name, value] of Object.entries(data.actions)) {
            if (isRecord(value) && typeof value.type === 'string' && (ACTION_TYPES as readonly string[]).includes(value.type)) {
                actions[name] = { type: value.type as ActionType, url: typeof value.url === 'string' ? value.url : null };
            }
        }
    }

    return { click: typeof data.click === 'string' ? data.click : null, actions };
}

function pathnameOf(rawUrl: string): string {
    try {
        return new URL(rawUrl).pathname;
    } catch {
        return '';
    }
}

function samePath(rawUrl: string, clickPath: string): boolean {
    try {
        const url = new URL(rawUrl);

        return url.pathname + url.search === clickPath;
    } catch {
        return false;
    }
}
