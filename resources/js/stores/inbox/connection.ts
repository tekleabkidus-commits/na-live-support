// FR-29's connection truth (S07 step 13): one store, three states, and the
// visible queue. Every banner, chip, and read-only posture renders FROM here;
// nothing else keeps its own opinion of whether the socket is alive (BR-01:
// the inbox never lies about state — one source, visibly reconciled).
import { defineStore } from 'pinia';
import type { QueuedSend } from '../../lib/sendQueue';

export type ConnectionState = 'online' | 'reconnecting' | 'offline';

export type FlushFailure = {
    id: string;
    conversationId: string;
    body: string;
    reason: string; // stable C-04 code where the server gave one
};

export const useInboxConnection = defineStore('inbox-connection', {
    state: () => ({
        state: 'online' as ConnectionState,
        // Mirror of the IndexedDB queue for rendering (chips, counts). The
        // database row is the truth the flush reads; this is its shadow.
        queued: [] as QueuedSend[],
        // FS-05's honest loss report: -1 = store rebuilt, count unknown.
        lostFromStore: 0,
        flushing: false,
        // The last flush's visible outcome (BR-01: reconcile or roll back
        // VISIBLY): refusals are surfaced, never silently dropped.
        flushFailures: [] as FlushFailure[],
        flushedCount: 0,
        // FR-30: build skew detected on a sync response → passive toast only.
        skewDetected: false,
        // FS-05: the session store was reset and rebuilt — tell the agent.
        storeRebuilt: false,
        syncCursor: null as string | null,
        // Refetch signals (T-02's full-row-fetch idiom, store-shaped): sync
        // and recovery bump these; the panes watch and refetch themselves.
        viewRefetchTick: 0,
        threadRefetchTick: 0,
        // FS-05: recovery REMOUNTS the thread (the refetch that makes the
        // composer-text preserve/restore guard load-bearing — sabotage #17's
        // target). The composer mirrors its live text here and recovery
        // hands it back through preservedText.
        threadRemountTick: 0,
        composerLiveText: {} as Record<string, string>,
        preservedText: {} as Record<string, string>,
    }),
    getters: {
        readOnly: (state): boolean => state.state !== 'online',
        queuedFor: (state) => {
            return (conversationId: string): QueuedSend[] =>
                state.queued.filter((send) => send.conversationId === conversationId);
        },
    },
});
