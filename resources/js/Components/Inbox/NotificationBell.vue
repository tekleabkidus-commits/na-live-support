<script setup lang="ts">
// FR-26 — the notification bell (S07 step 12), drawn in B1-S03: header with
// "Mark all read" + the quick mute (1 h / today), rows with the unread accent
// border + relative time, each row deep-linking IN PLACE, the "Nothing new"
// empty state. FS-04: when the notifier recorded a denied desktop permission,
// the ONE-TIME hint renders here — never a re-prompt loop.
//
// Q7/SEC-04 posture: rows carry identifiers only; the copy is built from
// i18n templates + numbers — message content never reaches this surface.
import { computed, ref } from 'vue';
import { notificationPermissionHintNeeded } from '../../lib/notifier';
import { serverNow } from '../../lib/serverClock';

const props = defineProps<{
    t: Record<string, string>;
    initialUnread: number;
    mutedUntil: string | null;
}>();

const emit = defineEmits<{
    (e: 'open-thread', payload: { ticketId: string; conversationId: string | null }): void;
    (e: 'mute', until: string | null): void;
}>();

type BellRow = {
    id: string;
    category: string;
    ticket_id: string | null;
    conversation_id?: string | null;
    payload: Record<string, unknown>;
    read_at: string | null;
    created_at: string;
    ticket_number?: number | string | null;
    actor_name?: string | null;
};

const open = ref(false);
const rows = ref<BellRow[]>([]);
const unread = ref(props.initialUnread);
const muteMenu = ref(false);
const permissionHint = ref(false);

async function refresh(): Promise<void> {
    const response = await fetch('/inbox/notifications', {
        headers: { Accept: 'application/json' },
    });
    if (!response.ok) return;
    const body = (await response.json()) as { notifications: BellRow[]; unread: number };
    rows.value = body.notifications;
    unread.value = body.unread;
}

async function toggle(): Promise<void> {
    open.value = !open.value;
    muteMenu.value = false;
    if (open.value) {
        permissionHint.value = notificationPermissionHintNeeded();
        await refresh();
    }
}

async function markAllRead(): Promise<void> {
    await fetch('/inbox/notifications/read-all', {
        method: 'POST',
        headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() },
    });
    await refresh();
}

function xsrf(): string {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    return match?.[1] ? decodeURIComponent(match[1]) : '';
}

// FR-26's quick mute: 1 hour, or the rest of today. Client wall time is
// FINE here — this is a per-user comfort preference, not an ordering or
// metric timestamp (R-19's scope).
function muteFor(kind: '1h' | 'today'): void {
    const until = new Date();
    if (kind === '1h') {
        until.setHours(until.getHours() + 1);
    } else {
        until.setHours(23, 59, 59, 999);
    }
    muteMenu.value = false;
    emit('mute', until.toISOString());
}

function unmute(): void {
    muteMenu.value = false;
    emit('mute', null);
}

const muted = computed(
    () => props.mutedUntil !== null && new Date(props.mutedUntil).getTime() > Date.now(),
);

// §14: ICU relative times switching to absolute past 24 h.
// EC-07 (step 13): delta from the SERVER-NOW offset, never the raw client
// clock — a client minutes wrong would otherwise mislabel every row.
function relative(stamp: string): string {
    const delta = (serverNow() - new Date(stamp).getTime()) / 1000;
    if (delta < 60) return props.t.just_now ?? '';
    if (delta < 3600)
        return (props.t.minutes_ago ?? '').replace(':count', String(Math.floor(delta / 60)));
    if (delta < 86400)
        return (props.t.hours_ago ?? '').replace(':count', String(Math.floor(delta / 3600)));
    return new Date(stamp).toLocaleDateString();
}

function rowCopy(row: BellRow): string {
    const number = row.ticket_number != null ? `#${row.ticket_number}` : '';
    const actor = row.actor_name ?? '';
    switch (row.category) {
        case 'notify.inbox.mention':
            return (props.t.row_mention ?? '').replace(':actor', actor).replace(':number', number);
        case 'notify.inbox.assignment':
            return (props.t.row_assignment ?? '').replace(':number', number);
        case 'notify.inbox.escalation':
            return (props.t.row_escalation ?? '').replace(':number', number);
        case 'notify.inbox.follower':
            return (props.t.row_follower ?? '').replace(':number', number);
        case 'notify.inbox.sla_warning':
            return (props.t.row_sla_warning ?? '').replace(':number', number);
        case 'notify.inbox.window_closing':
            return (props.t.row_window_closing ?? '').replace(':number', number);
        case 'notify.inbox.bulk_completed':
            return (props.t.row_bulk_completed ?? '')
                .replace(':ok', String(row.payload.ok ?? 0))
                .replace(':failed', String(row.payload.failed ?? 0));
        default:
            return number;
    }
}

function openRow(row: BellRow): void {
    if (row.ticket_id === null) return; // bulk rows have no single ticket
    open.value = false;
    emit('open-thread', { ticketId: row.ticket_id, conversationId: row.conversation_id ?? null });
}
</script>

<template>
    <div class="relative">
        <button
            type="button"
            data-testid="notification-bell"
            class="relative rounded-md p-2 text-sm"
            :aria-label="t.title"
            :aria-expanded="open"
            :style="{ color: 'var(--ds-text-muted)' }"
            @click="toggle"
        >
            🔔<span v-if="muted" data-testid="bell-muted-dot" aria-hidden="true">🔕</span>
            <span
                v-if="unread > 0"
                data-testid="bell-unread-badge"
                class="absolute -end-0.5 -top-0.5 rounded-full px-1 text-[10px] font-bold"
                :style="{ background: 'var(--ds-accent)', color: 'var(--ds-accent-contrast)' }"
                :aria-label="(t.unread_label ?? '').replace(':count', String(unread))"
                >{{ unread > 999 ? '999+' : unread }}</span
            >
        </button>

        <div
            v-if="open"
            data-testid="bell-dropdown"
            role="dialog"
            :aria-label="t.title"
            class="absolute end-0 top-full z-40 mt-1 w-[380px] overflow-hidden rounded-lg border shadow-xl"
            :style="{ background: 'var(--ds-panel-bg)', borderColor: 'var(--ds-border)' }"
        >
            <div
                class="flex items-center gap-2 border-b px-3.5 py-2.5"
                :style="{ borderColor: 'var(--ds-border-soft)' }"
            >
                <span
                    class="text-[13px] font-semibold"
                    :style="{ color: 'var(--ds-text-primary)' }"
                    >{{ t.title }}</span
                >
                <button
                    type="button"
                    data-testid="bell-mark-all-read"
                    class="ms-auto text-[11px]"
                    :style="{ color: 'var(--ds-text-muted)' }"
                    @click="markAllRead"
                >
                    {{ t.mark_all_read }}
                </button>
                <div class="relative">
                    <button
                        type="button"
                        data-testid="bell-mute-toggle"
                        class="rounded-md border px-2 py-0.5 text-[11px]"
                        :style="{
                            borderColor: 'var(--ds-border-soft)',
                            color: 'var(--ds-text-muted)',
                        }"
                        @click="muteMenu = !muteMenu"
                    >
                        {{ muted ? t.muted : t.mute }} ▾
                    </button>
                    <div
                        v-if="muteMenu"
                        data-testid="bell-mute-menu"
                        class="absolute end-0 top-full z-50 mt-1 w-36 rounded-md border py-1 shadow-lg"
                        :style="{
                            background: 'var(--ds-panel-bg)',
                            borderColor: 'var(--ds-border)',
                        }"
                    >
                        <button
                            type="button"
                            data-testid="bell-mute-1h"
                            class="block w-full px-3 py-1.5 text-start text-[12px]"
                            :style="{ color: 'var(--ds-text-primary)' }"
                            @click="muteFor('1h')"
                        >
                            {{ t.mute_1h }}
                        </button>
                        <button
                            type="button"
                            data-testid="bell-mute-today"
                            class="block w-full px-3 py-1.5 text-start text-[12px]"
                            :style="{ color: 'var(--ds-text-primary)' }"
                            @click="muteFor('today')"
                        >
                            {{ t.mute_today }}
                        </button>
                        <button
                            v-if="muted"
                            type="button"
                            data-testid="bell-unmute"
                            class="block w-full px-3 py-1.5 text-start text-[12px]"
                            :style="{ color: 'var(--ds-text-primary)' }"
                            @click="unmute"
                        >
                            {{ t.unmute }}
                        </button>
                    </div>
                </div>
            </div>

            <!-- FS-04: the ONE-TIME hint, never a re-prompt loop. -->
            <p
                v-if="permissionHint"
                data-testid="bell-permission-hint"
                class="border-b px-3.5 py-2 text-[11px]"
                :style="{ borderColor: 'var(--ds-border-soft)', color: 'var(--ds-text-muted)' }"
            >
                {{ t.permission_hint }}
            </p>

            <div class="max-h-[420px] overflow-y-auto">
                <button
                    v-for="row in rows"
                    :key="row.id"
                    type="button"
                    :data-bell-row="row.category"
                    class="block w-full border-b px-3.5 py-2.5 text-start"
                    :style="{
                        borderColor: 'var(--ds-border-soft)',
                        borderInlineStartWidth: row.read_at === null ? '3px' : '0',
                        borderInlineStartColor: 'var(--ds-accent)',
                        background: row.read_at === null ? 'var(--ds-panel-soft)' : 'transparent',
                    }"
                    @click="openRow(row)"
                >
                    <span
                        class="block text-[12px] leading-snug"
                        :style="{ color: 'var(--ds-text-primary)' }"
                        >{{ rowCopy(row) }}</span
                    >
                    <span
                        class="mt-0.5 block text-[10.5px]"
                        :style="{ color: 'var(--ds-text-muted)' }"
                        >{{ relative(row.created_at) }}</span
                    >
                </button>

                <div
                    v-if="rows.length === 0"
                    data-testid="bell-empty"
                    class="px-3.5 py-5 text-center"
                >
                    <p
                        class="text-[12px] font-semibold"
                        :style="{ color: 'var(--ds-text-primary)' }"
                    >
                        {{ t.empty_title }}
                    </p>
                    <p class="mt-0.5 text-[11px]" :style="{ color: 'var(--ds-text-muted)' }">
                        {{ t.empty_body }}
                    </p>
                </div>
            </div>
        </div>
    </div>
</template>
