/**
 * FR-04 (S07 step 12) — the tab-leader election over NATIVE BroadcastChannel
 * (the plan's declined-wrapper ruling): sounds and desktop notifications
 * deduplicate to exactly ONE tab. The dual-tab browser proof runs BEFORE
 * any sound ships (the release's ordered minimum); sabotage #5 disables the
 * election (every tab believes it leads) and the duplicate-notification
 * proof goes red.
 *
 * The election is deliberately boring: every tab claims with a random id;
 * the LOWEST id wins; leaders heartbeat every 1 s; a tab that misses 3
 * heartbeats is presumed dead and the survivors re-elect. Boring survives
 * refactors.
 */

type LeaderMessage = { type: 'claim' | 'heartbeat'; id: string };

const CHANNEL = 'inbox-tab-leader';
const HEARTBEAT_MS = 1000;
const DEAD_AFTER_MS = 3000;

let channel: BroadcastChannel | null = null;
let tabId = '';
let knownLeader: { id: string; seenAt: number } | null = null;
let timer: ReturnType<typeof setInterval> | null = null;

export function startLeaderElection(): void {
    if (channel !== null || typeof BroadcastChannel === 'undefined') {
        return;
    }
    tabId = Math.random().toString(36).slice(2) + Date.now().toString(36);
    channel = new BroadcastChannel(CHANNEL);
    channel.onmessage = (event: MessageEvent<LeaderMessage>) => {
        const message = event.data;
        if (message.type === 'claim' || message.type === 'heartbeat') {
            // A lower id outranks us — record it as the live leader.
            if (message.id < tabId) {
                knownLeader = { id: message.id, seenAt: Date.now() };
            } else if (message.type === 'claim') {
                // A higher id joined — restate our claim so it learns of us.
                channel?.postMessage({ type: 'heartbeat', id: tabId } satisfies LeaderMessage);
            }
        }
    };
    channel.postMessage({ type: 'claim', id: tabId } satisfies LeaderMessage);
    timer = setInterval(() => {
        if (knownLeader && Date.now() - knownLeader.seenAt > DEAD_AFTER_MS) {
            knownLeader = null; // the leader died — we may lead now
        }
        if (isLeader()) {
            channel?.postMessage({ type: 'heartbeat', id: tabId } satisfies LeaderMessage);
        }
    }, HEARTBEAT_MS);
    window.addEventListener('pagehide', stopLeaderElection);
}

export function stopLeaderElection(): void {
    if (timer !== null) {
        clearInterval(timer);
        timer = null;
    }
    channel?.close();
    channel = null;
    knownLeader = null;
}

/** TRUE when no LIVE lower id is known — sabotage #5 hardcodes this. */
export function isLeader(): boolean {
    return knownLeader === null;
}

/** Test introspection (the scripted-stream discipline). */
export function leaderState(): { tabId: string; isLeader: boolean } {
    return { tabId, isLeader: isLeader() };
}
