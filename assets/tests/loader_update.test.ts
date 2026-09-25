/**
 * @vitest-environment jsdom
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { WebPushClient } from '../src/client/web_push_client';
import { INTENT_KEY } from '../src/contract';
import { sameOriginPath, shouldCheckForUpdate, startWebPush, type WebPushPage } from '../src/page/start';
import { fakeContainer, fakeWindowCaches, renderConfig } from './page_harness';

const MARKER = 'a3f19c4e0b7d2851';

describe('shouldCheckForUpdate', () => {
    const NOW = 1_700_000_000_000;

    it('checks on the very first call', () => {
        expect(shouldCheckForUpdate(null, NOW)).toBe(true);
    });

    it('does not check twice within the window', () => {
        expect(shouldCheckForUpdate(NOW, NOW + 59_000)).toBe(false);
    });

    it('checks again past the window', () => {
        expect(shouldCheckForUpdate(NOW, NOW + 60_000)).toBe(true);
    });
});

describe('sameOriginPath', () => {
    const ORIGIN = 'https://app.example.com';

    it('keeps a root-relative path and its query', () => {
        expect(sameOriginPath('/sw.js?v=2', ORIGIN)).toBe('/sw.js?v=2');
    });

    it('normalizes dot segments', () => {
        expect(sameOriginPath('/app/../sw.js', ORIGIN)).toBe('/sw.js');
    });

    it.each([
        'https://app.example.com/sw.js',
        '//evil.example/sw.js',
        '/\\evil.example/sw.js',
        '/sw\\x.js',
        '/sw\n.js',
        'sw.js',
        '',
    ])('refuses %j', (candidate) => {
        expect(sameOriginPath(candidate, ORIGIN)).toBeNull();
    });

    it('refuses a non-string', () => {
        expect(sameOriginPath(42, ORIGIN)).toBeNull();
    });
});

describe('startWebPush', () => {
    let page: WebPushPage | null = null;

    beforeEach(() => {
        document.body.innerHTML = '';
        renderConfig({ clientState: MARKER });
    });

    afterEach(() => {
        page?.stop();
        page = null;
        vi.useRealTimers();
    });

    function start(options: Parameters<typeof startWebPush>[1] = {}) {
        const container = fakeContainer();
        const config = JSON.parse(document.querySelector('meta')!.getAttribute('content')!);

        page = startWebPush(document, { serviceWorker: container.container, client: new WebPushClient(config), sync: false, navigate: () => {}, ...options });

        return container;
    }

    it('returns null without a page config', () => {
        document.head.innerHTML = '';

        expect(startWebPush(document)).toBeNull();
    });

    it('registers the worker once, at scope / without HTTP cache', async () => {
        const container = start();

        await page!.registration;
        expect(startWebPush(document)).toBe(page);

        expect(container.registerCalls).toEqual([{ url: '/web-push-sw.js', options: { scope: '/', updateViaCache: 'none' } }]);
    });

    it('registers the application worker given as serviceWorkerUrl instead of the meta one', async () => {
        const container = start({ serviceWorkerUrl: '/sw.js' });

        await page!.registration;

        expect(container.registerCalls).toEqual([{ url: '/sw.js', options: { scope: '/', updateViaCache: 'none' } }]);
    });

    it.each([
        ['https://evil.example/sw.js'],
        ['//evil.example/sw.js'],
        ['/\\evil.example/sw.js'],
        ['sw.js'],
        ['javascript:alert(1)'],
        [''],
    ])('ignores serviceWorkerUrl %j and falls back to the meta', async (url) => {
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
        const container = start({ serviceWorkerUrl: url });

        await page!.registration;

        expect(container.registerCalls.map((call) => call.url)).toEqual(['/web-push-sw.js']);
        expect(warn).toHaveBeenCalledOnce();
        warn.mockRestore();
    });

    it('announces the client state marker to the worker', async () => {
        const container = start();

        await page!.registration;

        expect(container.posted).toContainEqual({ type: 'client-state', marker: MARKER });
    });

    it('announces a null marker on an anonymous page', async () => {
        renderConfig({ clientState: '' });
        const container = start();

        await page!.registration;

        expect(container.posted).toContainEqual({ type: 'client-state', marker: null });
    });

    it('asks for an update at most once a minute when coming back to the foreground', async () => {
        vi.useFakeTimers({ toFake: ['Date'] });
        vi.setSystemTime(1_700_000_000_000);
        const container = start();

        await page!.registration;

        document.dispatchEvent(new Event('visibilitychange'));
        expect(container.updateCalls).toBe(0);

        vi.setSystemTime(1_700_000_061_000);
        document.dispatchEvent(new Event('visibilitychange'));
        document.dispatchEvent(new Event('visibilitychange'));
        expect(container.updateCalls).toBe(1);
    });

    it('claims the intent on load and on a nudge, one claim at a time', async () => {
        const { store, caches } = fakeWindowCaches();
        const navigate = vi.fn();

        (window as unknown as { caches: CacheStorage }).caches = caches;

        try {
            const container = start({ navigate });

            await page!.claim();
            store.set(INTENT_KEY, new Response(JSON.stringify({ clickPath: '/app/x', at: Date.now(), marker: MARKER })));

            container.container.dispatchEvent(Object.assign(new Event('message'), { data: { type: 'claim-navigation-intent' } }));
            window.dispatchEvent(new Event('focus'));
            window.dispatchEvent(new Event('pageshow'));

            await vi.waitFor(() => expect(navigate).toHaveBeenCalledWith('/app/x'));
            await page!.claim();
            expect(navigate).toHaveBeenCalledOnce();
        } finally {
            delete (window as unknown as { caches?: CacheStorage }).caches;
        }
    });

    it('syncs the subscription once registered, unless disabled', async () => {
        const container = fakeContainer();
        const config = JSON.parse(document.querySelector('meta')!.getAttribute('content')!);
        const client = new WebPushClient(config);
        const sync = vi.spyOn(client, 'sync').mockResolvedValue('fresh');

        page = startWebPush(document, { serviceWorker: container.container, client, navigate: () => {} });
        await page!.registration;

        expect(sync).toHaveBeenCalledOnce();
    });

    it('survives a failing registration', async () => {
        const container = fakeContainer();
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});

        container.container.register = () => Promise.reject(new Error('blocked'));
        page = startWebPush(document, { serviceWorker: container.container, sync: false, navigate: () => {} });

        expect(await page!.registration).toBeNull();
        expect(warn).toHaveBeenCalled();
    });
});
