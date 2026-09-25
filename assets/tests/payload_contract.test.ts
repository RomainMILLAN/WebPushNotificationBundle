import { readFileSync, readdirSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it, vi } from 'vitest';

import { ORIGIN, loadServiceWorker } from './service_worker_harness';

const FIXTURES = resolve(import.meta.dirname, '../../tests/Fixtures/payload');

function fixture(name: string): unknown {
    return JSON.parse(readFileSync(resolve(FIXTURES, `${name}.json`), 'utf8'));
}

/**
 * The fixtures are produced by the PHP PayloadEncoder tests: the same files prove both
 * sides speak the same published language.
 */
describe('push payload contract v1 (PHP fixtures)', () => {
    it('ships the fixtures this suite relies on', () => {
        expect(readdirSync(FIXTURES).sort()).toEqual(expect.arrayContaining(['full.json', 'minimal.json']));
    });

    it('builds the minimal notification with the configured defaults', async () => {
        const sw = loadServiceWorker();

        await sw.push(fixture('minimal'));

        expect(sw.shownNotifications).toEqual([{
            title: 'Hello',
            options: {
                tag: 'wp-0123456789abcdef',
                icon: '/default-icon.png',
                badge: '/default-badge.png',
                data: { id: '0123456789abcdef', click: null, actions: {}, app: {} },
            },
        }]);
    });

    it('builds the full notification', async () => {
        const sw = loadServiceWorker();

        await sw.push(fixture('full'));

        expect(sw.shownNotifications).toEqual([{
            title: 'Payment received',
            options: {
                body: '120 € from ACME',
                tag: 'payment-42',
                renotify: true,
                requireInteraction: true,
                icon: '/static/icon-192.png',
                badge: '/static/badge-96.png',
                actions: [
                    { action: 'ack', title: 'Acknowledge' },
                    { action: 'open', title: 'Open' },
                ],
                data: {
                    id: 'fedcba9876543210',
                    click: '/app/payments/42',
                    actions: {
                        ack: { type: 'post', url: `${ORIGIN}/alerts/42/ack?signature=abc` },
                        open: { type: 'navigate', url: '/app/payments/42' },
                    },
                    app: { paymentId: 42, currency: 'EUR' },
                },
            },
        }]);
    });

    it('puts the badge count of the full payload on the app icon', async () => {
        const setAppBadge = vi.fn().mockResolvedValue(undefined);
        const sw = loadServiceWorker([], { navigator: { setAppBadge } });

        await sw.push(fixture('full'));

        expect(setAppBadge).toHaveBeenCalledWith(3);
    });

    it('lets the full payload actions work end to end', async () => {
        const sw = loadServiceWorker();

        await sw.push(fixture('full'));
        const { data } = sw.shownNotifications[0]!.options;

        await sw.click(data, 'ack');
        await sw.click(data, 'open');

        expect(sw.fetchCalls.map((call) => call.url)).toEqual([`${ORIGIN}/alerts/42/ack?signature=abc`]);
        expect(sw.openedWindows).toEqual(['/app/payments/42']);
    });

    it('also works from the BUILT dist/web-push-sw.js', async () => {
        const sw = loadServiceWorker([], { standalone: true });

        await sw.push(fixture('full'));

        expect(sw.shownNotifications[0]!.title).toBe('Payment received');
        expect(sw.shownNotifications[0]!.options.data.click).toBe('/app/payments/42');
    });
});

describe('push display robustness', () => {
    it('shows the fallback title for a payload that is not v1', async () => {
        const sw = loadServiceWorker();

        await sw.push({ v: 2, title: 'From the future', body: 'x' });

        expect(sw.shownNotifications).toEqual([{
            title: 'Fallback title',
            options: { icon: '/default-icon.png', badge: '/default-badge.png', data: { id: '', click: null, actions: {}, app: {} } },
        }]);
    });

    it('shows the fallback title for unreadable JSON and for an empty push', async () => {
        const sw = loadServiceWorker();

        await sw.push('{not json');
        await sw.dispatch('push', { data: null });

        expect(sw.shownNotifications.map((n) => n.title)).toEqual(['Fallback title', 'Fallback title']);
    });

    it('shows the fallback title when the title is blank', async () => {
        const sw = loadServiceWorker();

        await sw.push({ ...(fixture('minimal') as object), title: '   ' });

        expect(sw.shownNotifications[0]!.title).toBe('Fallback title');
    });

    it('never lets a failing plugin block the display', async () => {
        const sw = loadServiceWorker([], {
            plugins: [
                { name: 'throws', onPush: () => { throw new Error('boom'); } },
                { name: 'rejects', onPush: () => Promise.reject(new Error('boom')) },
            ],
        });

        await sw.push(fixture('minimal'));

        expect(sw.shownNotifications).toHaveLength(1);
    });

    it('displays even when setAppBadge rejects (not installed)', async () => {
        const sw = loadServiceWorker([], { navigator: { setAppBadge: () => Promise.reject(new Error('not installed')) } });

        await sw.push(fixture('full'));

        expect(sw.shownNotifications).toHaveLength(1);
    });

    it('clears the badge on a zero count, and ignores a missing Badging API', async () => {
        const clearAppBadge = vi.fn().mockResolvedValue(undefined);
        const sw = loadServiceWorker([], { navigator: { clearAppBadge } });

        await sw.push({ ...(fixture('minimal') as object), badgeCount: 0 });
        await loadServiceWorker([], { navigator: {} }).push(fixture('full'));

        expect(clearAppBadge).toHaveBeenCalledOnce();
    });

    it('drops an intent expired by the time the next push arrives', async () => {
        const sw = loadServiceWorker();

        await sw.writeIntent({ clickPath: '/app/x', at: Date.now() - 10 * 60 * 1000, marker: 'a3f19c4e0b7d2851' });
        await sw.push(fixture('minimal'));

        expect(await sw.readIntent()).toBeNull();
    });
});
