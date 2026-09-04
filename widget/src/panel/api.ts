/*
 * Doc 13 T-03 — the panel's doors, all on the platform origin (the frame's own
 * origin by default; `credentials: 'include'` carries the FR-22 cookie). The
 * widget key rides `X-Tenant-Key`, the visitor token `X-Visitor-Token` (Doc 02
 * FR-21). Every call is a plain fetch; a failed call answers null — the caller
 * decides what a labeled degrade looks like (§8, NFR-06).
 */
import type { ConfigEnvelope, MessagesPage } from './types';

export class WidgetApi {
    private token: string | null = null;

    constructor(
        private readonly platform: string,
        private readonly widgetKey: string,
    ) {}

    setToken(token: string): void {
        this.token = token;
    }

    async config(language: string | null): Promise<ConfigEnvelope | null> {
        const r = await this.call(
            'GET',
            '/api/widget/v1/config' + (language ? `?language=${encodeURIComponent(language)}` : ''),
        );
        return r && r.ok ? ((await r.json()) as ConfigEnvelope) : null;
    }

    async messages(cursor: string | null): Promise<MessagesPage | null> {
        const r = await this.call(
            'GET',
            '/api/widget/v1/messages' + (cursor ? `?cursor=${encodeURIComponent(cursor)}` : ''),
        );
        return r && r.ok ? ((await r.json()) as MessagesPage) : null;
    }

    async send(body: {
        client_message_id: string;
        kind: 'text';
        body: string;
        page_url?: string;
        form?: unknown;
    }): Promise<boolean> {
        const r = await this.call('POST', '/api/widget/v1/messages', body);
        return !!r && r.status === 202;
    }

    async receipts(ids: string[]): Promise<boolean> {
        const r = await this.call('POST', '/api/widget/v1/receipts', { message_ids: ids });
        return !!r && r.ok;
    }

    async typing(): Promise<void> {
        await this.call('POST', '/api/widget/v1/typing', {});
    }

    /** Doc 02 FR-21: the visitor's OWN channels only — the door answers the Pusher auth signature or refuses. */
    async broadcastAuth(socketId: string, channel: string): Promise<string | null> {
        const r = await this.call('POST', '/api/widget/v1/broadcasting/auth', {
            socket_id: socketId,
            channel_name: channel,
        });
        if (!r || !r.ok) return null;
        const body = (await r.json()) as { auth?: string };
        return typeof body.auth === 'string' ? body.auth : null;
    }

    async csat(token: string, score: number): Promise<boolean> {
        const r = await this.call('POST', '/api/widget/v1/csat', { token, score }, false);
        return !!r && r.ok;
    }

    beacon(kind: string, extra: Record<string, unknown> = {}): void {
        void this.call('POST', '/api/widget/v1/beacon', { kind, ...extra }, false).catch(
            () => undefined,
        );
    }

    private async call(
        method: 'GET' | 'POST',
        path: string,
        body?: unknown,
        withVisitor = true,
    ): Promise<Response | null> {
        const headers: Record<string, string> = {
            'X-Tenant-Key': this.widgetKey,
            Accept: 'application/json',
        };
        if (body !== undefined) headers['Content-Type'] = 'application/json';
        if (withVisitor && this.token) headers['X-Visitor-Token'] = this.token;
        try {
            return await fetch(this.platform + path, {
                method,
                headers,
                body: body === undefined ? undefined : JSON.stringify(body),
                credentials: 'include',
                mode: 'cors',
            });
        } catch {
            return null;
        }
    }
}
