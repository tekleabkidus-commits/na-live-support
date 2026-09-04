/*
 * Doc 13 FR-08 / NFR-03 — THE LOCAL CACHE (IndexedDB, this frame's origin):
 * the last 50 messages, CONTENT-LITE (body, author, kind, times, delivery —
 * never media bytes, never survey tokens), so a returning visitor's thread
 * paints before the first cursor page. `widget.cache.content` off (the
 * operator's setting) = nothing is ever written and the store is purged; a
 * cleared visitor (a fresh visitor id) purges too. Both states are proven.
 */
import type { Message } from './types';

const DB = 'nlw';
const STORE = 'thread';

function openDb(): Promise<IDBDatabase | null> {
    return new Promise((resolve) => {
        try {
            const req = indexedDB.open(DB, 1);
            req.onupgradeneeded = () => req.result.createObjectStore(STORE);
            req.onsuccess = () => resolve(req.result);
            req.onerror = () => resolve(null);
        } catch {
            resolve(null);
        }
    });
}

export async function readCache(visitorId: string): Promise<Message[]> {
    const db = await openDb();
    if (!db) return [];
    return new Promise((resolve) => {
        try {
            const tx = db.transaction(STORE, 'readonly');
            const req = tx.objectStore(STORE).get(visitorId);
            req.onsuccess = () =>
                resolve(Array.isArray(req.result) ? (req.result as Message[]) : []);
            req.onerror = () => resolve([]);
        } catch {
            resolve([]);
        }
    });
}

export async function writeCache(visitorId: string, messages: Message[]): Promise<void> {
    const db = await openDb();
    if (!db) return;
    const lite = messages.slice(-50).map((m) => ({
        id: m.id,
        created_at: m.created_at,
        direction: m.direction,
        author: m.author,
        kind: m.kind,
        body: m.body,
        media: m.media.map((a) => ({
            kind: a.kind,
            mime: a.mime,
            bytes: a.bytes,
            scan: a.scan,
            url: null,
        })),
        delivery_state: m.delivery_state,
        external_message_id: m.external_message_id,
        occurred_at: m.occurred_at,
        survey: null,
    }));
    try {
        db.transaction(STORE, 'readwrite').objectStore(STORE).put(lite, visitorId);
    } catch {
        /* quota or a private window: the server is the record anyway */
    }
}

export async function purgeCache(): Promise<void> {
    const db = await openDb();
    if (!db) return;
    try {
        db.transaction(STORE, 'readwrite').objectStore(STORE).clear();
    } catch {
        /* nothing to purge */
    }
}

export async function cacheKeys(): Promise<string[]> {
    const db = await openDb();
    if (!db) return [];
    return new Promise((resolve) => {
        try {
            const req = db.transaction(STORE, 'readonly').objectStore(STORE).getAllKeys();
            req.onsuccess = () => resolve((req.result as IDBValidKey[]).map(String));
            req.onerror = () => resolve([]);
        } catch {
            resolve([]);
        }
    });
}
