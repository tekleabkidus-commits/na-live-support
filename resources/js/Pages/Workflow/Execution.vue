<script setup lang="ts">
// Doc 12 FR-06 / SEC-03 — the EXECUTION DETAIL page (B9-S13 M02, SESSION-F1 [B12]): one execution
// of one rule — the event reference, the ticket, the rule and revision, the guard facts; the
// condition results with truth badges and evaluated values; the action outcomes in order with
// failures; and the SEC-03 elision side by side — the privileged viewer sees the values and the
// same execution elided beside them, the non-privileged viewer sees the elided one alone. A READ
// page on the existing key automation.rules.view (LogReader's own elision); no retry door (D07).
import { computed, ref } from 'vue';
import WorkflowShell from '../../Components/Workflow/WorkflowShell.vue';
import { tr } from '../../Components/Workflow/workflowApi';

type Leaf = {
    fact?: string;
    op?: string;
    value?: unknown;
    actual?: unknown;
    matched?: boolean;
    group?: string;
    depth?: number;
};
type Outcome = { action?: string; outcome?: string; reason?: string; at?: string };
type Execution = {
    id: string;
    event_id: string;
    matched: boolean;
    executed_at: string;
    conditions: Leaf[];
    action_outcomes: Outcome[];
    guard_trips: string[];
    ticket_id: string | null;
};
const props = defineProps<{
    copy: Record<string, unknown>;
    can: Record<string, boolean>;
    rule: { id: string; name: string; position: number; active: boolean; revision: string | null };
    execution: Execution;
    privileged: boolean;
    ticket: {
        id: string;
        number: number;
        channel?: string | null;
        department?: string | null;
    } | null;
    guards: { rules: number; actions: number; depth: number };
    retention_days?: number;
}>();
// eslint-disable-next-line @typescript-eslint/no-explicit-any
const c = props.copy as any;
const readonly = ref(window.innerWidth < 1024);
const done = computed(
    () =>
        props.execution.action_outcomes.filter(
            (o) => o.outcome === 'done' || o.outcome === 'ok' || o.outcome === 'applied',
        ).length,
);
const failed = computed(
    () => props.execution.action_outcomes.filter((o) => o.outcome === 'failed').length,
);
const total = computed(() => props.execution.action_outcomes.length);
const headLine = computed(() => {
    const parts = [
        props.execution.matched ? `✓ ${c.rules.log_matched}` : `✗ ${c.rules.log_unmatched}`,
    ];
    if (total.value)
        parts.push(tr(c.execution.actions_done, { done: done.value, total: total.value }));
    if (failed.value) parts.push(tr(c.execution.n_failed, { n: failed.value }));
    if (props.retention_days) parts.push(tr(c.execution.retention, { days: props.retention_days }));
    return parts.join(' · ');
});
function fmt(iso: string | null | undefined): string {
    if (!iso) return '';
    const d = new Date(iso.replace(' ', 'T'));
    return Number.isNaN(d.getTime())
        ? iso
        : d.toLocaleString(undefined, {
              day: 'numeric',
              month: 'short',
              hour: '2-digit',
              minute: '2-digit',
              second: '2-digit',
          });
}
function valueText(v: unknown): string {
    if (v === null || v === undefined) return '—';
    return typeof v === 'string'
        ? v
        : Array.isArray(v)
          ? `[${v.map(String).join(', ')}]`
          : typeof v === 'object'
            ? JSON.stringify(v)
            : String(v);
}
// the elided twin: message facts to [matched] / [no match] and [keyword] — LogReader's own rule, mirrored
function elide(leaf: Leaf): Leaf {
    const isMessage = typeof leaf.fact === 'string' && leaf.fact.startsWith('message.');
    if (!isMessage) return leaf;
    return {
        ...leaf,
        actual: leaf.matched ? '[matched]' : '[no match]',
        value: typeof leaf.value === 'string' ? '[keyword]' : leaf.value,
    };
}
const elided = computed(() => props.execution.conditions.map(elide));
const short = (id: string): string => (id.length > 12 ? `${id.slice(0, 12)}…` : id);
const actionWord = (type: string | undefined): string =>
    type ? (c.rules.action_card?.[type] ?? type) : '';
function outcomeWord(o: Outcome): string {
    if (o.outcome === 'failed') return `✕ ${c.execution.failed}`;
    if (o.outcome === 'skipped') return `– ${c.execution.skipped}`;
    return `✓ ${c.execution.done}`;
}
function outcomeClass(o: Outcome): string {
    return o.outcome === 'failed' ? 'bad' : o.outcome === 'skipped' ? 'skip' : 'ok';
}
const guardsLine = computed(() =>
    props.execution.guard_trips.length
        ? tr(c.rules.guards_tripped, { list: props.execution.guard_trips.join(', ') })
        : tr(c.execution.guards_none, {
              limit_rules: props.guards.rules,
              limit_actions: props.guards.actions,
              actions: total.value,
          }),
);
const crumb = computed(() =>
    tr(c.execution.title_line, { rule: props.rule.name, when: fmt(props.execution.executed_at) }),
);
</script>

<template>
    <WorkflowShell
        :copy="c"
        active="rules"
        :title="c.execution.title"
        :summary="crumb"
        :read-note="readonly ? c.execution.readnote : undefined"
    >
        <template #actions>
            <a
                class="bo-btn"
                :href="`/admin/workflow/rules/${rule.id}`"
                data-testid="wf-exec-open-rule"
                >{{ c.execution.open_rule }}</a
            >
            <a
                v-if="ticket"
                class="bo-btn"
                :href="`/inbox?ticket=${ticket.id}`"
                data-testid="wf-exec-open-ticket"
                >{{ tr(c.execution.open_ticket, { number: ticket.number }) }}</a
            >
            <a
                class="bo-btn"
                :href="`/admin/workflow/rules/${rule.id}#dry-run`"
                data-testid="wf-exec-replay"
                >{{ c.execution.replay }}</a
            >
        </template>

        <div class="ex-head">
            <h2>{{ crumb }}</h2>
            <p class="bo-hint" data-testid="wf-exec-headline">{{ headLine }}</p>
        </div>

        <div class="ex-grid" :class="{ single: !privileged }">
            <!-- the facts + the condition results (privileged: values; else the elided view) -->
            <section
                class="bo-pane ex-pane"
                :data-testid="privileged ? 'wf-exec-privileged' : 'wf-exec-elided'"
            >
                <div class="bo-pane-head">
                    <h3>{{ c.execution.facts_title }}</h3>
                    <span class="bo-chip" :class="privileged ? 'ok' : 'warn'">{{
                        privileged ? c.execution.privileged_viewer : c.execution.elided_viewer
                    }}</span>
                </div>
                <dl class="bo-kv2 ex-facts">
                    <dt>{{ c.execution.execution_word }}</dt>
                    <dd class="bo-mono">{{ short(execution.id) }}</dd>
                    <dt>{{ c.rules.log_event }}</dt>
                    <dd>
                        <span class="bo-mono">{{ short(execution.event_id) }}</span> ·
                        {{ fmt(execution.executed_at) }}
                    </dd>
                    <dt>{{ c.execution.ticket_word }}</dt>
                    <dd v-if="ticket">
                        #{{ ticket.number
                        }}<template v-if="ticket.channel"> · {{ ticket.channel }}</template
                        ><template v-if="ticket.department"> · {{ ticket.department }}</template>
                    </dd>
                    <dd v-else>—</dd>
                    <dt>{{ c.execution.rule_word }}</dt>
                    <dd>
                        {{ rule.name
                        }}<template v-if="rule.revision">
                            ·
                            {{ tr(c.execution.revision, { rev: short(rule.revision) }) }}</template
                        >
                        · {{ tr(c.rules.position_of, { n: rule.position, total: rule.position }) }}
                    </dd>
                    <dt>{{ c.execution.guards_word }}</dt>
                    <dd data-testid="wf-exec-guards">{{ guardsLine }}</dd>
                </dl>
                <h4>{{ c.execution.conditions_title }}</h4>
                <p v-if="!execution.conditions.length" class="bo-hint">
                    {{ c.rules.sent_no_conditions }}
                </p>
                <ol class="ex-conds" data-testid="wf-exec-conditions">
                    <li
                        v-for="(l, i) in privileged ? execution.conditions : elided"
                        :key="i"
                        :data-matched="l.matched ? 'true' : 'false'"
                    >
                        <span class="tb" :class="l.matched ? 'y' : 'n'">{{
                            l.matched
                                ? '✓ ' + c.rules.dry_run_truth_true
                                : '✗ ' + c.rules.dry_run_truth_false
                        }}</span>
                        <span class="fact bo-mono"
                            >{{ l.fact ?? l.group }} {{ l.op ?? '' }} {{ valueText(l.value) }}</span
                        >
                        <span class="ev"
                            >{{ c.execution.evaluated }} <b>{{ valueText(l.actual) }}</b></span
                        >
                    </li>
                </ol>
                <p v-if="!privileged" class="bo-hint" data-testid="wf-exec-elision-note">
                    {{ c.execution.elision_note }}
                </p>
            </section>

            <!-- the same execution, elided (privileged viewers see both) -->
            <section v-if="privileged" class="bo-pane ex-pane ex-el" data-testid="wf-exec-elided">
                <div class="bo-pane-head">
                    <h3>{{ c.execution.same_elided }}</h3>
                    <span class="bo-chip warn">{{ c.execution.elided_viewer }}</span>
                </div>
                <ol class="ex-conds">
                    <li
                        v-for="(l, i) in elided"
                        :key="i"
                        :data-matched="l.matched ? 'true' : 'false'"
                    >
                        <span class="tb" :class="l.matched ? 'y' : 'n'">{{
                            l.matched
                                ? '✓ ' + c.rules.dry_run_truth_true
                                : '✗ ' + c.rules.dry_run_truth_false
                        }}</span>
                        <span class="fact bo-mono"
                            >{{ l.fact ?? l.group }} {{ l.op ?? '' }} {{ valueText(l.value) }}</span
                        >
                        <span class="ev"
                            >{{ c.execution.evaluated }} <b>{{ valueText(l.actual) }}</b></span
                        >
                    </li>
                </ol>
                <p class="bo-hint">{{ c.execution.elision_note }}</p>
            </section>
        </div>

        <!-- the action outcomes -->
        <section class="bo-pane ex-pane" data-testid="wf-exec-outcomes">
            <div class="bo-pane-head">
                <h3>{{ c.execution.outcomes_title }}</h3>
                <span class="bo-hint">{{ c.execution.outcomes_sub }}</span>
            </div>
            <p v-if="!execution.action_outcomes.length" class="bo-hint">
                {{ execution.matched ? c.rules.log_no_actions : c.rules.skipped_no_match }}
            </p>
            <ol class="ex-outs">
                <li
                    v-for="(o, i) in execution.action_outcomes"
                    :key="i"
                    :class="outcomeClass(o)"
                    :data-outcome="o.outcome ?? ''"
                >
                    <span class="k">{{ i + 1 }}</span>
                    <span class="a"
                        ><b>{{ actionWord(o.action) }}</b
                        ><span v-if="o.reason" class="bo-hint">{{ o.reason }}</span
                        ><span v-if="o.at" class="bo-hint">{{ fmt(o.at) }}</span></span
                    >
                    <span class="o">{{ outcomeWord(o) }}</span>
                </li>
            </ol>
            <p class="bo-hint">{{ c.execution.outcomes_law }}</p>
        </section>
    </WorkflowShell>
</template>

<style scoped>
.ex-head h2 {
    margin: 0;
    font-size: 17px;
}
.ex-head .bo-hint {
    margin: 2px 0 12px;
}
.ex-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 14px;
    align-items: start;
}
.ex-grid.single {
    grid-template-columns: minmax(0, 1fr);
}
.ex-pane {
    padding: 12px 14px;
    margin-block-end: 14px;
}
.ex-pane h4 {
    margin: 12px 0 6px;
    font-size: 12.5px;
}
.ex-facts {
    font-size: 12.5px;
}
.ex-conds,
.ex-outs {
    list-style: none;
    margin: 0;
    padding: 0;
    display: grid;
    gap: 6px;
    font-size: 12.5px;
}
.ex-conds li {
    display: grid;
    grid-template-columns: auto minmax(0, 1fr);
    gap: 2px 10px;
    padding: 6px 8px;
    border: 1px solid var(--ds-border-soft);
    border-radius: 8px;
    background: var(--ds-inset-bg);
}
.ex-conds .tb {
    font-weight: 600;
    white-space: nowrap;
}
.ex-conds .tb.y {
    color: var(--ds-success-fg);
}
.ex-conds .tb.n {
    color: var(--ds-text-muted);
}
.ex-conds .ev {
    grid-column: 2;
    color: var(--ds-text-muted);
}
.ex-outs li {
    display: grid;
    grid-template-columns: 24px minmax(0, 1fr) auto;
    gap: 8px;
    align-items: start;
    padding: 6px 8px;
    border-inline-start: 3px solid var(--ds-border-strong);
    background: var(--ds-inset-bg);
    border-radius: 6px;
}
.ex-outs li.ok {
    border-inline-start-color: var(--ds-success-border);
}
.ex-outs li.bad {
    border-inline-start-color: var(--ds-danger);
}
.ex-outs li.skip {
    border-inline-start-color: var(--ds-warn-border);
}
.ex-outs .k {
    font-weight: 700;
    color: var(--ds-text-muted);
}
.ex-outs .a {
    display: grid;
    gap: 1px;
}
.ex-outs .o {
    white-space: nowrap;
    font-weight: 600;
}
.ex-outs li.bad .o {
    color: var(--ds-danger-text);
}
@media (max-width: 1023px) {
    .ex-grid {
        grid-template-columns: minmax(0, 1fr);
    }
}
</style>
