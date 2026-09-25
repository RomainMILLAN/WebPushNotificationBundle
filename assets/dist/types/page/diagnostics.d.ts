import { type ClickTrailEntry } from '../contract';
export interface WorkerDiagnostics {
    /** The version the running worker reports, null when unreachable. */
    version: string | null;
    /** The version this page bundle was built with. */
    expectedVersion: string;
    upToDate: boolean;
}
/**
 * Ping/pong with the active worker: without it there is no way to know whether the new
 * worker actually runs on a device — and no devtools on an iPhone without a Mac.
 */
export declare function readWorkerDiagnostics(container?: ServiceWorkerContainer | undefined): Promise<WorkerDiagnostics>;
/** The last clicks the worker recorded, newest first. Diagnostic only, never a source of truth. */
export declare function readClickTrail(container?: ServiceWorkerContainer | undefined): Promise<ClickTrailEntry[]>;
