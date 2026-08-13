<script setup lang="ts">
// C15 (B2-S04 v2, S07 step 14 Unit D) — the bulk progress drawer, drawn
// whole: "Closing 63 tickets" + ✕, "48 of 63 done", "76% · about 20 s
// left", the ✓/✕/waiting breakdown, per-row failures NAMED by ticket number
// and reason ("the agent never has to guess which fifteen went wrong"),
// Stop while running, "Retry the N that failed" when done. 375 renders the
// same content as a bottom sheet. Progress is announced at 25, 50, 75 and
// 100 per cent through the polite live region — "so it is not a silent
// bar" (the frozen note; B12-A01 AC-09's increments).
//
// The drawer POLLS the progress read door (GET /inbox/bulk-jobs?ids[]) —
// the server's item rows are the single truth (T-03 posture: counts are
// server counts, never client derivations). The time-left line is the one
// client estimate (elapsed ÷ done × remaining) and says "about".
import { computed, onBeforeUnmount, ref, watch } from 'vue';

type BulkJob = {
    id: string;
    action: string;
    status: string;
    total: number;
    ok: number;
    failed_count: number;
    done: number;
    cancel_requested: boolean;
    failed: { number: number; reason: string | null }[];
};

const props = defineProps<{
    open: boolean;
    jobIds: string[];
    action: string;
    mobile?: boolean;
    t: Record<string, string>;
    xsrf: () => string;
}>();

const emit = defineEmits<{
    (e: 'close'): void;
    (e: 'retried', jobIds: string[]): void;
}>();

const jobs = ref<BulkJob[]>([]);
const announcement = ref('');
const busy = ref(false);
let timer: ReturnType<typeof setTimeout> | null = null;
let startedAt = 0;
let announcedQuarter = 0;

const total = computed(() => jobs.value.reduce((n, j) => n + j.total, 0));
const ok = computed(() => jobs.value.reduce((n, j) => n + j.ok, 0));
const failedCount = computed(() => jobs.value.reduce((n, j) => n + j.failed_count, 0));
const done = computed(() => ok.value + failedCount.value);
const waiting = computed(() => Math.max(0, total.value - done.value));
const percent = computed(() =>
    total.value === 0 ? 0 : Math.floor((done.value / total.value) * 100),
);
const running = computed(
    () => jobs.value.length === 0 || jobs.value.some((j) => j.status !== 'completed'),
);
const failedRows = computed(() => jobs.value.flatMap((j) => j.failed));
const stopRequested = computed(() => jobs.value.some((j) => j.cancel_requested));

const fill = (key: string, replacements: Record<string, string>): string => {
    let value = props.t[key] ?? '';
    for (const [token, replacement] of Object.entries(replacements)) {
        value = value.replace(':' + token, replacement);
    }
    return value;
};

const title = computed(() => fill('running_' + props.action, { count: String(total.value) }));

// "about 20 s left" — the one client estimate, honest about being one.
const timeLeft = computed(() => {
    if (!running.value || done.value === 0 || startedAt === 0) return '';
    const elapsed = (Date.now() - startedAt) / 1000;
    const remaining = Math.ceil((elapsed / done.value) * waiting.value);
    return fill('time_left', { seconds: String(Math.max(1, remaining)) });
});

function reasonLabel(reason: string | null): string {
    if (reason === null) return props.t.reason_other ?? '';
    return props.t['reason_' + reason] ?? props.t.reason_other ?? '';
}

async function poll(): Promise<void> {
    if (!props.open || props.jobIds.length === 0) return;
    const query = props.jobIds.map((id) => 'ids[]=' + encodeURIComponent(id)).join('&');
    const response = await fetch('/inbox/bulk-jobs?' + query, {
        headers: { Accept: 'application/json' },
    }).catch(() => null);
    if (response?.ok) {
        const body = (await response.json()) as { jobs: BulkJob[] };
        jobs.value = body.jobs;
        // §13/B12-A01 AC-09: announce each 25% threshold exactly once.
        const quarter = Math.floor(percent.value / 25);
        if (quarter > announcedQuarter && done.value > 0) {
            announcedQuarter = quarter;
            announcement.value = fill('announce', {
                done: String(done.value),
                total: String(total.value),
                percent: String(percent.value),
            });
        }
    }
    if (props.open && running.value) {
        timer = setTimeout(() => void poll(), 1000);
    }
}

watch(
    () => [props.open, props.jobIds] as const,
    () => {
        if (timer) clearTimeout(timer);
        jobs.value = [];
        announcedQuarter = 0;
        if (props.open && props.jobIds.length > 0) {
            startedAt = Date.now();
            void poll();
        }
    },
    { immediate: true },
);

onBeforeUnmount(() => {
    if (timer) clearTimeout(timer);
});

async function stop(): Promise<void> {
    if (busy.value) return;
    busy.value = true;
    try {
        for (const job of jobs.value) {
            if (job.status !== 'completed' && !job.cancel_requested) {
                await fetch(`/inbox/bulk-jobs/${job.id}/cancel`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-XSRF-TOKEN': props.xsrf(),
                    },
                });
            }
        }
    } finally {
        busy.value = false;
    }
}

async function retryFailed(): Promise<void> {
    if (busy.value) return;
    busy.value = true;
    try {
        const newIds: string[] = [];
        for (const job of jobs.value) {
            if (job.failed_count === 0) continue;
            const response = await fetch(`/inbox/bulk-jobs/${job.id}/retry`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': props.xsrf(),
                },
            });
            if (response.ok) {
                const body = (await response.json()) as { bulk_job_ids: string[] };
                newIds.push(...body.bulk_job_ids);
            }
        }
        if (newIds.length > 0) {
            emit('retried', newIds);
        }
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div
        v-if="open"
        data-testid="bulk-progress-drawer"
        role="region"
        :aria-label="title"
        class="fixed z-40 border shadow-lg"
        :class="mobile ? 'inset-x-0 bottom-0 rounded-t-xl' : 'bottom-4 rounded-lg'"
        :style="{
            background: 'var(--ds-panel-bg)',
            borderColor: 'var(--ds-border)',
            insetInlineEnd: mobile ? undefined : '16px',
            width: mobile ? undefined : '340px',
        }"
    >
        <!-- §13: the polite live region — 25/50/75/100 announcements. -->
        <p aria-live="polite" class="sr-only" data-testid="bulk-progress-announcer">
            {{ announcement }}
        </p>

        <div class="flex items-center gap-2 px-3.5 pt-3">
            <p
                class="min-w-0 flex-1 truncate text-sm font-semibold"
                data-testid="bulk-progress-title"
            >
                {{ title }}
            </p>
            <button
                type="button"
                data-testid="bulk-progress-close"
                class="rounded p-1 text-sm"
                :style="{ color: 'var(--ds-text-muted)' }"
                :aria-label="t.drawer_close"
                @click="emit('close')"
            >
                ✕
            </button>
        </div>

        <div class="px-3.5 pb-3.5">
            <p class="mt-1 text-xs" data-testid="bulk-progress-done">
                {{ fill('done_of', { done: String(done), total: String(total) }) }}
            </p>
            <p class="text-[11px]" :style="{ color: 'var(--ds-text-muted)' }">
                {{ percent }}%<template v-if="timeLeft"> · {{ timeLeft }}</template>
            </p>
            <div
                class="mt-1.5 h-1.5 overflow-hidden rounded-full"
                :style="{ background: 'var(--ds-inset-bg)' }"
            >
                <div
                    class="h-full rounded-full transition-all"
                    :style="{ width: percent + '%', background: 'var(--ds-text-ink)' }"
                ></div>
            </div>

            <!-- ✓ closed · ✕ failed · waiting -->
            <p class="mt-2 flex flex-wrap gap-x-3 text-[11.5px]" data-testid="bulk-progress-tally">
                <span>✓ {{ fill('tally_ok_' + action, { count: String(ok) }) }}</span>
                <span v-if="failedCount > 0" :style="{ color: 'var(--ds-danger-strong)' }">
                    ✕ {{ fill('tally_failed', { count: String(failedCount) }) }}
                </span>
                <span v-if="waiting > 0" :style="{ color: 'var(--ds-text-muted)' }">
                    {{ fill('tally_waiting', { count: String(waiting) }) }}
                </span>
            </p>

            <!-- Failures name the reason on the row. -->
            <ul
                v-if="failedRows.length > 0"
                data-testid="bulk-progress-failures"
                class="mt-2 max-h-40 space-y-1.5 overflow-y-auto rounded-md border p-2"
                :style="{ background: 'var(--ds-inset-bg)', borderColor: 'var(--ds-border-soft)' }"
            >
                <li
                    v-for="row in failedRows"
                    :key="row.number"
                    class="text-[11px]"
                    :data-failed-number="row.number"
                >
                    <span class="font-semibold" :style="{ color: 'var(--ds-danger-strong)' }">
                        ✕ #{{ row.number }}
                    </span>
                    <span class="block" :style="{ color: 'var(--ds-text-secondary)' }">
                        {{ reasonLabel(row.reason) }}
                    </span>
                </li>
            </ul>

            <div class="mt-3 flex items-center gap-2">
                <button
                    v-if="running"
                    type="button"
                    data-testid="bulk-progress-stop"
                    class="min-h-[44px] rounded-md border px-3 text-xs font-semibold"
                    :style="{
                        borderColor: 'var(--ds-border-strong)',
                        opacity: stopRequested || busy ? 0.4 : 1,
                    }"
                    :disabled="stopRequested || busy"
                    @click="void stop()"
                >
                    {{ stopRequested ? t.stopping : t.stop }}
                </button>
                <button
                    v-if="!running && failedCount > 0"
                    type="button"
                    data-testid="bulk-progress-retry"
                    class="min-h-[44px] rounded-md border px-3 text-xs font-semibold"
                    :style="{ borderColor: 'var(--ds-border-strong)', opacity: busy ? 0.4 : 1 }"
                    :disabled="busy"
                    @click="void retryFailed()"
                >
                    {{ fill('retry_failed', { count: String(failedCount) }) }}
                </button>
            </div>
        </div>
    </div>
</template>
