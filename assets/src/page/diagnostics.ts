import { MessageType, SW_VERSION, type ClickTrailEntry } from '../contract';

/** Local patience, not a contract constant: the worker has no symmetric timeout. */
const WORKER_PING_TIMEOUT_MS = 2_000;

/** Bounds `serviceWorker.ready`, which never settles without an active registration. */
const WORKER_READY_TIMEOUT_MS = 300;

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
export async function readWorkerDiagnostics(container: ServiceWorkerContainer | undefined = defaultContainer()): Promise<WorkerDiagnostics> {
    const reply = await askWorker<{ version?: unknown }>(container, { type: MessageType.workerPing }, MessageType.workerPong);
    // Data from a worker whose script is public: validated before being shown.
    const version = typeof reply?.version === 'string' && /^[0-9A-Za-z.-]{1,32}$/.test(reply.version) ? reply.version : null;

    return { version, expectedVersion: SW_VERSION, upToDate: version === SW_VERSION };
}

/** The last clicks the worker recorded, newest first. Diagnostic only, never a source of truth. */
export async function readClickTrail(container: ServiceWorkerContainer | undefined = defaultContainer()): Promise<ClickTrailEntry[]> {
    const reply = await askWorker<{ trail?: unknown }>(container, { type: MessageType.readClickTrail }, MessageType.clickTrail);

    return Array.isArray(reply?.trail) ? reply.trail as ClickTrailEntry[] : [];
}

async function askWorker<T>(container: ServiceWorkerContainer | undefined, message: unknown, expectedType: string): Promise<T | null> {
    const worker = await activeWorker(container);

    if (!worker) {
        return null;
    }

    return new Promise<T | null>((resolve) => {
        const channel = new MessageChannel();
        const timer = setTimeout(() => {
            channel.port1.close();
            resolve(null);
        }, WORKER_PING_TIMEOUT_MS);

        channel.port1.onmessage = (event: MessageEvent) => {
            clearTimeout(timer);
            channel.port1.close();
            resolve(event.data && event.data.type === expectedType ? event.data as T : null);
        };

        try {
            worker.postMessage(message, [channel.port2]);
        } catch {
            clearTimeout(timer);
            resolve(null);
        }
    });
}

async function activeWorker(container: ServiceWorkerContainer | undefined): Promise<ServiceWorker | null> {
    if (!container) {
        return null;
    }

    if (container.controller) {
        return container.controller;
    }

    let timer: ReturnType<typeof setTimeout> | undefined;
    const timeout = new Promise<null>((resolve) => {
        timer = setTimeout(() => resolve(null), WORKER_READY_TIMEOUT_MS);
    });

    try {
        const registration = await Promise.race([container.ready.catch(() => null), timeout]);

        return registration?.active ?? null;
    } finally {
        clearTimeout(timer);
    }
}

function defaultContainer(): ServiceWorkerContainer | undefined {
    return typeof navigator !== 'undefined' && 'serviceWorker' in navigator ? navigator.serviceWorker : undefined;
}
