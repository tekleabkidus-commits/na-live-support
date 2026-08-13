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

const rows = ref<TimelineRow[]>([]);
const total = ref(0);
const expanded = ref(false);

async function load(all: boolean): Promise<void> {
    if (props.ticketId === null) {
        rows.value = [];
        total.value = 0;
        return;
    }
    const response = await fetch(`/inbox/t/${props.ticketId}/timeline` + (all ? '?all=1' : ''), {
        headers: { Accept: 'application/json' },
    });
    if (!response.ok) return;
    const body = (await response.json()) as { total: number; rows: TimelineRow[] };
    rows.value = body.rows;
    total.value = body.total;
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
        <p
            v-if="rows.length === 0"
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
                <br />
                <span :style="{ color: 'var(--ds-text-muted)' }" data-timeline-actor
                    >{{ actorLabel(row) }} · {{ timeLabel(row.occurred_at) }}</span
                >
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
