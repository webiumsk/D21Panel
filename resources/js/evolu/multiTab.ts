/**
 * Multi-tab detection for the Evolu (@evolu/web) SQLite/OPFS worker.
 *
 * Evolu shares one worker across tabs via a Web Lock named
 * `evolu-sharedwebworker-<db>`: the first tab holds it forever (the owner and
 * the only tab that runs the real Worker), and every other tab queues on the
 * same lock as a proxy. When a proxy tab misses the owner-ready handshake its
 * worker messages buffer indefinitely and no query ever resolves - the list
 * then spins forever with no error. We detect that "held + pending" contention
 * so the UI can tell the user to use a single window instead of hanging.
 */

const EVOLU_WORKER_LOCK_PREFIX = "evolu-sharedwebworker-";

interface LockInfoLike {
    name?: string;
}

export interface LockManagerSnapshotLike {
    held?: readonly LockInfoLike[];
    pending?: readonly LockInfoLike[];
}

/**
 * True when the Evolu worker lock is both held (an owner tab exists) and has a
 * pending waiter (another tab is queued) - i.e. more than one tab is
 * contending for the single worker. A single tab holds the lock with nothing
 * pending, so this stays false in the normal case.
 */
export function hasEvoluMultiTabContention(snapshot: LockManagerSnapshotLike): boolean {
    const isEvoluLock = (lock: LockInfoLike): boolean =>
        typeof lock.name === "string" && lock.name.startsWith(EVOLU_WORKER_LOCK_PREFIX);
    const held = snapshot.held ?? [];
    const pending = snapshot.pending ?? [];
    return held.some(isEvoluLock) && pending.some(isEvoluLock);
}

/** Best-effort: is the local Evolu worker blocked by another open tab? */
export async function detectEvoluMultiTabContention(): Promise<boolean> {
    if (typeof navigator === "undefined" || !navigator.locks?.query) {
        return false;
    }
    try {
        const snapshot = (await navigator.locks.query()) as LockManagerSnapshotLike;
        return hasEvoluMultiTabContention(snapshot);
    } catch {
        return false;
    }
}
