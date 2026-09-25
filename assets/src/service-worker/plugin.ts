import type { ClickVerdict } from '../contract';
import type { ServiceWorkerConfig } from './config';
import type { PushPayload } from './payload';
import type { StateStore } from './state_store';
import type { WorkerScope } from './types';

export interface PluginContext {
    readonly scope: WorkerScope;
    readonly config: ServiceWorkerConfig;
    readonly state: StateStore;
}

/** What happened to a click, as recorded by the diagnostics plugin. */
export type ClickOutcome =
    | 'already-there'
    | 'navigated'
    | 'new-window'
    | 'nudged'
    | 'focus-only'
    | 'rejected'
    | 'post-sent'
    | 'post-failed';

/**
 * Status of the navigation intent for one click: `written` / `skipped-no-marker` come
 * from the navigationIntent plugin; `not-applicable` means there was nothing to
 * navigate to; `disabled` means no plugin handles intents.
 */
export type IntentStatus = 'written' | 'skipped-no-marker' | 'not-applicable' | 'disabled';

export interface ClickRecord {
    outcome: ClickOutcome;
    verdict: ClickVerdict | 'accepted';
    clientCount: number | null;
    intent: IntentStatus;
}

export type Reply = (message: unknown) => void;

/**
 * An observer of the worker events. Every hook is optional and isolated: a throwing
 * or rejecting hook is swallowed by the core, it never blocks a display or a click.
 */
export interface WebPushPlugin {
    readonly name: string;
    /** Runs alongside showNotification(), never before it. */
    onPush?(payload: PushPayload | null, context: PluginContext): void | Promise<void>;
    /** Called for an accepted click path BEFORE any navigation attempt. */
    onBeforeNavigate?(clickPath: string, context: PluginContext): IntentStatus | void | Promise<IntentStatus | void>;
    /** The click landed (focused a window already there, or navigated one). */
    onNavigated?(clickPath: string, context: PluginContext): void | Promise<void>;
    /** Every click, whatever its outcome. */
    onClick?(record: ClickRecord, context: PluginContext): void | Promise<void>;
    /** A message from a page; `type` is already checked to be a string. */
    onMessage?(message: { type: string; [key: string]: unknown }, reply: Reply, context: PluginContext): void | Promise<void>;
    onActivate?(context: PluginContext): void | Promise<void>;
}
