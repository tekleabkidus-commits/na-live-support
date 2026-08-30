<script setup lang="ts">
// B2-S03/B12 (S07 step 14) — the ticket timeline inside the panel: the
// relayed events log, newest first, actor and time on every row (Doc 06
// FR-26 · Doc 17 FR-07). The frame's actor law renders here: humans by
// name, automated events by their CLASS — never a person's name. The
// expander serves the rest of the log on demand ("Show N earlier events").
import { ref, watch } from 'vue';

type TimelineRow = {
    id: string;
    name: string;
    occurred_at: string;
    actor_type: string;
    actor_name: string | null;
    detail: Record<string, string | null | undefined>;
};

const props = defineProps<{
    ticketId: string | null;
    t: Record<string, string>;
    refreshTick?: number;
}>();

const emit = defineEmits<{
    // D9/D10: the panel renders system explanations when a system event is
    // the newest thing that happened.
    (e: 'newest', name: string): void;
}>();

const rows = ref<TimelineRow[]>([]);
const total = ref(0);
const expanded = ref(false);

// S11 step 11b (STOP-G, countersigned): the FR-17 expandable — a `ticket.routed` row opens the
// record card (B5-S02 §D C09–C11 at full fidelity, tokens only). The record is fetched by id
// through its own SEC-02 door; elision is ABSENCE in the payload (no names map, no assigned_to)
// and the card renders the drawn "Names hidden" state (C11), never a redacted-looking blank.
type RoutingRecord = {
    id: string;
    trigger: string;
    outcome: string;
    strategy: string | null;
    boosts_applied: string[];
    waiting: boolean;
    steps: { step: string; input: Record<string, unknown>; outcome: string }[];
    at: string;
    assigned_to?: string | null;
    names?: Record<string, string>;
};
const openRecordId = ref<string | null>(null);
const record = ref<RoutingRecord | null>(null);
const recordError = ref(false);
async function toggleRecord(recordId: string): Promise<void> {
    if (openRecordId.value === recordId) {
        openRecordId.value = null;
        record.value = null;
        return;
    }
    openRecordId.value = recordId;
    record.value = null;
    recordError.value = false;
    try {
        const response = await fetch(`/inbox/t/${props.ticketId}/routing/${recordId}`, {
            headers: { Accept: 'application/json' },
        });
        if (!response.ok) throw new Error(String(response.status));
        record.value = (await response.json()) as RoutingRecord;
    } catch {
        recordError.value = true;
    }
}
function agentName(value: unknown): string {
    if (typeof value !== 'string') return String(value ?? '');
    return record.value?.names?.[value] ?? value;
}
function recordTitle(): string {
    const r = record.value;
    if (r === null) return '';
    if (r.outcome === 'waiting' || r.waiting) return props.t.record_title_waiting ?? '';
    const name = typeof r.assigned_to === 'string' ? agentName(r.assigned_to) : null;
    return name !== null
        ? (props.t.record_title_assigned ?? ':name').replace(':name', name)
        : (props.t.record_title_assigned_anon ?? '');
}
function inputLine(input: Record<string, unknown>): string {
    return Object.entries(input)
        .map(([k, v]) => `${k}=${Array.isArray(v) ? v.map(agentName).join(',') : agentName(v)}`)
        .join(' · ');
}
function copyRecord(): void {
    const r = record.value;
    if (r === null) return;
    const lines = r.steps.map(
        (step, i) => `${i + 1}. ${step.outcome}\n   ${inputLine(step.input)}`,
    );
    void navigator.clipboard?.writeText(`${recordTitle()}\n${lines.join('\n')}`);
}
// D6: this panel section can fail ALONE — the state says so and offers the
// retry; the conversation to the left is untouched (the drawn copy's law).
const failed = ref(false);

async function load(all: boolean): Promise<void> {
    if (props.ticketId === null) {
        rows.value = [];
        total.value = 0;
        return;
    }
    failed.value = false;
    let response: Response;
    try {
        response = await fetch(`/inbox/t/${props.ticketId}/timeline` + (all ? '?all=1' : ''), {
            headers: { Accept: 'application/json' },
        });
    } catch {
        failed.value = true;
        return;
    }
    if (!response.ok) {
        failed.value = true;
        return;
    }
    const body = (await response.json()) as { total: number; rows: TimelineRow[] };
    rows.value = body.rows;
    total.value = body.total;
    emit('newest', body.rows[0]?.name ?? '');
}

watch(
    () => [props.ticketId, props.refreshTick] as const,
    () => {
        expanded.value = false;
        void load(false);
    },
    { immediate: true },
);

function kindLabel(name: string): string {
    return props.t['ev_' + name.replace('ticket.', '')] ?? name;
}

function actorLabel(row: TimelineRow): string {
    if (row.actor_name !== null) return row.actor_name;
    return props.t['actor_' + row.actor_type] ?? props.t.actor_system ?? '';
}

function detailLine(row: TimelineRow): string {
    const d = row.detail;
    if (d.old_status && d.new_status) {
        return `${d.old_status} → ${d.new_status}`;
    }
    if ('new_assignee_name' in d || 'old_assignee_name' in d) {
        const from = d.old_assignee_name ?? props.t.unassigned_name ?? '';
        const to = d.new_assignee_name ?? props.t.unassigned_name ?? '';
        return `${from} → ${to}`;
    }
    if (d.old_priority && d.new_priority) {
        return `${d.old_priority} → ${d.new_priority}`;
    }
    if (d.reason) return String(d.reason);
    if (d.subject_source) return String(d.subject_source);
    if (d.source) return String(d.source);
    return '';
}

function timeLabel(iso: string): string {
    const at = new Date(iso);
    const now = new Date();
    const sameDay = at.toDateString() === now.toDateString();
    const clock = new Intl.DateTimeFormat(undefined, {
        hour: '2-digit',
        minute: '2-digit',
    }).format(at);
    if (sameDay) return (props.t.today_at ?? ':time').replace(':time', clock);
    return (
        new Intl.DateTimeFormat(undefined, { month: 'short', day: 'numeric' }).format(at) +
        ' ' +
        clock
    );
}

function showEarlier(): void {
    expanded.value = true;
    void load(true);
}
</script>

<template>
    <div data-testid="ticket-timeline">
        <p class="mb-1 text-xs" :style="{ color: 'var(--ds-text-muted)' }">
            {{ t.timeline_title }}
        </p>
        <div v-if="failed" data-testid="timeline-failed" class="text-xs">
            <p :style="{ color: 'var(--ds-danger-strong)' }">⚠ {{ t.load_failed }}</p>
            <p :style="{ color: 'var(--ds-text-muted)' }">{{ t.load_failed_hint }}</p>
            <button
                type="button"
                data-testid="timeline-retry"
                class="mt-1 underline"
                :style="{ color: 'var(--ds-text-secondary)' }"
                @click="void load(expanded)"
            >
                {{ t.try_again }}
            </button>
        </div>
        <p
            v-else-if="rows.length === 0"
            class="text-xs"
            :style="{ color: 'var(--ds-text-muted)' }"
            data-testid="timeline-empty"
        >
            {{ t.timeline_empty }}
        </p>
        <ol v-else class="flex flex-col gap-2">
            <li v-for="row in rows" :key="row.id" :data-timeline-event="row.name" class="text-xs">
                <span class="font-semibold">{{ kindLabel(row.name) }}</span>
                <span v-if="detailLine(row) !== ''" :style="{ color: 'var(--ds-text-secondary)' }">
                    · {{ detailLine(row) }}</span
                >
                <button
                    v-if="row.name === 'ticket.routed' && typeof row.detail.record_id === 'string'"
                    type="button"
                    class="ml-1 text-xs underline"
                    :style="{ color: 'var(--ds-text-secondary)' }"
                    :data-record-toggle="row.detail.record_id"
                    :aria-expanded="openRecordId === row.detail.record_id"
                    @click="void toggleRecord(String(row.detail.record_id))"
                >
                    {{ t.record_open }}
                </button>
                <br />
                <span :style="{ color: 'var(--ds-text-muted)' }" data-timeline-actor
                    >{{ actorLabel(row) }} · {{ timeLabel(row.occurred_at) }}</span
                >
                <!-- B5-S02 §D C09–C11: the record card. Border/radius/typography from tokens; the
                     numbered plain-language line + monospace facts line per step; the C11 lock chip
                     and elision note render on ABSENCE of the names map. -->
                <div
                    v-if="row.name === 'ticket.routed' && openRecordId === row.detail.record_id"
                    class="mt-2 overflow-hidden rounded-lg border"
                    :style="{ borderColor: 'var(--ds-border)', background: 'var(--ds-surface)' }"
                    data-testid="routing-record-card"
                >
                    <p v-if="recordError" class="p-3" :style="{ color: 'var(--ds-danger-strong)' }">
                        ⚠ {{ t.record_load_failed }}
                    </p>
                    <template v-else-if="record !== null">
                        <div
                            class="flex items-center gap-2 border-b px-3 py-2"
                            :style="{ borderColor: 'var(--ds-border-soft)' }"
                        >
                            <span class="text-[12.5px] font-semibold" :style="{ color: 'var(--ds-text-primary)' }" data-testid="record-title">{{ recordTitle() }}</span>
                            <span
                                v-if="!record.names"
                                class="ml-auto inline-flex flex-none items-center gap-1 whitespace-nowrap text-[10.5px]"
                                :style="{ color: 'var(--ds-text-muted)' }"
                                data-testid="record-names-hidden"
                                >🔒 {{ t.record_names_hidden }}</span
                            >
                            <button
                                type="button"
                                class="flex-none rounded-md border px-2 py-1 text-[11px] font-semibold"
                                :class="record.names ? 'ml-auto' : ''"
                                :style="{ borderColor: 'var(--ds-border)', color: 'var(--ds-text-primary)', background: 'var(--ds-surface)' }"
                                data-testid="record-copy"
                                @click="copyRecord"
                            >
                                {{ t.record_copy }}
                            </button>
                        </div>
                        <ol class="flex flex-col gap-2 p-3">
                            <li v-for="(step, index) in record.steps" :key="index" class="flex gap-2.5" :data-record-step="step.step">
                                <span
                                    class="w-[18px] flex-none font-mono text-[11px] font-semibold"
                                    :style="{ color: 'var(--ds-text-muted)' }"
                                    aria-hidden="true"
                                    >{{ index + 1 }}</span
                                >
                                <span class="min-w-0">
                                    <span class="block text-[12.5px] leading-relaxed" :style="{ color: 'var(--ds-text-primary)' }">{{ step.outcome }}</span>
                                    <span
                                        v-if="inputLine(step.input) !== ''"
                                        class="mt-0.5 block break-all font-mono text-[10.5px]"
                                        :style="{ color: 'var(--ds-text-muted)' }"
                                        >{{ inputLine(step.input) }}</span
                                    >
                                </span>
                            </li>
                        </ol>
                        <div
                            v-if="!record.names"
                            class="mx-3 mb-3 rounded-md border p-2.5 text-[11.5px] leading-relaxed"
                            :style="{ borderColor: 'var(--ds-border)', background: 'var(--ds-inset-bg)', color: 'var(--ds-text-secondary)' }"
                            data-testid="record-elision-note"
                        >
                            {{ t.record_elision_note }}
                        </div>
                    </template>
                </div>
            </li>
        </ol>
        <button
            v-if="!expanded && total > rows.length"
            type="button"
            data-testid="timeline-earlier"
            class="mt-1 text-xs underline"
            :style="{ color: 'var(--ds-text-secondary)' }"
            @click="showEarlier"
        >
            {{ (t.show_earlier ?? '').replace(':count', String(total - rows.length)) }}
        </button>
    </div>
</template>
