/**
 * @vitest-environment jsdom
 */
import { describe, expect, it } from 'vitest';

import { WebPushError, WebPushPermissionError, WebPushRequestError, WebPushUnsupportedError } from '../src/client/errors';
import { readPageConfig } from '../src/client/config';
import { WebPushClient } from '../src/client/web_push_client';
import { bodyOf, clientHarness } from './client_harness';
import { PUBLIC_KEY, fakeSubscription } from './page_harness';

describe('WebPushClient.subscribe', () => {
    it('POSTs the subscription JSON with its content encoding, CSRF header and same-origin credentials', async () => {
        const { client, requests } = clientHarness();

        await client.subscribe();

        expect(requests).toHaveLength(1);
        expect(requests[0]!.url).toBe('/web-push/subscribe');
        expect(requests[0]!.init).toMatchObject({
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': 'tok-123' },
        });
        expect(requests[0]!.init.keepalive).toBeUndefined();
        expect(bodyOf(requests[0]!)).toEqual({
            endpoint: 'https://push.example.net/send/fresh',
            expirationTime: null,
            keys: { p256dh: 'p256dh-value', auth: 'auth-value' },
            contentEncoding: 'aes128gcm',
        });
    });

    it('subscribes with userVisibleOnly and the key as a Uint8Array (Safari)', async () => {
        const { client, push } = clientHarness();

        await client.subscribe();

        expect(push.subscribeCalls).toHaveLength(1);
        expect(push.subscribeCalls[0]!.userVisibleOnly).toBe(true);
        expect(push.subscribeCalls[0]!.applicationServerKey).toBeInstanceOf(Uint8Array);
        expect(push.subscribeCalls[0]!.applicationServerKey).toHaveLength(65);
    });

    it('falls back to aesgcm when aes128gcm is not advertised', async () => {
        const { client, requests } = clientHarness({ encodings: undefined });

        await client.subscribe();

        expect(bodyOf(requests[0]!).contentEncoding).toBe('aesgcm');
    });

    it('omits the CSRF header when none is configured', async () => {
        const { client, requests } = clientHarness({ config: { csrfHeader: '' } });

        await client.subscribe();

        expect(requests[0]!.init.headers).toEqual({ 'Content-Type': 'application/json' });
    });

    it('asks the permission first, synchronously (user gesture)', () => {
        const { client, notification } = clientHarness();

        void client.subscribe();

        expect(notification.requestCalls).toBe(1);
    });

    it('supports the legacy callback form of requestPermission', async () => {
        const { client, requests } = clientHarness({ callbackOnly: true });

        await client.subscribe();

        expect(requests).toHaveLength(1);
    });

    it('throws a permission error when the user refuses or dismisses', async () => {
        await expect(clientHarness({ grant: 'denied' }).client.subscribe()).rejects.toMatchObject({ code: 'permission-denied' });
        await expect(clientHarness({ grant: 'default' }).client.subscribe()).rejects.toBeInstanceOf(WebPushPermissionError);
    });

    it('throws an unsupported error without the Push API', async () => {
        const { client } = clientHarness();
        const bare = new WebPushClient(client.config, { pushManager: undefined, serviceWorker: undefined });

        expect(bare.isSupported()).toBe(false);
        await expect(bare.subscribe()).rejects.toBeInstanceOf(WebPushUnsupportedError);
    });

    it.each([
        [403, 'anonymous-disabled'],
        [400, 'invalid-request'],
        [413, 'payload-too-large'],
        [415, 'unsupported-media-type'],
        [503, 'service-unavailable'],
        [500, 'http-error'],
    ])('throws a typed error on HTTP %i', async (status, code) => {
        const error = await clientHarness({ status }).client.subscribe().catch((e: unknown) => e);

        expect(error).toBeInstanceOf(WebPushRequestError);
        expect(error).toMatchObject({ status, code });
    });

    it('treats 204 as success, the server answer being neutral', async () => {
        await expect(clientHarness({ status: 204 }).client.subscribe()).resolves.toBeDefined();
        await expect(clientHarness({ status: 201 }).client.subscribe()).resolves.toBeDefined();
    });

    it('throws a network error when the request cannot be sent', async () => {
        const error = await clientHarness({ fetchError: new TypeError('offline') }).client.subscribe().catch((e: unknown) => e);

        expect(error).toBeInstanceOf(WebPushError);
        expect(error).toMatchObject({ code: 'network' });
    });
});

describe('WebPushClient.unsubscribe', () => {
    it('POSTs endpoint and auth with keepalive, then unsubscribes the browser', async () => {
        const existing = fakeSubscription(PUBLIC_KEY);
        const { client, requests } = clientHarness({ existing, permission: 'granted' });

        expect(await client.unsubscribe()).toBe(true);

        expect(requests).toHaveLength(1);
        expect(requests[0]!.url).toBe('/web-push/unsubscribe');
        expect(requests[0]!.init).toMatchObject({ method: 'POST', credentials: 'same-origin', keepalive: true, headers: { 'X-CSRF-Token': 'tok-123' } });
        expect(bodyOf(requests[0]!)).toEqual({ endpoint: existing.endpoint, keys: { auth: 'auth-value' } });
        expect(existing.unsubscribed).toBe(true);
    });

    it('unsubscribes the browser whatever the server answers, and never throws', async () => {
        const failing = fakeSubscription(PUBLIC_KEY);
        const offline = fakeSubscription(PUBLIC_KEY);

        await expect(clientHarness({ existing: failing, status: 503 }).client.unsubscribe()).resolves.toBe(true);
        await expect(clientHarness({ existing: offline, fetchError: new TypeError('offline') }).client.unsubscribe()).resolves.toBe(true);

        expect(failing.unsubscribed).toBe(true);
        expect(offline.unsubscribed).toBe(true);
    });

    it('does nothing without a subscription', async () => {
        const { client, requests } = clientHarness();

        expect(await client.unsubscribe()).toBe(false);
        expect(requests).toEqual([]);
    });
});

describe('readPageConfig', () => {
    function docWith(content: string | null): Document {
        const doc = document.implementation.createHTMLDocument('t');

        if (content !== null) {
            const meta = doc.createElement('meta');

            meta.setAttribute('name', 'web-push-config');
            meta.setAttribute('content', content);
            doc.head.append(meta);
        }

        return doc;
    }

    it('reads the meta rendered by the server', () => {
        const config = readPageConfig(docWith(JSON.stringify({
            publicKey: PUBLIC_KEY, serviceWorker: '/web-push-sw.js', subscribe: '/s', unsubscribe: '/u',
            clickPrefixes: ['/app/', '//evil'], csrfHeader: 'X-CSRF-Token', csrfToken: 't', clientState: 'a3f19c4e0b7d2851',
        })));

        expect(config).toEqual({
            publicKey: PUBLIC_KEY, serviceWorker: '/web-push-sw.js', subscribe: '/s', unsubscribe: '/u',
            clickPrefixes: ['/app/'], csrfHeader: 'X-CSRF-Token', csrfToken: 't', clientState: 'a3f19c4e0b7d2851', stateCache: 'web-push-state',
        });
    });

    it('returns null when absent, unreadable or incomplete', () => {
        expect(readPageConfig(docWith(null))).toBeNull();
        expect(readPageConfig(docWith('{oops'))).toBeNull();
        expect(readPageConfig(docWith('[]'))).toBeNull();
        expect(readPageConfig(docWith(JSON.stringify({ publicKey: PUBLIC_KEY })))).toBeNull();
    });

    it('turns a malformed clientState into anonymous', () => {
        const config = readPageConfig(docWith(JSON.stringify({ publicKey: 'k', serviceWorker: '/sw', subscribe: '/s', unsubscribe: '/u', clientState: 'NOT-A-MARKER' })));

        expect(config?.clientState).toBe('');
    });
});
