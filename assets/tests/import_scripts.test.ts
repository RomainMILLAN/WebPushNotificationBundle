import { existsSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

import { SW_VERSION } from '../src/contract';
import { loadServiceWorker, prebuiltWorker, servedWorker, TEST_CONFIG } from './service_worker_harness';

const built = existsSync(resolve(import.meta.dirname, '../dist/web-push-sw.js'));

/**
 * An application that owns its worker (/sw.js) pulls the package in with
 * importScripts('/web-push-sw.js'). These tests run the BUILT script the way a browser
 * does: a classic script evaluated in the worker's global scope.
 */
describe.runIf(built)('dist/web-push-sw.js through importScripts()', () => {
    it('is a classic script: evaluating it as one does not throw', () => {
        expect(() => loadServiceWorker([], {
            appWorker: { source: "importScripts('/web-push-sw.js');", scripts: { '/web-push-sw.js': prebuiltWorker() } },
        })).not.toThrow();
    });

    it('reads self.__WEB_PUSH_CONFIG__ defined by the including script', async () => {
        const sw = loadServiceWorker([], {
            config: TEST_CONFIG,
            appWorker: {
                source: `self.__WEB_PUSH_CONFIG__ = ${JSON.stringify(TEST_CONFIG)};\nimportScripts('/web-push-sw.js');`,
                scripts: { '/web-push-sw.js': prebuiltWorker() },
            },
        });

        // An unreadable payload falls back to the CONFIGURED title and icon.
        await sw.push('not json');

        expect(sw.shownNotifications).toHaveLength(1);
        expect(sw.shownNotifications[0]!.title).toBe(TEST_CONFIG.fallbackTitle);
        expect(sw.shownNotifications[0]!.options.icon).toBe(TEST_CONFIG.icon);
    });

    it('reads self.__WEB_PUSH_CONFIG__ injected by the PHP route', async () => {
        const sw = loadServiceWorker([], {
            config: TEST_CONFIG,
            appWorker: { source: "importScripts('/web-push-sw.js');", scripts: { '/web-push-sw.js': servedWorker(TEST_CONFIG) } },
        });

        await sw.push('not json');

        expect(sw.shownNotifications[0]!.title).toBe(TEST_CONFIG.fallbackTitle);
    });

    it('installs once when imported twice: each notification is displayed once', async () => {
        const sw = loadServiceWorker([], {
            config: TEST_CONFIG,
            appWorker: {
                source: "importScripts('/web-push-sw.js');\nimportScripts('/web-push-sw.js');",
                scripts: { '/web-push-sw.js': servedWorker(TEST_CONFIG) },
            },
        });

        await sw.push({ v: 1, title: 'Once' });

        expect(sw.self.importedScripts).toEqual(['/web-push-sw.js', '/web-push-sw.js']);
        expect(sw.shownNotifications).toHaveLength(1);
        expect(sw.listenerCount('push')).toBe(1);
        expect(sw.listenerCount('notificationclick')).toBe(1);
        expect(sw.listenerCount('message')).toBe(1);
    });

    it('lets the application worker keep its own handlers', async () => {
        const sw = loadServiceWorker([], {
            config: TEST_CONFIG,
            appWorker: {
                source: "importScripts('/web-push-sw.js');\nself.addEventListener('fetch', () => {});",
                scripts: { '/web-push-sw.js': servedWorker(TEST_CONFIG) },
            },
        });

        expect(sw.listenerCount('fetch')).toBe(1);
        expect(await sw.ask({ type: 'worker-ping' })).toEqual([{ type: 'worker-pong', version: SW_VERSION }]);
    });
});
