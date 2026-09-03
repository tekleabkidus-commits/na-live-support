<script setup lang="ts">
// Doc 12 §8 CSAT settings (S12 step 14, redrawn to the frozen B9-S12 record in SESSION-F1 [B12]):
// the three registered csat.* keys as SETTINGS ROWS in the B6-S11 contract — label · key ·
// description · typed control · provenance · reset — with the CONSEQUENCE BEFORE SAVE; the shipped
// button labels per language (overrides are Doc 16's); what a survey is, the Doc 15 events and the
// designed absences (BR-07); the customer-facing renders — the Telegram 1-tap prompt / window
// closed / thanks in both modes, the widget star row with radio semantics and its expired state —
// drawn from the shipped strings; 375 read posture. Door unchanged (the three keys, audited).
import { computed, ref } from 'vue';
import WorkflowShell from '../../Components/Workflow/WorkflowShell.vue';
import WorkflowRefusal from '../../Components/Workflow/WorkflowRefusal.vue';
import { post, tr } from '../../Components/Workflow/workflowApi';

type Prov = {
    updated_by_name?: string | null;
    updated_at?: string | null;
    version?: number | null;
} | null;
const props = defineProps<{
    copy: Record<string, unknown>;
    can: Record<string, boolean>;
    settings: { cooldown_hours: number; window_hours: number; comment_prompt_enabled: boolean };
    labels: Record<string, Record<string, string>>;
    defaults?: { cooldown_hours: number; window_hours: number; comment_prompt_enabled: boolean };
    provenance?: Record<string, Prov>;
}>();
// eslint-disable-next-line @typescript-eslint/no-explicit-any
const c = props.copy as any;
const readonly = ref(window.innerWidth < 1024); // L05 — phone-readable
const form = ref({ ...props.settings });
const refusal = ref('');
const saved = ref('');
const lang = ref<'en' | 'am'>('en');
const canEdit = computed(() => props.can['settings.edit'] === true && !readonly.value);
const defaults = computed(
    () => props.defaults ?? { cooldown_hours: 24, window_hours: 72, comment_prompt_enabled: true },
);
const KEYS = {
    cooldown_hours: 'csat.cooldown.hours',
    window_hours: 'csat.window.hours',
    comment_prompt_enabled: 'csat.comment_prompt.enabled',
} as const;
type K = keyof typeof KEYS;

const changed = computed(() =>
    (Object.keys(KEYS) as K[]).filter((k) => form.value[k] !== props.settings[k]),
);
function isModified(k: K): boolean {
    return props.settings[k] !== defaults.value[k];
}
function fmtDate(iso: string | null | undefined): string {
    if (!iso) return '';
    const d = new Date(iso.replace(' ', 'T'));
    return Number.isNaN(d.getTime())
        ? iso
        : d.toLocaleString(undefined, {
              day: 'numeric',
              month: 'short',
              year: 'numeric',
              hour: '2-digit',
              minute: '2-digit',
          });
}
function provLine(k: K): string {
    const p = props.provenance?.[KEYS[k]] ?? null;
    const def =
        typeof defaults.value[k] === 'boolean'
            ? defaults.value[k]
                ? c.common.on
                : c.common.off
            : String(defaults.value[k]);
    if (!p?.updated_at) return tr(c.csat.never_changed, { default: def });
    return tr(p.updated_by_name ? c.csat.changed_by : c.csat.changed_on, {
        name: p.updated_by_name ?? '',
        date: fmtDate(p.updated_at),
        default: def,
    });
}
function reset(k: K): void {
    (form.value as Record<K, number | boolean>)[k] = defaults.value[k];
}
const summaryLine = computed(() =>
    tr(c.csat.summary, { cooldown: form.value.cooldown_hours, window: form.value.window_hours }),
);
const cooldownConsequence = computed(() => {
    if (form.value.cooldown_hours === props.settings.cooldown_hours) return '';
    return tr(
        form.value.cooldown_hours > props.settings.cooldown_hours
            ? c.csat.cooldown_more
            : c.csat.cooldown_fewer,
        {
            from: props.settings.cooldown_hours,
            to: form.value.cooldown_hours,
        },
    );
});
const windowConsequence = computed(() =>
    tr(c.csat.window_consequence, { hour: form.value.window_hours + 1 }),
);
async function save(): Promise<void> {
    refusal.value = '';
    saved.value = '';
    const result = await post('/admin/workflow/csat', { ...form.value });
    if (!result.ok) {
        refusal.value = tr(c.common.refused, { detail: result.refusal });
        return;
    }
    saved.value = c.common.saved;
    window.setTimeout(() => window.location.reload(), 600);
}
function discard(): void {
    form.value = { ...props.settings };
    saved.value = '';
}
const L = (key: string): string => props.labels[lang.value]?.[key] ?? props.labels.en?.[key] ?? key;
const stars = [1, 2, 3, 4, 5];
const LANGS = ['en', 'am'] as const;
const EVENTS = [
    { name: 'csat.sent', key: 'ev_sent' },
    { name: 'csat.received', key: 'ev_received' },
    { name: 'csat.expired', key: 'ev_expired' },
];
const saveLabel = computed(() =>
    changed.value.length ? tr(c.csat.save_n, { n: changed.value.length }) : c.common.save,
);
</script>

<template>
    <WorkflowShell
        :copy="c"
        active="csat"
        :title="c.csat.title"
        :summary="summaryLine"
        :read-note="readonly ? c.csat.readnote : undefined"
    >
        <div class="cs-grid" :class="{ ro: readonly }">
            <!-- L01 · the settings rows -->
            <section
                class="bo-pane cs-settings"
                data-testid="wf-csat-settings"
                :aria-label="c.csat.title"
            >
                <div class="bo-pane-head">
                    <h3>{{ c.csat.settings_title }}</h3>
                    <span class="bo-hint">{{ summaryLine }}</span>
                </div>

                <!-- cooldown -->
                <div
                    class="cs-row"
                    :class="{ mod: isModified('cooldown_hours') }"
                    data-testid="wf-csat-row-cooldown"
                >
                    <div class="lbl">
                        <label for="cs-cooldown">{{ c.csat.cooldown_label }}</label>
                        <span v-if="isModified('cooldown_hours')" class="bo-chip warn">{{
                            c.csat.modified
                        }}</span>
                        <code class="bo-mono">{{ KEYS.cooldown_hours }}</code>
                        <p class="bo-hint">
                            {{ tr(c.csat.cooldown_desc, { default: defaults.cooldown_hours }) }}
                        </p>
                        <p class="bo-hint prov" data-testid="wf-csat-prov-cooldown">
                            {{ provLine('cooldown_hours') }}
                        </p>
                    </div>
                    <div class="ctl">
                        <span class="row"
                            ><input
                                id="cs-cooldown"
                                v-model.number="form.cooldown_hours"
                                type="number"
                                min="1"
                                max="168"
                                :disabled="!canEdit"
                                data-testid="wf-csat-cooldown"
                                class="bo-in num"
                            />
                            <span class="bo-hint">{{ c.csat.hours }}</span></span
                        >
                        <button
                            v-if="canEdit && form.cooldown_hours !== defaults.cooldown_hours"
                            type="button"
                            class="bo-link"
                            data-testid="wf-csat-reset-cooldown"
                            @click="reset('cooldown_hours')"
                        >
                            {{ c.csat.reset }}
                        </button>
                        <p
                            v-if="cooldownConsequence"
                            class="bo-warnbox cs-conseq"
                            data-testid="wf-csat-consequence"
                        >
                            <b>{{ c.csat.consequence_title }}</b> {{ cooldownConsequence }}
                            {{ c.csat.consequence_doc15 }}
                        </p>
                    </div>
                </div>

                <!-- window -->
                <div
                    class="cs-row"
                    :class="{ mod: isModified('window_hours') }"
                    data-testid="wf-csat-row-window"
                >
                    <div class="lbl">
                        <label for="cs-window">{{ c.csat.window_label }}</label>
                        <span v-if="isModified('window_hours')" class="bo-chip warn">{{
                            c.csat.modified
                        }}</span>
                        <code class="bo-mono">{{ KEYS.window_hours }}</code>
                        <p class="bo-hint">
                            {{ tr(c.csat.window_desc, { default: defaults.window_hours }) }}
                        </p>
                        <p class="bo-hint prov">{{ provLine('window_hours') }}</p>
                    </div>
                    <div class="ctl">
                        <span class="row"
                            ><input
                                id="cs-window"
                                v-model.number="form.window_hours"
                                type="number"
                                min="1"
                                max="336"
                                :disabled="!canEdit"
                                data-testid="wf-csat-window"
                                class="bo-in num"
                            />
                            <span class="bo-hint">{{ c.csat.hours }}</span></span
                        >
                        <button
                            v-if="canEdit && form.window_hours !== defaults.window_hours"
                            type="button"
                            class="bo-link"
                            @click="reset('window_hours')"
                        >
                            {{ c.csat.reset }}
                        </button>
                        <p class="bo-hint cs-conseq">
                            <b>{{ windowConsequence }}</b>
                            {{ tr(c.csat.window_gets, { telegram: L('window_closed') }) }}
                        </p>
                    </div>
                </div>

                <!-- comment prompt -->
                <div
                    class="cs-row"
                    :class="{ mod: isModified('comment_prompt_enabled') }"
                    data-testid="wf-csat-row-comment"
                >
                    <div class="lbl">
                        <label for="cs-comment">{{ c.csat.comment_label }}</label>
                        <span v-if="isModified('comment_prompt_enabled')" class="bo-chip warn">{{
                            c.csat.modified
                        }}</span>
                        <code class="bo-mono">{{ KEYS.comment_prompt_enabled }}</code>
                        <p class="bo-hint">{{ c.csat.comment_desc }}</p>
                        <p class="bo-hint prov">{{ provLine('comment_prompt_enabled') }}</p>
                    </div>
                    <div class="ctl">
                        <label class="bo-check"
                            ><input
                                id="cs-comment"
                                v-model="form.comment_prompt_enabled"
                                type="checkbox"
                                role="switch"
                                :aria-checked="form.comment_prompt_enabled ? 'true' : 'false'"
                                :disabled="!canEdit"
                                data-testid="wf-csat-comment"
                            />
                            {{ form.comment_prompt_enabled ? c.common.on : c.common.off }}</label
                        >
                        <button
                            v-if="
                                canEdit &&
                                form.comment_prompt_enabled !== defaults.comment_prompt_enabled
                            "
                            type="button"
                            class="bo-link"
                            @click="reset('comment_prompt_enabled')"
                        >
                            {{ c.csat.reset }}
                        </button>
                    </div>
                </div>

                <!-- labels per language -->
                <div class="cs-row">
                    <div class="lbl">
                        <span class="lab">{{ c.csat.labels_title }}</span>
                        <code class="bo-mono">workflow.csat.* · {{ c.csat.shipped }}</code>
                        <p class="bo-hint">{{ c.csat.labels_help }}</p>
                    </div>
                    <div class="ctl">
                        <table class="cs-labels" data-testid="wf-csat-labels">
                            <thead>
                                <tr>
                                    <th>{{ c.csat.col_key }}</th>
                                    <th>{{ c.common.language_en }}</th>
                                    <th>
                                        {{ c.common.language_am }}
                                        <span class="bo-hint">· {{ c.csat.machine_am }}</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="key in Object.keys(labels.en ?? {})" :key="key">
                                    <th scope="row" class="bo-mono">{{ key }}</th>
                                    <td>{{ labels.en?.[key] }}</td>
                                    <td lang="am">{{ labels.am?.[key] }}</td>
                                </tr>
                            </tbody>
                        </table>
                        <p class="bo-hint">{{ c.csat.overrides_note }}</p>
                    </div>
                </div>

                <WorkflowRefusal :text="refusal" />
                <div class="cs-savebar">
                    <button
                        v-if="canEdit"
                        type="button"
                        data-testid="wf-csat-save"
                        class="bo-btn p"
                        @click="save"
                    >
                        {{ saveLabel }}
                    </button>
                    <button
                        v-if="canEdit && changed.length"
                        type="button"
                        class="bo-btn"
                        @click="discard"
                    >
                        {{ c.csat.discard }}
                    </button>
                    <span v-if="saved" role="status" class="bo-chip ok" data-testid="wf-csat-saved"
                        >✓ {{ saved }}</span
                    >
                    <span class="bo-hint bo-mono">settings.edit · {{ c.csat.audited_note }}</span>
                </div>
            </section>

            <!-- L04 · what a survey is · events · absences -->
            <aside class="cs-rail">
                <section class="bo-card" data-testid="wf-csat-what">
                    <h4>{{ c.csat.what_title }}</h4>
                    <dl class="bo-kv2">
                        <dt>{{ c.csat.trigger }}</dt>
                        <dd>{{ c.csat.trigger_body }}</dd>
                        <dt>{{ c.csat.suppressed }}</dt>
                        <dd>{{ c.csat.suppressed_body }}</dd>
                        <dt>{{ c.csat.capture }}</dt>
                        <dd>{{ c.csat.capture_body }}</dd>
                        <dt>{{ c.csat.low_score }}</dt>
                        <dd>{{ c.csat.low_score_body }}</dd>
                    </dl>
                </section>
                <section class="bo-card" data-testid="wf-csat-events">
                    <h4>{{ c.csat.events_title }}</h4>
                    <dl class="bo-kv2">
                        <template v-for="ev in EVENTS" :key="ev.name">
                            <dt class="bo-mono">{{ ev.name }}</dt>
                            <dd>{{ c.csat[ev.key] }}</dd>
                        </template>
                    </dl>
                    <p class="bo-hint">{{ c.csat.event_note }} {{ c.csat.n14 }}</p>
                </section>
                <section class="bo-card" data-testid="wf-csat-absences">
                    <h4>{{ c.csat.absences_title }}</h4>
                    <p>
                        <b>{{ c.csat.no_incentive }}</b> {{ c.csat.no_incentive_body }}
                    </p>
                </section>
            </aside>
        </div>

        <!-- L02 + L03 · the customer-facing renders, drawn from the shipped strings -->
        <section
            class="bo-pane cs-renders"
            :aria-label="c.csat.renders_title"
            data-testid="wf-csat-renders"
        >
            <div class="bo-pane-head">
                <h3>{{ c.csat.renders_title }}</h3>
                <label class="bo-hint"
                    >{{ c.forms.preview_language }}
                    <select v-model="lang" class="bo-sel" data-testid="wf-csat-lang">
                        <option v-for="l in LANGS" :key="l" :value="l">{{ l }}</option>
                    </select></label
                >
            </div>
            <div class="cs-frames">
                <!-- Telegram · direct -->
                <div class="cs-tg" data-testid="wf-csat-telegram">
                    <div class="th">
                        <b>{{ c.forms.tg_bot }}</b
                        ><span class="bo-tag">{{ c.forms.tg_direct }}</span>
                    </div>
                    <p class="bot">✓ {{ c.csat.resolved_line }}</p>
                    <p class="bot">{{ L('prompt') }}</p>
                    <div class="ib" role="group" :aria-label="L('prompt')">
                        <span v-for="s in stars" :key="s">{{ s }} ★</span>
                    </div>
                    <p class="bo-hint">
                        {{ tr(c.csat.tg_note, { window: settings.window_hours }) }}
                    </p>
                    <p class="user">4 ★</p>
                    <p class="bot" data-testid="wf-csat-closed">{{ L('window_closed') }}</p>
                    <p class="bo-hint">
                        {{ tr(c.csat.hour_73, { hour: settings.window_hours + 1 }) }}
                    </p>
                    <p class="user">5 ★</p>
                    <p v-if="form.comment_prompt_enabled" class="bot">{{ L('comment_prompt') }}</p>
                    <p class="bot">{{ L('thanks') }}</p>
                    <p class="bo-hint">{{ c.csat.received_note }}</p>
                </div>
                <!-- Telegram · business -->
                <div class="cs-tg" data-testid="wf-csat-telegram-business">
                    <div class="th">
                        <b>{{ c.forms.tg_bot }}</b
                        ><span class="bo-tag">{{ c.forms.tg_business }}</span>
                    </div>
                    <p class="bot">✓ {{ c.csat.resolved_line }}</p>
                    <p class="bot">{{ L('prompt') }}</p>
                    <p class="bot num">1 · 2 · 3 · 4 · 5</p>
                    <p class="bo-hint">{{ c.csat.business_note }}</p>
                </div>
                <!-- widget · the star row -->
                <div class="cs-widget" data-testid="wf-csat-widget">
                    <div class="msg in" lang="am">{{ c.csat.sample_customer_line }}</div>
                    <div class="msg out">
                        <p class="q">{{ L('prompt') }}</p>
                        <div
                            class="stars"
                            role="radiogroup"
                            :aria-label="L('prompt')"
                            data-testid="wf-csat-star-row"
                        >
                            <button
                                v-for="s in stars"
                                :key="s"
                                type="button"
                                role="radio"
                                :aria-checked="s === 5 ? 'true' : 'false'"
                                :aria-label="tr(c.csat.star_aria, { n: s })"
                                :class="{ on: s <= 5 }"
                            >
                                ★
                            </button>
                        </div>
                        <p class="bo-hint">{{ c.csat.optional_line }}</p>
                    </div>
                    <div v-if="form.comment_prompt_enabled" class="msg out">
                        <p class="q">{{ L('comment_prompt') }}</p>
                        <span class="fake-in">{{ c.csat.comment_placeholder }}</span>
                    </div>
                    <div class="msg out expired" data-testid="wf-csat-expired">
                        <p class="q">{{ L('prompt') }}</p>
                        <div
                            class="stars"
                            role="radiogroup"
                            :aria-label="L('prompt')"
                            aria-disabled="true"
                        >
                            <button
                                v-for="s in stars"
                                :key="s"
                                type="button"
                                role="radio"
                                aria-checked="false"
                                disabled
                                :aria-label="tr(c.csat.star_aria, { n: s })"
                            >
                                ★
                            </button>
                        </div>
                        <p class="bo-hint">{{ L('window_closed') }}</p>
                    </div>
                </div>
            </div>
            <p class="bo-hint">{{ c.csat.renders_law }}</p>
        </section>
    </WorkflowShell>
</template>

<style scoped>
.cs-grid {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 320px;
    gap: 16px;
    align-items: start;
}
.cs-grid.ro {
    grid-template-columns: minmax(0, 1fr);
}
.cs-row {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(220px, 1fr);
    gap: 12px 18px;
    padding: 12px;
    border-block-end: 1px solid var(--ds-border-soft);
}
.cs-row.mod {
    box-shadow: inset 3px 0 0 var(--ds-warn-border);
}
.cs-row .lbl {
    display: grid;
    gap: 3px;
    justify-items: start;
}
.cs-row .lbl label,
.cs-row .lbl .lab {
    font-weight: 600;
}
.cs-row code {
    font-size: 11px;
    color: var(--ds-text-muted);
}
.cs-row .prov {
    margin: 0;
}
.cs-row .ctl {
    display: grid;
    gap: 6px;
    justify-items: start;
}
.cs-row .ctl .row {
    display: flex;
    align-items: center;
    gap: 6px;
}
.bo-in.num {
    inline-size: 100px;
}
.cs-conseq {
    margin: 0;
    font-size: 12px;
}
.cs-labels {
    border-collapse: collapse;
    font-size: 12px;
    inline-size: 100%;
}
.cs-labels th {
    text-align: start;
    font-weight: 600;
    color: var(--ds-text-label);
    padding: 3px 8px 3px 0;
    vertical-align: top;
}
.cs-labels td {
    padding: 3px 8px 3px 0;
    vertical-align: top;
}
.cs-savebar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
    padding: 12px;
}
.cs-rail {
    display: grid;
    gap: 10px;
}
.cs-rail .bo-card {
    padding: 12px 14px;
    font-size: 12.5px;
}
.cs-rail h4 {
    margin: 0 0 6px;
    font-size: 12.5px;
}
.cs-renders {
    margin-block-start: 16px;
    padding: 12px 14px;
}
.cs-frames {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
    gap: 14px;
    margin-block-start: 8px;
}
.cs-tg {
    border: 6px solid var(--ds-border-strong);
    border-radius: 22px;
    padding: 12px;
    background: var(--ds-inset-bg);
    display: grid;
    gap: 6px;
    font-size: 12.5px;
}
.cs-tg .th {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding-block-end: 6px;
    border-block-end: 1px solid var(--ds-border);
}
.cs-tg .bot,
.cs-tg .user {
    margin: 0;
    max-inline-size: 92%;
    padding: 6px 10px;
    border-radius: 12px;
    background: var(--ds-panel-bg);
    border: 1px solid var(--ds-border);
}
.cs-tg .user {
    justify-self: end;
    background: var(--ds-accent);
    color: var(--ds-accent-contrast);
    border-color: transparent;
}
.cs-tg .ib {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}
.cs-tg .ib span {
    min-height: 44px;
    display: inline-flex;
    align-items: center;
    border: 1px solid var(--ds-accent);
    color: var(--ds-accent);
    border-radius: 8px;
    padding: 0 12px;
    background: var(--ds-panel-bg);
}
.cs-widget {
    border: 1px solid var(--ds-border-strong);
    border-radius: 14px;
    padding: 12px;
    background: var(--ds-panel-bg);
    display: grid;
    gap: 8px;
    font-size: 13px;
}
.cs-widget .msg {
    padding: 8px 10px;
    border-radius: 12px;
    max-inline-size: 92%;
}
.cs-widget .msg.in {
    justify-self: end;
    background: var(--ds-accent);
    color: var(--ds-accent-contrast);
}
.cs-widget .msg.out {
    background: var(--ds-inset-bg);
    border: 1px solid var(--ds-border);
}
.cs-widget .q {
    margin: 0 0 6px;
    font-weight: 600;
}
.cs-widget .stars {
    display: flex;
    gap: 4px;
}
.cs-widget .stars button {
    inline-size: 44px;
    block-size: 44px;
    border: 1px solid var(--ds-border);
    border-radius: 8px;
    background: var(--ds-panel-bg);
    color: var(--ds-text-muted);
    font-size: 20px;
    cursor: pointer;
}
.cs-widget .stars button.on {
    color: var(--ds-warn-fg);
    background: var(--ds-warn-bg);
    border-color: var(--ds-warn-border);
}
.cs-widget .stars button:disabled {
    border-style: dashed;
    cursor: default;
    opacity: 0.7;
}
.cs-widget .fake-in {
    display: block;
    border: 1px solid var(--ds-border);
    border-radius: 8px;
    padding: 6px 10px;
    color: var(--ds-text-muted);
    background: var(--ds-panel-bg);
}
@media (max-width: 1023px) {
    .cs-grid {
        grid-template-columns: minmax(0, 1fr);
    }
    .cs-row {
        grid-template-columns: minmax(0, 1fr);
    }
}
</style>
