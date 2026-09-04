/*
 * Doc 13 T-04 / FR-10 — THE POLLING TRANSPORT (the cursor endpoint, every
 * `polling_interval_seconds`, ONLY while the panel is open; the socket client of
 * step 8b makes polling the fallback — NFR-03's "no polling when socket healthy").
 * Idempotent merge by id: a page never duplicates a bubble; the visitor's own
 * pending sends are matched by client id and promoted to `delivered` when the
 * server's page carries them — the truthful double-check (AC-01). New staff/AI
 * rows are acknowledged ONCE through POST receipts (their delivered glyph is
 * staff-side; the widget shows nothing for inbound).
 */
import type { WidgetApi } from '../api';
import { conversationId, envelope, messages, open } from '../state';
import type { Message } from '../types';

let timer: number | null = null;
let acked = new Set<string>();
let announceHook: ((m: Message) => void) | null = null;

export function onNewMessage(fn: (m: Message) => void): void {
    announceHook = fn;
}

export function merge(page: Message[]): Message[] {
    const byId = new Map<string, Message>();
    for (const m of messages.value) byId.set(m.id, m);
    const fresh: Message[] = [];
    for (const row of page) {
        const local = messages.value.find(
            (m) => m.client_id && m.client_id === row.external_message_id,
        );
        if (local) byId.delete(local.id);
        if (!byId.has(row.id)) fresh.push(row);
        byId.set(row.id, { ...row, local: row.author === 'customer' ? 'delivered' : undefined });
    }
    const list = Array.from(byId.values()).sort((a, b) =>
        a.created_at < b.created_at ? -1 : a.created_at > b.created_at ? 1 : 0,
    );
    return Object.assign(list, { fresh }) as Message[] & { fresh: Message[] };
}

export async function sync(api: WidgetApi): Promise<boolean> {
    const page = await api.messages(null);
    if (!page) return false;
    const merged = merge(page.data) as Message[] & { fresh?: Message[] };
    conversationId.value = page.conversation_id;
    envelope.value = page.envelope;
    messages.value = [...merged];
    const inbound = (merged.fresh ?? []).filter((m) => m.author !== 'customer');
    for (const m of inbound) announceHook?.(m);
    const toAck = inbound
        .filter((m) => m.delivery_state === 'sent' && !acked.has(m.id))
        .map((m) => m.id);
    if (toAck.length) {
        toAck.forEach((id) => acked.add(id));
        void api.receipts(toAck);
    }
    return true;
}

export function startPolling(api: WidgetApi, seconds: number): void {
    stopPolling();
    const tick = async (): Promise<void> => {
        if (open.value) await sync(api);
        timer = window.setTimeout(tick, Math.max(2, seconds) * 1000);
    };
    void tick();
}

export function isPolling(): boolean {
    return timer !== null;
}

export function stopPolling(): void {
    if (timer !== null) window.clearTimeout(timer);
    timer = null;
}

export function resetAcks(): void {
    acked = new Set();
}
