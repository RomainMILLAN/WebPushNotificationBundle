import type { WorkerCacheStorage } from './types';

/**
 * JSON documents in the worker's Cache Storage state cache.
 *
 * localStorage does not exist in a worker, so Cache Storage is the store. It is shared
 * by ORIGIN, not by user: nothing stored here may identify what a previous user did
 * (the click trail therefore never holds a path).
 *
 * Every method degrades instead of throwing: a full or unavailable cache must never
 * break a push display or a click.
 */
export class StateStore {
    constructor(
        private readonly caches: WorkerCacheStorage,
        readonly name: string,
    ) {
    }

    async read<T = unknown>(key: string): Promise<T | null> {
        try {
            const cache = await this.caches.open(this.name);
            const stored = await cache.match(key);

            return stored ? await stored.json() as T : null;
        } catch {
            return null;
        }
    }

    async write(key: string, value: unknown): Promise<void> {
        try {
            const cache = await this.caches.open(this.name);

            await cache.put(key, new Response(JSON.stringify(value), {
                headers: { 'content-type': 'application/json' },
            }));
        } catch {
            // Degrade: the feature is lost, the notification is not.
        }
    }

    async remove(key: string): Promise<boolean> {
        try {
            const cache = await this.caches.open(this.name);

            return await cache.delete(key);
        } catch {
            return false;
        }
    }

    /** Drops the whole state cache: the logout path. */
    async purge(): Promise<void> {
        try {
            await this.caches.delete(this.name);
        } catch {
            // Nothing else to do.
        }
    }
}
