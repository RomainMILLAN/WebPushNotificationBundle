/**
 * @vitest-environment jsdom
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { WebPushClient } from '../src/client/web_push_client';
import { startWebPush, type WebPushPage } from '../src/page/start';
import { MARKER, loadServiceWorker } from './service_worker_harness';
import { renderConfig, fakeContainer } from './page_harness';

describe('forget-client-state in the worker', () => {
    it('purges the whole state cache: intent, trail and marker', async () => {
        const sw = loadServiceWorker();

        await sw.announceMarker(MARKER);
        await sw.click({ click: '/app/x' });
        expect(await sw.readIntent()).not.toBeNull();

        await sw.dispatch('message', { data: { type: 'forget-client-state' } });

        expect(await sw.caches.has(sw.stateCache)).toBe(false);
        expect(await sw.readIntent()).toBeNull();
        expect(await sw.readTrail()).toEqual([]);
        expect(await sw.readMarker()).toBeNull();
    });
});

describe('logout hook on the page', () => {
    let page: WebPushPage | null = null;

    beforeEach(() => {
        renderConfig({ clientState: MARKER });
        document.body.innerHTML = `
            <form method="post" action="/logout" data-web-push-logout>
                <button type="submit" name="go" value="1">Log out</button>
            </form>
            <form id="search"><button type="submit">Search</button></form>
        `;
    });

    afterEach(() => {
        page?.stop();
        page = null;
        vi.useRealTimers();
    });

    function start(unsubscribe: () => Promise<boolean>, options: Parameters<typeof startWebPush>[1] = {}) {
        const container = fakeContainer();
        const client = new WebPushClient(JSON.parse(document.querySelector('meta')!.getAttribute('content')!));

        vi.spyOn(client, 'unsubscribe').mockImplementation(unsubscribe);
        page = startWebPush(document, { client, serviceWorker: container.container, sync: false, navigate: () => {}, ...options });

        return { container, client };
    }

    function submit(form: HTMLFormElement): Event {
        const event = new Event('submit', { bubbles: true, cancelable: true });

        form.dispatchEvent(event);

        return event;
    }

    it('unsubscribes while the session is valid, then purges and replays the submit', async () => {
        const order: string[] = [];
        const { container } = start(async () => { order.push('unsubscribe'); return true; });
        const form = document.querySelector<HTMLFormElement>('form[data-web-push-logout]')!;
        const replayed = vi.fn();

        form.requestSubmit = function () { replayed(); submit(form); } as typeof form.requestSubmit;
        container.onPost = (message) => {
            const { type } = message as { type: string };

            if (type === 'forget-client-state') {
                order.push(type);
            }
        };

        const first = submit(form);

        expect(first.defaultPrevented).toBe(true);
        await vi.waitFor(() => expect(replayed).toHaveBeenCalledOnce());
        expect(order).toEqual(['unsubscribe', 'forget-client-state']);
    });

    it('never holds the logout longer than the timeout', async () => {
        vi.useFakeTimers();
        const { container } = start(() => new Promise<boolean>(() => {}), { logoutTimeoutMs: 1_000 });
        const form = document.querySelector<HTMLFormElement>('form[data-web-push-logout]')!;
        const replayed = vi.fn();

        form.requestSubmit = function () { replayed(); submit(form); } as typeof form.requestSubmit;

        submit(form);
        await vi.advanceTimersByTimeAsync(999);
        expect(replayed).not.toHaveBeenCalled();

        await vi.advanceTimersByTimeAsync(1);
        expect(replayed).toHaveBeenCalledOnce();
        expect(container.posted).toContainEqual({ type: 'forget-client-state' });
    });

    it('only purges, without holding the submit, when unsubscribeOnLogout is false', () => {
        const unsubscribe = vi.fn(async () => true);
        const { container } = start(unsubscribe, { unsubscribeOnLogout: false });

        const event = submit(document.querySelector<HTMLFormElement>('form[data-web-push-logout]')!);

        expect(event.defaultPrevented).toBe(false);
        expect(unsubscribe).not.toHaveBeenCalled();
        expect(container.posted).toContainEqual({ type: 'forget-client-state' });
    });

    it('ignores the submission of another form', () => {
        const unsubscribe = vi.fn(async () => true);
        const { container } = start(unsubscribe);

        const event = submit(document.querySelector<HTMLFormElement>('#search')!);

        expect(event.defaultPrevented).toBe(false);
        expect(unsubscribe).not.toHaveBeenCalled();
        expect(container.posted).not.toContainEqual({ type: 'forget-client-state' });
    });

    it('does not throw when no worker controls the page', () => {
        const { container } = start(async () => false, { unsubscribeOnLogout: false });

        container.container.controller = null;

        expect(() => page!.forgetClientState()).not.toThrow();
    });
});
