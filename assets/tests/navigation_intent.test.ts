/**
 * @vitest-environment jsdom
 */
import { beforeEach, describe, expect, it } from 'vitest';

import { INTENT_KEY } from '../src/contract';
import { claimNavigationIntent, isSafeClickPath, readRenderedMarker } from '../src/page/navigation_intent';
import { parseMarker } from '../src/contract';
import { fakeWindowCaches, renderConfig } from './page_harness';

const MARKER_A = 'a3f19c4e0b7d2851';
const MARKER_B = 'b7d2851a3f19c4e0';
const TARGET = '/app/transactions/42';
const ORIGIN = window.location.origin;

describe('parseMarker', () => {
    it('refuses anything but sixteen lowercase hex characters', () => {
        expect(parseMarker(MARKER_A)).toBe(MARKER_A);
        expect(parseMarker(MARKER_A.toUpperCase())).toBeNull();
        expect(parseMarker(` ${MARKER_A} `)).toBeNull();
        expect(parseMarker(MARKER_A.slice(0, 15))).toBeNull();
        expect(parseMarker('zzzzzzzzzzzzzzzz')).toBeNull();
        expect(parseMarker('')).toBeNull();
        expect(parseMarker(undefined)).toBeNull();
        expect(parseMarker(null)).toBeNull();
    });
});

describe('isSafeClickPath', () => {
    it('refuses what the page must never follow', () => {
        const safe = (path: unknown) => isSafeClickPath(path, ORIGIN, ['/app/']);

        expect(safe(TARGET)).toBe(true);
        expect(safe('/')).toBe(true);
        expect(safe('/logout')).toBe(false);
        expect(safe('//evil.example/x')).toBe(false);
        expect(safe('https://evil.example/app/x')).toBe(false);
        expect(safe('javascript:alert(1)')).toBe(false);
        expect(safe('')).toBe(false);
        expect(safe(undefined)).toBe(false);
    });
});

describe('readRenderedMarker', () => {
    it('reads the clientState of the page config, and abstains during a Turbo preview', () => {
        renderConfig({ clientState: MARKER_A });
        document.documentElement.setAttribute('data-turbo-preview', '');
        expect(readRenderedMarker()).toBeNull();

        document.documentElement.removeAttribute('data-turbo-preview');
        expect(readRenderedMarker()).toBe(MARKER_A);
    });

    it('is null for an anonymous visitor', () => {
        renderConfig({ clientState: '' });

        expect(readRenderedMarker()).toBeNull();
    });
});

describe('claimNavigationIntent', () => {
    let store: Map<string, Response>;
    let caches: CacheStorage;
    let navigateCalls: string[];

    const claim = () => claimNavigationIntent({ caches, navigate: (path) => { navigateCalls.push(path); } });
    const writeIntent = (intent: unknown) => store.set(INTENT_KEY, new Response(JSON.stringify(intent)));

    beforeEach(() => {
        ({ store, caches } = fakeWindowCaches());
        navigateCalls = [];
        document.head.innerHTML = '';
        document.documentElement.removeAttribute('data-turbo-preview');
    });

    it('navigates when the rendered marker matches', async () => {
        renderConfig({ clientState: MARKER_A });
        writeIntent({ clickPath: TARGET, at: Date.now(), marker: MARKER_A });

        expect(await claim()).toBe('navigated');
        expect(navigateCalls).toEqual([TARGET]);
        expect(store.has(INTENT_KEY)).toBe(false);
    });

    it('does not navigate, and purges, when the intent belongs to another user', async () => {
        renderConfig({ clientState: MARKER_B });
        writeIntent({ clickPath: TARGET, at: Date.now(), marker: MARKER_A });

        expect(await claim()).toBe('foreign');
        expect(navigateCalls).toEqual([]);
        expect(store.has(INTENT_KEY)).toBe(false);
    });

    /** REFUSING IS NOT DESTROYING: on the login page the user is about to sign in. */
    it('refuses WITHOUT purging when no marker is rendered', async () => {
        renderConfig({ clientState: '' });
        writeIntent({ clickPath: TARGET, at: Date.now(), marker: MARKER_A });

        expect(await claim()).toBe('refused');
        expect(store.has(INTENT_KEY)).toBe(true);
    });

    it('refuses when the page has no config at all', async () => {
        writeIntent({ clickPath: TARGET, at: Date.now(), marker: MARKER_A });

        expect(await claim()).toBe('refused');
        expect(store.has(INTENT_KEY)).toBe(true);
    });

    /** The degenerate equality: two absences are not an identity. */
    it('refuses when both markers are missing', async () => {
        renderConfig({ clientState: '' });
        writeIntent({ clickPath: TARGET, at: Date.now() });

        expect(await claim()).toBe('refused');
        expect(navigateCalls).toEqual([]);
        expect(store.has(INTENT_KEY)).toBe(true);
    });

    it('purges an expired intent without navigating', async () => {
        renderConfig({ clientState: MARKER_A });
        writeIntent({ clickPath: TARGET, at: Date.now() - 5 * 60 * 1000 - 1, marker: MARKER_A });

        expect(await claim()).toBe('expired');
        expect(navigateCalls).toEqual([]);
        expect(store.has(INTENT_KEY)).toBe(false);
    });

    it('skips a hidden page, keeping the intent', async () => {
        renderConfig({ clientState: MARKER_A });
        writeIntent({ clickPath: TARGET, at: Date.now(), marker: MARKER_A });
        Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => 'hidden' });

        try {
            expect(await claim()).toBe('hidden');
            expect(store.has(INTENT_KEY)).toBe(true);
        } finally {
            delete (document as unknown as Record<string, unknown>).visibilityState;
        }
    });

    it('purges a target outside the prefixes without following it', async () => {
        renderConfig({ clientState: MARKER_A });
        writeIntent({ clickPath: '/logout', at: Date.now(), marker: MARKER_A });

        expect(await claim()).toBe('unsafe');
        expect(navigateCalls).toEqual([]);
        expect(store.has(INTENT_KEY)).toBe(false);
    });

    it('purges a javascript: target', async () => {
        renderConfig({ clientState: MARKER_A });
        writeIntent({ clickPath: 'javascript:alert(1)', at: Date.now(), marker: MARKER_A });

        expect(await claim()).toBe('unsafe');
        expect(navigateCalls).toEqual([]);
    });

    it('does nothing without an intent', async () => {
        renderConfig({ clientState: MARKER_A });

        expect(await claim()).toBe('no-intent');
    });

    /** Two claimers, one navigates: whoever wins the delete owns the click. */
    it('lets a single claimer win', async () => {
        renderConfig({ clientState: MARKER_A });
        writeIntent({ clickPath: TARGET, at: Date.now(), marker: MARKER_A });

        const results = await Promise.all([claim(), claim()]);

        expect(navigateCalls).toEqual([TARGET]);
        expect(results.sort()).toEqual(['lost-race', 'navigated']);
    });

    it('does not navigate when already on the target', async () => {
        renderConfig({ clientState: MARKER_A, clickPrefixes: ['/'] });
        writeIntent({ clickPath: window.location.pathname, at: Date.now(), marker: MARKER_A });

        expect(await claim()).toBe('already-there');
        expect(store.has(INTENT_KEY)).toBe(false);
    });
});
