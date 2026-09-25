import { describe, expect, it } from 'vitest';

import { resolveClickPath } from '../src/click_path';
import { MARKER, ORIGIN, loadServiceWorker } from './service_worker_harness';

async function verdictFor(click: unknown): Promise<{ verdict: string; opened: string[] }> {
    const sw = loadServiceWorker([]);

    await sw.announceMarker(MARKER);
    await sw.click({ click, actions: {} });

    return { verdict: (await sw.readTrail())[0].verdict, opened: sw.openedWindows };
}

describe('safe click path in the worker', () => {
    /** `raw.startsWith('/')` lets this through: new URL() resolves it off-origin. */
    it('refuses a scheme-relative path', async () => {
        const { verdict, opened } = await verdictFor('//evil.example/app/lines');

        expect(verdict).toBe('rejected-cross-origin');
        expect(opened).toEqual(['/']);
    });

    it('refuses a non http scheme', async () => {
        expect((await verdictFor('javascript:alert(1)')).verdict).toBe('rejected-scheme');
    });

    /** Origin validation alone would allow a GET logout route: a one-click denial of service. */
    it('refuses a target outside the click prefixes', async () => {
        expect((await verdictFor('/logout')).verdict).toBe('rejected-prefix');
    });

    it('refuses a cross-origin absolute URL', async () => {
        expect((await verdictFor('https://evil.example/app/lines')).verdict).toBe('rejected-cross-origin');
    });

    it('does not throw on a bare relative path', async () => {
        expect((await verdictFor('app/lines')).verdict).toBe('accepted');
    });

    it('tells a notification without destination apart from a refusal', async () => {
        const { verdict, opened } = await verdictFor(undefined);

        expect(verdict).toBe('no-destination');
        expect(opened).toEqual(['/']);
    });

    it('treats an empty string as no destination', async () => {
        expect((await verdictFor('')).verdict).toBe('no-destination');
    });

    it('accepts a target under a prefix, with its query', async () => {
        const { verdict, opened } = await verdictFor('/app/lines?page=2');

        expect(verdict).toBe('accepted');
        expect(opened).toEqual(['/app/lines?page=2']);
    });

    it('records every rejection without ever storing the path', async () => {
        const sw = loadServiceWorker([]);

        for (const click of ['//evil.example/x', 'javascript:alert(1)', '/logout']) {
            await sw.click({ click });
        }

        const trail = await sw.readTrail();
        expect(trail).toHaveLength(3);

        for (const entry of trail) {
            expect(entry.outcome).toBe('rejected');
            expect(entry.verdict).toMatch(/^rejected-/);
            expect(JSON.stringify(entry)).not.toContain('evil.example');
            expect(JSON.stringify(entry)).not.toContain('logout');
            expect(entry).not.toHaveProperty('clickPath');
        }
    });
});

describe('resolveClickPath', () => {
    const resolveIt = (raw: unknown, prefixes = ['/app/']) => resolveClickPath(raw, `${ORIGIN}/`, ORIGIN, prefixes);

    it('always accepts the root', () => {
        expect(resolveIt('/', [])).toEqual({ clickPath: '/', verdict: 'accepted' });
    });

    it('matches any configured prefix', () => {
        expect(resolveIt('/admin/x', ['/app/', '/admin/']).verdict).toBe('accepted');
        expect(resolveIt('/application', ['/app/']).verdict).toBe('rejected-prefix');
    });

    it('drops the fragment', () => {
        expect(resolveIt('/app/x?y=1#frag').clickPath).toBe('/app/x?y=1');
    });

    it('refuses a backslash trick resolving off-origin', () => {
        expect(resolveIt('/\\evil.example/app').verdict).toBe('rejected-cross-origin');
    });
});
