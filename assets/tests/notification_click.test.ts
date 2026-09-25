import { describe, expect, it } from 'vitest';

import { MARKER, ORIGIN, fakeClient, loadServiceWorker } from './service_worker_harness';

const TARGET = '/app/transactions/42';
const DATA = { id: '0123456789abcdef', click: TARGET, actions: {}, app: {} };

async function withMarker(clients = [] as ReturnType<typeof fakeClient>[]) {
    const sw = loadServiceWorker(clients);

    await sw.announceMarker(MARKER);

    return sw;
}

describe('notificationclick', () => {
    /** iOS renders no action buttons: a click on the body is `action === ''`, the common case. */
    it('honours a click without action, the iOS case', async () => {
        const sw = await withMarker();

        await sw.click(DATA, '');

        expect(sw.openedWindows).toEqual([TARGET]);
    });

    it('navigates an existing window and folds the intent', async () => {
        const client = fakeClient('a', { navigateBehaviour: 'moves' });
        const sw = await withMarker([client]);

        await sw.click(DATA);

        expect(client.url).toBe(`${ORIGIN}${TARGET}`);
        expect(await sw.readIntent()).toBeNull();
        expect((await sw.readTrail())[0].outcome).toBe('navigated');
    });

    /** navigate() resolving without moving the page is the trap: postcondition by observation. */
    it('nudges and KEEPS the intent when navigate does not move', async () => {
        const client = fakeClient('a', { navigateBehaviour: 'no-op' });
        const sw = await withMarker([client]);

        await sw.click(DATA);

        expect(client.messages).toEqual([{ type: 'claim-navigation-intent' }]);
        expect((await sw.readIntent()).clickPath).toBe(TARGET);
        expect((await sw.readIntent()).marker).toBe(MARKER);
        expect((await sw.readTrail())[0].outcome).toBe('nudged');
    });

    it('goes on with the chain when navigate rejects', async () => {
        const sw = await withMarker([fakeClient('a', { navigateBehaviour: 'rejects' })]);

        await sw.click(DATA);

        expect((await sw.readTrail())[0].outcome).toBe('nudged');
    });

    it('goes on with the chain when focus rejects', async () => {
        const sw = await withMarker([fakeClient('a', { focusBehaviour: 'rejects', navigateBehaviour: 'moves' })]);

        await sw.click(DATA);

        expect((await sw.readTrail())[0].outcome).toBe('navigated');
    });

    it('only focuses when the window is already on the target', async () => {
        const sw = await withMarker([fakeClient('a', { url: `${ORIGIN}${TARGET}` })]);

        await sw.click(DATA);

        expect((await sw.readTrail())[0].outcome).toBe('already-there');
        expect(await sw.readIntent()).toBeNull();
    });

    it('stays already-there when focus rejects on the right window', async () => {
        const sw = await withMarker([fakeClient('a', { url: `${ORIGIN}${TARGET}`, focusBehaviour: 'rejects' })]);

        await sw.click(DATA);

        expect((await sw.readTrail())[0].outcome).toBe('already-there');
        expect(await sw.readIntent()).toBeNull();
    });

    it('opens a window and keeps the intent when no client exists', async () => {
        const sw = await withMarker();

        await sw.click(DATA);

        expect(sw.openedWindows).toEqual([TARGET]);
        expect((await sw.readIntent()).clickPath).toBe(TARGET);
        expect((await sw.readTrail())[0].outcome).toBe('new-window');
    });

    it('prefers a window under a click prefix over a focused window elsewhere', async () => {
        const elsewhere = fakeClient('focused', { url: `${ORIGIN}/`, focused: true });
        const inApp = fakeClient('app', { url: `${ORIGIN}/app/lines` });
        const sw = await withMarker([elsewhere, inApp]);

        await sw.click(DATA);

        expect(inApp.url).toBe(`${ORIGIN}${TARGET}`);
        expect(elsewhere.url).toBe(`${ORIGIN}/`);
    });

    it('does not throw when data is absent, and falls back to the root', async () => {
        const sw = await withMarker();

        await sw.click(undefined);

        expect(sw.openedWindows).toEqual(['/']);
        expect((await sw.readTrail())[0]).toMatchObject({ outcome: 'focus-only', verdict: 'no-destination', intent: 'not-applicable' });
        expect(await sw.readIntent()).toBeNull();
    });

    it('closes the notification on every click', async () => {
        const sw = await withMarker();
        const event = { action: '', notification: { data: DATA, closed: 0, close() { this.closed += 1; } } };

        await sw.dispatch('notificationclick', event);

        expect(event.notification.closed).toBe(1);
    });

    it('does nothing on a dismiss action', async () => {
        const sw = await withMarker();

        await sw.click({ ...DATA, actions: { later: { type: 'dismiss', url: null } } }, 'later');

        expect(sw.openedWindows).toEqual([]);
        expect(await sw.readTrail()).toEqual([]);
    });

    it('navigates to the url of a navigate action', async () => {
        const sw = await withMarker();

        await sw.click({ ...DATA, actions: { open: { type: 'navigate', url: '/app/other' } } }, 'open');

        expect(sw.openedWindows).toEqual(['/app/other']);
    });

    it('falls back to the default click for an unknown action', async () => {
        const sw = await withMarker();

        await sw.click(DATA, 'ghost');

        expect(sw.openedWindows).toEqual([TARGET]);
    });

    it('POSTs a same-origin post action and does not navigate', async () => {
        const sw = await withMarker();

        await sw.click({ ...DATA, actions: { ack: { type: 'post', url: `${ORIGIN}/alerts/42/ack?signature=abc` } } }, 'ack');

        expect(sw.fetchCalls).toEqual([{ url: `${ORIGIN}/alerts/42/ack?signature=abc`, init: { method: 'POST', credentials: 'same-origin' } }]);
        expect(sw.openedWindows).toEqual([]);
        expect((await sw.readTrail())[0]).toMatchObject({ outcome: 'post-sent', verdict: 'accepted' });
    });

    it('records a failed post action', async () => {
        const sw = loadServiceWorker([], { fetch: () => Promise.reject(new TypeError('offline')) });

        await sw.click({ ...DATA, actions: { ack: { type: 'post', url: '/alerts/42/ack' } } }, 'ack');

        expect((await sw.readTrail())[0].outcome).toBe('post-failed');
    });

    it('bounds the trail to ten entries', async () => {
        const sw = await withMarker();

        for (let i = 0; i < 13; i += 1) {
            await sw.click(DATA);
        }

        expect(await sw.readTrail()).toHaveLength(10);
    });

    /** Without a marker announced by a page no intent is written — and the trail SAYS so. */
    it('writes no intent without a marker, and records it', async () => {
        const sw = loadServiceWorker([]);

        await sw.click(DATA);

        expect(await sw.readIntent()).toBeNull();
        expect((await sw.readTrail())[0].intent).toBe('skipped-no-marker');
    });

    it('forgets the marker when an anonymous page announces none', async () => {
        const sw = await withMarker();

        await sw.announceMarker(null);
        await sw.click(DATA);

        expect(await sw.readMarker()).toBeNull();
        expect(await sw.readIntent()).toBeNull();
    });

    it('ignores a malformed marker', async () => {
        const sw = loadServiceWorker([]);

        for (const marker of ['A3F19C4E0B7D2851', ` ${MARKER} `, MARKER.slice(0, 15), 'zzzzzzzzzzzzzzzz']) {
            await sw.announceMarker(marker);
            await sw.click(DATA);
            expect(await sw.readIntent()).toBeNull();
        }
    });

    it('treats a rejection as terminal: no intent and a single trail entry', async () => {
        const sw = await withMarker();

        await sw.click({ ...DATA, click: '/logout' });

        const trail = await sw.readTrail();
        expect(trail).toHaveLength(1);
        expect(trail[0]).toMatchObject({ outcome: 'rejected', verdict: 'rejected-prefix', intent: 'not-applicable' });
        expect(await sw.readIntent()).toBeNull();
    });

    it('still opens the root when focus rejects on a refused path', async () => {
        const sw = await withMarker([fakeClient('a', { focusBehaviour: 'rejects' })]);

        await sw.click({ ...DATA, click: '/logout' });

        expect(sw.openedWindows).toEqual(['/']);
    });

    it('reports "disabled" intents when no navigationIntent plugin is installed', async () => {
        const sw = loadServiceWorker([], { plugins: [] });

        await sw.click(DATA);

        expect(sw.openedWindows).toEqual([TARGET]);
        expect(await sw.readIntent()).toBeNull();
    });

    it('survives a throwing plugin', async () => {
        const sw = loadServiceWorker([], {
            plugins: [{ name: 'broken', onBeforeNavigate: () => { throw new Error('boom'); }, onClick: () => Promise.reject(new Error('boom')) }],
        });

        await sw.click(DATA);

        expect(sw.openedWindows).toEqual([TARGET]);
    });
});
