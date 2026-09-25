import { MessageType, SW_VERSION, TRAIL_KEY, TRAIL_MAX_ENTRIES, type ClickTrailEntry } from '../../contract';
import type { WebPushPlugin } from '../plugin';
import type { StateStore } from '../state_store';

/**
 * Answers `worker-ping` with the running version, and keeps a trail of the last clicks.
 *
 * There are no devtools on an iPhone without a Mac: "is the new worker running?" and
 * "what did my click do?" are unanswerable without this.
 *
 * The trail NEVER stores a path: it lives in a store shared by origin, so user B would
 * read what user A clicked. Diagnostic only, never a source of truth.
 */
export function diagnostics(): WebPushPlugin {
    return {
        name: 'diagnostics',

        async onClick(record, { state }) {
            const trail = await readTrail(state);

            trail.unshift({
                at: Date.now(),
                outcome: record.outcome,
                verdict: record.verdict,
                clientCount: record.clientCount,
                intent: record.intent,
            });

            await state.write(TRAIL_KEY, trail.slice(0, TRAIL_MAX_ENTRIES));
        },

        async onMessage(message, reply, { state }) {
            if (message.type === MessageType.workerPing) {
                reply({ type: MessageType.workerPong, version: SW_VERSION });

                return;
            }

            if (message.type === MessageType.readClickTrail) {
                reply({ type: MessageType.clickTrail, trail: await readTrail(state) });
            }
        },
    };
}

export async function readTrail(state: StateStore): Promise<ClickTrailEntry[]> {
    const trail = await state.read(TRAIL_KEY);

    return Array.isArray(trail) ? trail as ClickTrailEntry[] : [];
}
