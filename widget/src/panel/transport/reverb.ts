/*
 * Doc 13 T-04 / ADR 0020 item 2 — THE HAND-ROLLED PUSHER-PROTOCOL CLIENT (v7
 * subset): connect, `pusher:connection_established`, ping/pong, PRIVATE-channel
 * subscribe with the signature from the widget's own broadcasting-auth door
 * (Doc 02's visitor principal), `subscription_succeeded` / `subscription_error`,
 * event dispatch, exponential reconnect + resubscribe, and the `up` / `down`
 * signals the polling machine consumes (NFR-03: no polling while the socket is
 * healthy; K01: a lost socket changes NOTHING on screen). Presence channels and
 * client events are out of scope on purpose (a visitor's channels are private).
 *
 * The socket is a WAKE-UP, never the record: every thread frame triggers a
 * cursor sync (T-04's "the sync envelope the poller and the socket share"),
 * so the bubble a visitor sees is always the server's row.
 */
export interface ReverbOptions {
    url: string; // wss://host[:port]/app/{key}?protocol=7&client=nlw&version=…
    authorize: (socketId: string, channel: string) => Promise<string | null>; // the `auth` string, or null = refused
    channels: string[];
    onEvent: (channel: string, event: string, data: unknown) => void;
    onUp: () => void;
    onDown: () => void;
}

export class ReverbClient {
    private ws: WebSocket | null = null;
    private socketId: string | null = null;
    private attempts = 0;
    private timer: number | null = null;
    private pingTimer: number | null = null;
    private closed = false;
    private up = false;
    readonly subscribed = new Set<string>();

    constructor(private readonly o: ReverbOptions) {}

    connect(): void {
        this.closed = false;
        this.open();
    }

    close(): void {
        this.closed = true;
        this.clearTimers();
        this.ws?.close();
        this.ws = null;
        this.setUp(false);
    }

    isUp(): boolean {
        return this.up;
    }

    /** test hook (AC-08's drill from inside): drop the socket as if the network did */
    drop(): void {
        this.ws?.close();
    }

    private open(): void {
        if (this.closed) return;
        let ws: WebSocket;
        try {
            ws = new WebSocket(this.o.url);
        } catch {
            this.scheduleReconnect();
            return;
        }
        this.ws = ws;
        ws.onmessage = (ev) => this.onFrame(ev);
        ws.onclose = () => {
            if (this.ws !== ws) return;
            this.ws = null;
            this.socketId = null;
            this.subscribed.clear();
            this.setUp(false);
            this.scheduleReconnect();
        };
        ws.onerror = () => {
            /* onclose follows */
        };
    }

    private onFrame(ev: MessageEvent): void {
        let frame: { event?: string; channel?: string; data?: unknown };
        try {
            frame = JSON.parse(String(ev.data)) as typeof frame;
        } catch {
            return;
        }
        const data = typeof frame.data === 'string' ? safeJson(frame.data) : frame.data;
        switch (frame.event) {
            case 'pusher:connection_established': {
                this.socketId = (data as { socket_id?: string })?.socket_id ?? null;
                this.attempts = 0;
                this.startPing();
                void this.subscribeAll();
                break;
            }
            case 'pusher:ping':
                this.send({ event: 'pusher:pong', data: {} });
                break;
            case 'pusher:pong':
                break;
            case 'pusher_internal:subscription_succeeded':
                if (frame.channel) this.subscribed.add(frame.channel);
                if (this.subscribed.size >= this.o.channels.length) this.setUp(true);
                break;
            case 'pusher:subscription_error':
            case 'pusher:error':
                // A refused channel is a refusal, never retried in a loop: the polling fallback carries the thread.
                this.setUp(false);
                break;
            default:
                if (frame.event && frame.channel) this.o.onEvent(frame.channel, frame.event, data);
        }
    }

    private async subscribeAll(): Promise<void> {
        if (!this.socketId) return;
        for (const channel of this.o.channels) {
            if (!channel.startsWith('private-') && !channel.startsWith('presence-')) {
                // a PUBLIC channel (the per-widget config wake-up) needs no auth
                this.send({ event: 'pusher:subscribe', data: { channel } });
                continue;
            }
            const auth = await this.o.authorize(this.socketId, channel);
            if (!auth || !this.ws) continue;
            this.send({ event: 'pusher:subscribe', data: { channel, auth } });
        }
    }

    private send(frame: unknown): void {
        try {
            this.ws?.send(JSON.stringify(frame));
        } catch {
            /* the close handler follows */
        }
    }

    private startPing(): void {
        if (this.pingTimer !== null) window.clearInterval(this.pingTimer);
        this.pingTimer = window.setInterval(
            () => this.send({ event: 'pusher:ping', data: {} }),
            30000,
        );
    }

    private scheduleReconnect(): void {
        if (this.closed) return;
        this.clearTimers();
        const delay =
            Math.min(30000, 1000 * 2 ** Math.min(this.attempts++, 5)) +
            Math.floor(Math.random() * 500);
        this.timer = window.setTimeout(() => this.open(), delay);
    }

    private clearTimers(): void {
        if (this.timer !== null) window.clearTimeout(this.timer);
        if (this.pingTimer !== null) window.clearInterval(this.pingTimer);
        this.timer = this.pingTimer = null;
    }

    private setUp(up: boolean): void {
        if (this.up === up) return;
        this.up = up;
        if (up) this.o.onUp();
        else this.o.onDown();
    }
}

function safeJson(s: string): unknown {
    try {
        return JSON.parse(s);
    } catch {
        return s;
    }
}
