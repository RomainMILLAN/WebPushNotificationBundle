import { type ServiceWorkerConfig } from './config';
import type { PluginContext, WebPushPlugin } from './plugin';
import type { WorkerScope } from './types';
export interface InstallOptions {
    /** Raw or normalized config; normalized again, so untrusted input is fine. */
    config?: Partial<ServiceWorkerConfig> | unknown;
    plugins?: readonly WebPushPlugin[];
    /**
     * skipWaiting() on install and clients.claim() on activate. Turn it off when an
     * application worker imports this one and owns its own lifecycle policy.
     */
    manageLifecycle?: boolean;
}
export interface InstalledWebPush {
    readonly config: ServiceWorkerConfig;
    readonly context: PluginContext;
}
/**
 * Wires the push, notificationclick and message handlers on a worker scope.
 *
 * Idempotent per scope, across bundles: a second call (a script imported twice, or
 * the ES build next to the prebuilt worker) returns the first
 * installation instead of registering every handler twice, which would display every
 * notification twice.
 */
export declare function installWebPush(scope: WorkerScope, options?: InstallOptions): InstalledWebPush;
