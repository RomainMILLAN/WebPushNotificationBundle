import { Controller } from '@hotwired/stimulus';
export type WebPushState = 'unavailable' | 'unsupported' | 'denied' | 'subscribed' | 'unsubscribed' | 'busy';
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
    static targets: string[];
    static values: {
        labels: ObjectConstructor;
    };
    readonly hasStatusTarget: boolean;
    readonly statusTarget: HTMLElement;
    readonly subscribeButtonTargets: HTMLElement[];
    readonly unsubscribeButtonTargets: HTMLElement[];
    readonly labelsValue: Partial<Record<WebPushState, string>>;
    private client;
    connect(): void;
    /** Must stay bound to a user gesture: the client asks the permission first thing. */
    subscribe(event?: Event): Promise<void>;
    unsubscribe(event?: Event): Promise<void>;
    private refresh;
    private currentState;
    private render;
}
