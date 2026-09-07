/*
 * Doc 13 T-03 — the panel's doors, all on the platform origin (the frame's own
 * origin by default; `credentials: 'include'` carries the FR-22 cookie). The
 * widget key rides `X-Tenant-Key`, the visitor token `X-Visitor-Token` (Doc 02
 * FR-21). Every call is a plain fetch; a failed call answers null — the caller
 * decides what a labeled degrade looks like (§8, NFR-06).
 */
import type { ConfigEnvelope, FormContract, HelpArticle, HelpTree, MessagesPage } from './types';

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
        kind: string;
        body: string | null;
        page_url?: string;
        form?: unknown;
        media?: Array<{ reference: string; mime: string }>;
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

    /** Doc 10 FR-18 — the five Help doors (public + published only; `unavailable: true` is the honest degrade). */
    async helpTree(): Promise<HelpTree | null> {
        const r = await this.call('GET', '/api/widget/v1/kb/categories');
        return r && r.ok ? ((await r.json()) as HelpTree) : null;
    }

    async helpSearch(
        q: string,
        language: string,
    ): Promise<{
        unavailable: boolean;
        hits: Array<{
            article_id: string;
            title: string;
            summary: string | null;
            language: string;
            slug: string;
        }>;
    } | null> {
        const r = await this.call(
            'GET',
            `/api/widget/v1/kb/search?q=${encodeURIComponent(q)}&language=${encodeURIComponent(language)}`,
        );
        return r && r.ok
            ? ((await r.json()) as {
                  unavailable: boolean;
                  hits: Array<{
                      article_id: string;
                      title: string;
                      summary: string | null;
                      language: string;
                      slug: string;
                  }>;
              })
            : null;
    }

    async helpArticle(articleId: string, language: string): Promise<HelpArticle | null> {
        const r = await this.call(
            'GET',
            `/api/widget/v1/kb/articles/${encodeURIComponent(articleId)}?language=${encodeURIComponent(language)}`,
            undefined,
            true,
            { 'Accept-Language': language },
        );
        return r && r.ok ? ((await r.json()) as HelpArticle) : null;
    }

    async helpFeedback(variantId: string, score: number): Promise<boolean> {
        const r = await this.call('POST', '/api/widget/v1/kb/feedback', {
            variant_id: variantId,
            score,
        });
        return !!r && r.ok;
    }

    /** Doc 12 FR-20 — the immutable version's schema, and a step validated against it. */
    async formSchema(versionId: string): Promise<FormContract | null> {
        const r = await this.call('GET', `/api/widget/v1/forms/${encodeURIComponent(versionId)}`);
        return r && r.ok ? ((await r.json()) as FormContract) : null;
    }

    async formStep(
        versionId: string,
        body: {
            mode: 'form' | 'offline';
            step_index?: number;
            answers: Record<string, unknown>;
            complete?: boolean;
        },
    ): Promise<{ ok: boolean; status: number; body: Record<string, unknown> }> {
        const r = await this.call(
            'POST',
            `/api/widget/v1/forms/${encodeURIComponent(versionId)}/steps`,
            body,
        );
        if (!r) return { ok: false, status: 0, body: {} };
        const json = (await r.json().catch(() => ({}))) as Record<string, unknown>;
        return { ok: r.ok, status: r.status, body: json };
    }

    /** FR-07 — the host page's signed assertion; the server answers 204 either way (an invalid one only counts).
     *  13c F-13b-3: a verified assertion answers `re_session` (EC-04's switch) — consumed by the panel. */
    async identify(signed: {
        payload: Record<string, unknown>;
        sig: string;
    }): Promise<{ ok: boolean; reSession: boolean }> {
        const r = await this.call('POST', '/api/widget/v1/identify', signed);
        if (!r || !r.ok) return { ok: false, reSession: false };
        const body = (await r.json().catch(() => null)) as { re_session?: boolean } | null;
        return { ok: true, reSession: body?.re_session === true };
    }

    /** FR-16 — the INTAKE (multipart, with progress and a cancel handle); the cap is the server's too (EC-08). */
    upload(
        file: File,
        onProgress: (done: number, total: number) => void,
        signal: AbortSignal,
    ): Promise<{ ok: boolean; status: number; body: Record<string, unknown> }> {
        return new Promise((resolve) => {
            const xhr = new XMLHttpRequest();
            xhr.open('POST', this.platform + '/api/widget/v1/media');
            xhr.withCredentials = true;
            xhr.setRequestHeader('X-Tenant-Key', this.widgetKey);
            xhr.setRequestHeader('Accept', 'application/json');
            if (this.token) xhr.setRequestHeader('X-Visitor-Token', this.token);
            xhr.upload.onprogress = (e) =>
                onProgress(e.loaded, e.lengthComputable ? e.total : file.size);
            xhr.onload = () => {
                let body: Record<string, unknown> = {};
                try {
                    body = JSON.parse(xhr.responseText) as Record<string, unknown>;
                } catch {
                    /* an empty or non-JSON body */
                }
                resolve({ ok: xhr.status >= 200 && xhr.status < 300, status: xhr.status, body });
            };
            xhr.onerror = () => resolve({ ok: false, status: 0, body: {} });
            xhr.onabort = () => resolve({ ok: false, status: -1, body: {} });
            signal.addEventListener('abort', () => xhr.abort());
            const form = new FormData();
            // a pasted screenshot has no name; PHP drops a nameless part, so it gets one from its type
            form.append(
                'file',
                file,
                file.name ||
                    'pasted.' + (file.type.split('/')[1] || 'bin').replace(/[^a-z0-9]/gi, ''),
            );
            xhr.send(form);
        });
    }

    /** SEC-06 — the visitor's own transcript as text (null = the door is closed, refused or rate-limited). */
    async transcript(language: string): Promise<{ text: string | null; status: number }> {
        const r = await this.call(
            'GET',
            '/api/widget/v1/transcript?language=' + encodeURIComponent(language),
        );
        if (!r) return { text: null, status: 0 };
        return { text: r.ok ? await r.text() : null, status: r.status };
    }

    /** FR-24 — page context, allowlist-filtered SERVER-side (an undeclared key never persists). */
    async context(context: Record<string, unknown>): Promise<boolean> {
        const r = await this.call('POST', '/api/widget/v1/context', { context });
        return !!r && r.ok;
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
        extra: Record<string, string> = {},
    ): Promise<Response | null> {
        const headers: Record<string, string> = {
            'X-Tenant-Key': this.widgetKey,
            Accept: 'application/json',
            ...extra,
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
