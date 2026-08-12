// EC-07 (S07 step 13): client clocks lie — relative times and staleness
// judgements are computed from a SERVER-NOW OFFSET captured at handshake and
// refreshed on every /inbox/sync response, never from the client clock alone.
//
// The offset is (server_now − local now) at the moment the server answered.
// serverNow() = local now + offset ≈ the server's current clock, immune to a
// client that is minutes wrong. R-19/ADR 0010 stays server-side law — this is
// only the RENDERING anchor the document's EC-07 names.

let offsetMs = 0;
let anchored = false;

/** Anchor (or re-anchor) from a server timestamp — call with every sync/handshake. */
export function anchorServerNow(serverNowIso: string): void {
    const parsed = new Date(serverNowIso).getTime();
    if (Number.isNaN(parsed)) {
        return; // a bad anchor is worse than the last good one
    }
    offsetMs = parsed - Date.now();
    anchored = true;
}

/** Milliseconds-since-epoch as the SERVER would report now. */
export function serverNow(): number {
    return Date.now() + offsetMs;
}

/** Whether any anchor has been taken (unanchored → offset 0 → client clock). */
export function serverClockAnchored(): boolean {
    return anchored;
}

/** The current offset — surfaced for the test hook and diagnostics. */
export function serverClockOffsetMs(): number {
    return offsetMs;
}
