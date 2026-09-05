// S13 step 11 — the Designer pages' shared helpers: string interpolation (:key), the JSON doors, the config type.
export type Copy = Record<string, string>;

export function tr(
    template: string | undefined,
    vars: Record<string, string | number> = {},
): string {
    let out = template ?? '';
    for (const [k, v] of Object.entries(vars)) out = out.split(':' + k).join(String(v));
    return out;
}

export interface Shortcut {
    kind: 'article' | 'form' | 'chat' | 'url';
    label: Record<string, string>;
    target: string;
}
export interface ProactiveRule {
    id: string;
    condition: {
        kind: 'url' | 'time' | 'exit' | 'capture';
        match?: string;
        seconds?: number;
        key?: string;
        desktop_only?: boolean;
    };
    action: { kind: 'message'; message: Record<string, string> };
}
export interface WidgetConfig {
    appearance: {
        position: string;
        offset: { side: number; bottom: number };
        theme: 'light' | 'dark' | 'auto';
        font: string;
        launcher_icon: string;
        brand: { accent: string } | null;
    };
    content: {
        welcome: Record<string, string>;
        shortcuts: Shortcut[];
        tabs: { home: boolean; help: boolean };
        default_language: string | null;
        pre_chat_form_id: string | null;
        pre_chat_mode: 'off' | 'optional' | 'required';
        offline_form_id: string | null;
    };
    behavior: {
        hours_source: string;
        file_upload: boolean;
        sound: boolean | null;
        csat: boolean;
        proactive: ProactiveRule[];
        context_allowlist: string[];
    };
}
export interface Refusal {
    refusal_code: string;
    detail?: string;
    surface?: string;
    accent?: string;
    on?: string;
    ratio?: number;
    required?: number;
    suggestion?: string;
    suggestion_ratio?: number;
    entry?: string;
    errors?: Record<string, string[]>;
}

function xsrf(): string {
    return decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '');
}
export async function door(
    method: 'GET' | 'POST' | 'PUT' | 'PATCH',
    url: string,
    body?: unknown,
): Promise<{ ok: boolean; status: number; json: Record<string, unknown> }> {
    const r = await fetch(url, {
        method,
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-XSRF-TOKEN': xsrf(),
        },
        body: body === undefined ? undefined : JSON.stringify(body),
    });
    let json: Record<string, unknown> = {};
    try {
        json = (await r.json()) as Record<string, unknown>;
    } catch {
        /* no body */
    }
    return { ok: r.ok, status: r.status, json };
}
export function clone<T>(v: T): T {
    return JSON.parse(JSON.stringify(v)) as T;
}
