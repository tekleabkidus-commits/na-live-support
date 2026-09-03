<script setup lang="ts">
// Doc 09 FR-11/FR-18/US-06 Thresholds — SESSION-F1 [B8]: the frozen
// B8-S05-thresholds-simulation-v2 anatomy. Four sliders with their consequence
// and a value line ("0.34 · published 0.30 · pending +0.04"); the simulation
// panel with published-vs-pending bar pairs and WORDED deltas; the X5 gate made
// physical — Publish is locked unless the last simulation matches the pending
// values exactly, a slider touch re-locks it and the stale banner discredits the
// on-screen numbers; the no-history tenant keeps both controls locked with their
// reasons; the flips drill-down (anonymized); per-endpoint overrides; the same
// result as a text table (§13). Publish = the BR-07 token door, unchanged.
import { computed, ref, watch } from 'vue';
import AiSettingsShell from '../../../Components/Ai/AiSettingsShell.vue';
import { adminCall } from './adminApi';

type Sim = {
    day: string;
    endpoint: string;
    before: Record<string, number>;
    after: Record<string, number>;
    deltas: Record<string, number>;
    total: number;
    token: string;
    values: Record<string, number>;
};
type Flip = {
    who: string;
    intent: string | null;
    confidence: number | null;
    at: string;
    from: string;
    to: string;
    conversation_id: string | null;
};
const KEYS = ['auto_resolve_min', 'handoff_below', 'spam_min', 'rg_min'] as const;
type Key = (typeof KEYS)[number];
const METRICS: { key: string; label: string }[] = [
    { key: 'eligible_auto_resolve', label: 'metric_auto_resolved' },
    { key: 'handoff_low_confidence', label: 'metric_handoffs' },
    { key: 'spam', label: 'metric_spam' },
    { key: 'rg_override', label: 'metric_rg' },
    { key: 'eligible', label: 'metric_eligible' },
];
const props = defineProps<{
    t: Record<string, string>;
    published: { payload: Record<string, unknown>; version?: number } | null;
    endpoints: { ref: string; label: string; channel: string; chatbot_enabled: boolean }[];
    history: { id: string; key: string; version: number; status: string; created_at: string }[];
    overrides?: Record<string, { version: number; values: Record<string, number> }>;
    rg?: { target: number; corpus: number };
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
const endpoint = ref('default');
const DEFAULTS: Record<Key, number> = {
    rg_min: 0.8,
    spam_min: 0.9,
    handoff_below: 0.3,
    auto_resolve_min: 0.85,
};
function publishedFor(ref_: string): Record<Key, number> | null {
    const o = props.overrides?.[ref_];
    if (o) return o.values as Record<Key, number>;
    if (ref_ === 'default' && props.published)
        return props.published.payload as unknown as Record<Key, number>;
    return null;
}
const publishedValues = computed<Record<Key, number>>(() => {
    const p = publishedFor(endpoint.value) ?? publishedFor('default');
    return Object.fromEntries(KEYS.map((k) => [k, Number(p?.[k] ?? DEFAULTS[k])])) as Record<
        Key,
        number
    >;
});
const publishedVersion = computed(
    () =>
        props.overrides?.[endpoint.value]?.version ??
        (endpoint.value === 'default' ? props.published?.version : undefined),
);
const values = ref<Record<Key, number>>({ ...publishedValues.value });
const enabled = ref(props.endpoints.find((e) => e.ref === 'default')?.chatbot_enabled ?? false);
const sim = ref<Sim | null>(null);
const simAt = ref<number | null>(null);
const computing = ref(false);
const message = ref('');
const showTable = ref(false);
const flips = ref<{ items: Flip[]; total: number } | null>(null);
const flipsOpen = ref(false);
const confirming = ref(false);
const tick = ref(Date.now());
window.setInterval(() => (tick.value = Date.now()), 10_000);

const simMatchesValues = computed(() => {
    const current = sim.value;
    return (
        current !== null &&
        KEYS.every((k) => Math.abs((current.values[k] ?? NaN) - values.value[k]) < 1e-9)
    );
});
const pendingChange = computed(() =>
    KEYS.some((k) => Math.abs(values.value[k] - publishedValues.value[k]) > 1e-9),
);
const noHistory = computed(() => sim.value !== null && sim.value.total === 0);
const stale = computed(() => sim.value !== null && !simMatchesValues.value);
const agoText = computed(() => {
    void tick.value;
    if (!simAt.value) return '';
    const s = Math.max(0, Math.round((Date.now() - simAt.value) / 1000));
    return s < 90
        ? fill(props.t.sim_ago_s, { s })
        : fill(props.t.sim_ago_m, { m: Math.round(s / 60) });
});
const nextVersion = computed(() => (publishedVersion.value ?? 0) + 1);
const endpointLabel = computed(
    () => props.endpoints.find((e) => e.ref === endpoint.value)?.label ?? endpoint.value,
);

watch(endpoint, (ref_) => {
    enabled.value = props.endpoints.find((e) => e.ref === ref_)?.chatbot_enabled ?? false;
    values.value = { ...publishedValues.value };
    sim.value = null;
    flips.value = null;
    flipsOpen.value = false;
});
watch(
    values,
    () => {
        if (!simMatchesValues.value) {
            flips.value = null;
            flipsOpen.value = false;
        }
    },
    { deep: true },
);

function pct(n: number, total: number): number {
    return total > 0 ? Math.round((n / total) * 100) : 0;
}
function deltaText(key: string): { text: string; tone: 'up' | 'down' | 'flat' } {
    const s = sim.value;
    if (!s) return { text: '', tone: 'flat' };
    const before = s.before[key] ?? 0;
    const after = s.after[key] ?? 0;
    const d = after - before;
    if (d === 0) return { text: fill(props.t.delta_none, { n: after }), tone: 'flat' };
    if (key === 'eligible_auto_resolve' || key === 'eligible') {
        return {
            text: `${pct(before, s.total)}% → ${pct(after, s.total)}%`,
            tone: d > 0 ? 'up' : 'down',
        };
    }
    const rel = before > 0 ? Math.round((d / before) * 100) : 100;
    return {
        text: fill(d > 0 ? props.t.delta_more : props.t.delta_fewer, { pct: Math.abs(rel) }),
        tone: d > 0 ? 'down' : 'up',
    };
}
function valueLine(k: Key): string {
    const pub = publishedValues.value[k];
    const cur = values.value[k];
    const diff = cur - pub;
    return Math.abs(diff) < 1e-9
        ? fill(props.t.value_unchanged, { published: pub.toFixed(2) })
        : fill(props.t.value_pending, {
              published: pub.toFixed(2),
              delta: (diff > 0 ? '+' : '−') + Math.abs(diff).toFixed(2),
          });
}
function fillPct(k: Key): string {
    return `${Math.round(values.value[k] * 100)}%`;
}

async function simulate(): Promise<void> {
    computing.value = true;
    message.value = '';
    try {
        const r = await adminCall<Sim>('/admin/ai/thresholds/simulate', {
            endpoint: endpoint.value,
            ...values.value,
        });
        sim.value = r;
        simAt.value = Date.now();
        flips.value = null;
    } catch (e) {
        message.value = (e as Error).message;
    } finally {
        computing.value = false;
    }
}
async function loadFlips(): Promise<void> {
    if (!sim.value) return;
    flipsOpen.value = true;
    if (flips.value) return;
    try {
        flips.value = await adminCall<{ items: Flip[]; total: number }>(
            '/admin/ai/thresholds/flips',
            {
                endpoint: endpoint.value,
                day: sim.value.day,
                ...values.value,
            },
        );
    } catch (e) {
        message.value = (e as Error).message;
    }
}
async function publish(): Promise<void> {
    if (!sim.value || !simMatchesValues.value) return;
    try {
        await adminCall('/admin/ai/thresholds/publish', {
            endpoint: endpoint.value,
            day: sim.value.day,
            token: sim.value.token,
            chatbot_enabled: enabled.value,
            ...values.value,
        });
        window.location.reload();
    } catch (e) {
        message.value = (e as Error).message;
        confirming.value = false;
    }
}
function discard(): void {
    values.value = { ...publishedValues.value };
}
function headline(): string {
    const s = sim.value;
    if (!s) return '';
    const b = pct(s.before.eligible_auto_resolve ?? 0, s.total);
    const a = pct(s.after.eligible_auto_resolve ?? 0, s.total);
    const hb = s.before.handoff_low_confidence ?? 0;
    const ha = s.after.handoff_low_confidence ?? 0;
    const rel = hb > 0 ? Math.round(((ha - hb) / hb) * 100) : ha > 0 ? 100 : 0;
    return fill(props.t.headline, {
        after: a,
        before: b,
        handoffs: (rel >= 0 ? '+' : '−') + Math.abs(rel),
    });
}
const overrideRows = computed(() =>
    props.endpoints.map((e) => {
        const v = publishedFor(e.ref);
        const base = publishedFor('default');
        return {
            ref: e.ref,
            label: e.ref === 'default' ? props.t.endpoint_default : e.label,
            override: e.ref !== 'default' && !!props.overrides?.[e.ref],
            values: Object.fromEntries(
                (['auto_resolve_min', 'handoff_below', 'rg_min'] as Key[]).map((k) => [
                    k,
                    {
                        n: Number((v ?? base)?.[k] ?? DEFAULTS[k]),
                        bold:
                            e.ref !== 'default' &&
                            !!v &&
                            Math.abs(Number(v[k]) - Number(base?.[k] ?? DEFAULTS[k])) > 1e-9,
                    },
                ]),
            ) as Record<string, { n: number; bold: boolean }>,
        };
    }),
);
function firstWithoutOverride(): string | null {
    return (
        props.endpoints.find((e) => e.ref !== 'default' && !props.overrides?.[e.ref])?.ref ?? null
    );
}
const OVERRIDE_COLS = ['auto_resolve_min', 'handoff_below', 'rg_min'] as const; // registry keys, verbatim as drawn
function deltaPlain(key: string): string {
    const d = sim.value?.deltas[key] ?? 0;
    if (d === 0) return props.t.delta_none_plain ?? '';
    return (d > 0 ? '+' : '') + d + ' ' + (props.t.conversations_word ?? '');
}
const viewFlipsText = computed(() =>
    fill(props.t.view_flips, {
        n:
            sim.value && sim.value.deltas.eligible_auto_resolve !== undefined
                ? Object.values(sim.value.deltas).reduce((a, b) => a + Math.abs(b), 0) / 2
                : 0,
    }),
);
</script>

<template>
    <AiSettingsShell :t="t" :page="t.thresholds_page" :setup="setup">
        <template #chip>
            <label class="ai-vchip th-endpoint"
                ><span>{{ t.endpoint }}:</span>
                <select v-model="endpoint" data-testid="endpoint-select" class="th-select">
                    <option v-for="e in endpoints" :key="e.ref" :value="e.ref">
                        {{ e.ref === 'default' ? t.endpoint_default : e.label }} —
                        {{ e.chatbot_enabled ? t.on : t.off }}
                    </option>
                </select></label
            >
            <span class="ai-vchip" data-testid="thresholds-published-chip">{{
                publishedVersion
                    ? fill(t.chip_published, { version: publishedVersion })
                    : t.chip_platform_defaults
            }}</span>
        </template>

        <p
            v-if="message"
            role="status"
            data-testid="thresholds-message"
            class="mx-4 mt-3 rounded border px-2 py-1 text-xs"
            :style="{ borderColor: 'var(--ds-border-strong)' }"
        >
            {{ message }}
        </p>

        <div class="th-body">
            <!-- ── E01: the four sliders, each with its consequence and value line ── -->
            <div class="th-sliders">
                <div v-for="k in KEYS" :key="k" class="th-srow" :data-testid="'th-row-' + k">
                    <div class="th-nm">
                        <label :for="'slider-' + k">{{ k }}</label>
                        <span v-if="k === 'rg_min'" class="th-tag th-tag-rg">{{ t.rg_tag }}</span>
                    </div>
                    <p class="th-ds">{{ t['desc_' + k] }}</p>
                    <div class="th-track" aria-hidden="true">
                        <div class="th-fill" :style="{ width: fillPct(k) }"></div>
                        <div
                            class="th-knob"
                            :style="{ insetInlineStart: `calc(${fillPct(k)} - 7px)` }"
                        ></div>
                    </div>
                    <input
                        :id="'slider-' + k"
                        v-model.number="values[k]"
                        type="range"
                        min="0"
                        max="1"
                        step="0.01"
                        class="th-range"
                        :data-testid="'slider-' + k"
                        :aria-valuetext="values[k].toFixed(2)"
                    />
                    <div class="th-sval">
                        {{ values[k].toFixed(2) }} <small>{{ valueLine(k) }}</small>
                    </div>
                    <!-- E06: the RG row carries the precision readout — the NFR-07 target stated (the live figure is the harness's, see deviations) -->
                    <div
                        v-if="k === 'rg_min'"
                        class="th-gate th-gate-ok"
                        data-testid="th-rg-precision"
                    >
                        <span aria-hidden="true">✓</span>
                        <span
                            ><b>{{
                                fill(t.rg_precision_title, {
                                    value: values[k].toFixed(2),
                                    target: (rg?.target ?? 0.9).toFixed(2),
                                })
                            }}</b>
                            {{ fill(t.rg_precision_body, { corpus: rg?.corpus ?? 50 }) }}</span
                        >
                    </div>
                </div>
            </div>

            <!-- ── E02/E04/E07: the simulation panel and the X5 gate ── -->
            <div class="th-simcol">
                <section class="th-sim" :aria-label="t.simulation" data-testid="simulation-panel">
                    <div class="th-sh">
                        <span class="th-st">{{
                            sim
                                ? fill(t.sim_title_n, { n: sim.total.toLocaleString() })
                                : t.sim_title
                        }}</span>
                        <span class="th-ss" data-testid="th-sim-status">
                            <template v-if="computing">{{ t.sim_computing }}</template>
                            <template v-else-if="sim && simMatchesValues">{{
                                fill(t.sim_fresh, { ago: agoText })
                            }}</template>
                            <template v-else-if="sim && stale">{{ t.sim_stale_short }}</template>
                        </span>
                    </div>

                    <!-- no-history (B8-D13): the note renders as drawn; the controls follow BR-07 (the chatbot's enable flag rides this publish) -->
                    <div v-if="noHistory" class="th-nohist" data-testid="th-no-history">
                        <b>{{ t.nohist_title }}</b> {{ t.nohist_body }}
                    </div>

                    <template v-if="sim || computing">
                        <div v-if="!showTable" data-testid="simulation-results">
                            <div v-for="m in METRICS" :key="m.key" class="th-mrow">
                                <span class="th-mn">{{ t[m.label] }}</span>
                                <span class="th-bars" aria-hidden="true">
                                    <span class="th-bar th-bar-before"
                                        ><i
                                            :style="{
                                                width: sim
                                                    ? pct(sim.before[m.key] ?? 0, sim.total) + '%'
                                                    : '0%',
                                            }"
                                        ></i
                                    ></span>
                                    <span v-if="computing || !sim" class="th-sk"></span>
                                    <span v-else class="th-bar th-bar-after"
                                        ><i
                                            :style="{
                                                width: pct(sim.after[m.key] ?? 0, sim.total) + '%',
                                            }"
                                        ></i
                                    ></span>
                                </span>
                                <span
                                    class="th-delta"
                                    :class="'th-delta-' + deltaText(m.key).tone"
                                    :data-testid="'delta-' + m.key"
                                >
                                    {{ computing || !sim ? '…' : deltaText(m.key).text }}
                                </span>
                            </div>
                            <div class="th-legend" aria-hidden="true">
                                <span
                                    ><span
                                        class="th-sw"
                                        :style="{ background: 'var(--ds-border-strong)' }"
                                    ></span
                                    >{{ t.legend_published }}</span
                                >
                                <span
                                    ><span
                                        class="th-sw"
                                        :style="{ background: 'var(--ds-ai-edge)' }"
                                    ></span
                                    >{{ t.legend_pending }}</span
                                >
                            </div>
                        </div>
                        <!-- E07: the same simulation as a text table — the record -->
                        <table v-else-if="sim" class="ai-tt" data-testid="simulation-table">
                            <thead>
                                <tr>
                                    <th>{{ t.col_metric }}</th>
                                    <th>{{ t.col_published }}</th>
                                    <th>{{ t.col_pending }}</th>
                                    <th>{{ t.col_change }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="m in METRICS" :key="m.key">
                                    <td>{{ t[m.label] }}</td>
                                    <td class="num">
                                        {{ (sim.before[m.key] ?? 0).toLocaleString() }} ({{
                                            pct(sim.before[m.key] ?? 0, sim.total)
                                        }}%)
                                    </td>
                                    <td class="num">
                                        {{ (sim.after[m.key] ?? 0).toLocaleString() }} ({{
                                            pct(sim.after[m.key] ?? 0, sim.total)
                                        }}%)
                                    </td>
                                    <td class="num" :data-testid="'delta-' + m.key">
                                        {{ deltaPlain(m.key) }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </template>
                    <p v-else class="th-ds" style="margin: 0">{{ t.sim_help }}</p>
                </section>

                <!-- E04: the gate — stale locks, fresh unlocks -->
                <div
                    v-if="stale"
                    class="th-gate th-gate-stale"
                    role="status"
                    data-testid="th-stale"
                >
                    <span aria-hidden="true">⚠</span>
                    <span
                        ><b>{{ t.stale_title }}</b> {{ t.stale_body }}</span
                    >
                </div>
                <div
                    v-else-if="sim && simMatchesValues"
                    class="th-gate th-gate-ok"
                    role="status"
                    data-testid="th-fresh"
                >
                    <span aria-hidden="true">✓</span>
                    <span>{{
                        fill(t.fresh_body, { ago: agoText, n: sim.total.toLocaleString() })
                    }}</span>
                </div>

                <div class="th-acts">
                    <button
                        v-if="sim && simMatchesValues"
                        type="button"
                        class="ai-btn"
                        data-testid="th-flips-open"
                        @click="loadFlips"
                    >
                        {{ viewFlipsText }}
                    </button>
                    <button
                        v-if="sim"
                        type="button"
                        class="ai-btn"
                        data-testid="th-view-table"
                        :aria-pressed="showTable"
                        @click="showTable = !showTable"
                    >
                        {{ showTable ? t.view_bars : t.view_table }}
                    </button>
                </div>
                <div class="th-acts th-acts-end">
                    <button
                        v-if="pendingChange"
                        type="button"
                        class="ai-btn"
                        data-testid="th-discard"
                        @click="discard"
                    >
                        {{ t.discard_changes }}
                    </button>
                    <button
                        type="button"
                        data-testid="simulate"
                        class="ai-btn"
                        :class="{ 'ai-btn-pri': stale || !sim }"
                        :disabled="computing"
                        @click="simulate"
                    >
                        {{ t.simulate }}
                    </button>
                    <button
                        type="button"
                        data-testid="thresholds-publish"
                        class="ai-btn ai-btn-pri"
                        :disabled="!simMatchesValues || computing"
                        :title="simMatchesValues ? '' : t.simulate_first"
                        @click="confirming = true"
                    >
                        {{
                            simMatchesValues
                                ? fill(t.publish_v, { version: nextVersion })
                                : t.publish_locked_sim
                        }}
                    </button>
                </div>

                <!-- E03: the conversations that flip -->
                <section
                    v-if="flipsOpen"
                    class="th-flips"
                    :aria-label="t.flips_title"
                    data-testid="th-flips"
                >
                    <div class="th-flips-h">
                        <b>{{ t.flips_title }}</b>
                        <span class="ai-vchip">{{ t.anonymized }}</span>
                    </div>
                    <p v-if="!flips" class="th-ds">{{ t.loading }}</p>
                    <template v-else>
                        <div v-for="(f, i) in flips.items" :key="i" class="th-flip">
                            <div>
                                <div class="th-flip-who">{{ f.who }}</div>
                                <div class="th-flip-what">
                                    {{ f.intent ?? '—' }} · {{ t.confidence_word }}
                                    {{ f.confidence === null ? '—' : f.confidence.toFixed(2) }} ·
                                    {{ f.at }}
                                </div>
                            </div>
                            <div>
                                <span class="th-tag">{{ t['outcome_' + f.from] ?? f.from }}</span>
                                <span class="th-arrow" aria-hidden="true">→</span>
                                <span class="th-tag">{{ t['outcome_' + f.to] ?? f.to }}</span>
                            </div>
                        </div>
                        <p class="th-ds">
                            {{
                                fill(t.flips_more, {
                                    n: Math.max(0, flips.total - flips.items.length),
                                })
                            }}
                        </p>
                    </template>
                </section>
            </div>
        </div>

        <!-- ── E05: per-endpoint overrides ── -->
        <section class="th-overrides" :aria-label="t.overrides_title" data-testid="th-overrides">
            <span class="ai-lab">{{ t.overrides_title }}</span>
            <table class="ai-tt">
                <thead>
                    <tr>
                        <th>{{ t.endpoint }}</th>
                        <th v-for="k in OVERRIDE_COLS" :key="k">{{ k }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in overrideRows" :key="row.ref">
                        <td>
                            <b>{{ row.label }}</b>
                            <span v-if="row.override" class="th-tag">{{ t.override_tag }}</span>
                        </td>
                        <td
                            v-for="k in ['auto_resolve_min', 'handoff_below', 'rg_min']"
                            :key="k"
                            class="num"
                        >
                            <b v-if="row.values[k]?.bold">{{ row.values[k]?.n.toFixed(2) }}</b
                            ><template v-else>{{ row.values[k]?.n.toFixed(2) }}</template>
                        </td>
                        <td>
                            <button
                                type="button"
                                class="ai-btn"
                                :data-testid="'th-edit-' + row.ref"
                                @click="endpoint = row.ref"
                            >
                                {{ t.edit }}
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
            <div class="th-acts">
                <button
                    v-if="firstWithoutOverride()"
                    type="button"
                    class="ai-btn"
                    data-testid="th-add-override"
                    @click="endpoint = firstWithoutOverride() ?? 'default'"
                >
                    {{ t.add_override }}
                </button>
            </div>
            <p class="th-ds">{{ t.overrides_note }}</p>
        </section>

        <section class="th-versions" :aria-label="t.history">
            <span class="ai-lab">{{ t.history }}</span>
            <div v-for="h in history" :key="h.id" class="ai-vrow">
                <div>
                    <b>v{{ h.version }}</b>
                    <span class="ai-who"
                        >{{ h.key }} · {{ t['status_' + h.status] ?? h.status }} ·
                        {{ h.created_at.slice(0, 16).replace('T', ' ') }}</span
                    >
                </div>
            </div>
        </section>

        <!-- the publish confirm repeats the simulation's headline -->
        <div v-if="confirming && sim" class="ai-modal-backdrop" @click.self="confirming = false">
            <div
                class="ai-modal"
                role="dialog"
                aria-modal="true"
                :aria-label="fill(t.th_publish_confirm_title, { version: nextVersion })"
                data-testid="thresholds-publish-dialog"
            >
                <h2>{{ fill(t.th_publish_confirm_title, { version: nextVersion }) }}</h2>
                <p>
                    {{
                        fill(t.th_publish_confirm_body, {
                            endpoint: endpointLabel,
                            headline: headline(),
                            prev: publishedVersion ?? 0,
                        })
                    }}
                </p>
                <div class="ai-modal-acts">
                    <button type="button" class="ai-btn" @click="confirming = false">
                        {{ t.cancel }}
                    </button>
                    <button
                        type="button"
                        class="ai-btn ai-btn-pri"
                        data-testid="thresholds-publish-confirm"
                        @click="publish"
                    >
                        {{ t.publish_now }}
                    </button>
                </div>
            </div>
        </div>
    </AiSettingsShell>
</template>

<style scoped>
.th-endpoint {
    display: inline-flex;
    gap: 6px;
    align-items: center;
}
.th-select {
    background: transparent;
    border: 0;
    font: inherit;
    color: inherit;
    max-width: 240px;
}
.th-body {
    display: grid;
    grid-template-columns: 1fr 460px;
}
.th-sliders {
    padding: 16px;
    border-inline-end: 1px solid var(--ds-border);
}
.th-simcol {
    padding: 16px;
}
.th-srow {
    padding: 12px 0;
    border-top: 1px solid var(--ds-border);
}
.th-srow:first-of-type {
    border-top: 0;
    padding-top: 4px;
}
.th-nm {
    font-size: 12.5px;
    font-weight: 600;
    color: var(--ds-text-primary);
    display: flex;
    gap: 6px;
    align-items: center;
    flex-wrap: wrap;
}
.th-ds {
    font-size: 11px;
    color: var(--ds-text-muted);
    margin: 2px 0 8px;
    line-height: 1.5;
}
.th-track {
    position: relative;
    height: 4px;
    border-radius: 2px;
    background: var(--ds-inset-bg);
    max-width: 420px;
}
.th-fill {
    position: absolute;
    inset-inline-start: 0;
    top: 0;
    height: 4px;
    border-radius: 2px;
    background: var(--ds-ai-edge);
}
.th-knob {
    position: absolute;
    top: -5px;
    width: 14px;
    height: 14px;
    border-radius: 50%;
    background: var(--ds-panel-bg);
    border: 2px solid var(--ds-ai-edge);
}
.th-range {
    width: 100%;
    max-width: 420px;
    display: block;
    margin-top: 8px;
    accent-color: var(--ds-ai-edge);
}
.th-sval {
    font-size: 11px;
    font-weight: 700;
    color: var(--ds-text-primary);
    margin-top: 6px;
}
.th-sval small {
    font-weight: 400;
    color: var(--ds-text-muted);
}
.th-tag {
    font-size: 9.5px;
    font-weight: 700;
    border-radius: 4px;
    padding: 2px 7px;
    background: var(--ds-panel-soft);
    border: 1px solid var(--ds-border);
    color: var(--ds-text-secondary);
}
.th-tag-rg {
    background: var(--ds-status-closed-bg);
    color: var(--ds-status-closed-fg);
}
.th-sim {
    background: var(--ds-panel-soft);
    border: 1px solid var(--ds-border);
    border-radius: 8px;
    padding: 12px;
}
.th-sh {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    gap: 8px;
    margin-bottom: 10px;
    flex-wrap: wrap;
}
.th-st {
    font-size: 12px;
    font-weight: 600;
    color: var(--ds-text-primary);
}
.th-ss {
    font-size: 10.5px;
    color: var(--ds-text-muted);
}
.th-mrow {
    display: grid;
    grid-template-columns: 130px 1fr 92px;
    gap: 8px;
    align-items: center;
    padding: 6px 0;
    border-top: 1px solid var(--ds-border);
}
.th-mrow:first-of-type {
    border-top: 0;
}
.th-mn {
    font-size: 11.5px;
    color: var(--ds-text-secondary);
}
.th-bars {
    display: flex;
    flex-direction: column;
    gap: 3px;
}
.th-bar {
    height: 7px;
    border-radius: 3px;
    background: var(--ds-inset-bg);
    position: relative;
    overflow: hidden;
    display: block;
}
.th-bar i {
    position: absolute;
    inset-inline-start: 0;
    top: 0;
    bottom: 0;
    border-radius: 3px;
    display: block;
}
.th-bar-before i {
    background: var(--ds-border-strong);
}
.th-bar-after i {
    background: var(--ds-ai-edge);
}
.th-sk {
    height: 7px;
    border-radius: 3px;
    background: var(--ds-inset-bg);
    display: block;
}
.th-delta {
    font-size: 11px;
    font-weight: 700;
    text-align: end;
}
.th-delta-up {
    color: var(--ds-success-text);
}
.th-delta-down {
    color: var(--ds-warn-text);
}
.th-delta-flat {
    color: var(--ds-text-muted);
}
.th-legend {
    display: flex;
    gap: 12px;
    font-size: 10px;
    color: var(--ds-text-muted);
    margin-top: 8px;
}
.th-sw {
    display: inline-block;
    width: 10px;
    height: 7px;
    border-radius: 3px;
    vertical-align: middle;
    margin-inline-end: 4px;
}
.th-nohist {
    font-size: 11.5px;
    line-height: 1.55;
    color: var(--ds-text-secondary);
}
.th-gate {
    display: flex;
    gap: 8px;
    align-items: flex-start;
    border-radius: 8px;
    padding: 9px 11px;
    font-size: 11.5px;
    line-height: 1.5;
    margin-top: 12px;
}
.th-gate-ok {
    background: var(--ds-success-bg);
    color: var(--ds-success-text);
    border: 1px solid var(--ds-border);
}
.th-gate-stale {
    background: var(--ds-warn-bg);
    color: var(--ds-warn-text);
    border: 1px solid var(--ds-warn-border);
}
.th-srow .th-gate {
    margin-top: 8px;
    max-width: 420px;
}
.th-acts {
    display: flex;
    gap: 8px;
    margin-top: 12px;
    align-items: center;
    flex-wrap: wrap;
}
.th-acts-end {
    justify-content: flex-end;
}
.th-flips {
    margin-top: 12px;
    border: 1px solid var(--ds-border);
    border-radius: 8px;
    padding: 6px 12px 12px;
}
.th-flips-h {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 8px;
    padding: 6px 0;
    font-size: 11.5px;
}
.th-flip {
    border-top: 1px solid var(--ds-border);
    padding: 8px 2px;
    font-size: 12px;
    display: flex;
    justify-content: space-between;
    gap: 10px;
    align-items: baseline;
}
.th-flip-who {
    color: var(--ds-text-primary);
    font-weight: 500;
}
.th-flip-what {
    font-size: 10.5px;
    color: var(--ds-text-muted);
}
.th-arrow {
    font-weight: 700;
    color: var(--ds-ai-fg);
    margin: 0 4px;
}
.th-overrides,
.th-versions {
    padding: 12px 16px 16px;
    border-top: 1px solid var(--ds-border);
}
@media (max-width: 1023px) {
    .th-body {
        grid-template-columns: 1fr;
    }
    .th-sliders {
        border-inline-end: 0;
        border-bottom: 1px solid var(--ds-border);
    }
    .th-acts-end {
        flex-direction: column;
        align-items: stretch;
    }
}
</style>
