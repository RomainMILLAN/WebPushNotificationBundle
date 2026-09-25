/**
 * The prebuilt worker, dist/web-push-sw.js (IIFE, no module syntax).
 *
 * Served by the PHP route with `self.__WEB_PUSH_CONFIG__ = {...};` prepended, so the
 * configuration exists before this runs. Works both registered directly and pulled
 * into an application worker with `importScripts('/web-push-sw.js')`.
 */
import { installWebPush } from './install';
import { badge } from './plugins/badge';
import { clientState } from './plugins/client_state';
import { diagnostics } from './plugins/diagnostics';
import { navigationIntent } from './plugins/navigation_intent';
import type { WorkerScope } from './types';

declare const self: WorkerScope & { __WEB_PUSH_CONFIG__?: unknown };

installWebPush(self, {
    config: self.__WEB_PUSH_CONFIG__,
    plugins: [clientState(), navigationIntent(), diagnostics(), badge()],
});
