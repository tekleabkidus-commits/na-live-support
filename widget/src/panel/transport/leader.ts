/*
 * Doc 13 I05 / T-04 — MULTI-TAB LEADERSHIP: one socket, one sound, one unread
 * count per browser. The Web Locks API elects the leader (the lock is released
 * when a tab closes or crashes — the next waiter takes it, ≤ 2 s hand-off);
 * a BroadcastChannel mirrors the leader's wake-ups to the followers, who poll
 * nothing and open no socket. Panel STATE (open, tab) stays per tab.
 */
export class TabLeader {
    private channel: BroadcastChannel | null = null;
    private leader = false;
    private listeners: Array<(msg: unknown) => void> = [];
    private onElected: (() => void) | null = null;
    private onDemoted: (() => void) | null = null;

    constructor(private readonly name: string) {
        try {
            this.channel = new BroadcastChannel(name);
            this.channel.onmessage = (ev) => {
                for (const fn of this.listeners) fn(ev.data);
            };
        } catch {
            this.channel = null;
        }
    }

    isLeader(): boolean {
        return this.leader;
    }

    /** Resolves once this tab holds the lock; the callback runs while it holds it. */
    elect(onElected: () => void, onDemoted: () => void): void {
        this.onElected = onElected;
        this.onDemoted = onDemoted;
        const locks = (navigator as Navigator & { locks?: LockManager }).locks;
        if (!locks) {
            // No Web Locks (an old WebView): every tab leads itself — sound may double, the record stays right.
            this.leader = true;
            onElected();
            return;
        }
        void locks.request(this.name + '.leader', () => {
            this.leader = true;
            this.onElected?.();
            return new Promise<void>(() => {
                /* held until the tab goes away */
            });
        });
    }

    broadcast(msg: unknown): void {
        try {
            this.channel?.postMessage(msg);
        } catch {
            /* followers simply poll on their own cadence */
        }
    }

    onMessage(fn: (msg: unknown) => void): void {
        this.listeners.push(fn);
    }

    release(): void {
        this.leader = false;
        this.onDemoted?.();
        this.channel?.close();
    }
}
