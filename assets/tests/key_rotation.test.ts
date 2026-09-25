import { describe, expect, it } from 'vitest';

import { SYNC_INTERVAL_MS, sameApplicationServerKey, urlBase64ToUint8Array } from '../src/client/web_push_client';
import { bodyOf, clientHarness, memoryStorage } from './client_harness';
import { PUBLIC_KEY, ROTATED_KEY, fakeSubscription } from './page_harness';

describe('application server key', () => {
    it('decodes base64url to the raw 65-byte key', () => {
        const key = urlBase64ToUint8Array(PUBLIC_KEY);

        expect(key).toHaveLength(65);
        expect(key[0]).toBe(0x04);
    });

    it('compares the key a subscription was created with', () => {
        expect(sameApplicationServerKey(fakeSubscription(PUBLIC_KEY) as never, urlBase64ToUint8Array(PUBLIC_KEY))).toBe(true);
        expect(sameApplicationServerKey(fakeSubscription(ROTATED_KEY) as never, urlBase64ToUint8Array(PUBLIC_KEY))).toBe(false);
        expect(sameApplicationServerKey(fakeSubscription(null) as never, urlBase64ToUint8Array(PUBLIC_KEY))).toBe(false);
    });
});

describe('key rotation', () => {
    it('reuses a subscription created with the configured key', async () => {
        const existing = fakeSubscription(PUBLIC_KEY);
        const { client, push, requests } = clientHarness({ existing, permission: 'granted' });

        const subscription = await client.subscribe();

        expect(subscription).toBe(existing);
        expect(push.subscribeCalls).toEqual([]);
        expect(requests.map((r) => r.url)).toEqual(['/web-push/subscribe']);
    });

    it('drops a subscription made with another key and resubscribes', async () => {
        const stale = fakeSubscription(ROTATED_KEY, 'https://push.example.net/send/stale');
        const { client, push, requests } = clientHarness({ existing: stale, permission: 'granted' });

        const subscription = await client.subscribe();

        expect(stale.unsubscribed).toBe(true);
        expect(push.subscribeCalls).toHaveLength(1);
        expect(subscription.endpoint).toBe('https://push.example.net/send/fresh');
        expect(requests.map((r) => r.url)).toEqual(['/web-push/unsubscribe', '/web-push/subscribe']);
        expect(bodyOf(requests[0]!).endpoint).toBe('https://push.example.net/send/stale');
    });

    it('resubscribes during sync() when the key changed', async () => {
        const stale = fakeSubscription(ROTATED_KEY);
        const { client, requests } = clientHarness({ existing: stale, permission: 'granted' });

        expect(await client.sync()).toBe('rotated');
        expect(stale.unsubscribed).toBe(true);
        expect(requests.map((r) => r.url)).toEqual(['/web-push/unsubscribe', '/web-push/subscribe']);
    });
});

describe('sync', () => {
    it('re-POSTs at most once per 24 hours', async () => {
        let now = 1_700_000_000_000;
        const storage = memoryStorage();
        const existing = fakeSubscription(PUBLIC_KEY);
        const make = () => clientHarness({ existing, permission: 'granted', storage, now: () => now });

        expect(await make().client.sync()).toBe('synced');
        expect(await make().client.sync()).toBe('fresh');

        now += SYNC_INTERVAL_MS;
        const later = make();
        expect(await later.client.sync()).toBe('synced');
        expect(bodyOf(later.requests[0]!).endpoint).toBe(existing.endpoint);
    });

    it('re-POSTs at once when the signed-in user changed', async () => {
        const storage = memoryStorage();
        const existing = fakeSubscription(PUBLIC_KEY);

        expect(await clientHarness({ existing, permission: 'granted', storage, config: { clientState: 'a3f19c4e0b7d2851' } }).client.sync()).toBe('synced');
        expect(await clientHarness({ existing, permission: 'granted', storage, config: { clientState: 'b7d2851a3f19c4e0' } }).client.sync()).toBe('synced');
    });

    it('works when localStorage throws', async () => {
        const throwing = { getItem: () => { throw new Error('denied'); }, setItem: () => { throw new Error('denied'); }, removeItem: () => { throw new Error('denied'); } } as unknown as Storage;
        const { client } = clientHarness({ existing: fakeSubscription(PUBLIC_KEY), permission: 'granted', storage: throwing });

        expect(await client.sync()).toBe('synced');
    });

    it('does nothing without permission or subscription, and never throws', async () => {
        expect(await clientHarness({ permission: 'default' }).client.sync()).toBe('not-granted');
        expect(await clientHarness({ permission: 'granted' }).client.sync()).toBe('no-subscription');
        expect(await clientHarness({ existing: fakeSubscription(PUBLIC_KEY), permission: 'granted', status: 503 }).client.sync()).toBe('failed');
    });
});
