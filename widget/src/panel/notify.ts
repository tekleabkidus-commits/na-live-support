/*
 * Doc 13 FR-17 / BR-03 / I01–I03 / EC-09 — NOTIFICATIONS, EARNED: the widget
 * asks its OWN question only after the visitor's first sent message; the
 * browser dialog opens only on yes; never on load. While the page is visible
 * and focused none of the three fire; unfocused: the sound (a WebAudio tone —
 * no asset, no fetch, CSP-safe), the title counter (the loader's one declared
 * host write) and a Notification. No Notification API (a WebView) = a no-op.
 */
export interface NotifyOptions {
    soundEnabled: () => boolean;
    onTitleCount: (n: number) => void;
}

let asked = false;

export function notificationsSupported(): boolean {
    return (
        typeof window !== 'undefined' &&
        'Notification' in window &&
        typeof (window as { Notification?: unknown }).Notification === 'function'
    );
}

export function permissionState(): 'granted' | 'denied' | 'default' | 'unsupported' {
    if (!notificationsSupported()) return 'unsupported';
    return Notification.permission;
}

/** The browser's own dialog — ONLY from the widget's yes button, never before a message was sent. */
export async function requestPermission(): Promise<
    'granted' | 'denied' | 'default' | 'unsupported'
> {
    if (!notificationsSupported()) return 'unsupported';
    asked = true;
    try {
        return await Notification.requestPermission();
    } catch {
        return 'default';
    }
}

export function wasAsked(): boolean {
    return asked;
}

export function pageIsAttended(): boolean {
    return document.visibilityState === 'visible' && document.hasFocus();
}

let ctx: AudioContext | null = null;
export function playTone(): void {
    try {
        ctx ??= new (
            window.AudioContext ||
            (window as unknown as { webkitAudioContext: typeof AudioContext }).webkitAudioContext
        )();
        const o = ctx.createOscillator();
        const g = ctx.createGain();
        o.frequency.value = 880;
        g.gain.value = 0.0001;
        o.connect(g).connect(ctx.destination);
        const t = ctx.currentTime;
        g.gain.exponentialRampToValueAtTime(0.08, t + 0.02);
        g.gain.exponentialRampToValueAtTime(0.0001, t + 0.25);
        o.start(t);
        o.stop(t + 0.26);
    } catch {
        /* no audio context: silence is fine */
    }
}

/** A new inbound message while the page is NOT attended: all three; attended: none. */
export function notifyNew(text: string, unread: number, o: NotifyOptions): void {
    if (pageIsAttended()) return;
    if (o.soundEnabled()) playTone();
    o.onTitleCount(unread);
    if (permissionState() === 'granted') {
        try {
            const n = new Notification(text.slice(0, 120));
            n.onclick = () => window.focus();
        } catch {
            /* a WebView without the API, or a refused constructor: no-op (EC-09) */
        }
    }
}
