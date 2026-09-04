/*
 * Doc 13 FR-01 / FR-02 / FR-03 / FR-23 / T-01 / T-07 / T-08 / SEC-01 / NFR-06 —
 * THE LOADER. Vanilla, no dependencies, and BORING FOREVER (§21): mounting, the
 * origin checks, the namespaced API and history hooks, beacons — nothing else
 * ever migrates in here, because every loader byte is host-page risk surface.
 *
 * Its ENTIRE footprint on the host page (the audit, AC-02, asserts this
 * inventory EXACTLY):
 *   globals   window.PlatformChat (the FR-02 API), window.__nlwLoaded (EC-01)
 *   DOM       two fixed-position iframes (launcher, panel), nothing else — no
 *             stylesheet, no script, no wrapper element
 *   listeners message (the panel's postMessage), popstate (T-07), the wrapped
 *             pushState/replaceState (T-07, restorable)
 * It reads NO cookies, NO localStorage, NO form fields — those code paths do
 * not exist here (FR-24's structural clause). The only host reads are
 * location (origin + URL for triggers), document.referrer, and
 * document.activeElement's PRESENCE for EC-05 (never its value).
 *
 * Every path is wrapped: a dead widget is invisible, not a broken site
 * (NFR-06). Failures beacon (T-08) and stay silent otherwise.
 */
declare global {
    interface Window {
        PlatformChat?: PlatformChatApi;
        __nlwLoaded?: string;
    }
}

export interface PlatformChatApi {
    open(): void;
    close(): void;
    openHelp(articleSlug?: string): void;
    identify(signed: { payload: Record<string, unknown>; sig: string }): void;
    setLanguage(code: string): void;
    on(event: 'open' | 'close' | 'message' | 'handoff', cb: () => void): void;
    capture(context: Record<string, unknown>): void;
    /** internal — the footprint audit and the hostile-host suite */
    __nlw?: { unmount(): void; inventory(): Inventory };
}

interface Inventory {
    globals: string[];
    nodes: string[];
    listeners: string[];
}

interface ShimConfig {
    widget_key: string;
    widget_id: string;
    platform_origin: string;
    frame_origin: string;
    allowed_origins: string[];
    bundle: { panel: string; version: string };
    position: 'bottom_right' | 'bottom_left';
    offset: { side: number; bottom: number };
    proactive: Array<{
        id: string;
        condition: { kind: 'url' | 'time'; match?: string; seconds?: number };
    }>;
}

type Queued = { method: keyof PlatformChatApi; args: unknown[] };

/** The per-key shim (`GET /w/{widget_key}.js`, a 60 s-cache Laravel route) wraps this file as
 *  `;(function(){var __NLW_CFG={…}; <loader> })();` — the config never touches the DOM. */
declare const __NLW_CFG: ShimConfig | undefined;

(function boot(): void {
    let cfg: ShimConfig | null = null;
    try {
        cfg = typeof __NLW_CFG === 'object' && __NLW_CFG !== null ? __NLW_CFG : null;
    } catch {
        cfg = null;
    }
    if (!cfg) return; // no config, no widget — silent (the shim always embeds it)

    // EC-01: a second snippet no-ops with a console warning + a beacon.
    if (window.__nlwLoaded) {
        try {
            console.warn('[PlatformChat] already installed — the second snippet was ignored');
            beacon(cfg, 'double_install');
        } catch {
            /* silent */
        }
        return;
    }
    window.__nlwLoaded = cfg.widget_key;

    // FR-03 / SEC-02 layer 1 (client): the host origin AND the ancestor chain against the allowlist.
    const origin = location.origin;
    const ancestors = ancestorOrigins();
    const allowed = [origin, ...ancestors].every((o) => originAllowed(o, cfg!.allowed_origins));
    if (!allowed) {
        beacon(cfg, 'blocked_origin'); // Health lists it (FR-19); the visitor sees nothing
        return;
    }

    // NFR-05: below the support matrix → graceful no-mount (silent, beaconed).
    if (
        typeof window.fetch !== 'function' ||
        typeof window.Promise === 'undefined' ||
        !('BroadcastChannel' in window)
    ) {
        beacon(cfg, 'mount_fail', 'below_matrix');
        return;
    }

    const queue: Queued[] = [];
    let ready = false;
    let launcher: HTMLIFrameElement | null = null;
    let panel: HTMLIFrameElement | null = null;
    const handlers: Record<string, Array<() => void>> = {
        open: [],
        close: [],
        message: [],
        handoff: [],
    };
    const origPush = history.pushState;
    const origReplace = history.replaceState;

    const post = (type: string, data?: unknown): void => {
        try {
            panel?.contentWindow?.postMessage({ nlw: 1, type, data }, cfg!.frame_origin);
            launcher?.contentWindow?.postMessage({ nlw: 1, type, data }, cfg!.frame_origin);
        } catch {
            /* silent */
        }
    };
    const call = (method: keyof PlatformChatApi, args: unknown[]): void => {
        if (!ready) {
            queue.push({ method, args });
            return;
        }
        post('api', { method, args });
    };

    const api: PlatformChatApi = {
        open: () => call('open', []),
        close: () => call('close', []),
        openHelp: (slug) => call('openHelp', [slug]),
        identify: (signed) => call('identify', [signed]),
        setLanguage: (code) => call('setLanguage', [code]),
        on: (event, cb) => {
            (handlers[event] ??= []).push(cb);
        },
        capture: (context) => call('capture', [context]),
        __nlw: { unmount, inventory },
    };
    window.PlatformChat = api;

    // T-07: SPA hooks — namespaced wrappers, restorable, no monkey-patch leaks.
    function onRoute(): void {
        post('route', { url: location.href });
    }
    history.pushState = function (this: History, ...a: Parameters<History['pushState']>) {
        const r = origPush.apply(this, a);
        onRoute();
        return r;
    };
    history.replaceState = function (this: History, ...a: Parameters<History['replaceState']>) {
        const r = origReplace.apply(this, a);
        onRoute();
        return r;
    };
    window.addEventListener('popstate', onRoute);

    // The panel's messages: ready, size, events for the host hooks (names only, FR-02), EC-05 focus probe.
    function onMessage(ev: MessageEvent): void {
        if (ev.origin !== cfg!.frame_origin || !ev.data || ev.data.nlw !== 1) return;
        const { type, data } = ev.data as { type: string; data?: unknown };
        switch (type) {
            case 'ready':
                ready = true;
                // The host facts the panel's /session needs (Doc 02 FR-21/EC-08's server layer reads the
                // declared ancestor chain; the loader is the only party that can see it): origin, the
                // ancestor origins, the page URL — never the page's content.
                post('host', {
                    origin,
                    ancestors,
                    url: location.href,
                    referrer: document.referrer,
                });
                for (const q of queue.splice(0)) post('api', { method: q.method, args: q.args });
                break;
            case 'size':
                sizePanel(data as { open: boolean; sheet: boolean });
                break;
            case 'event': {
                const name = (data as { name: string }).name;
                for (const cb of handlers[name] ?? []) {
                    try {
                        cb();
                    } catch {
                        /* the host's handler, not our problem to crash on */
                    }
                }
                break;
            }
            case 'focus-probe': {
                // EC-05: the PRESENCE of focus in a page input, never its value.
                const el = document.activeElement;
                const typing =
                    !!el &&
                    (el.tagName === 'INPUT' ||
                        el.tagName === 'TEXTAREA' ||
                        (el as HTMLElement).isContentEditable);
                post('focus-state', { typing });
                break;
            }
            default:
                break;
        }
    }
    window.addEventListener('message', onMessage);

    // Two fixed-position iframes and nothing else (SEC-01, NFR-01 zero layout shift).
    try {
        launcher = frame('nlw-launcher', cfg, { width: '64px', height: '64px' });
        panel = frame('nlw-panel', cfg, { width: '0px', height: '0px' }); // opens on demand; 380×640 or the full sheet
        document.body.appendChild(launcher);
        document.body.appendChild(panel);
        beacon(cfg, 'load');
    } catch {
        // EC-02: a host CSP that blocks the frame — silent, beaconed with the copy-paste fix's reason.
        beacon(cfg, 'mount_fail', 'csp');
        unmount();
    }

    function frame(
        id: string,
        c: ShimConfig,
        size: { width: string; height: string },
    ): HTMLIFrameElement {
        const f = document.createElement('iframe');
        f.id = id;
        f.title = id === 'nlw-launcher' ? 'chat' : 'chat panel';
        f.src = `${c.frame_origin}/w/panel/${encodeURIComponent(c.widget_key)}?v=${encodeURIComponent(c.bundle.version)}&frame=${id === 'nlw-launcher' ? 'launcher' : 'panel'}`;
        f.setAttribute('allow', 'microphone; clipboard-write');
        f.setAttribute('aria-hidden', id === 'nlw-panel' ? 'true' : 'false');
        const side = c.position === 'bottom_left' ? 'left' : 'right';
        // Every inline property carries !important: a hostile host's `* { all: unset !important }`
        // (SS-03) beats a plain inline style, but an inline !important beats the author's — the
        // frames keep their own size and place on any page.
        important(f, {
            position: 'fixed',
            [side]: `${c.offset.side}px`,
            bottom: `${c.offset.bottom}px`,
            width: size.width,
            height: size.height,
            border: '0',
            background: 'transparent',
            'z-index': '2147483000',
            'color-scheme': 'normal',
            'max-width': '100vw',
            'max-height': '100vh',
            display: 'block',
        });
        return f;
    }

    function important(el: HTMLElement, props: Record<string, string>): void {
        for (const k of Object.keys(props)) el.style.setProperty(k, props[k] ?? '', 'important');
    }

    function sizePanel(s: { open: boolean; sheet: boolean }): void {
        if (!panel) return;
        if (!s.open) {
            important(panel, { width: '0px', height: '0px' });
            panel.setAttribute('aria-hidden', 'true');
            return;
        }
        panel.setAttribute('aria-hidden', 'false');
        if (s.sheet) {
            important(panel, {
                width: '100vw',
                height: '100vh',
                left: '0',
                right: '0',
                bottom: '0',
            });
        } else {
            important(panel, { width: '380px', height: '640px' });
        }
    }

    function unmount(): void {
        try {
            window.removeEventListener('message', onMessage);
            window.removeEventListener('popstate', onRoute);
            history.pushState = origPush;
            history.replaceState = origReplace;
            launcher?.remove();
            panel?.remove();
            delete window.PlatformChat;
            delete window.__nlwLoaded;
        } catch {
            /* silent */
        }
    }

    function inventory(): Inventory {
        return {
            globals: ['PlatformChat', '__nlwLoaded'],
            nodes: [launcher?.id ?? '', panel?.id ?? ''].filter(Boolean),
            listeners: ['message', 'popstate', 'history.pushState', 'history.replaceState'],
        };
    }
})();

/** EC-12 physics: exact origins, or `*.brand.com` matching on the DOT boundary; https only. */
export function originAllowed(origin: string, allowlist: string[]): boolean {
    let host: string;
    let scheme: string;
    let port: string;
    try {
        const u = new URL(origin);
        host = u.hostname.toLowerCase();
        scheme = u.protocol;
        port = u.port;
    } catch {
        return false;
    }
    if (scheme !== 'https:') return false;
    for (const entry of allowlist) {
        const e = entry.toLowerCase();
        if (e.startsWith('*.')) {
            const suffix = e.slice(1); // ".brand.com"
            if (host.endsWith(suffix) && host !== suffix.slice(1)) return true;
            continue;
        }
        try {
            const eu = new URL(e.includes('://') ? e : `https://${e}`);
            if (eu.hostname.toLowerCase() === host && (eu.port || '') === (port || '')) return true;
        } catch {
            /* a malformed entry admits nothing */
        }
    }
    return false;
}

function ancestorOrigins(): string[] {
    try {
        const a = (location as Location & { ancestorOrigins?: DOMStringList }).ancestorOrigins;
        if (a && a.length) return Array.from(a);
    } catch {
        /* Firefox: no ancestorOrigins — the referrer chain below */
    }
    try {
        if (window.top !== window && document.referrer) return [new URL(document.referrer).origin];
    } catch {
        /* cross-origin top: unknowable here; the server's ancestor validation is the layer that decides */
    }
    return [];
}

function beacon(cfg: ShimConfig, kind: string, reason?: string): void {
    try {
        const body = JSON.stringify({ kind, reason, bundle_hash: cfg.bundle.version });
        const url = `${cfg.platform_origin}/api/widget/v1/beacon`;
        // Tenant key + origin ride the request; sendBeacon carries no custom headers, so fetch keepalive is the shape.
        void fetch(url, {
            method: 'POST',
            mode: 'cors',
            keepalive: true,
            headers: { 'Content-Type': 'application/json', 'X-Tenant-Key': cfg.widget_key },
            body,
        }).catch(() => undefined);
    } catch {
        /* silent */
    }
}
