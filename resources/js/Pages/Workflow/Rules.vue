<script setup lang="ts">
// Doc 12 §8 rules list (S12 step 14): position-ordered, MOVE BUTTONS for reorder (§13 keyboard law —
// drag is enhancement, buttons are the requirement), active toggles permission-gated
// (automation.rules.activate), matched count + last run (FR-06), guard-trip badge (§9 amber shield),
// the revision indicator on edited-active rules (FR-07), SEC-01 drift warning. [B12-batch].
// SESSION-F1 (frozen B9-S05): the drawn row anatomy — grip + position + ↑↓ · name + description + chips ·
// trigger (schedules with cadence · timezone · next run) · matched count WITH the 14-day sparkline ·
// last run linking to the log · the switch with its state word — the toolbar (search · trigger · state ·
// has guard trips · the BR-05 guard limits), the explainer strip, the PAUSE-ALL banner from the R-04
// flag's state, the two confirmation dialogs with consequences in nouns, the empty explainer, the
// skeleton while a door is in flight, the legend, 375 cards. Tokens only; no animation.
import { computed, ref } from 'vue';
import WorkflowShell from '../../Components/Workflow/WorkflowShell.vue';
import WorkflowRefusal from '../../Components/Workflow/WorkflowRefusal.vue';
import { post, tr } from '../../Components/Workflow/workflowApi';

type Rule = {
    id: string;
    name: string;
    description: string | null;
    trigger: string;
    position: number;
    active: boolean;
    has_draft: boolean;
    matched_count: number;
    last_run_at: string | null;
    guard_trips: number;
    guard_names: Record<string, number>;
    per_day: number[];
    schedule_cron: string | null;
    schedule_next_run_at: string | null;
    drift: string[];
    updated_at: string | null;
};
const props = defineProps<{
    copy: Record<string, unknown>;
    can: Record<string, boolean>;
    rules: Rule[];
    triggers: string[];
    paused: boolean;
    guards: { rules: number; actions: number; depth: number };
    timezone: string;
}>();
// eslint-disable-next-line @typescript-eslint/no-explicit-any
const c = props.copy as any;
const rules = ref<Rule[]>([...props.rules].sort((a, b) => a.position - b.position));
const refusal = ref('');
const refusalFor = ref<string | null>(null);
const confirming = ref<{ rule: Rule; kind: 'activate' | 'deactivate' } | null>(null);
const creating = ref(false);
const newName = ref('');
const newTrigger = ref(props.triggers[0] ?? 'ticket.created');
const busy = ref(false);
const selected = ref<string | null>(null);
const live = ref('');
const query = ref('');
const fTrigger = ref('');
const fState = ref('');
const fGuards = ref(false);

const listed = computed(() =>
    rules.value.filter((r) => {
        if (
            query.value &&
            !(r.name + ' ' + (r.description ?? ''))
                .toLowerCase()
                .includes(query.value.toLowerCase())
        )
            return false;
        if (fTrigger.value && r.trigger !== fTrigger.value) return false;
        if (fState.value === 'active' && !r.active) return false;
        if (fState.value === 'draft' && r.active) return false;
        if (fGuards.value && r.guard_trips === 0) return false;
        return true;
    }),
);
const activeCount = computed(() => rules.value.filter((r) => r.active).length);
const draftCount = computed(() => rules.value.filter((r) => !r.active).length);
const newest = computed(
    () =>
        [...rules.value].sort((a, b) =>
            (b.updated_at ?? '').localeCompare(a.updated_at ?? ''),
        )[0] ?? null,
);

function stateWord(r: Rule): string {
    if (!r.active) return c.rules.state_draft;
    if (props.paused) return c.rules.state_paused;
    return c.rules.state_active;
}
function stateGlyph(r: Rule): string {
    if (!r.active) return '○';
    if (props.paused) return '⏸';
    return '●';
}
function hhmm(iso: string): string {
    return iso.replace('T', ' ').slice(11, 16);
}
function rel(iso: string | null): string {
    if (!iso) return c.common.never;
    const then = new Date(
        iso
            .replace(' ', 'T')
            .replace(/(\.\d{3})\d+/, '$1')
            .replace(/([+-]\d{2})$/, '$1:00'),
    ).getTime();
    if (Number.isNaN(then)) return iso;
    const mins = Math.max(0, Math.round((Date.now() - then) / 60000));
    if (mins < 60) return tr(c.rules.rel_min, { n: mins });
    const hours = Math.round(mins / 60);
    if (hours < 24) return tr(c.rules.rel_hours, { n: hours });
    const days = Math.round(hours / 24);
    if (days === 1) return tr(c.rules.rel_yesterday, { time: hhmm(iso) });
    return tr(c.rules.rel_days, { n: days });
}
const maxDay = (r: Rule): number => Math.max(1, ...r.per_day);
const cadence = (r: Rule): string => (r.schedule_cron ?? '').trim();
const guardEntries = (r: Rule): [string, number][] => Object.entries(r.guard_names);

async function move(rule: Rule, delta: number): Promise<void> {
    const target = rule.position + delta;
    if (target < 1 || target > rules.value.length) return;
    busy.value = true;
    live.value = tr(c.rules.live_moved, { name: rule.name, n: target, total: rules.value.length });
    const result = await post(`/admin/workflow/rules/${rule.id}/reorder`, { position: target });
    if (!result.ok) {
        busy.value = false;
        refusalFor.value = rule.id;
        refusal.value = tr(c.common.refused, { detail: result.refusal });
        return;
    }
    window.location.reload();
}
function onRowKey(e: KeyboardEvent, rule: Rule): void {
    if (!e.altKey || !props.can['automation.rules.edit']) return;
    if (e.key === 'ArrowUp') {
        e.preventDefault();
        void move(rule, -1);
    } else if (e.key === 'ArrowDown') {
        e.preventDefault();
        void move(rule, 1);
    }
}
async function toggle(): Promise<void> {
    if (!confirming.value) return;
    const { rule, kind } = confirming.value;
    busy.value = true;
    const result = await post(`/admin/workflow/rules/${rule.id}/${kind}`, {});
    confirming.value = null;
    if (!result.ok) {
        busy.value = false;
        refusalFor.value = rule.id;
        refusal.value = tr(c.common.refused, { detail: result.refusal });
        return;
    }
    window.location.reload();
}
async function create(): Promise<void> {
    const result = await post<{ rule_id: string }>('/admin/workflow/rules', {
        name: newName.value,
        trigger: newTrigger.value,
        conditions: { all: [] },
        actions: [],
    });
    if (!result.ok) {
        refusalFor.value = null;
        refusal.value = tr(c.common.refused, { detail: result.refusal });
        return;
    }
    window.location.href = `/admin/workflow/rules/${result.data.rule_id}`;
}
</script>

<template>
    <WorkflowShell
        :copy="c"
        active="rules"
        :title="c.rules.title"
        :read-note="c.rules.readnote_rules"
    >
        <template #sub>
            <span data-testid="wf-rules-summary">{{
                tr(c.rules.summary, { active: activeCount, draft: draftCount })
            }}</span>
            <span v-if="paused" class="bo-chip warn" data-testid="wf-rules-paused-chip">{{
                c.rules.sub_paused
            }}</span>
        </template>
        <template #actions>
            <a
                v-if="newest"
                class="bo-btn"
                :href="`/admin/workflow/rules/${newest.id}#dry-run`"
                data-testid="wf-dry-run-tester"
                >{{ c.rules.dry_run_tester }}</a
            >
            <a
                v-if="newest"
                class="bo-btn"
                :href="`/admin/workflow/rules/${newest.id}#log`"
                data-testid="wf-execution-log"
                >{{ c.rules.execution_log }}</a
            >
            <button
                v-if="can['automation.rules.edit']"
                type="button"
                data-testid="wf-rule-new"
                class="bo-btn p"
                @click="creating = !creating"
            >
                {{ c.rules.new }}
            </button>
        </template>
        <template #banner>
            <div v-if="paused" class="wf-paused" role="alert" data-testid="wf-paused">
                <span class="glyph" aria-hidden="true">⏸</span>
                <div>
                    <p class="big">{{ c.rules.paused_title }}</p>
                    <p>{{ c.rules.paused_body }}</p>
                    <p class="bo-hint">{{ c.rules.paused_platform }}</p>
                </div>
            </div>
        </template>

        <form v-if="creating" class="wf-create" @submit.prevent="create">
            <label class="bo-fld"
                >{{ c.common.name
                }}<input v-model="newName" data-testid="wf-rule-name" required class="bo-in"
            /></label>
            <label class="bo-fld"
                >{{ c.rules.trigger_label
                }}<select v-model="newTrigger" data-testid="wf-rule-trigger" class="bo-in">
                    <option v-for="t in triggers" :key="t" :value="t">{{ t }}</option>
                </select></label
            >
            <button type="submit" data-testid="wf-rule-create" class="bo-btn p">
                {{ c.common.save_draft }}
            </button>
        </form>
        <WorkflowRefusal v-if="refusalFor === null" :text="refusal" />

        <div class="wf-tbar">
            <label class="bo-search2"
                ><span aria-hidden="true">⌕</span
                ><span class="sr-only">{{ c.rules.search_rules }}</span
                ><input
                    v-model="query"
                    type="search"
                    :placeholder="c.rules.search_rules"
                    data-testid="wf-rules-search"
            /></label>
            <select
                v-model="fTrigger"
                class="bo-sel"
                :class="{ on: fTrigger }"
                :aria-label="c.rules.filter_trigger"
                data-testid="wf-rules-filter-trigger"
            >
                <option value="">{{ c.rules.filter_trigger }}: {{ c.rules.any }}</option>
                <option v-for="t in triggers" :key="t" :value="t">
                    {{ c.rules.filter_trigger }}: {{ t }}
                </option>
            </select>
            <select
                v-model="fState"
                class="bo-sel"
                :class="{ on: fState }"
                :aria-label="c.rules.filter_state"
                data-testid="wf-rules-filter-state"
            >
                <option value="">{{ c.rules.filter_state }}: {{ c.rules.any }}</option>
                <option value="active">
                    {{ c.rules.filter_state }}: {{ c.rules.state_active }}
                </option>
                <option value="draft">{{ c.rules.filter_state }}: {{ c.rules.state_draft }}</option>
            </select>
            <button
                type="button"
                class="bo-sel"
                :class="{ on: fGuards }"
                :aria-pressed="fGuards"
                data-testid="wf-rules-filter-guards"
                @click="fGuards = !fGuards"
            >
                {{ c.rules.has_guard_trips }}
            </button>
            <span class="wf-guards" data-testid="wf-guards-sentence">{{
                tr(c.rules.guards_sentence, {
                    rules: guards.rules,
                    actions: guards.actions,
                    depth: guards.depth,
                })
            }}</span>
        </div>
        <p v-if="rules.length" class="wf-explain" data-testid="wf-rules-explainer">
            <span aria-hidden="true">↕</span> {{ c.rules.explainer }}
        </p>

        <div v-if="rules.length === 0" class="wf-rl">
            <div class="bo-empty" data-testid="wf-rules-empty">
                <b>{{ c.rules.empty }}</b>
                <span class="wf-empty-acts">
                    <button
                        v-if="can['automation.rules.edit']"
                        type="button"
                        class="bo-btn p"
                        @click="creating = true"
                    >
                        {{ c.rules.new }}
                    </button>
                </span>
                <span>{{ c.rules.empty_para }}</span>
            </div>
        </div>
        <div v-else class="wf-rl" :class="{ skl: busy }" data-testid="wf-rules-table">
            <div class="wf-rh">
                <span>{{ c.rules.col_position }}</span
                ><span>{{ c.rules.col_name }}</span
                ><span>{{ c.rules.col_trigger }}</span
                ><span>{{ c.rules.col_matched_14d }}</span
                ><span>{{ c.rules.col_last_run }}</span
                ><span>{{ c.common.active }}</span>
            </div>
            <template v-if="busy">
                <div v-for="i in 3" :key="i" class="wf-rr" aria-hidden="true">
                    <span class="bo-sk w40"></span><span><span class="bo-sk w80"></span></span
                    ><span class="bo-sk w60"></span><span class="bo-sk w60"></span
                    ><span class="bo-sk w40"></span><span class="bo-sk h20"></span>
                </div>
            </template>
            <template v-else>
                <div
                    v-for="rule in listed"
                    :key="rule.id"
                    :data-rule-row="rule.id"
                    class="wf-rr"
                    :class="{ on: selected === rule.id }"
                    tabindex="0"
                    @focusin="selected = rule.id"
                    @keydown="onRowKey($event, rule)"
                >
                    <div class="pos">
                        <span v-if="can['automation.rules.edit']" class="grip" aria-hidden="true"
                            >⋮⋮</span
                        >
                        <span class="n">{{ rule.position }}</span>
                        <span v-if="can['automation.rules.edit']" class="mv">
                            <button
                                type="button"
                                :aria-label="c.common.move_up"
                                :data-testid="`wf-rule-up-${rule.id}`"
                                @click="move(rule, -1)"
                            >
                                ↑
                            </button>
                            <button
                                type="button"
                                :aria-label="c.common.move_down"
                                :data-testid="`wf-rule-down-${rule.id}`"
                                @click="move(rule, 1)"
                            >
                                ↓
                            </button>
                        </span>
                    </div>
                    <div class="nm-cell">
                        <a :href="`/admin/workflow/rules/${rule.id}`" class="nm bo-link">{{
                            rule.name
                        }}</a>
                        <p v-if="rule.description" class="ds">{{ rule.description }}</p>
                        <p class="flags">
                            <span
                                v-if="rule.has_draft && rule.active"
                                class="wf-rev"
                                :title="c.rules.revision_pending"
                                >{{ c.rules.revision_chip }}</span
                            >
                            <span
                                v-for="[g, n] in guardEntries(rule)"
                                :key="g"
                                class="wf-gb"
                                :title="tr(c.rules.guard_badge_title, { n, guard: g })"
                                :data-testid="`wf-rule-guard-${rule.id}`"
                                >{{ tr(c.rules.guard_badge, { guard: g, n }) }}</span
                            >
                        </p>
                        <p v-if="rule.drift.length" class="drift">
                            ⚠ {{ tr(c.rules.floor_warning, { missing: rule.drift.join(', ') }) }}
                        </p>
                        <WorkflowRefusal v-if="refusalFor === rule.id" :text="refusal" />
                    </div>
                    <div class="trg">
                        <span class="bo-mono">{{ rule.trigger }}</span>
                        <small v-if="rule.trigger === 'schedule' && cadence(rule)">{{
                            tr(c.rules.schedule_line, {
                                cadence: cadence(rule),
                                tz: timezone,
                                next: rule.schedule_next_run_at ?? '—',
                            })
                        }}</small>
                    </div>
                    <div class="mt">
                        <template v-if="rule.matched_count > 0 || rule.per_day.some((n) => n > 0)">
                            <b>{{ rule.matched_count.toLocaleString() }}</b>
                            <span
                                class="spk"
                                role="img"
                                :aria-label="
                                    tr(c.rules.sparkline_aria, { series: rule.per_day.join(', ') })
                                "
                            >
                                <i
                                    v-for="(n, i) in rule.per_day"
                                    :key="i"
                                    :class="{ z: n === 0 }"
                                    :style="{
                                        height:
                                            n === 0
                                                ? '2px'
                                                : Math.max(3, Math.round((n / maxDay(rule)) * 18)) +
                                                  'px',
                                    }"
                                ></i>
                            </span>
                            <small>{{ c.rules.days_14 }}</small>
                        </template>
                        <template v-else
                            ><b>—</b><small>{{ c.rules.no_runs_yet }}</small></template
                        >
                    </div>
                    <div class="lr">
                        {{ rel(rule.last_run_at) }}
                        <small v-if="rule.last_run_at"
                            ><a class="bo-link" :href="`/admin/workflow/rules/${rule.id}#log`">{{
                                c.rules.the_log
                            }}</a></small
                        >
                    </div>
                    <div class="act">
                        <button
                            v-if="can['automation.rules.activate']"
                            type="button"
                            role="switch"
                            :aria-checked="rule.active"
                            :aria-label="rule.active ? c.common.active : c.common.inactive"
                            class="wf-sw"
                            :class="{ on: rule.active && !paused, paused: rule.active && paused }"
                            :data-testid="`wf-rule-toggle-${rule.id}`"
                            @click="
                                confirming = { rule, kind: rule.active ? 'deactivate' : 'activate' }
                            "
                        ></button>
                        <span class="st"
                            ><span aria-hidden="true">{{ stateGlyph(rule) }} </span
                            ><span :data-testid="`wf-rule-state-${rule.id}`">{{
                                stateWord(rule)
                            }}</span></span
                        >
                    </div>
                </div>
            </template>
            <p v-if="live" class="wf-live" role="status" data-testid="wf-rules-live">{{ live }}</p>
        </div>
        <p v-if="rules.length" class="wf-legend">
            <span
                ><span class="wf-gb">{{ c.rules.legend_guard_chip }}</span>
                {{ c.rules.legend_guard }}</span
            >
            <span
                ><span class="wf-rev">{{ c.rules.revision_chip }}</span>
                {{ c.rules.legend_revision }}</span
            >
            <span>{{ c.rules.drift_word }} {{ c.rules.legend_drift }}</span>
            <span>{{ c.rules.legend_spark }}</span>
        </p>

        <div v-if="confirming" class="bo-scrim" @click.self="confirming = null">
            <div
                class="bo-dlg"
                role="dialog"
                aria-modal="true"
                :aria-label="
                    confirming.kind === 'activate' ? c.rules.activate_verb : c.rules.deactivate_verb
                "
                data-testid="wf-rule-dialog"
            >
                <h3 class="dt">
                    {{
                        confirming.kind === 'activate'
                            ? c.rules.activate_verb
                            : c.rules.deactivate_verb
                    }}
                </h3>
                <div class="db">
                    <p>
                        {{
                            tr(
                                confirming.kind === 'activate'
                                    ? c.rules.activate_body
                                    : c.rules.deactivate_body,
                                { name: confirming.rule.name, position: confirming.rule.position },
                            )
                        }}
                    </p>
                    <dl v-if="confirming.kind === 'activate'" class="bo-row2">
                        <dt>{{ c.rules.row_trigger }}</dt>
                        <dd>
                            <span class="bo-mono">{{ confirming.rule.trigger }}</span>
                        </dd>
                        <dt>{{ c.rules.row_actions }}</dt>
                        <dd>
                            {{
                                tr(c.rules.row_actions_note, {
                                    description: confirming.rule.description ?? '—',
                                })
                            }}
                        </dd>
                        <dt>{{ c.rules.row_capability }}</dt>
                        <dd>{{ c.rules.capability_body }}</dd>
                        <dt>{{ c.rules.row_dry_run }}</dt>
                        <dd>
                            <span class="bo-chip warn">{{ c.rules.dry_run_unknown }}</span>
                            <a
                                class="bo-link"
                                :href="`/admin/workflow/rules/${confirming.rule.id}#dry-run`"
                                >{{ c.rules.run_one_first }}</a
                            >
                        </dd>
                        <dt>{{ c.rules.row_audited }}</dt>
                        <dd>{{ c.rules.audited_activate }}</dd>
                    </dl>
                    <dl v-else class="bo-row2">
                        <dt>{{ c.rules.row_stops }}</dt>
                        <dd>{{ c.rules.stops_body }}</dd>
                        <dt>{{ c.rules.row_keeps }}</dt>
                        <dd>
                            {{
                                tr(c.rules.keeps_body, {
                                    n: confirming.rule.matched_count.toLocaleString(),
                                })
                            }}
                        </dd>
                        <dt>{{ c.rules.row_untouched }}</dt>
                        <dd>{{ c.rules.untouched_body }}</dd>
                        <dt>{{ c.rules.row_audited }}</dt>
                        <dd>{{ c.rules.audited_deactivate }}</dd>
                    </dl>
                </div>
                <div class="df">
                    <button type="button" class="bo-btn" @click="confirming = null">
                        {{ c.common.cancel }}
                    </button>
                    <button
                        type="button"
                        data-testid="wf-rule-confirm"
                        class="bo-btn"
                        :class="confirming.kind === 'deactivate' ? 'danger' : 'p'"
                        @click="toggle"
                    >
                        {{
                            confirming.kind === 'activate'
                                ? c.rules.activate_verb
                                : c.rules.deactivate_verb
                        }}
                    </button>
                </div>
            </div>
        </div>
    </WorkflowShell>
</template>

<style>
.wf-paused {
    display: flex;
    gap: 12px;
    align-items: flex-start;
    padding: 12px 14px;
    margin: 12px 18px 0;
    border: 1px solid var(--ds-danger-border);
    background: var(--ds-danger-bg);
    border-radius: 8px;
    color: var(--ds-text-primary);
}
.wf-paused .glyph {
    font-size: 20px;
}
.wf-paused p {
    margin: 0;
}
.wf-paused .big {
    font-size: 14px;
    font-weight: 700;
    letter-spacing: 0.02em;
    color: var(--ds-danger-text);
}
.wf-create {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    gap: 8px;
    padding: 12px;
    border: 1px solid var(--ds-border);
    border-radius: 8px;
    background: var(--ds-panel-bg);
}
.wf-create .bo-fld {
    display: grid;
    gap: 4px;
    font-size: 11.5px;
    font-weight: 600;
    color: var(--ds-text-secondary);
}
.wf-create .bo-in {
    width: auto;
    min-width: 220px;
}
.wf-tbar {
    display: flex;
    gap: 8px;
    align-items: center;
    flex-wrap: wrap;
}
.wf-guards {
    font-size: 11.5px;
    color: var(--ds-text-muted);
    margin-inline-start: auto;
}
.wf-explain {
    display: flex;
    gap: 10px;
    align-items: center;
    padding: 8px 12px;
    border: 1px dashed var(--ds-border-strong);
    border-radius: 8px;
    font-size: 11.5px;
    color: var(--ds-text-secondary);
    background: var(--ds-panel-soft);
    margin: 0;
}
.wf-rl {
    background: var(--ds-panel-bg);
    border: 1px solid var(--ds-border);
    border-radius: 8px;
    overflow: hidden;
}
.wf-rh,
.wf-rr {
    display: grid;
    grid-template-columns: 76px minmax(0, 1fr) 150px 170px 110px 120px;
    gap: 10px;
    align-items: center;
    padding: 0 12px;
}
.wf-rh {
    height: 34px;
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.07em;
    color: var(--ds-text-label);
    border-block-end: 1px solid var(--ds-border-soft);
    background: var(--ds-panel-soft);
}
.wf-rr {
    min-height: 64px;
    padding-top: 10px;
    padding-bottom: 10px;
    border-block-end: 1px solid var(--ds-border-soft);
    font-size: 12.5px;
}
.wf-rr:hover {
    background: var(--ds-row-hover);
}
.wf-rr.on {
    background: var(--ds-inset-bg);
}
.wf-rr:focus-visible {
    outline: 2px solid var(--ds-focus-ring);
    outline-offset: -2px;
}
.wf-rr .pos {
    display: flex;
    align-items: center;
    gap: 6px;
    font-variant-numeric: tabular-nums;
    font-weight: 600;
}
.wf-rr .pos .grip {
    color: var(--ds-text-muted);
    cursor: grab;
    font-size: 12px;
}
.wf-rr .pos .mv {
    display: none;
    gap: 2px;
}
.wf-rr:focus-within .pos .mv,
.wf-rr.on .pos .mv {
    display: inline-flex;
}
.wf-rr .pos .mv button {
    width: 22px;
    height: 22px;
    border: 1px solid var(--ds-border-strong);
    border-radius: 4px;
    background: var(--ds-panel-bg);
    font-size: 10px;
    color: var(--ds-text-primary);
    cursor: pointer;
}
.wf-rr .nm-cell {
    min-width: 0;
}
.wf-rr .nm {
    font-weight: 600;
    line-height: 1.35;
    overflow-wrap: anywhere;
    font-size: 12.5px;
    text-decoration: none;
}
.wf-rr .nm:hover {
    text-decoration: underline;
}
.wf-rr .ds {
    font-size: 11px;
    color: var(--ds-text-muted);
    margin: 2px 0 0;
    line-height: 1.4;
}
.wf-rr .flags {
    display: flex;
    gap: 4px;
    flex-wrap: wrap;
    margin: 5px 0 0;
}
.wf-rr .flags:empty {
    display: none;
}
.wf-rr .drift {
    display: flex;
    gap: 6px;
    align-items: flex-start;
    font-size: 11px;
    color: var(--ds-danger-text);
    margin: 5px 0 0;
    line-height: 1.4;
}
.wf-rr .trg {
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    font-size: 11px;
    color: var(--ds-text-secondary);
    overflow-wrap: anywhere;
}
.wf-rr .trg small {
    display: block;
    font-family: inherit;
    color: var(--ds-text-muted);
    font-size: 10.5px;
}
.wf-rr .mt {
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.wf-rr .mt b {
    font-variant-numeric: tabular-nums;
    font-size: 13px;
}
.wf-rr .mt small {
    font-size: 10.5px;
    color: var(--ds-text-muted);
}
.spk {
    display: inline-flex;
    gap: 2px;
    align-items: flex-end;
    height: 18px;
}
.spk i {
    display: block;
    width: 5px;
    background: var(--ds-text-primary);
    border-radius: 1px;
    opacity: 0.85;
}
.spk i.z {
    background: var(--ds-border-strong);
}
.wf-rr .lr {
    font-size: 11.5px;
    color: var(--ds-text-secondary);
}
.wf-rr .lr small {
    display: block;
    color: var(--ds-text-muted);
    font-size: 10.5px;
}
.wf-rr .act {
    display: flex;
    justify-content: flex-end;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.wf-rr .st {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 11.5px;
    font-weight: 600;
}
.wf-sw {
    width: 44px;
    height: 24px;
    border-radius: 999px;
    background: var(--ds-border-strong);
    position: relative;
    flex: none;
    border: 0;
    padding: 0;
    cursor: pointer;
}
.wf-sw::after {
    content: '';
    position: absolute;
    top: 3px;
    inset-inline-start: 3px;
    width: 18px;
    height: 18px;
    border-radius: 50%;
    background: var(--ds-panel-bg);
}
.wf-sw.on {
    background: var(--ds-success-text);
}
.wf-sw.on::after,
.wf-sw.paused::after {
    inset-inline-start: 23px;
}
.wf-sw.paused {
    background: var(--ds-warn-border);
}
.wf-gb {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    height: 20px;
    padding: 0 7px;
    border-radius: 999px;
    font-size: 10.5px;
    font-weight: 600;
    background: var(--ds-warn-bg);
    color: var(--ds-warn-text);
    border: 1px solid var(--ds-warn-border);
}
.wf-rev {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    height: 20px;
    padding: 0 7px;
    border-radius: 999px;
    font-size: 10.5px;
    font-weight: 600;
    background: var(--ds-status-pending-internal-bg);
    color: var(--ds-status-pending-internal-fg);
}
.wf-live {
    font-size: 11.5px;
    color: var(--ds-text-secondary);
    padding: 8px 12px;
    border-block-start: 1px dashed var(--ds-border);
    margin: 0;
}
.wf-legend {
    display: flex;
    gap: 18px;
    flex-wrap: wrap;
    font-size: 11.5px;
    color: var(--ds-text-secondary);
    margin: 0;
}
.wf-legend > span {
    display: inline-flex;
    gap: 6px;
    align-items: center;
}
.wf-empty-acts {
    display: flex;
    gap: 8px;
}
@media (max-width: 639px) {
    .wf-rh {
        display: none;
    }
    .wf-rr {
        grid-template-columns: 1fr;
        gap: 6px;
    }
    .wf-rr .pos .mv,
    .wf-sw {
        display: none;
    }
    .wf-rr .act {
        justify-content: flex-start;
    }
    .wf-guards {
        margin-inline-start: 0;
    }
}
</style>
