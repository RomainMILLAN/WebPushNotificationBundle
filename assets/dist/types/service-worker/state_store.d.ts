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
export declare class StateStore {
    private readonly caches;
    readonly name: string;
    constructor(caches: WorkerCacheStorage, name: string);
    read<T = unknown>(key: string): Promise<T | null>;
    write(key: string, value: unknown): Promise<void>;
    remove(key: string): Promise<boolean>;
    /** Drops the whole state cache: the logout path. */
    purge(): Promise<void>;
}
