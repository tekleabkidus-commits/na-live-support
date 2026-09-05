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
    helpArticle,
    host,
    hostViewport,
    language,
    lastSeenId,
    messages,
    notifyPromptOpen,
    open,
    preChat,
    proactive,
    sheet,
    tab,
    unread,
    visitorId,
} from './state';
import { theme } from './theme';
import {
    isPolling,
    onNewMessage,
    resetAcks,
    startPolling,
    stopPolling,
    sync,
} from './transport/poll';
import type { ConfigEnvelope, HostFacts, Message, Shortcut } from './types';
import { ReverbClient } from './transport/reverb';
import { TabLeader } from './transport/leader';
import {
    notificationsSupported,
    notifyNew,
    permissionState,
    requestPermission,
    wasAsked,
} from './notify';
import { purgeCache, readCache, writeCache } from './cache';
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
let socket: ReverbClient | null = null;
let leader: TabLeader | null = null;
let pollSeconds = 5;
let lastQueueAnnounced: string | null = null;
let firstSendDone = false;
let soundOn = true;

function post(type: string, data?: unknown): void {
    try {
        window.parent.postMessage({ nlw: 1, type, data }, parentOrigin);
    } catch {
        /* NFR-06 */
    }
}

/** role=log: entries are APPENDED (a screen reader announces additions); the last five stay in the DOM. */
let logEntries: string[] = [];
function announce(text: string): void {
    logEntries = [...logEntries, text].slice(-5);
    announceText = logEntries.join('\n');
    setAnnounce?.(announceText);
}

/** §13: what a screen reader hears — author + kind, at sentence level, never every recompute. */
onNewMessage((m: Message) => {
    if (m.author === 'customer') return;
    const text = m.body ?? t('a11y.attachment');
    announce(
        m.author === 'ai'
            ? t('a11y.new_ai', { text })
            : m.author === 'agent'
              ? t('a11y.new_staff', { name: t('header.team'), text })
              : t('a11y.new_system', { text }),
    );
    if (!open.value || tab.value !== 'messages') unread.value += 1;
    if (!open.value) launcherNudge = true;
    // I02/I03: only the LEADER tab speaks, and only while the page is not attended.
    if (leader?.isLeader() !== false)
        notifyNew(text, Math.max(1, unread.value), {
            soundEnabled: () => soundOn,
            onTitleCount: (n) => post('title', n),
        });
});

/** D10–D13: the queue position is ANNOUNCED on change only, never on every recompute. */
effect(() => {
    const q = envelope.value.queue;
    const key = q ? `${q.position ?? 'band'}:${q.band}` : null;
    if (key === lastQueueAnnounced) return;
    lastQueueAnnounced = key;
    if (q && q.position !== null) announce(t('queue.announce', { n: q.position }));
});

interface PreviewBoot {
    envelope: ConfigEnvelope;
    theme: 'light' | 'dark';
    language: string;
}
/** Designer preview mode (S13 step 11): the panel bundle booted from an inlined envelope — no session, no transport, a fixture thread. */
const PREVIEW = (window as unknown as { __NLW_PREVIEW?: PreviewBoot }).__NLW_PREVIEW ?? null;

function bootPreview(p: PreviewBoot): void {
    config.value = p.envelope;
    strings.value = p.envelope.strings;
    language.value = p.language;
    document.documentElement.lang = p.language;
    theme.value = p.theme;
    if (!p.envelope.config.content.tabs.home) tab.value = 'messages';
    const now = new Date().toISOString();
    const fixture = (id: string, author: 'customer' | 'ai' | 'agent', body: string): Message => ({
        id: 'preview:' + id,
        created_at: now,
        direction: author === 'customer' ? 'in' : 'out',
        author,
        kind: 'text',
        body,
        media: [],
        delivery_state: author === 'customer' ? 'delivered' : null,
        external_message_id: null,
        occurred_at: now,
        survey: null,
        local: author === 'customer' ? 'delivered' : undefined,
    });
    // The drawn preview thread (B3-S08 J03): the greeting, a shortcut question, the assistant's line — all strings from the envelope.
    const t = (k: string): string => {
        const v = p.envelope.strings[k];
        return typeof v === 'string' ? v : '';
    };
    messages.value = [
        fixture('1', 'customer', t('preview.customer_line') || '…'),
        fixture('2', 'ai', t('preview.assistant_line') || '…'),
    ];
    visitorId.value = 'preview';
    boot.value = 'ready';
    open.value = true;
}

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
    // FR-06: the pre-chat gate — pending while the mode is on and no message has ever been sent (the server's page is the truth).
    preChat.value =
        cfg.forms.pre_chat.mode !== 'off' && cfg.forms.pre_chat.version_id ? 'pending' : 'none';
    resetAcks();
    pollSeconds = cfg.transport.polling_interval_seconds;
    soundOn = cfg.config.behavior.sound ?? cfg.settings.sound_default;
    // FR-08/NFR-03: the content-lite cache paints the returning visitor's thread before the first page —
    // only when the operator's `widget.cache.content` is on; off = nothing stored, the store purged.
    if (cfg.settings.cache_content) {
        const cached = await readCache(session.visitor_id);
        if (cached.length && messages.value.length === 0)
            messages.value = cached.map((m) => ({
                ...m,
                local: m.author === 'customer' ? 'delivered' : undefined,
            }));
    } else {
        void purgeCache();
    }
    await sync(api);
    if (messages.value.length > 0) preChat.value = 'none';
    unread.value = 0;
    lastSeenId.value = messages.value[messages.value.length - 1]?.id ?? null;
    boot.value = 'ready';
    startTransport(cfg);
    startConfigPoll(cfg);
}

/** T-04: the leader tab owns the socket; the socket is the wake-up, the cursor page the record; polling only while the socket is down. */
function startTransport(cfg: ConfigEnvelope): void {
    // I05: the lock and the mirror are per VISITOR (the thread channel is the visitor's stable name), so two tabs
    // of one visitor share one socket even when the install key differs between them (a rotated key, the harness).
    leader = new TabLeader('nlw.' + (cfg.transport.channels.thread || widgetKey));
    leader.onMessage((msg) => {
        if ((msg as { type?: string }).type === 'sync') void sync(api);
    });
    leader.elect(
        () => {
            const reverb = cfg.transport.reverb;
            const host =
                reverb.host && reverb.host !== 'localhost' && reverb.host !== '0.0.0.0'
                    ? `${reverb.host}:${reverb.port}`
                    : location.host;
            const url = `wss://${host}/app/${encodeURIComponent(reverb.key)}?protocol=7&client=nlw&version=${encodeURIComponent(config.value?.version?.toString() ?? '0')}&flash=false`;
            socket = new ReverbClient({
                url,
                channels: [
                    ...[cfg.transport.channels.thread, cfg.transport.channels.queue].map(
                        (c) => 'private-' + c,
                    ),
                    cfg.transport.channels.config, // FR-20: public, version-only wake-up
                ],
                authorize: (socketId, channel) => api.broadcastAuth(socketId, channel),
                onEvent: (_channel, event, data) => {
                    if (event === 'widget.config.published') {
                        // FR-20 / EC-11: a new config is live — re-read the door; the swap is in place (CSS vars, signals), no remount
                        void refreshConfig();
                        return;
                    }
                    if (event === 'webchat.typing') {
                        envelope.value = { ...envelope.value, typing: true };
                        return;
                    }
                    const joined = (data as { joined?: { display: string | null } } | null)?.joined;
                    if (event === 'webchat.queue' && joined) {
                        // D14: the agent-joined line — a client-side system line (the record is the staff message that follows).
                        const who = joined.display ?? t('header.team');
                        const now = new Date().toISOString();
                        messages.value = [
                            ...messages.value,
                            {
                                id: 'local:joined:' + now,
                                created_at: now,
                                direction: 'out',
                                author: 'system',
                                kind: 'text',
                                body: t('messages.joined', { name: who }),
                                media: [],
                                delivery_state: null,
                                external_message_id: null,
                                occurred_at: now,
                                survey: null,
                            },
                        ];
                        announce(t('messages.joined', { name: who }));
                    }
                    void sync(api).then(() =>
                        leader?.broadcast({ type: 'sync', event, data: null }),
                    );
                },
                onUp: () => {
                    stopPolling();
                    void sync(api); // converge: anything missed while the socket was down
                },
                onDown: () => startPolling(api, pollSeconds),
            });
            socket.connect();
            startPolling(api, pollSeconds); // until the first subscription succeeds
        },
        () => {
            socket?.close();
            socket = null;
        },
    );
}

let configPollTimer: number | null = null;
/** NFR-04: the poll floor (config_poll_seconds, 5 min by default) — the push is the fast path, this is the guarantee. */
function startConfigPoll(cfg: ConfigEnvelope): void {
    if (configPollTimer !== null) window.clearInterval(configPollTimer);
    const seconds = Math.max(30, cfg.transport.config_poll_seconds || 300);
    configPollTimer = window.setInterval(() => void refreshConfig(), seconds * 1000);
}

async function refreshConfig(): Promise<void> {
    const cfg = await api.config(language.value);
    if (cfg) {
        config.value = cfg;
        strings.value = cfg.strings;
    }
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

/** The visitor's OWN message earns its ✓✓ from the cursor page (AC-01) — the socket carries no echo of it, so
 *  after a send the page is re-read with a short backoff until the row is there (bounded; then the next wake-up). */
async function confirmSend(clientId: string): Promise<void> {
    for (const wait of [600, 1200, 2000, 3000, 5000]) {
        await new Promise((r) => window.setTimeout(r, wait));
        await sync(api);
        if (
            messages.value.some(
                (m) => m.external_message_id === clientId && m.local === 'delivered',
            )
        )
            return;
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

let pendingPreChat: Record<string, unknown> | null = null;
let seq = 0;
async function send(text: string, media?: { reference: string; mime: string }): Promise<void> {
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
            kind: media
                ? media.mime.startsWith('image/')
                    ? 'photo'
                    : media.mime.startsWith('video/')
                      ? 'video'
                      : media.mime.startsWith('audio/')
                        ? 'voice'
                        : 'document'
                : 'text',
            body: text || null,
            media: media
                ? [
                      {
                          kind: media.mime.startsWith('image/') ? 'photo' : 'document',
                          mime: media.mime,
                          bytes: null,
                          scan: 'pending',
                          url: null,
                      },
                  ]
                : [],
            delivery_state: null,
            external_message_id: clientId,
            occurred_at: now,
            survey: null,
            local: 'sending',
        },
    ];
    tab.value = 'messages';
    await ready();
    const form =
        pendingPreChat && config.value?.forms.pre_chat.version_id
            ? { version_id: config.value.forms.pre_chat.version_id, answers: pendingPreChat }
            : undefined;
    pendingPreChat = null;
    const kind = media
        ? media.mime.startsWith('image/')
            ? 'photo'
            : media.mime.startsWith('video/')
              ? 'video'
              : media.mime.startsWith('audio/')
                ? 'voice'
                : 'document'
        : 'text';
    const ok = await api.send({
        client_message_id: clientId,
        kind,
        body: text || null,
        page_url: host.value?.url,
        form,
        media: media ? [{ reference: media.reference, mime: media.mime }] : undefined,
    });
    messages.value = messages.value.map((m) =>
        m.client_id === clientId ? { ...m, local: ok ? 'sent' : 'sending' } : m,
    );
    announce(t('a11y.new_customer', { text }));
    post('event', { name: 'message' });
    if (ok) void confirmSend(clientId);
    if (!firstSendDone) {
        firstSendDone = true;
        // I01/BR-03: the widget's OWN question, only now — after the first sent message; never on load.
        if (notificationsSupported() && permissionState() === 'default' && !wasAsked())
            notifyPromptOpen.value = true;
    }
}

effect(() => {
    const list = messages.value;
    const cfg = config.value;
    const v = visitorId.value;
    if (!cfg || !v || list.length === 0 || !cfg.settings.cache_content) return;
    void writeCache(v, list);
});

export async function answerNotifyPrompt(yes: boolean): Promise<void> {
    notifyPromptOpen.value = false;
    if (yes) await requestPermission();
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

/** FR-14 guards (H02/H03): one per visitor per cooldown ACROSS all rules; a dismissal ends the rule on that page for good. */
function proactiveAllowed(id: string, url: string): boolean {
    try {
        const hours = config.value?.settings.proactive_cooldown_hours ?? 24;
        const last = Number(localStorage.getItem('nlw.proactive.last') ?? '0');
        if (Date.now() - last < hours * 3600 * 1000) return false;
        const page = new URL(url).pathname;
        if (localStorage.getItem('nlw.proactive.dismissed:' + id + ':' + page)) return false;
    } catch {
        /* no storage: fire at most once per load (the loader's own dedupe) */
    }
    return true;
}

let pendingProactive: { id: string; url: string } | null = null;
let proactiveReceived = 0;
function launcherFrame(extra: Record<string, unknown> = {}): void {
    const st = strings.value as { launcher?: unknown; proactive?: unknown };
    post('launcher', {
        strings: { launcher: st.launcher ?? {}, proactive: st.proactive ?? {} },
        unread: unread.value,
        open: open.value,
        language: language.value,
        ...extra,
    });
}
function showProactive(id: string, url: string): void {
    const rule = config.value?.config.behavior.proactive.find((r) => r.id === id);
    if (!rule || !proactiveAllowed(id, url) || open.value) return;
    const text = rule.action.message?.[language.value] ?? rule.action.message?.en ?? '';
    proactive.value = { id, text };
    try {
        localStorage.setItem('nlw.proactive.last', String(Date.now()));
    } catch {
        /* per-viewer convenience */
    }
    launcherFrame({ proactive: proactive.value });
    post('launcher-size', { card: true });
}

function dismissProactive(): void {
    const p = proactive.value;
    if (!p) return;
    try {
        localStorage.setItem(
            'nlw.proactive.dismissed:' +
                p.id +
                ':' +
                new URL(host.value?.url ?? location.href).pathname,
            '1',
        );
    } catch {
        /* ignore */
    }
    proactive.value = null;
    launcherFrame({ proactive: null });
    post('launcher-size', { card: false });
}

function openPanel(): void {
    open.value = true;
    if (proactive.value) {
        proactive.value = null;
        post('launcher-size', { card: false });
    }
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
            proactive?: { id: string; text: string } | null;
        };
        strings.value = { ...strings.value, ...d.strings };
        unread.value = d.unread;
        open.value = d.open;
        if (d.proactive !== undefined) proactive.value = d.proactive;
        document.documentElement.lang = d.language;
        return;
    }
    if (data.type === 'proactive' && frameKind.value === 'panel') {
        // EC-05: never over a keyboard — ask the loader whether a page field has focus; the card waits for blur.
        pendingProactive = data.data as { id: string; url: string };
        proactiveReceived++;
        // the probe happens at SHOW time — after the boot (the config carries the rule) — never at fire time
        void ready().then(() => post('focus-probe'));
        return;
    }
    if (data.type === 'focus-state' && frameKind.value === 'panel') {
        const typing = !!(data.data as { typing?: boolean } | null)?.typing;
        if (!pendingProactive) return;
        if (typing) {
            window.setTimeout(() => post('focus-probe'), 1500); // re-ask until the field loses focus
            return;
        }
        const p = pendingProactive;
        pendingProactive = null;
        showProactive(p.id, p.url);
        return;
    }
    if (data.type === 'proactive-dismiss' && frameKind.value === 'panel') {
        dismissProactive();
        return;
    }
    if (data.type === 'attended') {
        // The host tab regained focus: the title counter clears (the unread badge stays until the panel opens).
        post('title', 0);
        return;
    }
    if (data.type === 'viewport') {
        hostViewport.value = data.data as { width: number; height: number };
        return;
    }
    if (data.type === 'host') {
        host.value = data.data as HostFacts;
        if (Object.keys(strings.value).length === 0 && host.value.strings) {
            // The last-resort card can render before any session exists: the shim's minimal copy, by the browser's language.
            const lang = (navigator.language || 'en').slice(0, 2);
            strings.value = host.value.strings[lang] ?? host.value.strings.en ?? {};
        }
        if (host.value.viewport) hostViewport.value = host.value.viewport;
        if (!visitorId.value) void bootSequence();
        return;
    }
    if (data.type === 'preview-config' && PREVIEW) {
        // B3-S08 J03: the editor's draft, applied in place (EC-11) — the SAME object that publishes.
        const draft = data.data as { config: ConfigEnvelope['config']; theme?: 'light' | 'dark' };
        if (config.value) config.value = { ...config.value, config: draft.config };
        if (draft.theme) theme.value = draft.theme;
        if (draft.config.content.tabs.home === false && tab.value === 'home') tab.value = 'messages';
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
            helpArticle.value = typeof call.args[0] === 'string' ? call.args[0] : null;
            tab.value = 'help';
            break;
        case 'capture': {
            // FR-24: the operator page's declared keys — the SERVER keeps only the allowlisted ones; a `capture` rule may fire.
            const ctx = (call.args[0] ?? {}) as Record<string, unknown>;
            void ready().then(() => api.context(ctx));
            for (const rule of config.value?.config.behavior.proactive ?? []) {
                if (
                    rule.condition.kind === 'capture' &&
                    rule.condition.key &&
                    rule.condition.key in ctx
                )
                    showProactive(rule.id, host.value?.url ?? location.href);
            }
            break;
        }
        case 'identify':
            // FR-07: the host page's signed assertion → the identify door (the server verifies; an invalid one is a 204 + a Health counter).
            void ready()
                .then(() =>
                    api.identify(call.args[0] as { payload: Record<string, unknown>; sig: string }),
                )
                .then(async (ok) => {
                    if (ok) {
                        await sync(api);
                        await refreshConfig();
                    }
                });
            break;
        case 'setLanguage':
            if (typeof call.args[0] === 'string') void setLanguage(call.args[0]);
            break;
        default:
            break;
    }
}

function LauncherFrame(): preact.JSX.Element {
    const p = proactive.value;
    return (
        <>
            {p ? (
                <div class="nlw-proactive" data-role="proactive" role="status">
                    <span>{p.text}</span>
                    <span class="nlw-meta">
                        <button
                            type="button"
                            class="nlw-btn"
                            style="width:auto"
                            onClick={() => post('api', { method: 'open', args: [] })}
                        >
                            {t('proactive.open')}
                        </button>
                        <button
                            type="button"
                            class="nlw-icon"
                            aria-label={t('proactive.dismiss')}
                            onClick={() => post('proactive-dismiss')}
                        >
                            ✕
                        </button>
                    </span>
                </div>
            ) : null}
            <Launcher
                nudge={launcherNudge}
                onToggle={() => {
                    post('api', { method: open.value ? 'close' : 'open', args: [] });
                    if (open.value) closePanel();
                    else openPanel();
                }}
            />
        </>
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
            onSend={(text, media) => void send(text, media)}
            onTyping={() => void api.typing()}
            onStar={(token, score) => {
                void api.csat(token, score).then(() => sync(api));
            }}
            onShortcut={onShortcut}
            onLanguage={(code) => void setLanguage(code)}
            onRetry={() => post('session', { token: storedToken() })}
            onNotify={(yes) => void answerNotifyPrompt(yes)}
            api={api}
            onPreChatDone={(answers) => {
                preChat.value = 'done';
                pendingPreChat = answers;
            }}
            onPreChatSkip={() => (preChat.value = 'done')}
        />
    );
}

// Test hooks (AC-08's drill, the transport assertions): read-only state + a socket drop — never a data door.
(window as unknown as { __nlwPanel?: unknown }).__nlwPanel = {
    socketUp: () => socket?.isUp() ?? false,
    isLeader: () => leader?.isLeader() ?? false,
    dropSocket: () => socket?.drop(),
    polling: () => isPolling(),
    proactive: () => ({
        received: proactiveReceived,
        pending: pendingProactive,
        shown: proactive.value,
        rules: config.value?.config.behavior.proactive.length ?? -1,
    }),
    boot: () => boot.value,
};

const root = document.getElementById('nlw-root');
if (root) {
    window.addEventListener('message', onLoaderMessage);
    effect(() => {
        // Both frames share state through the loader's bus; each renders its own kind.
        if (PREVIEW) {
            frameKind.value = 'panel';
            bootPreview(PREVIEW);
        }
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
