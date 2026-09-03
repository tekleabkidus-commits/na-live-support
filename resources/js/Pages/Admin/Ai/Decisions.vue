<script setup lang="ts">
// Doc 09 FR-20/FR-16/SEC-06/SS-02 Decisions — SESSION-F1 [B8]: the frozen
// B8-S09-decision-log-v1 anatomy. The enriched table (outcome as the row's own
// ending, confidence as text, model · config version, the 30-day payload chip as
// a property of the ROW), composable filter chips with an honest empty state,
// the record view an ai.decisions.view viewer gets (I03 — the payload door is a
// different key and does not exist on this screen), and the quality loop with
// exactly TWO tabs (ruling Q2 — no RG material anywhere here): the top-10
// unanswerable questions with the one-click Doc 10 draft, and the calibration
// curve with its text table and its finding in words. The read door is the
// curated allowlist, unchanged (cursor pagination).
import { computed, onMounted, ref } from 'vue';
import AiSettingsShell from '../../../Components/Ai/AiSettingsShell.vue';
import { adminCall } from './adminApi';

type Row = Record<string, string | number | null>;
type Bucket = {
    bucket: string;
    total: number;
    resolved: number;
    reopened: number;
    handoff: number;
    discarded: number;
};
const props = defineProps<{
    t: Record<string, string>;
    counts?: { today: number };
    config_versions?: Record<string, string>;
    unanswerable?: {
        intent: string;
        count: number;
        no_grounding: number;
        locked: number;
        sample_conversation_ids: string[];
    }[];
    calibration?: Bucket[];
    kb_categories?: { id: string; name: string }[];
    setup?: {
        kb_published: boolean;
        persona_published: boolean;
        categories_ai_usable: boolean;
        kb_url: string;
        categories_url: string;
    } | null;
}>();

function fill(template: string | undefined, vars: Record<string, string | number>): string {
    let out = template ?? '';
    for (const [k, v] of Object.entries(vars)) out = out.replaceAll(':' + k, String(v));
    return out;
}
const rows = ref<Row[]>([]);
const cursor = ref<string | null>(null);
const surface = ref('');
const task = ref('');
const action = ref('');
const outcome = ref('');
const endpoint = ref('');
const failed = ref(false);
const loading = ref(false);
const selected = ref<Row | null>(null);
const tab = ref<'unanswerable' | 'calibration'>('unanswerable');
const draftCategory = ref(props.kb_categories?.[0]?.id ?? '');
const drafting = ref<string | null>(null);
const drafted = ref<Record<string, string>>({});
const message = ref('');

async function load(reset = false): Promise<void> {
    if (reset) {
        rows.value = [];
        cursor.value = null;
    }
    loading.value = true;
    const q = new URLSearchParams({ limit: '50' });
    if (cursor.value) q.set('cursor', cursor.value);
    if (surface.value) q.set('surface', surface.value);
    try {
        const r = await fetch(`/api/staff/ai/decisions?${q}`, {
            headers: { Accept: 'application/json' },
        });
        if (!r.ok) throw new Error(String(r.status));
        const json = (await r.json()) as { data: Row[]; next_cursor: string | null };
        rows.value.push(...json.data);
        cursor.value = json.next_cursor;
        failed.value = false;
    } catch {
        failed.value = true;
    } finally {
        loading.value = false;
    }
}
onMounted(() => void load());

const distinct = (key: string): string[] =>
    Array.from(new Set(rows.value.map((r) => String(r[key] ?? '')).filter((v) => v !== ''))).sort();
const filtered = computed(() =>
    rows.value.filter(
        (r) =>
            (!task.value || r.task === task.value) &&
            (!action.value || r.action === action.value) &&
            (!outcome.value || String(r.outcome ?? '') === outcome.value) &&
            (!endpoint.value || String(r.endpoint_ref ?? '') === endpoint.value),
    ),
);
const emptyReason = computed(() => {
    if (filtered.value.length || !rows.value.length) return '';
    const parts: string[] = [];
    if (task.value)
        parts.push(
            fill(props.t.empty_task, {
                task: task.value,
                n: rows.value.filter((r) => r.task === task.value).length,
            }),
        );
    if (outcome.value)
        parts.push(fill(props.t.empty_outcome, { outcome: outcomeWord(outcome.value) }));
    return parts.join(' ');
});
function hhmmss(iso: string | number | null | undefined): string {
    const s = String(iso ?? '');
    const d = new Date(s);
    if (Number.isNaN(d.getTime())) return s.slice(11, 19);
    const older = Date.now() - d.getTime() > 86_400_000;
    const time = `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}:${String(d.getSeconds()).padStart(2, '0')}`;
    return older ? `${d.toISOString().slice(0, 10)} ${time.slice(0, 5)}` : time;
}
function olderThan30d(r: Row): boolean {
    const d = new Date(String(r.created_at ?? ''));
    return !Number.isNaN(d.getTime()) && Date.now() - d.getTime() > 30 * 86_400_000;
}
function band(c: number): string {
    return props.t['band_' + (c >= 0.8 ? 'high' : c >= 0.5 ? 'medium' : 'low')] ?? '';
}
function confidenceText(r: Row): string {
    const c = typeof r.confidence === 'number' ? r.confidence : Number(r.confidence);
    return Number.isFinite(c) && r.confidence !== null ? `${c.toFixed(2)} · ${band(c)}` : '—';
}
function outcomeWord(o: string): string {
    return props.t['oc_' + o] ?? o;
}
function outcomeTone(o: string | null): string {
    if (!o) return 'plain';
    if (['resolved', 'handoff_agreed_routing', 'inserted_unedited', 'csat_scored'].includes(o))
        return 'good';
    if (['resolved_then_reopened', 'handoff_rerouted'].includes(o)) return 'warn';
    return 'aic';
}
function configLabel(r: Row): string {
    const id = String(r.config_version_id ?? '');
    return props.config_versions?.[id] ?? (id ? id.slice(0, 8) : '—');
}
function fmtLatency(ms: string | number | null | undefined): string {
    const n = Number(ms);
    return Number.isFinite(n) ? (n >= 1000 ? `${(n / 1000).toFixed(1)} s` : `${n} ms`) : '—';
}
function tokens(r: Row): string {
    return String((Number(r.tokens_in) || 0) + (Number(r.tokens_out) || 0));
}
async function draftFrom(intent: string): Promise<void> {
    drafting.value = intent;
    message.value = '';
    try {
        const r = await adminCall<{ variant_id?: string; article_id?: string }>(
            '/admin/kb/drafts/from-conversations',
            {
                intent,
                category_id: draftCategory.value,
                audience: 'public',
                language: 'en',
            },
        );
        drafted.value[intent] = r.variant_id
            ? `/admin/kb/variants/${r.variant_id}/edit`
            : '/admin/kb';
    } catch (e) {
        message.value = (e as Error).message;
    } finally {
        drafting.value = null;
    }
}
// ── calibration: the curve (illustration), the table (record), the finding (the point)
const curve = computed(() => (props.calibration ?? []).filter((b) => b.total > 0));
const points = computed(() =>
    curve.value.map((b) => {
        const [lo, hi] = b.bucket.split('-').map(Number);
        const stated = ((lo ?? 0) + (hi ?? 0)) / 2;
        const success = b.total ? b.resolved / b.total : 0;
        return {
            bucket: b.bucket,
            stated,
            success,
            total: b.total,
            gap: Math.round((success - stated) * 100),
        };
    }),
);
const svgPoints = computed(() =>
    points.value.map((p) => `${30 + p.stated * 170},${160 - p.success * 150}`).join(' '),
);
const finding = computed(() => {
    if (!points.value.length) return props.t.calibration_empty ?? '';
    const worst = points.value.reduce((a, b) => (Math.abs(b.gap) > Math.abs(a.gap) ? b : a));
    const avg = Math.round(points.value.reduce((a, p) => a + p.gap, 0) / points.value.length);
    return fill(
        avg < -1 ? props.t.finding_over : avg > 1 ? props.t.finding_under : props.t.finding_ok,
        { bucket: worst.bucket, gap: Math.abs(worst.gap), avg: Math.abs(avg) },
    );
});
// the sign glyph lives in script — the string-lint gate reads templates by tag boundaries
function gapSign(gap: number): string {
    return gap > 0 ? '+' : gap < 0 ? '−' : '';
}
</script>

<template>
    <AiSettingsShell :t="t" :page="t.decisions_page" :crumb-root="t.crumb_ai" :setup="setup">
        <template #chip>
            <span class="ai-vchip" data-testid="dl-chip">{{
                fill(t.today_count, { n: (counts?.today ?? rows.length).toLocaleString() })
            }}</span>
        </template>

        <p v-if="failed" role="status" class="mx-4 mt-3 text-xs">{{ t.load_failed }}</p>
        <p
            v-if="message"
            role="status"
            class="mx-4 mt-3 rounded border px-2 py-1 text-xs"
            :style="{ borderColor: 'var(--ds-border-strong)' }"
        >
            {{ message }}
        </p>

        <!-- I01: composable filter chips -->
        <div class="dl-filters" data-testid="dl-filters">
            <label class="dl-fchip" :class="{ on: surface }"
                ><span>{{ t.surface }}:</span>
                <select
                    v-model="surface"
                    data-testid="decisions-surface"
                    class="dl-fsel"
                    @change="load(true)"
                >
                    <option value="">{{ t.all }}</option>
                    <option value="chatbot">{{ t.surface_chatbot }}</option>
                    <option value="copilot">{{ t.surface_copilot }}</option>
                    <option value="supervisor">{{ t.surface_supervisor }}</option>
                </select></label
            >
            <label
                v-for="[key, model, label] in [
                    ['task', task, t.col_task],
                    ['action', action, t.col_action],
                    ['outcome', outcome, t.col_outcome],
                    ['endpoint_ref', endpoint, t.endpoint],
                ] as const"
                :key="key"
                class="dl-fchip"
                :class="{ on: model }"
                ><span>{{ label }}:</span>
                <select
                    :value="model"
                    :data-testid="'dl-filter-' + key"
                    class="dl-fsel"
                    @change="
                        key === 'task'
                            ? (task = ($event.target as HTMLSelectElement).value)
                            : key === 'action'
                              ? (action = ($event.target as HTMLSelectElement).value)
                              : key === 'outcome'
                                ? (outcome = ($event.target as HTMLSelectElement).value)
                                : (endpoint = ($event.target as HTMLSelectElement).value)
                    "
                >
                    <option value="">{{ t.all }}</option>
                    <option v-for="v in distinct(key)" :key="v" :value="v">
                        {{ key === 'outcome' ? outcomeWord(v) : v }}
                    </option>
                </select></label
            >
            <span class="dl-fchip on">{{ t.today }}</span>
        </div>

        <!-- I01/I02: the enriched table; rows become cards at 375 -->
        <div class="dl-tablewrap">
            <table class="ai-tt dl-table" data-testid="decisions-table">
                <thead>
                    <tr>
                        <th>{{ t.col_time }}</th>
                        <th>{{ t.surface }}</th>
                        <th>{{ t.col_task }}</th>
                        <th>{{ t.col_model }}</th>
                        <th>{{ t.col_intent }}</th>
                        <th>{{ t.col_confidence }}</th>
                        <th>{{ t.col_action }}</th>
                        <th>{{ t.col_outcome }}</th>
                        <th>{{ t.col_tokens }}</th>
                        <th>{{ t.col_latency }}</th>
                        <th>{{ t.col_payload }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="r in filtered"
                        :key="String(r.id)"
                        class="dl-row"
                        :class="{ 'dl-dim': olderThan30d(r) }"
                        :data-testid="'dl-row-' + String(r.id)"
                        tabindex="0"
                        @click="selected = r"
                        @keydown.enter="selected = r"
                    >
                        <td class="ai-mono" :data-label="t.col_time">{{ hhmmss(r.created_at) }}</td>
                        <td :data-label="t.surface">
                            {{ t['surface_' + String(r.surface)] ?? r.surface }}
                        </td>
                        <td class="ai-mono" :data-label="t.col_task">{{ r.task }}</td>
                        <td class="ai-mono" :data-label="t.col_model">
                            {{ r.model ?? '—' }} · {{ configLabel(r) }}
                        </td>
                        <td :data-label="t.col_intent">{{ r.intent ?? '—' }}</td>
                        <td :data-label="t.col_confidence">{{ confidenceText(r) }}</td>
                        <td class="ai-mono" :data-label="t.col_action">{{ r.action ?? '—' }}</td>
                        <td :data-label="t.col_outcome">
                            <span
                                class="dl-oc"
                                :class="
                                    'dl-oc-' +
                                    outcomeTone(r.outcome === null ? null : String(r.outcome))
                                "
                                >{{
                                    r.outcome ? outcomeWord(String(r.outcome)) : t.oc_pending
                                }}</span
                            >
                        </td>
                        <td class="ai-mono num" :data-label="t.col_tokens">{{ tokens(r) }}</td>
                        <td class="ai-mono num" :data-label="t.col_latency">
                            {{ fmtLatency(r.latency_ms) }}
                        </td>
                        <td :data-label="t.col_payload">
                            <span class="ai-vchip">{{
                                olderThan30d(r) ? t.payload_hash : t.payload_30d
                            }}</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div
            v-if="emptyReason || (!loading && !rows.length && !failed)"
            class="dl-empty"
            data-testid="dl-empty"
        >
            {{ rows.length ? t.empty_filtered : t.empty_none }}<br /><span class="dl-empty-sub">{{
                emptyReason || t.empty_none_sub
            }}</span>
        </div>
        <div class="dl-foot">
            <span>{{ t.pagination_note }}</span>
            <span class="dl-ec11" data-testid="dl-ec11-note">{{ t.ec11_note }}</span>
            <button
                v-if="cursor"
                type="button"
                class="ai-btn"
                data-testid="dl-load-more"
                :disabled="loading"
                @click="load()"
            >
                {{ t.load_more }}
            </button>
        </div>

        <!-- I03: the record an ai.decisions.view viewer gets — the payload door is a different key (SEC-06) -->
        <div v-if="selected" class="ai-modal-backdrop" @click.self="selected = null">
            <div
                class="ai-modal dl-record"
                role="dialog"
                aria-modal="true"
                :aria-label="t.record_title"
                data-testid="dl-record"
            >
                <div class="ai-crumb dl-record-crumb">
                    <b
                        >{{ t.decision }} {{ String(selected.id).slice(0, 4) }}…{{
                            String(selected.id).slice(-4)
                        }}</b
                    >
                    · {{ hhmmss(selected.created_at) }} · {{ selected.task }}
                </div>
                <span class="ai-lab">{{ t.record_title }}</span>
                <p class="dl-record-body">
                    {{ (t.col_intent ?? '').toLowerCase() }} {{ selected.intent ?? '—' }} ·
                    {{ (t.col_confidence ?? '').toLowerCase() }} {{ confidenceText(selected) }} ·
                    {{ (t.col_action ?? '').toLowerCase() }} {{ selected.action ?? '—' }} ·
                    {{ (t.col_outcome ?? '').toLowerCase() }}
                    {{ selected.outcome ? outcomeWord(String(selected.outcome)) : t.oc_pending }} ·
                    {{ tokens(selected) }} {{ t.tokens_word }} ·
                    {{ fmtLatency(selected.latency_ms) }} · {{ configLabel(selected) }}
                </p>
                <p class="ai-who">{{ t.record_note }}</p>
                <div class="ai-modal-acts">
                    <button type="button" class="ai-btn" @click="selected = null">
                        {{ t.close }}
                    </button>
                </div>
            </div>
        </div>

        <!-- I04/I05/I06: the quality loop — exactly TWO tabs, and no RG material anywhere on this screen -->
        <section class="dl-quality" :aria-label="t.quality_title" data-testid="dl-quality">
            <div class="dl-tabs" role="tablist">
                <button
                    type="button"
                    role="tab"
                    :aria-selected="tab === 'unanswerable'"
                    class="dl-tab"
                    :class="{ on: tab === 'unanswerable' }"
                    data-testid="dl-tab-unanswerable"
                    @click="tab = 'unanswerable'"
                >
                    {{ t.tab_unanswerable }}
                </button>
                <button
                    type="button"
                    role="tab"
                    :aria-selected="tab === 'calibration'"
                    class="dl-tab"
                    :class="{ on: tab === 'calibration' }"
                    data-testid="dl-tab-calibration"
                    @click="tab = 'calibration'"
                >
                    {{ t.tab_calibration }}
                </button>
            </div>
            <div
                v-if="tab === 'unanswerable'"
                role="tabpanel"
                class="dl-panel"
                data-testid="dl-panel-unanswerable"
            >
                <p class="ai-who">{{ t.unanswerable_note }}</p>
                <div v-if="kb_categories?.length" class="dl-draftcat">
                    <label class="text-xs"
                        ><span class="ai-lab">{{ t.draft_category }}</span>
                        <select
                            v-model="draftCategory"
                            class="ai-in dl-catsel"
                            data-testid="dl-draft-category"
                        >
                            <option v-for="c in kb_categories" :key="c.id" :value="c.id">
                                {{ c.name }}
                            </option>
                        </select></label
                    >
                </div>
                <div
                    v-for="(q, i) in unanswerable ?? []"
                    :key="q.intent"
                    class="dl-qrow"
                    :data-testid="'dl-q-' + q.intent"
                >
                    <div>
                        <div class="dl-qq">{{ i + 1 }} · {{ q.intent }}</div>
                        <div class="dl-qs">
                            {{
                                fill(t.q_stats, {
                                    n: q.count,
                                    ng: q.no_grounding,
                                    locked: q.locked,
                                })
                            }}
                        </div>
                    </div>
                    <a
                        v-if="drafted[q.intent]"
                        :href="drafted[q.intent]"
                        class="ai-btn ai-btn-ai"
                        :data-testid="'dl-drafted-' + q.intent"
                        >{{ t.draft_open }}</a
                    >
                    <button
                        v-else
                        type="button"
                        class="ai-btn ai-btn-ai"
                        :data-testid="'dl-draft-' + q.intent"
                        :disabled="drafting !== null || !draftCategory"
                        @click="draftFrom(q.intent)"
                    >
                        {{ drafting === q.intent ? t.drafting : t.draft_kb }}
                    </button>
                </div>
                <p v-if="!(unanswerable ?? []).length" class="ai-who">{{ t.unanswerable_empty }}</p>
                <p class="ai-who dl-qfoot">{{ t.unanswerable_foot }}</p>
            </div>
            <div
                v-else
                role="tabpanel"
                class="dl-panel dl-calib"
                data-testid="dl-panel-calibration"
            >
                <svg viewBox="0 0 220 190" class="dl-curve" role="img" :aria-label="t.curve_aria">
                    <line x1="30" y1="160" x2="200" y2="160" class="dl-axis" />
                    <line x1="30" y1="160" x2="30" y2="10" class="dl-axis" />
                    <line
                        x1="30"
                        y1="160"
                        x2="200"
                        y2="10"
                        class="dl-axis"
                        stroke-dasharray="4 3"
                    />
                    <polyline
                        v-if="points.length"
                        :points="svgPoints"
                        fill="none"
                        class="dl-line"
                        stroke-width="2"
                    />
                    <circle
                        v-for="p in points"
                        :key="p.bucket"
                        :cx="30 + p.stated * 170"
                        :cy="160 - p.success * 150"
                        r="3.5"
                        class="dl-pt"
                    />
                    <text x="115" y="180" font-size="8" text-anchor="middle" class="dl-axistext">
                        {{ t.axis_stated }}
                    </text>
                    <text
                        x="14"
                        y="85"
                        font-size="8"
                        transform="rotate(-90 14 85)"
                        text-anchor="middle"
                        class="dl-axistext"
                    >
                        {{ t.axis_actual }}
                    </text>
                    <text x="196" y="20" font-size="7.5" text-anchor="end" class="dl-axistext">
                        {{ t.perfect_calibration }}
                    </text>
                </svg>
                <div>
                    <table class="ai-tt" data-testid="dl-calibration-table">
                        <thead>
                            <tr>
                                <th>{{ t.col_stated }}</th>
                                <th>{{ t.col_decisions }}</th>
                                <th>{{ t.col_succeeded }}</th>
                                <th>{{ t.col_gap }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="p in points" :key="p.bucket">
                                <td class="ai-mono">{{ p.bucket }}</td>
                                <td class="ai-mono num">{{ p.total }}</td>
                                <td class="ai-mono num">{{ Math.round(p.success * 100) }}%</td>
                                <td class="ai-mono num">
                                    {{ gapSign(p.gap) }}{{ Math.abs(p.gap) }} {{ t.pts }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <p class="dl-finding" data-testid="dl-finding">
                        <b>{{ t.reading }}</b> {{ finding }}
                    </p>
                    <p class="ai-who">{{ t.success_def }}</p>
                </div>
            </div>
        </section>
    </AiSettingsShell>
</template>

<style scoped>
.dl-filters {
    display: flex;
    gap: 6px;
    padding: 10px 16px;
    border-bottom: 1px solid var(--ds-border);
    flex-wrap: wrap;
}
.dl-fchip {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 10.5px;
    font-weight: 600;
    border: 1px solid var(--ds-border);
    border-radius: 999px;
    padding: 3px 9px;
    color: var(--ds-text-muted);
    background: var(--ds-panel-bg);
}
.dl-fchip.on {
    color: var(--ds-text-primary);
    border-color: var(--ds-border-strong);
    background: var(--ds-panel-soft);
}
.dl-fsel {
    background: transparent;
    border: 0;
    font: inherit;
    color: inherit;
}
.dl-tablewrap {
    overflow-x: auto;
}
.dl-row {
    cursor: pointer;
}
.dl-row:hover td,
.dl-row:focus td {
    background: var(--ds-row-hover);
}
.dl-dim td {
    color: var(--ds-text-muted);
}
.dl-oc {
    font-size: 10px;
    font-weight: 700;
    border-radius: 4px;
    padding: 2px 7px;
    border: 1px solid var(--ds-border);
    white-space: nowrap;
}
.dl-oc-good {
    background: var(--ds-success-bg);
    color: var(--ds-success-text);
}
.dl-oc-warn {
    background: var(--ds-warn-bg);
    color: var(--ds-warn-text);
    border-color: var(--ds-warn-border);
}
.dl-oc-aic {
    background: var(--ds-ai-bg);
    color: var(--ds-ai-fg);
    border-color: var(--ds-ai-border);
}
.dl-oc-plain {
    background: var(--ds-panel-soft);
    color: var(--ds-text-secondary);
}
.dl-empty {
    padding: 22px 16px;
    text-align: center;
    font-size: 12.5px;
    color: var(--ds-text-secondary);
}
.dl-empty-sub {
    font-size: 10.5px;
    color: var(--ds-text-muted);
}
.dl-foot {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    padding: 9px 16px;
    font-size: 10.5px;
    color: var(--ds-text-muted);
    border-top: 1px solid var(--ds-border);
    flex-wrap: wrap;
}
.dl-record {
    max-width: 560px;
    padding: 0 0 12px;
}
.dl-record-crumb {
    margin-bottom: 12px;
}
.dl-record .ai-lab,
.dl-record .dl-record-body,
.dl-record .ai-who,
.dl-record .ai-modal-acts {
    margin-inline: 16px;
}
.dl-record-body {
    font-size: 12px;
    line-height: 1.7;
    margin-top: 4px;
}
.dl-quality {
    border-top: 1px solid var(--ds-border);
}
.dl-tabs {
    display: flex;
    gap: 2px;
    border-bottom: 1px solid var(--ds-border);
    padding: 0 16px;
}
.dl-tab {
    font-size: 11.5px;
    font-weight: 600;
    padding: 9px 12px;
    color: var(--ds-text-muted);
    border: 0;
    border-bottom: 2px solid transparent;
    background: transparent;
}
.dl-tab.on {
    color: var(--ds-text-primary);
    border-bottom-color: var(--ds-text-primary);
}
.dl-panel {
    padding: 10px 16px 16px;
}
.dl-draftcat {
    margin: 8px 0;
}
.dl-catsel {
    width: auto;
}
.dl-qrow {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    padding: 9px 2px;
    border-top: 1px solid var(--ds-border);
}
.dl-qq {
    font-size: 12.5px;
    font-weight: 600;
    color: var(--ds-text-primary);
}
.dl-qs {
    font-size: 10.5px;
    color: var(--ds-text-muted);
    margin-top: 2px;
}
.dl-qfoot {
    padding-top: 8px;
}
.dl-calib {
    display: grid;
    grid-template-columns: 380px 1fr;
    gap: 20px;
}
.dl-curve {
    width: 100%;
    max-width: 360px;
    height: auto;
}
.dl-axis {
    stroke: var(--ds-border-strong);
    stroke-width: 1;
}
.dl-line {
    stroke: var(--ds-ai-edge);
}
.dl-pt {
    fill: var(--ds-ai-edge);
}
.dl-axistext {
    fill: var(--ds-text-label);
}
.dl-finding {
    font-size: 11.5px;
    line-height: 1.55;
    color: var(--ds-text-secondary);
    margin: 10px 0 6px;
}
@media (max-width: 1023px) {
    .dl-calib {
        grid-template-columns: 1fr;
    }
}
@media (max-width: 639px) {
    /* §G: rows become cards */
    .dl-table thead {
        display: none;
    }
    .dl-table tr {
        display: block;
        border: 1px solid var(--ds-border);
        border-radius: 8px;
        margin: 8px 12px;
        padding: 6px 4px;
    }
    .dl-table td {
        display: flex;
        gap: 8px;
        border: 0;
        padding: 3px 8px;
        font-size: 11.5px;
    }
    .dl-table td::before {
        content: attr(data-label);
        color: var(--ds-text-label);
        font-size: 10px;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        min-width: 72px;
    }
    .dl-qrow {
        flex-direction: column;
        align-items: stretch;
    }
}
.dl-ec11 {
    display: block;
    margin-top: 4px;
}
</style>
