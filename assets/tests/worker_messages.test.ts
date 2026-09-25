import { describe, expect, it } from 'vitest';

import { INTENT_KEY, SW_VERSION } from '../src/contract';
import { installWebPush } from '../src/service-worker/index';
import { MARKER, loadServiceWorker } from './service_worker_harness';

describe('worker messages and lifecycle', () => {
    it('answers worker-ping with its version through the port', async () => {
        const sw = loadServiceWorker();

        expect(await sw.ask({ type: 'worker-ping' })).toEqual([{ type: 'worker-pong', version: SW_VERSION }]);
    });

    it('answers through event.source when no port is given', async () => {
        const replies: unknown[] = [];
        const sw = loadServiceWorker();

        await sw.dispatch('message', { data: { type: 'worker-ping' }, source: { postMessage: (m: unknown) => replies.push(m) } });

        expect(replies).toEqual([{ type: 'worker-pong', version: SW_VERSION }]);
    });

    it('returns the click trail, newest first', async () => {
        const sw = loadServiceWorker();

        await sw.click({ click: '/logout' });
        await sw.click({});

        const [reply] = await sw.ask({ type: 'read-click-trail' }) as Array<{ type: string; trail: Array<{ outcome: string }> }>;

        expect(reply!.type).toBe('click-trail');
        expect(reply!.trail.map((entry) => entry.outcome)).toEqual(['focus-only', 'rejected']);
    });

    it('ignores messages without a string type', async () => {
        const sw = loadServiceWorker();

        expect(await sw.ask(null)).toEqual([]);
        expect(await sw.ask({ type: 42 })).toEqual([]);
        expect(await sw.ask('worker-ping')).toEqual([]);
    });

    it('stores the announced marker', async () => {
        const sw = loadServiceWorker();

        await sw.announceMarker(MARKER);

        expect(await sw.readMarker()).toEqual({ marker: MARKER });
    });

    it('skips waiting on install and claims clients on activate', async () => {
        const sw = loadServiceWorker();

        await sw.dispatch('install');
        await sw.dispatch('activate');

        expect(sw.skipWaitingCalls).toBe(1);
        expect(sw.claimCalls).toBe(1);
    });

    it('drops an expired intent on activate, keeps a fresh one', async () => {
        const sw = loadServiceWorker();

        await sw.writeIntent({ clickPath: '/app/x', at: Date.now() - 301_000, marker: MARKER });
        await sw.dispatch('activate');
        expect(await sw.readIntent()).toBeNull();

        await sw.writeIntent({ clickPath: '/app/x', at: Date.now(), marker: MARKER });
        await sw.dispatch('activate');
        expect(await sw.readIntent()).not.toBeNull();
    });

    it('installs once per scope, so a double importScripts does not display twice', async () => {
        const sw = loadServiceWorker();

        installWebPush(sw.self, {});
        await sw.push({ v: 1, title: 'Once' });

        expect(sw.shownNotifications).toHaveLength(1);
        expect(sw.listenerCount('push')).toBe(1);
    });

    it('leaves the lifecycle alone when asked to', async () => {
        const sw = loadServiceWorker([], { plugins: [] });
        const other: any = { ...sw.self, addEventListener: () => {} };
        const registered: string[] = [];

        other.addEventListener = (type: string) => { registered.push(type); };
        installWebPush(other, { manageLifecycle: false });

        expect(registered).not.toContain('install');
    });

    it('keeps the state cache key the page reads', () => {
        expect(INTENT_KEY).toBe('/__web-push/navigation-intent');
    });
});
