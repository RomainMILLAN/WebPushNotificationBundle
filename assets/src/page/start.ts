import { readPageConfig } from '../client/config';
import { WebPushClient } from '../client/web_push_client';
import { MessageType } from '../contract';
import { claimNavigationIntent, readRenderedMarker, type ClaimResult } from './navigation_intent';

/** An installed iOS PWA almost never navigates: ask for a fresh script when coming back, at most this often. */
export const UPDATE_THROTTLE_MS = 60_000;

export const DEFAULT_LOGOUT_SELECTOR = 'form[data-web-push-logout]';

/** A logout must never be held back longer than this by the unsubscription. */
export const DEFAULT_LOGOUT_TIMEOUT_MS = 1_000;

export interface StartOptions {
    /** Unsubscribe this device when a logout form is submitted. Default true. */
    unsubscribeOnLogout?: boolean;
    logoutSelector?: string;
    logoutTimeoutMs?: number;
    /** Run client.sync() once the worker is registered. Default true. */
    sync?: boolean;
    /** Injected navigation (tests); defaults to location.assign(). */
    navigate?: (path: string) => void;
    client?: WebPushClient;
    serviceWorker?: ServiceWorkerContainer;
    /**
     * URL of an application worker that pulls the package in with
     * `importScripts('/web-push-sw.js')`. Takes precedence over the meta `serviceWorker`
     * field. Must be a same-origin, root-relative path (e.g. '/sw.js'); anything else is
     * ignored and the meta value is used.
     */
    serviceWorkerUrl?: string;
}

export interface WebPushPage {
    readonly client: WebPushClient;
    /** Resolves to null when the worker could not be registered. */
    readonly registration: Promise<ServiceWorkerRegistration | null>;
    /** Serialized claim of the navigation intent. */
    claim(): Promise<ClaimResult>;
    /** Posts `forget-client-state` to the worker. Synchronous on purpose. */
    forgetClientState(): void;
    /** Removes every listener (tests, teardown). */
    stop(): void;
}

/** Pure decision, testable without mocking Date nor observing a request. */
export function shouldCheckForUpdate(lastCheckAt: number | null, now: number): boolean {
    return lastCheckAt === null || now - lastCheckAt >= UPDATE_THROTTLE_MS;
}

/**
 * Returns `candidate` normalized to path + query when it is a root-relative path of
 * `origin`, else null. A protocol-relative ('//host') or backslash form ('/\\host') is
 * refused before resolution: browsers read both as another host.
 */
export function sameOriginPath(candidate: unknown, origin: string): string | null {
    if (typeof candidate !== 'string' || !candidate.startsWith('/') || /^\/[\/\\]/.test(candidate) || /[\u0000-\u001f\\]/.test(candidate)) {
        return null;
    }

    let resolved: URL;

    try {
        resolved = new URL(candidate, origin);
    } catch {
        return null;
    }

    return resolved.origin === origin ? `${resolved.pathname}${resolved.search}` : null;
}

const started = new WeakMap<Document, WebPushPage>();

/**
 * Starts Web Push on a page: the SINGLE registration point of the worker, the claim of
 * navigation intents, and the logout hook. Idempotent per document. Returns null when
 * the page carries no <meta name="web-push-config">.
 */
export function startWebPush(doc: Document = document, options: StartOptions = {}): WebPushPage | null {
    const existing = started.get(doc);

    if (existing) {
        return existing;
    }

    const config = readPageConfig(doc);
    const view = doc.defaultView;

    if (config === null || view === null) {
        return null;
    }

    const client = options.client ?? new WebPushClient(config);
    const workerUrl = resolveWorkerUrl(options.serviceWorkerUrl, config.serviceWorker, view.location.origin);
    const container = options.serviceWorker ?? ('serviceWorker' in view.navigator ? view.navigator.serviceWorker : undefined);
    const cleanups: Array<() => void> = [];
    const listen = (target: EventTarget, type: string, listener: EventListener, capture = false): void => {
        target.addEventListener(type, listener, capture);
        cleanups.push(() => target.removeEventListener(type, listener, capture));
    };

    let knownRegistration: ServiceWorkerRegistration | null = null;
    let lastUpdateCheckAt: number | null = null;
    let pendingClaim: Promise<ClaimResult> | null = null;

    const activeWorker = (): ServiceWorker | null => container?.controller ?? knownRegistration?.active ?? null;

    /** One sequence at a time: pageshow, focus and visibilitychange fire together. */
    const claim = (): Promise<ClaimResult> => {
        if (pendingClaim) {
            return pendingClaim;
        }

        pendingClaim = claimNavigationIntent({ doc, navigate: options.navigate })
            .catch((): ClaimResult => 'unsupported')
            .finally(() => { pendingClaim = null; });

        return pendingClaim;
    };

    const sendClientState = (): void => {
        // Abstain on a Turbo preview: its <head> is a snapshot.
        if (doc.documentElement.hasAttribute('data-turbo-preview')) {
            return;
        }

        try {
            activeWorker()?.postMessage({ type: MessageType.clientState, marker: readRenderedMarker(doc) });
        } catch {
            // A redundant worker: the next page load announces the marker again.
        }
    };

    const forgetClientState = (): void => {
        // A PROPERTY read, never `await ready`: a purge must never hold back a logout.
        // The worker's waitUntil() keeps it alive after the page has navigated away.
        try {
            activeWorker()?.postMessage({ type: MessageType.forgetClientState });
        } catch {
            // Nothing to purge without a worker.
        }
    };

    const checkForUpdate = (): void => {
        const now = Date.now();

        if (knownRegistration === null || !shouldCheckForUpdate(lastUpdateCheckAt, now)) {
            return;
        }

        lastUpdateCheckAt = now;
        // No automatic reload: skipWaiting() + clients.claim() let the new worker take
        // over by itself. update() fails offline, which must not surface.
        void knownRegistration.update().catch(() => {});
    };

    const registration: Promise<ServiceWorkerRegistration | null> = container === undefined
        ? Promise.resolve(null)
        : container.register(workerUrl, { scope: '/', updateViaCache: 'none' })
            .then((registered) => {
                knownRegistration = registered;
                lastUpdateCheckAt = Date.now();
                sendClientState();

                if (options.sync ?? true) {
                    void client.sync();
                }

                return registered;
            })
            .catch((error: unknown) => {
                console.warn('[web-push] Service worker registration failed:', error);

                return null;
            });

    if (container !== undefined) {
        listen(container, 'message', ((event: MessageEvent) => {
            // The nudge carries no payload: the page reads the authoritative store.
            if (event.data && event.data.type === MessageType.claimNavigationIntent) {
                void claim();
            }
        }) as EventListener);
        // NOT optional: a client's message queue starts disabled, and addEventListener
        // alone does not enable it. Without this the nudge is queued, never delivered.
        container.startMessages();
        listen(container, 'controllerchange', () => sendClientState());
    }

    listen(view, 'pageshow', () => { void claim(); });
    listen(view, 'focus', () => { void claim(); });
    listen(doc, 'turbo:load', () => {
        sendClientState();
        void claim();
    });
    listen(doc, 'visibilitychange', () => {
        if (doc.visibilityState !== 'visible') {
            return;
        }

        void claim();
        sendClientState();
        checkForUpdate();
    });

    installLogoutHook(doc, listen, {
        selector: options.logoutSelector ?? DEFAULT_LOGOUT_SELECTOR,
        timeoutMs: options.logoutTimeoutMs ?? DEFAULT_LOGOUT_TIMEOUT_MS,
        unsubscribe: (options.unsubscribeOnLogout ?? true) ? () => client.unsubscribe() : null,
        forget: forgetClientState,
    });

    // Catches the frozen iOS page, whenever it thaws.
    void claim();

    const page: WebPushPage = {
        client,
        registration,
        claim,
        forgetClientState,
        stop: () => {
            cleanups.splice(0).forEach((cleanup) => cleanup());
            started.delete(doc);
        },
    };

    started.set(doc, page);

    return page;
}

function resolveWorkerUrl(override: string | undefined, fromMeta: string, origin: string): string {
    if (override === undefined) {
        return fromMeta;
    }

    const path = sameOriginPath(override, origin);

    if (path === null) {
        console.warn('[web-push] Ignoring serviceWorkerUrl: not a same-origin path.', override);

        return fromMeta;
    }

    return path;
}

interface LogoutHook {
    selector: string;
    timeoutMs: number;
    unsubscribe: (() => Promise<unknown>) | null;
    forget: () => void;
}

/**
 * Delegated on the document, on `submit`: any logout form marked with the selector is
 * covered, and no route path is compared here.
 *
 * With unsubscription, the submit is held while the session is still valid (the
 * unsubscribe endpoint needs it), bounded by timeoutMs, then replayed. If this code
 * never runs, the form still logs out: the purge is a bonus, never a gate.
 */
function installLogoutHook(
    doc: Document,
    listen: (target: EventTarget, type: string, listener: EventListener, capture?: boolean) => void,
    hook: LogoutHook,
): void {
    const released = new WeakSet<HTMLFormElement>();
    const pending = new WeakSet<HTMLFormElement>();

    listen(doc, 'submit', ((event: SubmitEvent) => {
        const target = event.target as Element | null;
        const form = target?.closest?.(hook.selector) as HTMLFormElement | null | undefined;

        if (!form) {
            return;
        }

        if (hook.unsubscribe === null || released.has(form) || event.defaultPrevented) {
            released.delete(form);
            hook.forget();

            return;
        }

        event.preventDefault();

        if (pending.has(form)) {
            // Double click on the logout button: one release is already scheduled.
            return;
        }

        pending.add(form);

        const submitter = event.submitter;

        void withTimeout(hook.unsubscribe(), hook.timeoutMs).finally(() => {
            pending.delete(form);
            released.add(form);

            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit(submitter instanceof HTMLElement && submitter.closest('form') === form ? submitter : undefined);
            } else {
                // No submit event is fired: forget here.
                released.delete(form);
                hook.forget();
                HTMLFormElement.prototype.submit.call(form);
            }
        });
    }) as EventListener, true);
}

function withTimeout(promise: Promise<unknown>, timeoutMs: number): Promise<void> {
    return new Promise<void>((resolve) => {
        const timer = setTimeout(resolve, timeoutMs);

        promise.catch(() => {}).finally(() => {
            clearTimeout(timer);
            resolve();
        });
    });
}
