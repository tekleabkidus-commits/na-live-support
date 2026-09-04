/*
 * Doc 13 T-01 — the PANEL entry (Preact + signals; ADR 0020). Both frames run
 * this file: `?frame=launcher` renders the bubble, `?frame=panel` the dialog.
 *
 * Boot (step 8a): `ready` → the loader answers `host` (origin, ancestor chain —
 * the facts only the host page can see) → POST /session with them (Doc 02
 * FR-21/EC-08's server layer) → GET /config (the strings, the band, the
 * channels) → the first cursor page → polling while open (the socket client of
 * step 8b makes polling the fallback). Every visible string is a key resolved
 * from the config envelope (N-03). The Help tab and the form renderer are LAZY
 * chunks opened on first use (NFR-01).
 */
import { render } from 'preact';
import { effect } from '@preact/signals';
import { useState } from 'preact/hooks';
import { WidgetApi } from './api';
import { Launcher } from './components/Launcher';
import { Panel } from './components/Panel';
import { strings, t } from './i18n';
import {
    boot,
    config,
    envelope,
    frameKind,
    host,
    hostViewport,
    language,
    lastSeenId,
    messages,
    open,
    sheet,
    tab,
    unread,
    visitorId,
} from './state';
import { theme } from './theme';
import { onNewMessage, resetAcks, startPolling, stopPolling, sync } from './transport/poll';
import type { HostFacts, Message, Shortcut } from './types';
import './../styles/panel.css';

const params = new URLSearchParams(location.search);
const widgetKey = (() => {
    const m = /\/w\/panel\/([^/?#]+)/.exec(location.pathname);
    return m?.[1] ? decodeURIComponent(m[1]) : '';
})();
const platform = location.origin;
const api = new WidgetApi(platform, widgetKey);
let parentOrigin = '*';
let announceText = '';
let setAnnounce: ((s: string) => void) | null = null;
let launcherNudge = false;

function post(type: string, data?: unknown): void {
    try {
        window.parent.postMessage({ nlw: 1, type, data }, parentOrigin);
    } catch {
        /* NFR-06 */
    }
}

function announce(text: string): void {
    announceText = text;
    setAnnounce?.(text);
}

/** §13: what a screen reader hears — author + kind, at sentence level, never every recompute. */
onNewMessage((m: Message) => {
    if (m.author === 'customer') return;
    const text = m.body ?? t('a11y.attachment');
    announce(
        m.author === 'ai'
            ? t('a11y.new_ai', { text })
            : m.author === 'staff'
              ? t('a11y.new_staff', { name: t('header.team'), text })
              : t('a11y.new_system', { text }),
    );
    if (!open.value || tab.value !== 'messages') unread.value += 1;
    if (!open.value) launcherNudge = true;
});

async function bootSequence(): Promise<void> {
    boot.value = 'booting';
    // The session is the LOADER's mint (Doc 02 FR-21: only the host page can present the operator's origin).
    const session = host.value?.session ?? null;
    if (!session) {
        boot.value = 'down';
        api.beacon('panel_error', { stage: 'session' });
        return;
    }
    api.setToken(session.token);
    visitorId.value = session.visitor_id;
    try {
        localStorage.setItem('nlw.token', session.token);
    } catch {
        /* a private window: the visit is one page long */
    }
    const stored = (() => {
        try {
            return localStorage.getItem('nlw.language');
        } catch {
            return null;
        }
    })();
    const cfg = await api.config(stored);
    if (!cfg) {
        boot.value = 'down';
        api.beacon('panel_error', { stage: 'config' });
        return;
    }
    config.value = cfg;
    strings.value = cfg.strings;
    language.value = cfg.language;
    document.documentElement.lang = cfg.language;
    theme.value = cfg.config.appearance.theme;
    if (!cfg.config.content.tabs.home) tab.value = 'messages';
    resetAcks();
    await sync(api);
    unread.value = 0;
    lastSeenId.value = messages.value[messages.value.length - 1]?.id ?? null;
    boot.value = 'ready';
    startPolling(api, cfg.transport.polling_interval_seconds);
}

async function setLanguage(code: string): Promise<void> {
    try {
        localStorage.setItem('nlw.language', code);
    } catch {
        /* per-viewer convenience only */
    }
    const cfg = await api.config(code);
    if (!cfg) return;
    config.value = cfg;
    strings.value = cfg.strings;
    language.value = cfg.language;
    document.documentElement.lang = cfg.language;
}

/** FR-08: the visitor token lives in THIS frame's origin storage (the platform's), never the host page's. */
function storedToken(): string | null {
    try {
        return localStorage.getItem('nlw.token');
    } catch {
        return null;
    }
}

function ready(): Promise<void> {
    if (boot.value === 'ready') return Promise.resolve();
    return new Promise((resolve) => {
        const stop = effect(() => {
            if (boot.value === 'ready' || boot.value === 'down') {
                stop();
                resolve();
            }
        });
    });
}

let seq = 0;
async function send(text: string): Promise<void> {
    const clientId = `wc_${Date.now().toString(36)}_${(seq++).toString(36)}`;
    const now = new Date().toISOString();
    messages.value = [
        ...messages.value,
        {
            id: 'local:' + clientId,
            client_id: clientId,
            created_at: now,
            direction: 'in',
            author: 'customer',
            kind: 'text',
            body: text,
            media: [],
            delivery_state: null,
            external_message_id: clientId,
            occurred_at: now,
            survey: null,
            local: 'sending',
        },
    ];
    tab.value = 'messages';
    await ready();
    const ok = await api.send({
        client_message_id: clientId,
        kind: 'text',
        body: text,
        page_url: host.value?.url,
    });
    messages.value = messages.value.map((m) =>
        m.client_id === clientId ? { ...m, local: ok ? 'sent' : 'sending' } : m,
    );
    announce(t('a11y.new_customer', { text }));
    post('event', { name: 'message' });
    if (ok) window.setTimeout(() => void sync(api), 600);
}

function onShortcut(s: Shortcut): void {
    if (s.kind === 'url') {
        post('navigate', { url: s.target });
        return;
    }
    if (s.kind === 'chat') {
        tab.value = 'messages';
        void send(s.label[language.value] ?? s.target);
        return;
    }
    if (s.kind === 'article') {
        tab.value = 'help';
        void import('./tabs/Help');
        return;
    }
    tab.value = 'messages';
    void import('./forms/FormRenderer');
}

function openPanel(): void {
    open.value = true;
    unread.value = 0;
    launcherNudge = false;
    post('size', { open: true, sheet: sheet.value });
    post('event', { name: 'open' });
    if (boot.value === 'ready') void sync(api);
    api.beacon('open');
}

function closePanel(): void {
    open.value = false;
    post('size', { open: false, sheet: false });
    post('event', { name: 'close' });
}

/** The loader's frames: the API calls (queued until `ready`), the host facts, route changes, focus state. */
function onLoaderMessage(ev: MessageEvent): void {
    const data = ev.data as { nlw?: number; type?: string; data?: unknown } | null;
    if (!data || data.nlw !== 1) return;
    if (parentOrigin === '*' && ev.origin) parentOrigin = ev.origin;
    if (data.type === 'launcher' && frameKind.value === 'launcher') {
        const d = data.data as {
            strings: Record<string, unknown>;
            unread: number;
            open: boolean;
            language: string;
        };
        strings.value = d.strings;
        unread.value = d.unread;
        open.value = d.open;
        document.documentElement.lang = d.language;
        return;
    }
    if (data.type === 'viewport') {
        hostViewport.value = data.data as { width: number; height: number };
        return;
    }
    if (data.type === 'host') {
        host.value = data.data as HostFacts;
        if (host.value.viewport) hostViewport.value = host.value.viewport;
        if (!visitorId.value) void bootSequence();
        return;
    }
    if (data.type !== 'api' || !data.data) return;
    const call = data.data as { method: string; args: unknown[] };
    switch (call.method) {
        case 'open':
            openPanel();
            break;
        case 'close':
            closePanel();
            break;
        case 'openHelp':
            openPanel();
            tab.value = 'help';
            void import('./tabs/Help');
            break;
        case 'setLanguage':
            if (typeof call.args[0] === 'string') void setLanguage(call.args[0]);
            break;
        default:
            break;
    }
}

function LauncherFrame(): preact.JSX.Element {
    return (
        <Launcher
            nudge={launcherNudge}
            onToggle={() => {
                post('api', { method: open.value ? 'close' : 'open', args: [] });
                if (open.value) closePanel();
                else openPanel();
            }}
        />
    );
}

function PanelFrame(): preact.JSX.Element {
    const [a, set] = useState(announceText);
    setAnnounce = set;
    return (
        <Panel
            announcement={a}
            onClose={() => {
                closePanel();
                post('api', { method: 'close', args: [] });
            }}
            onSend={(text) => void send(text)}
            onTyping={() => void api.typing()}
            onStar={(token, score) => {
                void api.csat(token, score).then(() => sync(api));
            }}
            onShortcut={onShortcut}
            onLanguage={(code) => void setLanguage(code)}
            onRetry={() => post('session', { token: storedToken() })}
        />
    );
}

const root = document.getElementById('nlw-root');
if (root) {
    window.addEventListener('message', onLoaderMessage);
    effect(() => {
        // Both frames share state through the loader's bus; each renders its own kind.
        render(frameKind.value === 'launcher' ? <LauncherFrame /> : <PanelFrame />, root);
    });
    effect(() => void envelope.value);
    post('ready', { token: storedToken() });
    // Without a loader (the document opened directly) no session can exist: the last-resort card after a beat.
    window.setTimeout(() => {
        if (boot.value === 'booting' && !host.value) boot.value = 'down';
    }, 4000);
}
if (frameKind.value === 'panel') {
    effect(() => {
        const st = strings.value;
        if (Object.keys(st).length === 0) return;
        post('launcher', {
            strings: { launcher: (st as { launcher?: unknown }).launcher ?? {} },
            unread: unread.value,
            open: open.value,
            language: language.value,
        });
    });
}
window.addEventListener('beforeunload', stopPolling);
export { params };
