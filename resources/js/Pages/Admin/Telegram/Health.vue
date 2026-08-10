<script setup lang="ts">
// B6-S06 — Channel health & observability (FR-29/FR-30; rows F01–F12, §K).
// Ordered by "what is wrong, since when, what it is costing, what to press":
// the action sits with the symptom. The throughput bars use the ink ramp
// (never a state colour); every health word travels with its dot (§13);
// re-register stays OPERABLE at 375 behind the idempotent-effect
// confirmation (owner ruling, 3 Aug); everything configuration is read-only
// there. The viewer is masked for everyone — §K: keyboard traversal never
// enters a masked value.
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import HealthRollupPill from '../../../Components/Telegram/HealthRollupPill.vue';

type RawUpdate = { received_at: string; update: Record<string, unknown> };

const props = defineProps<{
    bot: {
        id: string;
        username: string;
        health_status: string;
        health_changed_at: string | null;
        pending_updates: number;
        last_error: string | null;
        last_inbound_at: string | null;
        last_polled_at: string | null;
        has_token: boolean;
        webhook_path: string | null;
    };
    metrics: {
        in_per_hour: number;
        out_per_hour: number;
        failure_rate_pct: number;
        failures_by_kind: Record<string, number>;
        retry_queue_depth: number;
    };
    hourly: { hour: string; in: number; out: number }[];
    connections: {
        account_name: string;
        state: string;
        can_reply: boolean;
        last_inbound: string | null;
        sends_blocked: number;
    }[];
    rate: { bot_per_second: unknown; chat_per_second: unknown; bot_max: number; chat_max: number };
    thresholds: { degraded_pct: unknown; down_pct: unknown; silent_minutes: unknown };
    staging: boolean;
    updates: RawUpdate[];
    t: Record<string, string>;
}>();

const wide = ref(true);
let mq: MediaQueryList | null = null;
const onMq = (e: MediaQueryListEvent | MediaQueryList): void => {
    wide.value = e.matches;
};
onMounted(() => {
    mq = window.matchMedia('(min-width: 1024px)');
    onMq(mq);
    mq.addEventListener('change', onMq);
});
onBeforeUnmount(() => mq?.removeEventListener('change', onMq));
const readonly = computed(() => !wide.value); // configuration only — re-register stays live

const down = computed(() => props.bot.health_status === 'down');
const rollupStatus = computed<'healthy' | 'degraded' | 'down'>(() =>
    props.bot.health_status === 'down' || props.bot.health_status === 'degraded'
        ? props.bot.health_status
        : 'healthy',
);
const confirmOpen = ref(false);
const viewerAnnouncement = ref('');

const fill = (template: string, values: Record<string, string | number>): string =>
    Object.entries(values).reduce((s, [k, v]) => s.replaceAll(`:${k}`, String(v)), template);

const minutesSince = (iso: string | null): number =>
    iso ? Math.max(0, Math.round((Date.now() - new Date(iso).getTime()) / 60000)) : 0;

const maxHourly = computed(() => Math.max(1, ...props.hourly.map((h) => h.in + h.out)));

// Ink ramp (the approved B5 chart set): full → mid → faint by magnitude,
// never a state colour. The collapse is stated in words underneath.
const barColor = (v: number): string => {
    const ratio = v / maxHourly.value;
    if (ratio > 0.66) return 'var(--ds-text-ink)';
    if (ratio > 0.15) return 'var(--ds-text-faint)';
    return 'var(--ds-border)';
};

const kindLabel = (kind: string): string => props.t[`kind_${kind}`] ?? props.t.kind_unknown ?? kind;
const failureEntries = computed(() => Object.entries(props.metrics.failures_by_kind));
const maxKind = computed(() => Math.max(1, ...failureEntries.value.map(([, n]) => n)));

// ── §K — viewer traversal (rows F06/F07) ─────────────────────────────────
const expanded = ref<number | null>(null);
const focused = ref(0);

function announceViewer(i: number): void {
    const key = expanded.value === i ? 'viewer_expanded' : 'viewer_position';
    viewerAnnouncement.value = fill(props.t[key] ?? '', {
        position: i + 1,
        count: props.updates.length,
    });
}

function onViewerKey(e: KeyboardEvent): void {
    const last = props.updates.length - 1;
    if (last < 0) return;
    if (e.key === 'ArrowDown') {
        e.preventDefault();
        focused.value = Math.min(focused.value + 1, last);
        announceViewer(focused.value);
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        focused.value = Math.max(focused.value - 1, 0);
        announceViewer(focused.value);
    } else if (e.key === 'Enter') {
        e.preventDefault();
        expanded.value = expanded.value === focused.value ? null : focused.value;
        announceViewer(focused.value);
    } else if (e.key === 'Home') {
        e.preventDefault();
        focused.value = 0;
        announceViewer(0);
    } else if (e.key === 'End') {
        e.preventDefault();
        focused.value = last;
        announceViewer(last);
    }
}

const updateType = (u: RawUpdate): string => {
    const keys = Object.keys(u.update).filter((k) => k !== 'update_id');
    return keys[0] ?? 'unknown';
};
const updateId = (u: RawUpdate): string => String(u.update.update_id ?? '');
</script>

<template>
    <div
        class="min-h-screen"
        :style="{ background: 'var(--ds-page-bg)', color: 'var(--ds-text-primary)' }"
    >
        <div class="mx-auto max-w-[1180px] px-4 py-6 sm:px-6">
            <div aria-live="polite" class="sr-only" data-testid="viewer-live">
                {{ viewerAnnouncement }}
            </div>

            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="text-xs" :style="{ color: 'var(--ds-text-muted)' }">
                        @{{ bot.username }}
                    </p>
                    <h1 class="text-lg font-semibold">{{ t.title }}</h1>
                </div>
                <div class="flex items-center gap-3">
                    <span
                        class="rounded border px-2 py-1 font-mono text-[10px]"
                        :style="{ borderColor: 'var(--ds-border)', color: 'var(--ds-text-muted)' }"
                        >telegram.health.view</span
                    >
                    <HealthRollupPill
                        :status="rollupStatus"
                        :label="t[`state_${rollupStatus}`] ?? rollupStatus"
                    />
                </div>
            </div>

            <!-- F01/F10 — the down banner with the inline re-register confirmation -->
            <section
                v-if="down"
                data-frame="F01-down"
                role="alert"
                class="mt-4 rounded-xl border p-4"
                :style="{
                    borderColor: 'var(--ds-danger-surface-border)',
                    background: 'var(--ds-danger-bg)',
                }"
            >
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <p class="text-sm font-semibold" :style="{ color: 'var(--ds-danger-text)' }">
                        {{
                            fill(t.down_banner ?? '', {
                                minutes: minutesSince(bot.health_changed_at),
                            })
                        }}
                    </p>
                    <button
                        type="button"
                        data-action="reregister"
                        class="min-h-[44px] rounded-lg px-4 text-sm font-semibold"
                        :style="{
                            background: 'var(--ds-danger-strong)',
                            color: 'var(--ds-accent-contrast)',
                        }"
                        @click="confirmOpen = !confirmOpen"
                    >
                        {{ t.reregister }}
                    </button>
                </div>
                <p class="mt-1 text-xs" :style="{ color: 'var(--ds-danger-text)' }">
                    {{ t.down_sub }}
                </p>

                <!-- the confirmation naming its idempotent effect FIRST (v2 ruling) -->
                <div
                    v-if="confirmOpen"
                    data-frame="F10-confirm"
                    class="mt-3 rounded-lg border p-3"
                    :style="{ borderColor: 'var(--ds-border)', background: 'var(--ds-panel-bg)' }"
                >
                    <h2 class="text-sm font-semibold">{{ t.reregister_title }}</h2>
                    <ul
                        class="mt-2 space-y-1.5 text-xs"
                        :style="{ color: 'var(--ds-text-secondary)' }"
                    >
                        <li class="flex gap-2">
                            <span aria-hidden="true" :style="{ color: 'var(--ds-success-text)' }"
                                >✓</span
                            >
                            <span
                                ><strong :style="{ color: 'var(--ds-text-primary)' }">{{
                                    t.reregister_safe
                                }}</strong></span
                            >
                        </li>
                        <li class="flex gap-2">
                            <span aria-hidden="true" :style="{ color: 'var(--ds-success-text)' }"
                                >✓</span
                            >
                            <span>{{
                                fill(t.reregister_redeliver ?? '', { count: bot.pending_updates })
                            }}</span>
                        </li>
                        <li class="flex gap-2">
                            <span aria-hidden="true" :style="{ color: 'var(--ds-text-muted)' }"
                                >↻</span
                            >
                            <span>{{ t.reregister_rotate }}</span>
                        </li>
                    </ul>
                    <div class="mt-3 flex gap-2">
                        <button
                            type="button"
                            class="min-h-[44px] rounded-lg px-4 text-sm font-semibold"
                            :style="{
                                background: 'var(--ds-accent)',
                                color: 'var(--ds-accent-contrast)',
                            }"
                        >
                            {{ t.reregister_now }}
                        </button>
                        <button
                            type="button"
                            class="min-h-[44px] rounded-lg px-4 text-sm"
                            :style="{ color: 'var(--ds-text-secondary)' }"
                            @click="confirmOpen = false"
                        >
                            {{ t.not_yet }}
                        </button>
                    </div>
                </div>
            </section>

            <!-- F09 — outage note: windows are frozen (FS-01, stated honestly) -->
            <section
                v-if="down"
                data-frame="F09"
                class="mt-3 rounded-lg border-s-4 border p-3 text-xs"
                :style="{
                    borderColor: 'var(--ds-border)',
                    borderInlineStartColor: 'var(--ds-health-down)',
                    background: 'var(--ds-panel-soft)',
                }"
            >
                <strong>{{ t.outage_frozen_title }}</strong>
                <span :style="{ color: 'var(--ds-text-muted)' }"> {{ t.outage_frozen_body }}</span>
            </section>

            <!-- F11 — credentials unavailable (FS-03) -->
            <section
                v-if="!bot.has_token"
                data-frame="F11"
                role="alert"
                class="mt-3 rounded-xl border p-4"
                :style="{
                    borderColor: 'var(--ds-danger-surface-border)',
                    background: 'var(--ds-danger-bg)',
                }"
            >
                <p class="text-sm font-semibold" :style="{ color: 'var(--ds-danger-text)' }">
                    {{ t.vault_title }}
                </p>
                <p class="mt-1 text-xs" :style="{ color: 'var(--ds-danger-text)' }">
                    {{ t.vault_body }}
                </p>
                <p class="mt-1 text-[11px]" :style="{ color: 'var(--ds-danger-text)' }">
                    {{ fill(t.vault_footer ?? '', { count: metrics.retry_queue_depth }) }}
                </p>
            </section>

            <div class="mt-4 grid gap-5" :class="wide ? 'grid-cols-[1.4fr_1fr]' : 'grid-cols-1'">
                <!-- F01 — throughput chart + stats -->
                <section
                    data-frame="F01"
                    class="rounded-xl border p-4"
                    :style="{ borderColor: 'var(--ds-border)', background: 'var(--ds-panel-bg)' }"
                >
                    <h2 class="text-sm font-semibold">{{ t.chart_title }}</h2>
                    <p class="text-[11px]" :style="{ color: 'var(--ds-text-muted)' }">
                        {{ t.chart_sub }}
                    </p>
                    <div class="mt-3 flex h-24 items-end gap-1" aria-hidden="true">
                        <div
                            v-for="h in hourly"
                            :key="h.hour"
                            class="flex-1 rounded-t"
                            :style="{
                                height: `${Math.max(4, ((h.in + h.out) / maxHourly) * 100)}%`,
                                background: barColor(h.in + h.out),
                            }"
                        ></div>
                        <p
                            v-if="!hourly.length"
                            class="text-[11px]"
                            :style="{ color: 'var(--ds-text-faint)' }"
                        >
                            —
                        </p>
                    </div>
                    <p
                        v-if="down"
                        class="mt-1 text-[11px]"
                        :style="{ color: 'var(--ds-text-muted)' }"
                    >
                        {{ t.chart_collapse_note }}
                    </p>

                    <div
                        class="mt-3 grid grid-cols-2 gap-3 border-t pt-3 sm:grid-cols-4"
                        :style="{ borderColor: 'var(--ds-border)' }"
                    >
                        <div>
                            <p
                                class="text-[10px] uppercase"
                                :style="{ color: 'var(--ds-text-muted)' }"
                            >
                                {{ t.stat_pending }}
                            </p>
                            <p
                                data-stat="pending"
                                class="text-lg font-semibold"
                                :style="{
                                    color:
                                        bot.pending_updates > 0
                                            ? 'var(--ds-danger-text)'
                                            : 'var(--ds-text-primary)',
                                }"
                            >
                                {{ bot.pending_updates }}
                            </p>
                        </div>
                        <div>
                            <p
                                class="text-[10px] uppercase"
                                :style="{ color: 'var(--ds-text-muted)' }"
                            >
                                {{ t.stat_in }}
                            </p>
                            <p class="text-lg font-semibold">{{ metrics.in_per_hour }}</p>
                        </div>
                        <div>
                            <p
                                class="text-[10px] uppercase"
                                :style="{ color: 'var(--ds-text-muted)' }"
                            >
                                {{ t.stat_out }}
                            </p>
                            <p class="text-lg font-semibold">{{ metrics.out_per_hour }}</p>
                        </div>
                        <div>
                            <p
                                class="text-[10px] uppercase"
                                :style="{ color: 'var(--ds-text-muted)' }"
                            >
                                {{ t.stat_failure }}
                            </p>
                            <p class="text-lg font-semibold">{{ metrics.failure_rate_pct }}%</p>
                            <p class="text-[10px]" :style="{ color: 'var(--ds-text-faint)' }">
                                {{ t.stat_failure_note }}
                            </p>
                        </div>
                    </div>
                </section>

                <div class="space-y-4">
                    <!-- F02 — webhook card -->
                    <section
                        data-frame="F02"
                        class="rounded-xl border p-4"
                        :style="{
                            borderColor: 'var(--ds-border)',
                            background: 'var(--ds-panel-bg)',
                        }"
                    >
                        <h2 class="text-sm font-semibold">{{ t.webhook_title }}</h2>
                        <dl class="mt-2 space-y-1.5 text-xs">
                            <div class="flex justify-between gap-3">
                                <dt :style="{ color: 'var(--ds-text-muted)' }">
                                    {{ t.webhook_status }}
                                </dt>
                                <dd
                                    class="font-medium"
                                    :style="{
                                        color: down
                                            ? 'var(--ds-danger-text)'
                                            : 'var(--ds-success-text)',
                                    }"
                                >
                                    {{
                                        down
                                            ? `! ${fill(t.webhook_no_traffic ?? '', { time: bot.last_inbound_at ?? '—' })}`
                                            : `✓ ${t.webhook_ok}`
                                    }}
                                </dd>
                            </div>
                            <div class="flex justify-between gap-3">
                                <dt :style="{ color: 'var(--ds-text-muted)' }">
                                    {{ t.webhook_address }}
                                </dt>
                                <dd class="truncate font-mono text-[11px]">
                                    {{ bot.webhook_path ?? '—' }}
                                </dd>
                            </div>
                            <div class="flex justify-between gap-3">
                                <dt :style="{ color: 'var(--ds-text-muted)' }">
                                    {{ t.webhook_last_error }}
                                </dt>
                                <dd>{{ bot.last_error ?? t.webhook_no_error }}</dd>
                            </div>
                            <div class="flex justify-between gap-3">
                                <dt :style="{ color: 'var(--ds-text-muted)' }">
                                    {{ t.webhook_secret }}
                                </dt>
                                <dd>{{ t.webhook_secret_set }}</dd>
                            </div>
                        </dl>
                        <!-- F04 — the values currently deciding the badge -->
                        <p
                            data-frame="F04"
                            class="mt-2 border-t pt-2 text-[10px]"
                            :style="{
                                borderColor: 'var(--ds-border)',
                                color: 'var(--ds-text-faint)',
                            }"
                        >
                            degraded ≥ {{ thresholds.degraded_pct }}% · down ≥
                            {{ thresholds.down_pct }}% · silent ≥ {{ thresholds.silent_minutes }}m
                        </p>
                        <button
                            v-if="!down"
                            type="button"
                            data-action="reregister"
                            class="mt-2 min-h-[44px] w-full rounded-lg border px-3 text-xs font-medium"
                            :style="{
                                borderColor: 'var(--ds-border-strong)',
                                color: 'var(--ds-text-secondary)',
                            }"
                            @click="confirmOpen = !confirmOpen"
                        >
                            {{ t.reregister_anyway }}
                        </button>
                    </section>

                    <!-- F01 — failures by kind + the parked-work card (component 07) -->
                    <section
                        data-frame="F01-failures"
                        class="rounded-xl border p-4"
                        :style="{
                            borderColor: 'var(--ds-border)',
                            background: 'var(--ds-panel-bg)',
                        }"
                    >
                        <h2 class="text-sm font-semibold">{{ t.failures_title }}</h2>
                        <p class="text-[11px]" :style="{ color: 'var(--ds-text-muted)' }">
                            {{ t.failures_sub }}
                        </p>
                        <ul class="mt-2 space-y-1.5">
                            <li v-for="[kind, n] in failureEntries" :key="kind" class="text-xs">
                                <span class="flex justify-between">
                                    <span>{{ kindLabel(kind) }}</span>
                                    <span class="font-medium">{{ n }}</span>
                                </span>
                                <span
                                    aria-hidden="true"
                                    class="mt-0.5 block h-1.5 rounded"
                                    :style="{
                                        width: `${(n / maxKind) * 100}%`,
                                        background: 'var(--ds-text-ink)',
                                        opacity: String(0.4 + 0.6 * (n / maxKind)),
                                    }"
                                ></span>
                            </li>
                            <li
                                v-if="!failureEntries.length"
                                class="text-xs"
                                :style="{ color: 'var(--ds-text-faint)' }"
                            >
                                —
                            </li>
                        </ul>
                        <!-- parked-work card: count → nothing dropped → resume -->
                        <div
                            data-frame="F01-parked"
                            class="mt-3 rounded-lg border p-3"
                            :style="{
                                borderColor: 'var(--ds-border)',
                                background: 'var(--ds-panel-soft)',
                            }"
                        >
                            <p class="text-xs font-semibold">
                                {{
                                    fill(t.waiting_body ?? '', { count: metrics.retry_queue_depth })
                                }}
                            </p>
                            <p
                                class="mt-0.5 text-[11px]"
                                :style="{ color: 'var(--ds-text-muted)' }"
                            >
                                {{ t.waiting_resume }}
                            </p>
                        </div>
                    </section>
                </div>
            </div>

            <div
                class="mt-5 grid gap-5"
                :class="wide ? 'grid-cols-[1.3fr_0.85fr_0.95fr]' : 'grid-cols-1'"
            >
                <!-- F03 — per-connection metrics -->
                <section
                    data-frame="F03"
                    class="rounded-xl border p-4"
                    :style="{ borderColor: 'var(--ds-border)', background: 'var(--ds-panel-bg)' }"
                >
                    <h2 class="text-sm font-semibold">{{ t.accounts_title }}</h2>
                    <!-- §13 (the S18 lesson): a region that can scroll must be
                         keyboard-reachable — tabindex + label, not a mouse-only pane. -->
                    <div
                        class="mt-2 overflow-x-auto"
                        tabindex="0"
                        role="region"
                        :aria-label="t.accounts_title"
                    >
                        <table class="w-full text-xs">
                            <thead>
                                <tr
                                    class="text-[10px] uppercase tracking-wide"
                                    :style="{ color: 'var(--ds-text-muted)' }"
                                >
                                    <th class="py-1.5 text-start">{{ t.col_account }}</th>
                                    <th class="py-1.5 text-start">{{ t.col_can_reply }}</th>
                                    <th class="py-1.5 text-start">{{ t.col_last_inbound }}</th>
                                    <th class="py-1.5 text-start">{{ t.col_sends_blocked }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y" :style="{ borderColor: 'var(--ds-border)' }">
                                <tr
                                    v-for="c in connections"
                                    :key="c.account_name"
                                    :data-connection="c.state"
                                >
                                    <td class="py-2 font-medium">{{ c.account_name }}</td>
                                    <td
                                        class="py-2"
                                        :style="{
                                            color: c.can_reply
                                                ? 'var(--ds-text-primary)'
                                                : 'var(--ds-warn-text)',
                                            fontWeight: c.can_reply ? undefined : '600',
                                        }"
                                    >
                                        {{
                                            c.can_reply
                                                ? `✓ ${t.can_reply_yes}`
                                                : `✕ ${t.can_reply_no}`
                                        }}
                                    </td>
                                    <td class="py-2" :style="{ color: 'var(--ds-text-muted)' }">
                                        {{ c.last_inbound ?? '—' }}
                                    </td>
                                    <td
                                        class="py-2"
                                        :style="{
                                            color:
                                                c.sends_blocked > 0
                                                    ? 'var(--ds-warn-text)'
                                                    : 'var(--ds-text-primary)',
                                            fontWeight: c.sends_blocked > 0 ? '600' : undefined,
                                        }"
                                    >
                                        {{ c.sends_blocked }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <!-- F05 — tools -->
                <section
                    data-frame="F05"
                    class="rounded-xl border p-4"
                    :style="{ borderColor: 'var(--ds-border)', background: 'var(--ds-panel-bg)' }"
                >
                    <h2 class="text-sm font-semibold">{{ t.tools_title }}</h2>
                    <div class="mt-2 space-y-2">
                        <button
                            type="button"
                            data-action="send-test"
                            class="min-h-[44px] w-full rounded-lg border px-3 text-start text-xs font-medium"
                            :style="{
                                borderColor: 'var(--ds-border-strong)',
                                color: 'var(--ds-text-secondary)',
                            }"
                        >
                            {{ t.tool_send_test }}
                        </button>
                        <button
                            type="button"
                            data-action="open-viewer"
                            class="min-h-[44px] w-full rounded-lg border px-3 text-start text-xs font-medium"
                            :style="{
                                borderColor: 'var(--ds-border-strong)',
                                color: 'var(--ds-text-secondary)',
                            }"
                        >
                            {{ t.tool_viewer }}
                        </button>
                        <!-- staging-only: disabled OUTSIDE staging, enabled on it (FR-30) -->
                        <button
                            type="button"
                            data-action="simulate-expiry"
                            :disabled="!staging || readonly"
                            class="min-h-[44px] w-full rounded-lg border px-3 text-start text-xs font-medium disabled:cursor-not-allowed disabled:opacity-50"
                            :style="{
                                borderColor: 'var(--ds-border-strong)',
                                color: 'var(--ds-text-secondary)',
                            }"
                        >
                            {{ t.tool_simulate }}
                        </button>
                    </div>
                </section>

                <!-- F08 — sending speed, read-only with platform ceilings -->
                <section
                    data-frame="F08"
                    class="rounded-xl border p-4"
                    :style="{ borderColor: 'var(--ds-border)', background: 'var(--ds-panel-bg)' }"
                >
                    <h2 class="text-sm font-semibold">{{ t.speed_title }}</h2>
                    <dl class="mt-2 space-y-1.5 text-xs">
                        <div class="flex justify-between">
                            <dt :style="{ color: 'var(--ds-text-muted)' }">{{ t.speed_bot }}</dt>
                            <dd class="font-medium">{{ rate.bot_per_second }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt :style="{ color: 'var(--ds-text-muted)' }">{{ t.speed_chat }}</dt>
                            <dd class="font-medium">{{ rate.chat_per_second }}</dd>
                        </div>
                    </dl>
                    <p class="mt-2 text-[11px]" :style="{ color: 'var(--ds-text-secondary)' }">
                        🔒 {{ fill(t.speed_max ?? '', { bot: rate.bot_max, chat: rate.chat_max }) }}
                    </p>
                    <p class="mt-1 text-[11px]" :style="{ color: 'var(--ds-text-faint)' }">
                        {{ t.speed_note }}
                    </p>
                </section>
            </div>

            <!-- 375 lock notice: config read-only, re-register stays live -->
            <p
                v-if="readonly"
                data-frame="F-readonly"
                class="mt-4 rounded-lg border px-3 py-2 text-xs"
                :style="{
                    borderColor: 'var(--ds-border-strong)',
                    background: 'var(--ds-inset-bg)',
                    color: 'var(--ds-text-muted)',
                }"
            >
                <span class="font-medium" :style="{ color: 'var(--ds-text-primary)' }">{{
                    t.readonly_notice
                }}</span>
                {{ t.readonly_body }}
            </p>

            <!-- F06/F07 — the raw-update viewer (masked; §K list traversal) -->
            <section
                data-frame="F06"
                class="mt-5 rounded-xl border p-4"
                :style="{ borderColor: 'var(--ds-border)', background: 'var(--ds-panel-bg)' }"
            >
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <h2 class="text-sm font-semibold">{{ t.viewer_title }}</h2>
                        <p class="text-[11px]" :style="{ color: 'var(--ds-text-muted)' }">
                            {{ fill(t.viewer_entries ?? '', { count: updates.length }) }}
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <span
                            class="rounded border px-2 py-1 font-mono text-[10px]"
                            :style="{
                                borderColor: 'var(--ds-border)',
                                color: 'var(--ds-text-muted)',
                            }"
                            >telegram.health.view</span
                        >
                        <button
                            type="button"
                            class="min-h-[44px] rounded-lg border px-3 text-xs font-medium"
                            :style="{
                                borderColor: 'var(--ds-border-strong)',
                                color: 'var(--ds-text-secondary)',
                            }"
                        >
                            {{ t.viewer_copy }}
                        </button>
                    </div>
                </div>

                <!-- F12 — the empty state with the test shortcut inside it -->
                <div
                    v-if="!updates.length"
                    data-frame="F12"
                    class="mt-3 rounded-lg border p-6 text-center"
                    :style="{ borderColor: 'var(--ds-border)', background: 'var(--ds-inset-bg)' }"
                >
                    <p class="text-sm font-semibold">{{ t.empty_title }}</p>
                    <p class="mt-1 text-xs" :style="{ color: 'var(--ds-text-muted)' }">
                        {{ t.empty_body }}
                    </p>
                    <button
                        type="button"
                        data-action="send-test"
                        class="mt-3 min-h-[44px] rounded-lg px-4 text-sm font-semibold"
                        :style="{
                            background: 'var(--ds-accent)',
                            color: 'var(--ds-accent-contrast)',
                        }"
                    >
                        {{ t.empty_cta }}
                    </button>
                </div>

                <ul
                    v-else
                    role="listbox"
                    :aria-label="t.viewer_title"
                    tabindex="0"
                    class="mt-3 divide-y rounded-lg border focus:outline-2"
                    :style="{ borderColor: 'var(--ds-border)' }"
                    @keydown="onViewerKey"
                >
                    <li
                        v-for="(u, i) in updates"
                        :key="`u-${i}`"
                        role="option"
                        :aria-selected="focused === i"
                        :data-update-entry="i"
                        class="px-3 py-2 text-xs"
                        :style="{
                            outline: focused === i ? '2px solid var(--ds-focus-ring)' : undefined,
                            outlineOffset: '-2px',
                            background: focused === i ? 'var(--ds-row-hover)' : undefined,
                        }"
                        @click="
                            focused = i;
                            expanded = expanded === i ? null : i;
                        "
                    >
                        <span class="flex items-center gap-2">
                            <span
                                class="font-mono text-[10px]"
                                :style="{ color: 'var(--ds-text-muted)' }"
                                >{{ u.received_at }}</span
                            >
                            <span class="font-medium">{{ updateType(u) }}</span>
                            <span
                                class="font-mono text-[10px]"
                                :style="{ color: 'var(--ds-text-faint)' }"
                                >{{ updateId(u) }}</span
                            >
                            <span
                                v-if="focused === i && expanded === i"
                                class="ms-auto text-[10px]"
                                :style="{ color: 'var(--ds-text-muted)' }"
                                >{{
                                    fill(t.viewer_expanded ?? '', {
                                        position: i + 1,
                                        count: updates.length,
                                    })
                                }}</span
                            >
                        </span>
                        <!-- masked fields stay greyed pills — the payload keeps its shape -->
                        <pre
                            v-if="expanded === i"
                            tabindex="0"
                            role="region"
                            :aria-label="`${updateType(u)} ${updateId(u)}`"
                            class="mt-2 overflow-x-auto rounded p-2 font-mono text-[10px] leading-4"
                            :style="{
                                background: 'var(--ds-inset-bg)',
                                color: 'var(--ds-text-secondary)',
                            }"
                            >{{ JSON.stringify(u.update, null, 2) }}</pre>
                    </li>
                </ul>

                <dl
                    class="mt-2 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-[11px]"
                    :style="{ color: 'var(--ds-text-muted)' }"
                >
                    <dt class="font-mono">↑ ↓</dt>
                    <dd>{{ t.viewer_key_move }}</dd>
                    <dt class="font-mono">Enter</dt>
                    <dd>{{ t.viewer_key_expand }}</dd>
                    <dt class="font-mono">Home / End</dt>
                    <dd>{{ t.viewer_key_ends }}</dd>
                </dl>

                <!-- F07 — what is hidden, and why -->
                <div
                    data-frame="F07"
                    class="mt-3 rounded-lg border p-3 text-xs"
                    :style="{ borderColor: 'var(--ds-border)', background: 'var(--ds-panel-soft)' }"
                >
                    <p class="font-medium">{{ t.masked_title }}</p>
                    <p class="mt-0.5" :style="{ color: 'var(--ds-text-muted)' }">
                        {{ t.masked_body }}
                    </p>
                </div>
            </section>
        </div>
    </div>
</template>
