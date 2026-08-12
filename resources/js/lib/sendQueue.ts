// FR-29's queued sends + FS-05's guarded store (S07 step 13).
//
// SEC-03 posture, stated: client stores are per-tab memory and no ticket or
// message CONTENT lands in localStorage — drafts are the named exception
// (Doc 04 T-07: body only, IndexedDB, cleared on logout). A queued send IS
// that exception at its send moment: the user's own unsent text, body only,
// plus the routing minimums (conversation id, mode, the FR-21 `then` intent)
// and the idempotency key that makes the reconnect flush exactly-once. It
// lives in IndexedDB beside the drafts contract and is cleared on logout by
// the same lifecycle.
//
// FS-05: the store is VERSIONED. A schema mismatch after a deploy (FR-30's
// skew) or a corrupt record does not take the session down — readAll()
// reports `recovered: false` rows separately so the caller can tell the agent
// plainly ("2 queued replies could not be recovered") instead of silently
// dropping them. Composer TEXT is preserved above all of this — it never
// depends on IndexedDB being readable (FS-05's own words: drafts are the
// last thing ever dropped).

export type QueuedSend = {
    id: string; // idempotency key — minted ONCE at queue time (SS-03 zero-dupes)
    conversationId: string;
    mode: 'reply' | 'note';
    body: string;
    then: string | null; // FR-21 split-button intent, carried through the flush
    queuedAt: number; // serverNow() ms at queue time
};

const DB_NAME = 'inbox-send-queue';
const STORE = 'sends';
// FS-05's tripwire: bump on any record-shape change; a mismatched database is
// recovered (readable rows kept, unreadable reported), never trusted blindly.
export const SEND_QUEUE_SCHEMA_VERSION = 1;

function openDb(): Promise<IDBDatabase> {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open(DB_NAME, SEND_QUEUE_SCHEMA_VERSION);
        request.onupgradeneeded = () => {
            const db = request.result;
            if (!db.objectStoreNames.contains(STORE)) {
                db.createObjectStore(STORE, { keyPath: 'id' });
            }
        };
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error ?? new Error('indexeddb open failed'));
        request.onblocked = () => reject(new Error('indexeddb blocked'));
    });
}

function isQueuedSend(row: unknown): row is QueuedSend {
    if (typeof row !== 'object' || row === null) return false;
    const r = row as Record<string, unknown>;
    return (
        typeof r.id === 'string' &&
        typeof r.conversationId === 'string' &&
        (r.mode === 'reply' || r.mode === 'note') &&
        typeof r.body === 'string' &&
        (r.then === null || typeof r.then === 'string') &&
        typeof r.queuedAt === 'number'
    );
}

export async function enqueueSend(send: QueuedSend): Promise<void> {
    const db = await openDb();
    await new Promise<void>((resolve, reject) => {
        const tx = db.transaction(STORE, 'readwrite');
        tx.objectStore(STORE).put(send);
        tx.oncomplete = () => resolve();
        tx.onerror = () => reject(tx.error ?? new Error('enqueue failed'));
    });
    db.close();
}

export async function removeSend(id: string): Promise<void> {
    const db = await openDb();
    await new Promise<void>((resolve, reject) => {
        const tx = db.transaction(STORE, 'readwrite');
        tx.objectStore(STORE).delete(id);
        tx.oncomplete = () => resolve();
        tx.onerror = () => reject(tx.error ?? new Error('remove failed'));
    });
    db.close();
}

/**
 * FS-05's honest read: every VALID row in queue order (oldest first — the
 * in-order flush contract), plus the count of rows that were present but
 * unreadable. Unreadable rows are deleted after being counted — reported
 * lost, never silently dropped, never retried as garbage.
 */
export async function readQueue(): Promise<{ sends: QueuedSend[]; lost: number }> {
    let db: IDBDatabase;
    try {
        db = await openDb();
    } catch {
        // The whole database is unopenable — FS-05's hard case. Destroy and
        // report; the composer text does not live here and survives.
        await destroyQueue();
        return { sends: [], lost: -1 }; // -1 = "unknown count, store rebuilt"
    }
    const rows = await new Promise<unknown[]>((resolve, reject) => {
        const tx = db.transaction(STORE, 'readonly');
        const request = tx.objectStore(STORE).getAll();
        request.onsuccess = () => resolve(request.result as unknown[]);
        request.onerror = () => reject(request.error ?? new Error('read failed'));
    }).catch(() => null);
    if (rows === null) {
        db.close();
        await destroyQueue();
        return { sends: [], lost: -1 };
    }

    const sends: QueuedSend[] = [];
    let lost = 0;
    for (const row of rows) {
        if (isQueuedSend(row)) {
            sends.push(row);
        } else {
            lost += 1;
        }
    }
    if (lost > 0) {
        // Purge the garbage so it is counted exactly once.
        const tx = db.transaction(STORE, 'readwrite');
        tx.objectStore(STORE).clear();
        await new Promise<void>((resolve) => {
            tx.oncomplete = () => resolve();
            tx.onerror = () => resolve();
        });
        for (const send of sends) {
            await enqueueSend(send);
        }
    }
    db.close();
    sends.sort((a, b) => a.queuedAt - b.queuedAt || a.id.localeCompare(b.id));
    return { sends, lost };
}

/** Logout / hard recovery: the queue dies with the session (SEC-03). */
export async function destroyQueue(): Promise<void> {
    await new Promise<void>((resolve) => {
        const request = indexedDB.deleteDatabase(DB_NAME);
        request.onsuccess = () => resolve();
        request.onerror = () => resolve();
        request.onblocked = () => resolve();
    });
}

/** A collision-safe idempotency key (T-03's outbound half, minted client-side). */
export function mintIdempotencyKey(): string {
    if (typeof crypto !== 'undefined' && 'randomUUID' in crypto) {
        return crypto.randomUUID();
    }
    return 'q-' + Math.random().toString(36).slice(2) + '-' + Date.now().toString(36);
}
