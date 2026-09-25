/**
 * @vitest-environment jsdom
 */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import WebPushController from '../src/controllers/web_push_controller';
import { WebPushClient } from '../src/client/web_push_client';
import { WebPushPermissionError } from '../src/client/errors';
import { renderConfig } from './page_harness';

describe('web-push Stimulus controller', () => {
    let application: Application;

    beforeEach(() => {
        renderConfig();
        vi.spyOn(WebPushClient.prototype, 'isSupported').mockReturnValue(true);
        vi.spyOn(WebPushClient.prototype, 'permission').mockReturnValue('default');
        vi.spyOn(WebPushClient.prototype, 'getSubscription').mockResolvedValue(null);
        document.body.innerHTML = `
            <div data-controller="web-push">
                <p data-web-push-target="status"></p>
                <button data-web-push-target="subscribeButton" data-action="web-push#subscribe">Enable</button>
                <button data-web-push-target="unsubscribeButton" data-action="web-push#unsubscribe">Disable</button>
            </div>
        `;
        application = Application.start();
        application.register('web-push', WebPushController);
    });

    afterEach(() => {
        application.stop();
    });

    const element = () => document.querySelector<HTMLElement>('[data-controller="web-push"]')!;
    const button = (target: string) => document.querySelector<HTMLButtonElement>(`[data-web-push-target="${target}"]`)!;

    it('renders the state without inserting HTML', async () => {
        await vi.waitFor(() => expect(element().dataset.webPushState).toBe('unsubscribed'));

        expect(button('subscribeButton').hidden).toBe(false);
        expect(button('unsubscribeButton').hidden).toBe(true);
        expect(document.querySelector('[data-web-push-target="status"]')!.children).toHaveLength(0);
    });

    it('dispatches web-push:subscribed on success', async () => {
        const subscribed = vi.fn();

        vi.spyOn(WebPushClient.prototype, 'subscribe').mockResolvedValue({ endpoint: 'https://push.example.net/x' } as PushSubscription);
        element().addEventListener('web-push:subscribed', subscribed);
        await vi.waitFor(() => expect(element().dataset.webPushState).toBe('unsubscribed'));

        button('subscribeButton').click();

        await vi.waitFor(() => expect(subscribed).toHaveBeenCalledOnce());
        expect(subscribed.mock.calls[0]![0].detail).toEqual({ subscribed: true });
        expect(JSON.stringify(subscribed.mock.calls[0]![0].detail)).not.toContain('push.example.net');
    });

    it('dispatches web-push:error with the error code', async () => {
        const failed = vi.fn();

        vi.spyOn(WebPushClient.prototype, 'subscribe').mockRejectedValue(new WebPushPermissionError('denied'));
        element().addEventListener('web-push:error', failed);
        await vi.waitFor(() => expect(element().dataset.webPushState).toBe('unsubscribed'));

        button('subscribeButton').click();

        await vi.waitFor(() => expect(failed).toHaveBeenCalledOnce());
        expect(failed.mock.calls[0]![0].detail.code).toBe('permission-denied');
    });

    it('dispatches web-push:unsubscribed', async () => {
        const unsubscribed = vi.fn();

        vi.spyOn(WebPushClient.prototype, 'unsubscribe').mockResolvedValue(true);
        element().addEventListener('web-push:unsubscribed', unsubscribed);

        button('unsubscribeButton').click();

        await vi.waitFor(() => expect(unsubscribed).toHaveBeenCalledOnce());
    });
});
