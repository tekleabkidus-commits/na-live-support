<script setup lang="ts">
// FR-14/FR-15 — the thread (S07 step 10): date separators; FR-14's
// ticket-BOUNDARY markers (a conversation spans tickets); the Doc 06 FR-11
// handoff-packet card pinned at its ticket's start, collapsible (AC-08's
// card — the Doc 06 closing row); consecutive system events collapsed in
// bulk ("show N events"); FR-15 opens at FIRST UNREAD (the viewer's marker
// boundary) else bottom, with both jump affordances; reply-to jumps fetch
// the Doc 04 EC-08 anchor window when the target is outside the loaded
// page. Opening marks read through the REAL marker writer — and a Read
// Only viewer's 403 is swallowed SILENTLY into session-local read state
// (the R-26 fourth-sibling consequence, the countersign's posture).
import { computed, nextTick, onMounted, ref } from 'vue';
import ComposerPane from './ComposerPane.vue';
import HandoffCard from './HandoffCard.vue';
import MediaLightbox from './MediaLightbox.vue';
import MessageBubble from './MessageBubble.vue';

type Msg = Record<string, unknown> & { id: string; created_at: string };
type Ticket = {
    id: string;
    number: number | string;
    status: string;
    created_at: string;
    source: string;
};

const props = defineProps<{
    conversationId: string;
    t: Record<string, string>;
    composerT: Record<string, string>;
    voiceFlag: boolean;
}>();

const messages = ref<Msg[]>([]);
const tickets = ref<Ticket[]>([]);
const packets = ref<{ ticket_id: string; payload: Record<string, unknown> }[]>([]);
const lastReadAt = ref<string | null>(null);
const prevCursor = ref<string | null>(null);
const channel = ref('');
const customerName = ref('');
const ticketStatus = ref('');
const conversationState = ref('active');
const windowExpiresAt = ref<string | null>(null);
const windowState = ref<'open' | 'closing' | 'expired' | 'none'>('none');
const lightbox = ref<Record<string, unknown> | null>(null);
const history = ref<{
    open: boolean;
    denied: boolean;
    revisions: { body: string; edited_at: string }[];
}>({
    open: false,
    denied: false,
    revisions: [],
});
const expandedEvents = ref<Record<string, boolean>>({});
const scroller = ref<HTMLElement | null>(null);

async function load(anchor?: string): Promise<void> {
    const params = anchor ? `?anchor=${anchor}` : '';
    const response = await fetch(`/inbox/thread/${props.conversationId}${params}`, {
        headers: { Accept: 'application/json' },
    });
    if (!response.ok) {
        return;
    }
    const body = (await response.json()) as {
        messages: Msg[];
        tickets: Ticket[];
        handoff_packets: { ticket_id: string; payload: Record<string, unknown> }[];
        last_read_at: string | null;
        prev_cursor: string | null;
        conversation: { channel: string };
    };
    messages.value = body.messages;
    tickets.value = body.tickets;
    packets.value = body.handoff_packets;
    lastReadAt.value = body.last_read_at;
    prevCursor.value = body.prev_cursor;
    channel.value = body.conversation.channel;
    const conversationMeta = body.conversation as unknown as {
        channel: string;
        customer_display_name?: string;
        state?: string;
        reply_window_state?: string;
        reply_window_expires_at?: string | null;
    };
    customerName.value = conversationMeta.customer_display_name ?? '';
    conversationState.value = conversationMeta.state ?? 'active';
    windowExpiresAt.value = conversationMeta.reply_window_expires_at ?? null;
    const rawWindow = conversationMeta.reply_window_state ?? 'none';
    windowState.value =
        rawWindow === 'open' || rawWindow === 'closing' || rawWindow === 'expired'
            ? rawWindow
            : 'none';
    ticketStatus.value =
        tickets.value.length > 0 ? (tickets.value[tickets.value.length - 1]?.status ?? '') : '';
}

// ── The RENDER STREAM: messages interleaved with boundary markers (at each
// ticket's created_at), the handoff card at its ticket start, date
// separators, and consecutive-system-event grouping.
type StreamItem =
    | { type: 'date'; key: string; label: string }
    | { type: 'boundary'; key: string; label: string; ticketId: string }
    | { type: 'unread'; key: string }
    | { type: 'events'; key: string; items: Msg[] }
    | { type: 'message'; key: string; message: Msg };

const stream = computed((): StreamItem[] => {
    const out: StreamItem[] = [];
    let lastDay = '';
    let unreadShown = false;
    let boundaryIndex = 0;
    const boundaries = tickets.value.map((ticket, index) => ({ ticket, index })).slice();

    const pushBoundaries = (uptoStamp: string | null): void => {
        while (
            boundaryIndex < boundaries.length &&
            (uptoStamp === null || boundaries[boundaryIndex]!.ticket.created_at <= uptoStamp)
        ) {
            const { ticket, index } = boundaries[boundaryIndex]!;
            const previous = index > 0 ? tickets.value[index - 1] : undefined;
            out.push({
                type: 'boundary',
                key: 'b-' + ticket.id,
                ticketId: ticket.id,
                label: previous
                    ? (props.t.boundary ?? '')
                          .replace(':closed', String(previous.number))
                          .replace(':opened', String(ticket.number))
                    : (props.t.ticket_opened ?? '').replace(':number', String(ticket.number)),
            });
            boundaryIndex += 1;
        }
    };

    let eventBuffer: Msg[] = [];
    const flushEvents = (): void => {
        if (eventBuffer.length === 0) {
            return;
        }
        out.push({ type: 'events', key: 'e-' + eventBuffer[0]!.id, items: eventBuffer });
        eventBuffer = [];
    };

    for (const message of messages.value) {
        pushBoundaries(message.created_at);

        const day = message.created_at.slice(0, 10);
        if (day !== lastDay) {
            flushEvents();
            out.push({ type: 'date', key: 'd-' + day, label: new Date(day).toLocaleDateString() });
            lastDay = day;
        }

        // FR-15: the first-unread divider at the marker boundary.
        if (!unreadShown && lastReadAt.value !== null && message.created_at > lastReadAt.value) {
            flushEvents();
            out.push({ type: 'unread', key: 'unread' });
            unreadShown = true;
        }

        if (message.kind === 'system') {
            eventBuffer.push(message);
            continue;
        }
        flushEvents();
        out.push({ type: 'message', key: message.id, message });
    }
    flushEvents();
    pushBoundaries(null);

    return out;
});

const packetFor = (ticketId: string) =>
    packets.value.find((packet) => packet.ticket_id === ticketId);

// Prettier's HTML parser reads a generic cast's `<` as a tag — keep casts
// out of interpolations.
const openMedia = (media: Record<string, unknown>): void => {
    lightbox.value = media;
};

const systemCode = (event: Msg): string => {
    const meta = (event.channel_meta ?? {}) as Record<string, unknown>;
    return typeof meta.system_code === 'string' ? meta.system_code : '';
};

// FR-15: open at first unread, else bottom.
async function positionOnOpen(): Promise<void> {
    await nextTick();
    const el = scroller.value;
    if (!el) {
        return;
    }
    const divider = el.querySelector('[data-role="first-unread"]');
    if (divider) {
        divider.scrollIntoView({ block: 'center' });
    } else {
        el.scrollTop = el.scrollHeight;
    }
}

function jumpLatest(): void {
    if (scroller.value) {
        scroller.value.scrollTop = scroller.value.scrollHeight;
    }
}

function jumpFirstUnread(): void {
    scroller.value
        ?.querySelector('[data-role="first-unread"]')
        ?.scrollIntoView({ block: 'center' });
}

// Doc 04 EC-08: a reply-ref target outside the loaded page fetches the
// anchor WINDOW, then scrolls.
async function jumpTo(messageId: string): Promise<void> {
    if (!messages.value.some((message) => message.id === messageId)) {
        await load(messageId);
        await nextTick();
    }
    scroller.value
        ?.querySelector(`[data-message="${messageId}"]`)
        ?.scrollIntoView({ block: 'center' });
}

async function openHistory(messageId: string): Promise<void> {
    const response = await fetch(`/inbox/messages/${messageId}/history`, {
        headers: { Accept: 'application/json' },
    });
    history.value = {
        open: true,
        denied: response.status === 403,
        revisions:
            response.status === 403
                ? []
                : ((await response.json()) as { revisions: { body: string; edited_at: string }[] })
                      .revisions,
    };
}

// Opening MARKS READ at the newest message — silently for Read Only (the
// fourth-sibling consequence: 403 → session-local, zero toasts).
async function markRead(): Promise<void> {
    const newest = messages.value[messages.value.length - 1];
    if (!newest) {
        return;
    }
    const xsrf = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    try {
        await fetch('/inbox/read', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': xsrf?.[1] ? decodeURIComponent(xsrf[1]) : '',
            },
            body: JSON.stringify({ conversation_id: props.conversationId, message_id: newest.id }),
        });
        // 403 (Read Only) and any failure land here SILENTLY — the divider
        // this session showed stays session-local, exactly the countersigned
        // degrade posture. No toast, ever.
    } catch {
        /* offline: same silence */
    }
}

onMounted(async () => {
    await load();
    await positionOnOpen();
    void markRead();
});
</script>

<template>
    <div class="flex min-h-0 flex-1 flex-col" data-testid="thread-pane">
        <div
            class="flex items-center gap-2 border-b px-3 py-1.5"
            :style="{ borderColor: 'var(--ds-border)' }"
        >
            <button
                type="button"
                data-testid="jump-first-unread"
                class="rounded border px-2 py-0.5 text-xs"
                :style="{ borderColor: 'var(--ds-border-soft)', color: 'var(--ds-text-secondary)' }"
                @click="jumpFirstUnread"
            >
                {{ t.jump_first_unread }}
            </button>
            <button
                type="button"
                data-testid="jump-latest"
                class="rounded border px-2 py-0.5 text-xs"
                :style="{ borderColor: 'var(--ds-border-soft)', color: 'var(--ds-text-secondary)' }"
                @click="jumpLatest"
            >
                {{ t.jump_latest }}
            </button>
            <a
                data-testid="print-thread"
                :href="`/inbox/thread/${conversationId}/print`"
                target="_blank"
                class="ms-auto rounded border px-2 py-0.5 text-xs"
                :style="{ borderColor: 'var(--ds-border-soft)', color: 'var(--ds-text-secondary)' }"
            >
                {{ t.print }}
            </a>
        </div>

        <div
            ref="scroller"
            data-testid="thread-scroll"
            class="min-h-0 flex-1 space-y-2 overflow-y-auto p-3"
        >
            <template v-for="item in stream" :key="item.key">
                <p
                    v-if="item.type === 'date'"
                    class="text-center text-[10px] font-semibold uppercase"
                    :style="{ color: 'var(--ds-text-secondary)' }"
                >
                    {{ item.label }}
                </p>

                <div v-else-if="item.type === 'boundary'">
                    <p
                        data-role="ticket-boundary"
                        class="my-2 border-t border-dashed pt-1 text-center text-[10px]"
                        :style="{
                            borderColor: 'var(--ds-border-strong)',
                            color: 'var(--ds-text-muted)',
                        }"
                    >
                        {{ item.label }}
                    </p>
                    <!-- The AC-08 card, pinned at its ticket's start. -->
                    <HandoffCard
                        v-if="packetFor(item.ticketId)"
                        :packet="packetFor(item.ticketId)!.payload"
                        :t="t"
                    />
                </div>

                <p
                    v-else-if="item.type === 'unread'"
                    data-role="first-unread"
                    class="my-2 border-t pt-1 text-center text-[10px] font-bold uppercase"
                    :style="{ borderColor: 'var(--ds-accent)', color: 'var(--ds-accent)' }"
                >
                    {{ t.first_unread }}
                </p>

                <!-- Consecutive system events, collapsed in bulk. -->
                <div
                    v-else-if="item.type === 'events'"
                    data-role="system-events"
                    class="text-center"
                >
                    <button
                        v-if="!expandedEvents[item.key] && item.items.length > 1"
                        type="button"
                        data-role="show-events"
                        class="text-[11px] underline"
                        :style="{ color: 'var(--ds-text-secondary)' }"
                        @click="expandedEvents[item.key] = true"
                    >
                        {{ (t.show_events ?? '').replace(':count', String(item.items.length)) }}
                    </button>
                    <template v-else>
                        <p
                            v-for="event in item.items"
                            :key="event.id"
                            data-role="system-event"
                            class="text-[11px] italic"
                            :style="{ color: 'var(--ds-text-secondary)' }"
                        >
                            {{ systemCode(event) }}
                        </p>
                    </template>
                </div>

                <MessageBubble
                    v-else-if="item.type === 'message'"
                    :message="item.message"
                    :channel="channel"
                    :t="t"
                    @open-media="openMedia"
                    @open-history="openHistory"
                    @jump-to="jumpTo"
                />
            </template>
        </div>

        <ComposerPane
            :conversation-id="conversationId"
            :channel="channel"
            :customer-name="customerName"
            :ticket-status="ticketStatus"
            :conversation-state="conversationState"
            :window-state="windowState"
            :window-expires-at="windowExpiresAt"
            connection-state="active"
            connection-account=""
            :customer-has-phone="false"
            :blocked-by-user="false"
            :t="composerT"
            :voice-flag="voiceFlag"
            @sent="() => load()"
        />

        <MediaLightbox v-if="lightbox" :media="lightbox" :t="t" @close="lightbox = null" />

        <!-- B12-S02: history modal — content by capability, refusal plain. -->
        <div
            v-if="history.open"
            data-testid="history-modal"
            role="dialog"
            aria-modal="true"
            :aria-label="t.history_title"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-6"
            @click.self="history.open = false"
        >
            <div
                class="max-h-[70vh] w-96 overflow-y-auto rounded-lg p-4"
                :style="{ background: 'var(--ds-panel-bg)' }"
            >
                <div class="mb-2 flex items-center justify-between">
                    <h2 class="text-sm font-semibold" :style="{ color: 'var(--ds-text-primary)' }">
                        {{ t.history_title }}
                    </h2>
                    <button
                        type="button"
                        data-testid="history-close"
                        class="text-xs"
                        :style="{ color: 'var(--ds-text-muted)' }"
                        :aria-label="t.history_close"
                        @click="history.open = false"
                    >
                        ✕
                    </button>
                </div>
                <p
                    v-if="history.denied"
                    data-testid="history-denied"
                    class="text-xs"
                    :style="{ color: 'var(--ds-text-muted)' }"
                >
                    {{ t.history_denied }}
                </p>
                <ul v-else class="space-y-2">
                    <li
                        v-for="(revision, index) in history.revisions"
                        :key="index"
                        data-role="revision"
                        class="rounded border p-2 text-xs"
                        :style="{
                            borderColor: 'var(--ds-border-soft)',
                            color: 'var(--ds-text-secondary)',
                        }"
                    >
                        <p class="whitespace-pre-wrap">{{ revision.body }}</p>
                        <p class="mt-1 text-[10px]" :style="{ color: 'var(--ds-text-faint)' }">
                            {{ revision.edited_at }}
                        </p>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</template>
