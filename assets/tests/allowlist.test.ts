import { describe, expect, it } from 'vitest';

import { acceptAssetUrl, buildNotification, normalizeServiceWorkerConfig, parsePayload } from '../src/service-worker/index';
import { MARKER, ORIGIN, TEST_CONFIG, loadServiceWorker } from './service_worker_harness';

const BASE = {
    v: 1,
    id: '0123456789abcdef',
    title: 'Hello',
    body: 'World',
    tag: 't-1',
    silent: false,
    requireInteraction: false,
    renotify: false,
    actions: [],
    data: {},
};

function build(payload: Record<string, unknown>) {
    return buildNotification(parsePayload(payload), TEST_CONFIG, ORIGIN);
}

describe('notification options allowlist', () => {
    it('drops unknown fields', () => {
        const { options } = build({
            ...BASE,
            vibrate: [200, 100, 200],
            image: 'https://tracker.example/pixel.png',
            dir: 'rtl',
            timestamp: 1,
            options: { body: 'injected' },
            data: { ok: 'yes', nested: { no: true }, list: [1], fn: null },
        });

        expect(Object.keys(options).sort()).toEqual(['badge', 'body', 'data', 'icon', 'tag']);
        expect((options.data as any).app).toEqual({ ok: 'yes' });
    });

    it('replaces a cross-origin icon and badge by the configured defaults', () => {
        const { options } = build({ ...BASE, icon: 'https://evil.example/i.png', badge: '//evil.example/b.png' });

        expect(options.icon).toBe('/default-icon.png');
        expect(options.badge).toBe('/default-badge.png');
    });

    it('keeps an https icon from an allowed asset host', () => {
        const { options } = build({ ...BASE, icon: 'https://cdn.example.com/i.png' });

        expect(options.icon).toBe('https://cdn.example.com/i.png');
    });

    it('refuses an allowed host over plain http or with credentials, and data: URLs', () => {
        expect(acceptAssetUrl('http://cdn.example.com/i.png', ORIGIN, ['cdn.example.com'])).toBeNull();
        expect(acceptAssetUrl('https://user:pw@cdn.example.com/i.png', ORIGIN, ['cdn.example.com'])).toBeNull();
        expect(acceptAssetUrl('data:image/png;base64,AAAA', ORIGIN, ['cdn.example.com'])).toBeNull();
        expect(acceptAssetUrl('javascript:alert(1)', ORIGIN, [])).toBeNull();
    });

    it('omits the icon entirely when neither the payload nor the config has one', () => {
        const { options } = buildNotification(parsePayload(BASE), normalizeServiceWorkerConfig({}), ORIGIN);

        expect(options).not.toHaveProperty('icon');
        expect(options).not.toHaveProperty('badge');
    });

    it('sets renotify only together with a tag', () => {
        expect(build({ ...BASE, renotify: true }).options.renotify).toBe(true);
        expect(build({ ...BASE, tag: '', renotify: true }).options).not.toHaveProperty('renotify');
    });

    it('keeps requireInteraction and silent only when true', () => {
        expect(build({ ...BASE, requireInteraction: true, silent: true }).options).toMatchObject({ requireInteraction: true, silent: true });
        expect(build({ ...BASE, requireInteraction: 'yes', silent: 1 }).options).not.toHaveProperty('requireInteraction');
    });

    it('keeps at most two well-formed actions, exposing only action and title', () => {
        const { options } = build({
            ...BASE,
            actions: [
                { action: 'Bad Name', title: 'x', type: 'navigate' },
                { action: 'evil', title: 'x', type: 'eval' },
                { action: 'notitle', title: '', type: 'dismiss' },
                { action: 'one', title: 'One', type: 'navigate', url: '/app/1', icon: 'https://evil.example/a.png' },
                { action: 'two', title: 'Two', type: 'dismiss' },
                { action: 'three', title: 'Three', type: 'dismiss' },
            ],
        });

        expect(options.actions).toEqual([{ action: 'one', title: 'One' }, { action: 'two', title: 'Two' }]);
        expect((options.data as any).actions).toEqual({ one: { type: 'navigate', url: '/app/1' }, two: { type: 'dismiss', url: null } });
    });

    it('keeps at most sixteen app data properties', () => {
        const data = Object.fromEntries(Array.from({ length: 20 }, (_, i) => [`k${i}`, i]));

        expect(Object.keys((build({ ...BASE, data }).options.data as any).app)).toHaveLength(16);
    });
});

describe('click allowlist', () => {
    it('ignores a post action with a cross-origin URL', async () => {
        const sw = loadServiceWorker();

        await sw.push({ ...BASE, actions: [{ action: 'ack', title: 'Ack', type: 'post', url: 'https://evil.example/steal' }] });
        await sw.click(sw.shownNotifications[0]!.options.data, 'ack');

        expect(sw.fetchCalls).toEqual([]);
        expect(sw.openedWindows).toEqual([]);
        expect((await sw.readTrail())[0]).toMatchObject({ outcome: 'rejected', verdict: 'rejected-cross-origin' });
    });

    it('ignores a scheme-relative post URL', async () => {
        const sw = loadServiceWorker();

        await sw.click({ actions: { ack: { type: 'post', url: '//evil.example/steal' } } }, 'ack');

        expect(sw.fetchCalls).toEqual([]);
    });

    it('ignores a click path outside the prefixes and lands on the root', async () => {
        const sw = loadServiceWorker();

        await sw.announceMarker(MARKER);
        await sw.push({ ...BASE, click: '/logout' });
        await sw.click(sw.shownNotifications[0]!.options.data);

        expect(sw.openedWindows).toEqual(['/']);
        expect(await sw.readIntent()).toBeNull();
    });

    it('ignores a navigate action outside the prefixes', async () => {
        const sw = loadServiceWorker();

        await sw.push({ ...BASE, actions: [{ action: 'go', title: 'Go', type: 'navigate', url: 'https://evil.example/app/x' }] });
        await sw.click(sw.shownNotifications[0]!.options.data, 'go');

        expect(sw.openedWindows).toEqual(['/']);
    });
});

describe('service worker config', () => {
    it('normalizes an untrusted config', () => {
        expect(normalizeServiceWorkerConfig({
            fallbackTitle: '',
            icon: 3,
            clickPrefixes: ['/app/', '//evil', 'relative', 7],
            assetHosts: ['CDN.example.com'],
            stateCache: '',
            extra: true,
        })).toEqual({
            fallbackTitle: 'Notification',
            icon: '',
            badge: '',
            clickPrefixes: ['/app/'],
            assetHosts: ['cdn.example.com'],
            stateCache: 'web-push-state',
        });
    });

    it('starts without any config', async () => {
        const sw = loadServiceWorker([], { config: undefined });

        await sw.push(BASE);

        expect(sw.shownNotifications).toHaveLength(1);
    });
});
