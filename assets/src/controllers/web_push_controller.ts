import { Controller } from '@hotwired/stimulus';

import { readPageConfig } from '../client/config';
import { WebPushError } from '../client/errors';
import { WebPushClient } from '../client/web_push_client';

export type WebPushState = 'unavailable' | 'unsupported' | 'denied' | 'subscribed' | 'unsubscribed' | 'busy';

const DEFAULT_LABELS: Record<WebPushState, string> = {
    unavailable: 'Notifications are not configured on this page.',
    unsupported: 'This browser does not support push notifications.',
    denied: 'Notifications are blocked for this site.',
    subscribed: 'Notifications are enabled on this device.',
    unsubscribed: 'Notifications are disabled on this device.',
    busy: '…',
};

/**
 * Subscribe / unsubscribe buttons for Web Push.
 *
 * Renders no HTML: it sets `data-web-push-state` on its element, the text of the
 * `status` target (labels overridable with the `labels` value), and the `hidden` /
 * `disabled` state of the button targets. Events: `web-push:subscribed` (detail:
 * { subscribed: true }), `web-push:unsubscribed` (detail: { unsubscribed }), `web-push:error` (detail: { code, error }).
 *
 *     <div data-controller="web-push">
 *         <p data-web-push-target="status"></p>
 *         <button data-web-push-target="subscribeButton" data-action="web-push#subscribe">Enable</button>
 *         <button data-web-push-target="unsubscribeButton" data-action="web-push#unsubscribe">Disable</button>
 *     </div>
 */
export default class WebPushController extends Controller<HTMLElement> {
    static override targets = ['status', 'subscribeButton', 'unsubscribeButton'];

    static override values = { labels: Object };

    declare readonly hasStatusTarget: boolean;
    declare readonly statusTarget: HTMLElement;
    declare readonly subscribeButtonTargets: HTMLElement[];
    declare readonly unsubscribeButtonTargets: HTMLElement[];
    declare readonly labelsValue: Partial<Record<WebPushState, string>>;

    private client: WebPushClient | null = null;

    override connect(): void {
        const config = readPageConfig(this.element.ownerDocument);

        this.client = config === null ? null : new WebPushClient(config);
        void this.refresh();
    }

    /** Must stay bound to a user gesture: the client asks the permission first thing. */
    async subscribe(event?: Event): Promise<void> {
        event?.preventDefault();

        const client = this.client;

        if (client === null) {
            return;
        }

        // Called BEFORE any await: iOS drops the transient user activation otherwise.
        const pending = client.subscribe();

        this.render('busy');

        try {
            await pending;

            // Never the endpoint: it is a capability URL, anyone holding it can push to this device.
            this.dispatch('subscribed', { prefix: 'web-push', detail: { subscribed: true } });
        } catch (error) {
            this.dispatch('error', {
                prefix: 'web-push',
                detail: { code: error instanceof WebPushError ? error.code : 'subscription-failed', error },
            });
        }

        await this.refresh();
    }

    async unsubscribe(event?: Event): Promise<void> {
        event?.preventDefault();

        const client = this.client;

        if (client === null) {
            return;
        }

        this.render('busy');

        const done = await client.unsubscribe();

        this.dispatch('unsubscribed', { prefix: 'web-push', detail: { unsubscribed: done } });
        await this.refresh();
    }

    private async refresh(): Promise<void> {
        this.render(await this.currentState());
    }

    private async currentState(): Promise<WebPushState> {
        const client = this.client;

        if (client === null) {
            return 'unavailable';
        }

        if (!client.isSupported()) {
            return 'unsupported';
        }

        if (client.permission() === 'denied') {
            return 'denied';
        }

        const subscription = client.permission() === 'granted' ? await client.getSubscription() : null;

        return subscription === null ? 'unsubscribed' : 'subscribed';
    }

    private render(state: WebPushState): void {
        this.element.setAttribute('data-web-push-state', state);

        if (this.hasStatusTarget) {
            this.statusTarget.textContent = this.labelsValue[state] ?? DEFAULT_LABELS[state];
        }

        const busy = state === 'busy';

        // While busy, the buttons keep their visibility and are only disabled.
        for (const button of this.subscribeButtonTargets) {
            if (!busy) {
                button.hidden = state !== 'unsubscribed';
            }

            toggleDisabled(button, busy);
        }

        for (const button of this.unsubscribeButtonTargets) {
            if (!busy) {
                button.hidden = state !== 'subscribed';
            }

            toggleDisabled(button, busy);
        }
    }
}

function toggleDisabled(element: HTMLElement, disabled: boolean): void {
    if ('disabled' in element) {
        (element as HTMLButtonElement).disabled = disabled;
    }
}
