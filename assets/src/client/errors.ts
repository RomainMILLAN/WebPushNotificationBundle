export type WebPushErrorCode =
    | 'unsupported'
    | 'permission-denied'
    | 'permission-dismissed'
    | 'no-service-worker'
    | 'subscription-failed'
    | 'network'
    | 'anonymous-disabled'
    | 'invalid-request'
    | 'payload-too-large'
    | 'unsupported-media-type'
    | 'service-unavailable'
    | 'http-error';

export class WebPushError extends Error {
    constructor(
        readonly code: WebPushErrorCode,
        message: string,
        options?: { cause?: unknown },
    ) {
        super(message, options);
        this.name = 'WebPushError';
    }
}

/** The browser lacks Service Worker, Push API or Notification support. */
export class WebPushUnsupportedError extends WebPushError {
    constructor(message = 'Web Push is not supported by this browser.') {
        super('unsupported', message);
        this.name = 'WebPushUnsupportedError';
    }
}

/** The user refused ('denied') or dismissed ('default') the permission prompt. */
export class WebPushPermissionError extends WebPushError {
    constructor(readonly permission: NotificationPermission) {
        super(permission === 'denied' ? 'permission-denied' : 'permission-dismissed', `Notification permission is "${permission}".`);
        this.name = 'WebPushPermissionError';
    }
}

/** The server answered a non-2xx status to a subscription request. */
export class WebPushRequestError extends WebPushError {
    constructor(readonly status: number) {
        super(codeForStatus(status), `The subscription endpoint answered HTTP ${status}.`);
        this.name = 'WebPushRequestError';
    }
}

function codeForStatus(status: number): WebPushErrorCode {
    switch (status) {
        case 403: return 'anonymous-disabled';
        case 400: return 'invalid-request';
        case 413: return 'payload-too-large';
        case 415: return 'unsupported-media-type';
        case 503: return 'service-unavailable';
        default: return 'http-error';
    }
}
