export type WebPushErrorCode = 'unsupported' | 'permission-denied' | 'permission-dismissed' | 'no-service-worker' | 'subscription-failed' | 'network' | 'anonymous-disabled' | 'invalid-request' | 'payload-too-large' | 'unsupported-media-type' | 'service-unavailable' | 'http-error';
export declare class WebPushError extends Error {
    readonly code: WebPushErrorCode;
    constructor(code: WebPushErrorCode, message: string, options?: {
        cause?: unknown;
    });
}
/** The browser lacks Service Worker, Push API or Notification support. */
export declare class WebPushUnsupportedError extends WebPushError {
    constructor(message?: string);
}
/** The user refused ('denied') or dismissed ('default') the permission prompt. */
export declare class WebPushPermissionError extends WebPushError {
    readonly permission: NotificationPermission;
    constructor(permission: NotificationPermission);
}
/** The server answered a non-2xx status to a subscription request. */
export declare class WebPushRequestError extends WebPushError {
    readonly status: number;
    constructor(status: number);
}
