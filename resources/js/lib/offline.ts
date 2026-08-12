// FR-29 / T-07-client / EC-08 / FR-30 — the offline machine (S07 step 13).
//
// One machine, one reconciliation path: a drop flips the store to
// `reconnecting` the moment it is seen (FR-29's ≤2 s pill is render latency,
// not detection latency), a full browser-offline shows the cached last view
// read-only, and EVERY return to life runs the same sequence —
//
//   jitter (0–10 s, EC-08) → GET /inbox/sync?cursor (T-07, the SINGLE path)
//     → 429? honor Retry-After with backoff, try again (EC-08's shed)
//     → anchor server clock (EC-07) · compare build_id (FR-30, passive)
//     → overflow? full refetch of the active views : apply the event slice
//     → flush queued sends IN ORDER with their minted idempotency keys
//       (SS-03: zero dupes — the key, not optimism, is the guarantee)
//
// Sends that the server REFUSES (4xx) are removed from the queue and surfaced
// as visible failures (BR-01) — a refusal retried forever would hold the whole
// in-order queue hostage behind a send that can never land. A NETWORK failure
// mid-flush stops the flush where it stands; order is preserved for the next
// attempt because nothing after the stall was attempted.
//
// Production sockets (Reverb) will call signalDrop()/signalUp() from their
// connection callbacks — the same seam the browser events and the test hook
// drive. The machine does not care who noticed the drop.

import { anchorServerNow } from './serverClock';
import {
    destroyQueue,
    enqueueSend,
    mintIdempotencyKey,
    readQueue,
    removeSend,
    type QueuedSend,
} from './sendQueue';
import { useInboxConnection } from '../stores/inbox/connection';

type MachineOptions = {
    buildId: string;
    xsrf: () => string;
    /** Sync produced a bounded event slice — the tickets it names get refetched. */
    onEvents: (ticketIds: string[]) => void;
    /** Sync overflowed (T-07): abandon deltas, refetch the active view whole. */
    onOverflow: () => void;
    /** §13 / B12-A01 AC-09: offline transitions + flush outcomes are ANNOUNCED. */
    announce: (message: string) => void;
    /** A flushed send landed — the thread refreshes to show the real bubble. */
    onFlushed: (conversationId: string) => void;
};

let options: MachineOptions | null = null;
let jitterMaxMs = 10_000;
let reconnectTimer: ReturnType<typeof setTimeout> | null = null;
let reconnectAttempt = 0;

function store(): ReturnType<typeof useInboxConnection> {
    return useInboxConnection();
}

export function initOfflineMachine(opts: MachineOptions): void {
    options = opts;
    window.addEventListener('offline', () => signalDrop('browser-offline'));
    window.addEventListener('online', () => signalUp());
    // A previous session may have died with sends still queued (B12-A01
    // AC-07's reload case): mirror them, then flush if we are online.
    void refreshQueueMirror().then(() => {
        if (store().state === 'online' && store().queued.length > 0) {
            void flushQueue();
        }
    });
}

/** EC-08's jitter is real by default; tests pin it to zero. */
export function setJitterMax(ms: number): void {
    jitterMaxMs = ms;
}

export function signalDrop(reason: string): void {
    const connection = store();
    if (connection.state !== 'online') {
        return;
    }
    // navigator.onLine false = the browser KNOWS there is no network — the
    // full-offline screen posture; a socket drop with a live network is the
    // amber reconnecting posture. Both are read-only (FR-29).
    connection.state =
        typeof navigator !== 'undefined' && !navigator.onLine ? 'offline' : 'reconnecting';
    options?.announce(reason === 'browser-offline' ? 'offline' : 'reconnecting');
    if (connection.state === 'reconnecting') {
        scheduleReconnect();
    }
}

export function signalUp(): void {
    const connection = store();
    if (connection.state === 'online') {
        return;
    }
    connection.state = 'reconnecting';
    scheduleReconnect();
}

function scheduleReconnect(): void {
    if (reconnectTimer !== null) {
        return;
    }
    const jitter = Math.floor(Math.random() * (jitterMaxMs + 1));
    reconnectTimer = setTimeout(() => {
        reconnectTimer = null;
        void attemptReconnect();
    }, jitter);
}

async function attemptReconnect(): Promise<void> {
    const connection = store();
    if (connection.state === 'online' || options === null) {
        return;
    }
    let response: Response;
    try {
        const cursor = connection.syncCursor;
        response = await fetch(
            '/inbox/sync' + (cursor ? '?cursor=' + encodeURIComponent(cursor) : ''),
            {
                headers: { Accept: 'application/json' },
            },
        );
    } catch {
        // Still down — the browser online event or the next signalUp retries;
        // hold a slow heartbeat so a silent recovery is still found.
        reconnectAttempt += 1;
        reconnectTimer = setTimeout(
            () => {
                reconnectTimer = null;
                void attemptReconnect();
            },
            Math.min(30_000, 2_000 * 2 ** Math.min(reconnectAttempt, 4)),
        );
        return;
    }

    if (response.status === 429) {
        // EC-08: the server is shedding a storm — honor it, with headroom.
        const retryAfter = Number(response.headers.get('Retry-After') ?? '0');
        reconnectAttempt += 1;
        const backoff =
            Math.max(
                retryAfter * 1000,
                Math.min(30_000, 1_000 * 2 ** Math.min(reconnectAttempt, 5)),
            ) + Math.floor(Math.random() * 1_000);
        reconnectTimer = setTimeout(() => {
            reconnectTimer = null;
            void attemptReconnect();
        }, backoff);
        return;
    }
    if (!response.ok) {
        reconnectAttempt += 1;
        reconnectTimer = setTimeout(
            () => {
                reconnectTimer = null;
                void attemptReconnect();
            },
            Math.min(30_000, 2_000 * 2 ** Math.min(reconnectAttempt, 4)),
        );
        return;
    }

    reconnectAttempt = 0;
    const body = (await response.json()) as {
        events: { ticket_id?: string | null }[];
        next_cursor: string | null;
        overflow: boolean;
        server_now: string;
        build_id: string;
    };

    anchorServerNow(body.server_now);
    connection.syncCursor = body.next_cursor;

    // FR-30: skew is a PASSIVE toast — never a forced reload, never
    // mid-composition interruption. The banner renders from this flag.
    if (body.build_id !== options.buildId) {
        connection.skewDetected = true;
    }

    if (body.overflow) {
        options.onOverflow();
    } else {
        const ids = body.events
            .map((event) => event.ticket_id)
            .filter((id): id is string => typeof id === 'string' && id !== '');
        if (ids.length > 0) {
            options.onEvents([...new Set(ids)]);
        }
    }

    connection.state = 'online';
    options.announce('online');
    await flushQueue();
}

/** FR-29: an offline send is QUEUED, visibly, with its key minted at queue time. */
export async function queueSend(input: {
    conversationId: string;
    mode: 'reply' | 'note';
    body: string;
    then: string | null;
    queuedAt: number;
}): Promise<QueuedSend> {
    const send: QueuedSend = { id: mintIdempotencyKey(), ...input };
    await enqueueSend(send);
    await refreshQueueMirror();
    return send;
}

/** The mirror the chips render from — refreshed after every queue mutation. */
export async function refreshQueueMirror(): Promise<void> {
    const connection = store();
    const { sends, lost } = await readQueue();
    connection.queued = sends;
    if (lost !== 0) {
        connection.lostFromStore = lost;
    }
}

/**
 * The in-order flush (AC-07's heart). Sequential by construction — one send
 * must land (or be refused) before the next is attempted.
 */
export async function flushQueue(): Promise<void> {
    const connection = store();
    if (connection.flushing || options === null) {
        return;
    }
    connection.flushing = true;
    try {
        const { sends } = await readQueue();
        for (const send of sends) {
            if (connection.state !== 'online') {
                break;
            }
            let response: Response;
            try {
                response = await fetch('/inbox/thread/' + send.conversationId + '/' + send.mode, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-XSRF-TOKEN': options.xsrf(),
                    },
                    body: JSON.stringify(
                        send.mode === 'reply'
                            ? {
                                  body: send.body,
                                  idempotency_key: send.id,
                                  ...(send.then ? { then: send.then } : {}),
                              }
                            : { body: send.body },
                    ),
                });
            } catch {
                // Network died mid-flush: stop HERE. Nothing later was
                // attempted, so order holds for the next reconnect.
                signalDrop('flush-network-loss');
                break;
            }
            if (response.ok || response.status === 201) {
                await removeSend(send.id);
                connection.flushedCount += 1;
                options.onFlushed(send.conversationId);
                continue;
            }
            // A refusal (4xx/5xx): visible failure, out of the queue — it
            // would otherwise hold every later send hostage (BR-01: rolled
            // back VISIBLY, never silently).
            let reason = 'send_failed';
            try {
                const problem = (await response.json()) as { code?: string };
                if (typeof problem.code === 'string') {
                    reason = problem.code;
                }
            } catch {
                // keep the generic reason
            }
            await removeSend(send.id);
            connection.flushFailures.push({
                id: send.id,
                conversationId: send.conversationId,
                body: send.body,
                reason,
            });
        }
    } finally {
        connection.flushing = false;
        await refreshQueueMirror();
        if (store().queued.length === 0 && store().flushedCount > 0) {
            options.announce('flushed');
        }
    }
}

/**
 * FS-05: hard store recovery. Preserves NOTHING from the dead database (that
 * is the point) and reports what could not be carried — the composer text is
 * not this store's to lose, and the caller re-persists it after the reset.
 */
export async function recoverStore(): Promise<void> {
    const connection = store();
    const { sends, lost } = await readQueue();
    if (lost !== 0) {
        connection.lostFromStore = lost;
    }
    connection.queued = sends;
    connection.storeRebuilt = true;
}

/** Logout hygiene (SEC-03): the queue dies with the session. */
export async function clearOfflineStore(): Promise<void> {
    await destroyQueue();
}
