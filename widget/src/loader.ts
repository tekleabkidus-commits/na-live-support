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
    /** the minimal copy the panel needs BEFORE a session exists (the last-resort card), per language */
    strings: Record<string, Record<string, unknown>>;
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
        condition: {
            kind: 'url' | 'time' | 'exit' | 'capture';
            match?: string;
            seconds?: number;
            key?: string;
        };
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
    // NFR-01 core-boot state — declared HERE, before mount: a proactive URL rule can fire during mount and call
    // bootCore(); a `let` declared further down would still be in its temporal dead zone at that moment, the throw
    // would land in mount's catch, and the whole widget would unmount as a "CSP" failure (found on /deposit).
    let coreBooted = false;
    let coreBootScheduled = false;
    let launcherReady = false; // the launcher frame's CORE said ready
    let panelReady = false; // the panel frame's core said ready
    let launcherDocReady = false; // the loader-drawn launcher DOCUMENT painted and is listening
    let bootCorePending = false; // bootCore() ran before the launcher document could hear `boot-core`
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

    /** Doc 02 FR-21/EC-08: the session is minted FROM THE HOST PAGE — the request's Origin header is the
     *  origin of record (the server refuses a declared origin that differs), and only this page can
     *  present the operator's origin. The visitor token travels ONLY to the panel frame, targeted
     *  at the frame origin; nothing of it is exposed on window.PlatformChat. */
    let titleSaved: string | null = null;
    let session: Promise<unknown> | null = null;
    // No JSON.stringify here: a hostile host (SS-03) tampers it; the body is two strings and a list.
    const jsonString = (v: string): string =>
        '"' +
        v.replace(
            /[\\"\u0000-\u001f]/g,
            (c) => '\\u' + ('000' + c.charCodeAt(0).toString(16)).slice(-4),
        ) +
        '"';
    // FR-08 continuity: the panel keeps the visitor token in ITS OWN origin's storage (never the host's)
    // and hands it over on `ready`; presented with its cookie the door answers the SAME visitor (FR-22).
    const mintSession = (token?: string | null): Promise<unknown> => {
        try {
            let list = '';
            for (const a of ancestors) list += (list ? ',' : '') + jsonString(a);
            session = fetch(`${cfg!.platform_origin}/api/widget/v1/session`, {
                method: 'POST',
                mode: 'cors',
                credentials: 'include',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Tenant-Key': cfg!.widget_key,
                    ...(token ? { 'X-Visitor-Token': token } : {}),
                    Accept: 'application/json',
                },
                body: '{"origin":' + jsonString(origin) + ',"ancestors":[' + list + ']}',
            })
                .then((r) => (r.status === 200 || r.status === 201 ? r.json() : null))
                .catch(() => null);
        } catch {
            session = Promise.resolve(null);
        }
        return session;
    };
    const viewport = (): { width: number; height: number } => ({
        width: window.innerWidth,
        height: window.innerHeight,
    });
    const handHost = (token?: string | null): void => {
        void mintSession(token).then((s) => {
            try {
                panel?.contentWindow?.postMessage(
                    {
                        nlw: 1,
                        type: 'host',
                        data: {
                            origin,
                            ancestors,
                            url: location.href,
                            referrer: document.referrer,
                            viewport: viewport(),
                            strings: cfg!.strings,
                            session: s,
                        },
                    },
                    cfg!.frame_origin,
                );
            } catch {
                /* silent */
            }
        });
    };
    const post = (type: string, data?: unknown): void => {
        try {
            panel?.contentWindow?.postMessage({ nlw: 1, type, data }, cfg!.frame_origin);
            launcher?.contentWindow?.postMessage({ nlw: 1, type, data }, cfg!.frame_origin);
        } catch {
            /* silent */
        }
    };
    const call = (method: keyof PlatformChatApi, args: unknown[]): void => {
        bootCore(); // NFR-01: the first use of the API is a "first open" — the core boots now, the call queues until `ready`
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

    // FR-14 — the proactive TRIGGERS live here (the loader sees the page's URL, time and the pointer
    // leaving; the panel holds the copy, the cooldown, the dismissals and EC-05's defer). A rule fires
    // at most once per page load; the panel's guards decide whether anything shows.
    const fired = new Set<string>();
    const firedBeforeReady: string[] = [];
    const fire = (id: string): void => {
        if (fired.has(id)) return;
        fired.add(id);
        bootCore(); // the panel holds the copy and the guards — it must exist to decide
        // a rule that fires at mount lands before the panel frame can listen: held until `ready`
        if (!ready) firedBeforeReady.push(id);
        else post('proactive', { id, url: location.href });
    };
    const armProactive = (): void => {
        for (const rule of cfg!.proactive ?? []) {
            const c = rule.condition;
            if (c.kind === 'url' && c.match && location.href.includes(c.match)) fire(rule.id);
            if (c.kind === 'time' && typeof c.seconds === 'number')
                window.setTimeout(() => fire(rule.id), Math.max(1, c.seconds) * 1000);
        }
    };
    // exit-intent (desktop only) is read on WINDOW — the loader never touches the host's DOM (B10/FR-24).
    const exitRules =
        (cfg.proactive ?? []).some((r) => r.condition.kind === 'exit') &&
        matchMedia('(pointer: fine)').matches;
    if (exitRules) {
        window.addEventListener('mouseout', (e) => {
            if (e.relatedTarget === null && e.clientY <= 0)
                for (const r of cfg!.proactive) if (r.condition.kind === 'exit') fire(r.id);
        });
    }

    // T-07: SPA hooks — namespaced wrappers, restorable, no monkey-patch leaks.
    function onRoute(): void {
        post('route', { url: location.href });
        armProactive(); // SPA route re-eval (FR-14)
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
    window.addEventListener('resize', () => post('viewport', viewport()));
    window.addEventListener('focus', () => post('attended'));

    // The panel's messages: ready, size, events for the host hooks (names only, FR-02), EC-05 focus probe.
    function onMessage(ev: MessageEvent): void {
        if (ev.origin !== cfg!.frame_origin || !ev.data || ev.data.nlw !== 1) return;
        const { type, data } = ev.data as { type: string; data?: unknown };
        switch (type) {
            case 'ready':
                // `ready` means THE PANEL is listening. NFR-01's deferred boot lets the launcher frame's core (injected
                // into an already-loaded document) say ready before the panel's core has attached its listener — a
                // queue flushed on the launcher's word would post `open` and the proactive fires into a panel that
                // cannot hear them yet (found by the step-13 trace: the panel saw the message, its app never did).
                if (ev.source === panel?.contentWindow) ready = true;
                // NFR-01: the two cores boot independently — the launcher frame's may take its frame over after the panel
                // already posted its launcher state (open flag, unread, a proactive card), or before the panel listens.
                // Once BOTH have said ready (either order) the panel is asked to re-send that state.
                if (ev.source === launcher?.contentWindow) launcherReady = true;
                if (ev.source === panel?.contentWindow) panelReady = true;
                if (launcherReady && panelReady) {
                    try {
                        panel?.contentWindow?.postMessage(
                            { nlw: 1, type: 'launcher-sync' },
                            cfg!.frame_origin,
                        );
                    } catch {
                        /* silent */
                    }
                }
                if (ev.source === panel?.contentWindow)
                    for (const id of firedBeforeReady.splice(0))
                        post('proactive', { id, url: location.href });
                // The host facts + the minted session go to the PANEL frame only (targeted origin):
                // origin, the ancestor chain, the page URL — never the page's content.
                // ONLY the panel frame's ready mints the session: the launcher frame boots the same file and
                // says ready too — a second mint would rotate the FR-22 cookie and orphan the panel's token.
                if (ev.source === panel?.contentWindow)
                    handHost((data as { token?: string | null } | undefined)?.token ?? null);
                if (ev.source === panel?.contentWindow)
                    for (const q of queue.splice(0))
                        post('api', { method: q.method, args: q.args });
                break;
            case 'title': {
                // I02: the unread counter in the tab title — the loader's ONE declared host write (never a read),
                // restored to the page's own title when the count returns to zero.
                const n = typeof data === 'number' ? data : 0;
                try {
                    if (n > 0 && !titleSaved) titleSaved = document.title;
                    document.title =
                        n > 0
                            ? `(${n}) ${titleSaved ?? document.title}`
                            : (titleSaved ?? document.title);
                    if (n === 0) titleSaved = null;
                } catch {
                    /* silent */
                }
                break;
            }
            case 'launcher':
                // The panel hands the launcher frame its strings, unread count and open state (one config
                // fetch per page, not two); relayed frame-to-frame through this bus, targeted.
                try {
                    launcher?.contentWindow?.postMessage(
                        { nlw: 1, type: 'launcher', data },
                        cfg!.frame_origin,
                    );
                } catch {
                    /* silent */
                }
                break;
            case 'session':
                // The panel asks for a fresh mint (a refused token, the retry card): re-POST and hand it over again.

                handHost((data as { token?: string | null } | undefined)?.token ?? null);
                break;
            case 'size':
                sizePanel(data as { open: boolean; sheet: boolean });
                break;
            case 'proactive-dismiss':
            case 'focus-state-for-panel':
                try {
                    panel?.contentWindow?.postMessage(
                        {
                            nlw: 1,
                            type:
                                type === 'proactive-dismiss' ? 'proactive-dismiss' : 'focus-state',
                            data,
                        },
                        cfg!.frame_origin,
                    );
                } catch {
                    /* silent */
                }
                break;
            case 'launcher-size':
                sizeLauncher(!!(data as { card?: boolean } | undefined)?.card);
                break;
            case 'launcher-ready':
                // NFR-01 (ruling 2026-09-05): the loader-drawn launcher has PAINTED and listens — the core boots on
                // idle after that paint (or on the first open, whichever comes first), never before it.
                if (ev.source === launcher?.contentWindow) {
                    launcherDocReady = true;
                    if (bootCorePending) postBootCore();
                    else scheduleCoreBoot();
                }
                break;
            case 'api':
                // The launcher frame's own call (a click on the bubble — the static launcher before the core,
                // the core's Launcher after): the SAME path as window.PlatformChat — boots the core, queues until
                // `ready`, then reaches the panel. (Before this case existed the message had no handler.)
                if (ev.source === launcher?.contentWindow) {
                    const d = data as
                        { method?: keyof PlatformChatApi; args?: unknown[] } | undefined;
                    if (d?.method && d.method in api && d.method !== 'on' && d.method !== '__nlw')
                        call(d.method, Array.isArray(d.args) ? d.args : []);
                }
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
        launcher = frame('nlw-launcher', cfg, { width: '64px', height: '64px' }, true);
        // NFR-01: the panel frame is created WITHOUT a document — its src (the core) is set by bootCore() on the
        // first open or on idle after the launcher painted; 380×640 or the full sheet once open.
        panel = frame('nlw-panel', cfg, { width: '0px', height: '0px' }, false);
        document.body.appendChild(launcher);
        document.body.appendChild(panel);

        beacon(cfg, 'load');
        armProactive();
    } catch {
        // EC-02: a host CSP that blocks the frame — silent, beaconed with the copy-paste fix's reason.
        beacon(cfg, 'mount_fail', 'csp');
        unmount();
    }

    function frameUrl(c: ShimConfig, kind: 'launcher' | 'panel'): string {
        return `${c.frame_origin}/w/panel/${encodeURIComponent(c.widget_key)}?v=${encodeURIComponent(c.bundle.version)}&frame=${kind}`;
    }

    /** NFR-01: the core bundle loads on the FIRST OPEN or on IDLE after the launcher painted — never as a precondition of the bubble. */
    function bootCore(): void {
        if (coreBooted || !cfg || !launcher || !panel) return;
        coreBooted = true;
        try {
            panel.src = frameUrl(cfg, 'panel');
            if (launcherDocReady) postBootCore();
            else bootCorePending = true; // delivered on `launcher-ready` — a message to a document still loading is lost
        } catch {
            /* silent (NFR-06) */
        }
    }
    function postBootCore(): void {
        bootCorePending = false;
        try {
            launcher?.contentWindow?.postMessage({ nlw: 1, type: 'boot-core' }, cfg!.frame_origin);
        } catch {
            /* silent */
        }
    }
    function scheduleCoreBoot(): void {
        if (coreBooted || coreBootScheduled) return;
        coreBootScheduled = true;
        const w = window as Window & {
            requestIdleCallback?: (cb: () => void, o?: { timeout: number }) => number;
        };
        if (typeof w.requestIdleCallback === 'function')
            w.requestIdleCallback(() => bootCore(), { timeout: 3000 });
        else window.setTimeout(() => bootCore(), 1500);
    }

    function frame(
        id: string,
        c: ShimConfig,
        size: { width: string; height: string },
        withDocument: boolean,
    ): HTMLIFrameElement {
        const f = document.createElement('iframe');
        f.id = id;
        f.title = id === 'nlw-launcher' ? 'chat' : 'chat panel';
        if (withDocument) f.src = frameUrl(c, id === 'nlw-launcher' ? 'launcher' : 'panel');
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

    function sizeLauncher(card: boolean): void {
        if (!launcher) return;
        important(
            launcher,
            card ? { width: '320px', height: '150px' } : { width: '64px', height: '64px' },
        );
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
            listeners: [
                'focus',
                'message',
                'popstate',
                'resize',
                'history.pushState',
                'history.replaceState',
                ...(exitRules ? ['mouseout'] : []),
            ],
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
