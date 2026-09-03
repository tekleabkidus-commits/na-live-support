<script setup lang="ts">
// Doc 12 §8 rule editor (S12 step 14): three panels — Trigger → Conditions (ConditionTree) → Actions
// (ordered cards, per-type config; the external arms' CLOSED cards render only their grammar's keys —
// an SMS card offers a template reference and nothing else, a webhook card a destination pick) — with
// inline validation, the DRY-RUN DRAWER alongside (FR-05; §9's mandatory ✓/✗ truth badges with the
// evaluated value, amber shield guard badges) and save-as-draft vs activate (FR-07). The execution
// log (FR-06) sits below. J19: editing needs ≥ 1024 px (read-only under it). [B12-batch].
// SESSION-F1 (frozen B9-S06): the B5-S07 builder idiom — three NUMBERED panels connected top-to-bottom,
// the 13 triggers as a chip row, the schedule floor + tenant-timezone next run beside the cron, one help
// line per action card naming its door, the closed cards marked, the inline validation with its
// role=alert summary and the save bar that says what blocks it, the dry-run drawer with the verdict,
// the "first ✗ is where to look" line, the guard sentence, the activate CONFIRMATION (atomic swap ·
// dry-run status · capability floor · history · audit), the draft-saved banner + the §21 nudge, the
// execution log with expandable detail and the SEC-03 note, the < 1024 READ posture as sentences.
import { computed, onMounted, ref } from 'vue';
import WorkflowShell from '../../Components/Workflow/WorkflowShell.vue';
import WorkflowRefusal from '../../Components/Workflow/WorkflowRefusal.vue';
import ConditionTree from '../../Components/Workflow/ConditionTree.vue';
import { post, tr } from '../../Components/Workflow/workflowApi';

type Action = Record<string, unknown> & { type: string };
type Leaf = { fact: string; op: string; value: unknown; keyword_set_id?: string };
type Group = { all?: (Leaf | Group)[]; any?: (Leaf | Group)[] };
type Execution = {
    id: string;
    event_id?: string;
    matched: boolean;
    executed_at: string;
    conditions?: { fact: string; op: string; value: unknown; actual: unknown; matched: boolean }[];
    action_outcomes: { action: string; outcome: string; reason?: string }[];
    guard_trips: string[];
    ticket_id?: string | null;
};
const props = defineProps<{
    copy: Record<string, unknown>;
    can: Record<string, boolean>;
    rule: {
        id: string;
        name: string;
        description: string | null;
        trigger: string;
        position: number;
        active: boolean;
        cooldown_seconds: number;
        schedule_cron: string | null;
        schedule_next_run_at: string | null;
        run_window: unknown;
        active_revision_id: string | null;
        latest_revision_id: string | null;
        has_draft: boolean;
        updated_at: string | null;
    };
    definition: { conditions: Record<string, unknown>; actions: Action[] };
    triggers: string[];
    action_types: string[];
    ai_tasks: string[];
    keyword_sets: { id: string; name: string; active: boolean }[];
    destinations: {
        id: string;
        name: string;
        url: string;
        active: boolean;
        auto_disabled_at: string | null;
    }[];
    macros: { id: string; name: string; scope: string }[];
    departments: { id: string; name: string }[];
    queues: { id: string; name: string; department_id: string }[];
    fields: { id: string; entity: string; key: string; label: string; type: string }[];
    log: { matched_count: number; last_run_at: string | null; executions: Execution[] };
    recent_events: { event_id: string; name: string; occurred_at?: string }[];
    rule_count?: number;
    timezone?: string;
    guards?: { rules: number; actions: number; depth: number };
}>();
// eslint-disable-next-line @typescript-eslint/no-explicit-any
const c = props.copy as any;
const readonly = ref(window.innerWidth < 1024); // J19 viewport law
const name = ref(props.rule.name);
const description = ref(props.rule.description ?? '');
const trigger = ref(props.rule.trigger);
const cooldown = ref(props.rule.cooldown_seconds);
const cron = ref(props.rule.schedule_cron ?? '');
const conditions = ref<Record<string, unknown>>(props.definition.conditions ?? { all: [] });
const actions = ref<Action[]>([...props.definition.actions]);
const refusal = ref('');
const savedAt = ref('');
const dirty = ref(false);
const drawerOpen = ref(true);
const dryEvent = ref(props.recent_events[0]?.event_id ?? '');
const dryResult = ref<{
    matched: boolean;
    trace: { fact: string; op: string; value: unknown; actual: unknown; matched: boolean }[];
    actions: { type: string; would_run: boolean; note: string | null }[];
    guards: string[];
} | null>(null);
const dryRanAt = ref('');
const activateOpen = ref(false);
const openLog = ref<string | null>(null);
const guards = computed(() => props.guards ?? { rules: 10, actions: 20, depth: 3 });
const CLOSED = ['send_sms_template', 'webhook_out', 'run_ai_task'];
const FACTS = [
    'ticket.status',
    'ticket.priority',
    'ticket.department_id',
    'ticket.queue_id',
    'ticket.channel',
    'ticket.tags',
    'ticket.age_minutes',
    'customer.tags',
    'customer.language',
    'customer.country',
    'customer.blocked',
    'customer.vip_source',
    'message.body',
    'message.language',
    'ai.intent',
    'ai.sentiment',
    'ai.confidence',
    'ai.urgency',
    'time.within_business_hours',
    'time.day_of_week',
];
const facts = computed(() => [...FACTS, ...props.fields.map((f) => `${f.entity}.custom.${f.key}`)]);
const hhmm = (iso: string | null | undefined): string =>
    iso ? iso.replace('T', ' ').slice(11, 16) : '';
const nowHhmm = (): string => new Date().toTimeString().slice(0, 5);

function cardTemplate(type: string): Action {
    switch (type) {
        case 'set_status':
            return { type, status: 'open' };
        case 'set_priority':
            return { type, priority: 'high' };
        case 'assign':
            return { type, queue_id: '' };
        case 'transfer_department':
            return { type, department_id: '' };
        case 'add_tags':
        case 'remove_tags':
            return { type, tags: [] };
        case 'set_custom_field':
            return { type, key: '', value: '' };
        case 'send_macro_reply':
            return { type, macro_id: '' };
        case 'add_note':
            return { type, body: '' };
        case 'notify':
            return { type, user_ids: [], role_ids: [] };
        case 'send_sms_template':
            return { type, template_ref: '' };
        case 'webhook_out':
            return { type, destination_id: '' };
        case 'run_ai_task':
            return { type, task: 'summarize_thread' };
        case 'escalate':
            return { type, reason: '' };
        case 'suppress':
            return { type, reason: '' };
        default:
            return { type };
    }
}
function addAction(type: string): void {
    if (!type) return;
    actions.value = [...actions.value, cardTemplate(type)];
    dirty.value = true;
}
function moveAction(i: number, d: number): void {
    const j = i + d;
    if (j < 0 || j >= actions.value.length) return;
    const next = [...actions.value];
    [next[i], next[j]] = [next[j] as Action, next[i] as Action];
    actions.value = next;
    dirty.value = true;
}
function removeAction(i: number): void {
    actions.value = actions.value.filter((_, j) => j !== i);
    dirty.value = true;
}
function setField(i: number, key: string, value: unknown): void {
    const next = [...actions.value];
    next[i] = { ...(next[i] as Action), [key]: value };
    actions.value = next;
    dirty.value = true;
}
const list = (v: unknown): string[] => (Array.isArray(v) ? (v as string[]) : []);
const splitList = (raw: string): string[] =>
    raw
        .split(',')
        .map((s) => s.trim())
        .filter((s) => s);
const cardProblem = (a: Action): string =>
    a.type === 'send_sms_template' && !String(a.template_ref ?? '').trim()
        ? c.rules.sms_template_missing
        : '';
const validation = computed<{ index: number; text: string }[]>(() =>
    actions.value.map((a, i) => ({ index: i, text: cardProblem(a) })).filter((p) => p.text !== ''),
);
const blockedLine = computed(() =>
    validation.value.length ? tr(c.rules.blocked_by, { n: validation.value.length }) : '',
);
const capabilityLine = computed(() =>
    tr(c.rules.capability_for, { types: actions.value.map((a) => actionWord(a.type)).join(' · ') }),
);
function payload(): Record<string, unknown> {
    return {
        name: name.value,
        description: description.value || null,
        trigger: trigger.value,
        cooldown_seconds: cooldown.value,
        schedule_cron: trigger.value === 'schedule' ? cron.value : null,
        conditions: conditions.value,
        actions: actions.value,
    };
}
async function saveDraft(): Promise<void> {
    refusal.value = '';
    // F06 — inline validation blocks save before the door is asked
    if (validation.value.length > 0) {
        refusal.value = blockedLine.value;
        return;
    }
    const result = await post<{ revision_id: string }>(
        `/admin/workflow/rules/${props.rule.id}`,
        payload(),
    );
    if (!result.ok) {
        refusal.value = tr(c.common.refused, { detail: result.refusal });
        return;
    }
    savedAt.value = nowHhmm();
    dirty.value = false;
    // F08 — the "draft saved · active untouched" banner survives the revision reload
    try {
        sessionStorage.setItem('wf-draft-saved', savedAt.value);
    } catch {
        /* storage may be unavailable */
    }
    window.location.reload();
}
async function activate(): Promise<void> {
    refusal.value = '';
    const result = await post<{ revision_id: string }>(
        `/admin/workflow/rules/${props.rule.id}`,
        payload(),
    );
    if (!result.ok) {
        refusal.value = tr(c.common.refused, { detail: result.refusal });
        activateOpen.value = false;
        return;
    }
    const act = await post(`/admin/workflow/rules/${props.rule.id}/activate`, {
        revision_id: result.data.revision_id,
    });
    if (!act.ok) {
        refusal.value = tr(c.common.refused, { detail: act.refusal });
        activateOpen.value = false;
        return;
    }
    window.location.reload();
}
async function runDry(): Promise<void> {
    refusal.value = '';
    const result = await post<typeof dryResult.value & { ok: true }>(
        '/admin/workflow/rules/dry-run',
        {
            event_id: dryEvent.value,
            definition: { conditions: conditions.value, actions: actions.value },
        },
    );
    if (!result.ok) {
        refusal.value = tr(c.common.refused, { detail: result.refusal });
        return;
    }
    dryResult.value = result.data;
    dryRanAt.value = nowHhmm();
    drawerOpen.value = true;
}
function valueText(v: unknown): string {
    return v === null || v === undefined
        ? '∅'
        : Array.isArray(v)
          ? v.join(', ')
          : typeof v === 'object'
            ? JSON.stringify(v)
            : String(v);
}
const firstMiss = computed(() => dryResult.value?.trace.find((l) => !l.matched) ?? null);
const actionWord = (type: string): string => c.rules.action_card?.[type] ?? type;
const helpFor = (type: string): string => c.rules.action_help?.[type] ?? '';
const eventLabel = (e: { event_id: string; name: string; occurred_at?: string }): string =>
    `${e.name} · ${e.occurred_at ? e.occurred_at.replace('T', ' ').slice(0, 16) : e.event_id.slice(0, 8)}`;
const triggerSentence = computed(() => {
    const parts = [tr(c.rules.sent_trigger, { trigger: trigger.value })];
    if (cooldown.value) parts.push(tr(c.rules.sent_cooldown, { n: cooldown.value }));
    if (trigger.value === 'schedule' && cron.value)
        parts.push(tr(c.rules.sent_schedule, { cron: cron.value, tz: props.timezone ?? '' }));
    return parts.join(' ');
});
function isGroup(n: Leaf | Group): n is Group {
    return 'all' in n || 'any' in n;
}
function prose(g: Group): string {
    const key = 'any' in g ? 'any' : 'all';
    const items = ((g[key] ?? []) as (Leaf | Group)[]).map((n) =>
        isGroup(n) ? '(' + prose(n) + ')' : `${n.fact} ${n.op} ${valueText(n.value)}`,
    );
    if (!items.length) return c.rules.sent_no_conditions;
    return (key === 'any' ? c.rules.group_any : c.rules.group_all) + ': ' + items.join('; ');
}
const conditionsSentence = computed(() => prose(conditions.value as Group));
const actionSentence = (a: Action): string => {
    const detail = String(
        a.status ??
            a.priority ??
            a.template_ref ??
            a.task ??
            a.reason ??
            a.body ??
            (Array.isArray(a.tags) ? (a.tags as string[]).join(', ') : '') ??
            '',
    );
    return detail ? `${actionWord(a.type)} → ${detail}` : actionWord(a.type);
};
const outcomeClass = (o: { outcome: string }): string =>
    o.outcome === 'ok' || o.outcome === 'done' ? 'ok' : o.outcome === 'failed' ? 'fail' : 'skip';
const aiOff = ref(false);
async function loadAiBanner(): Promise<void> {
    // M05 — the AI-off state from Doc 09's banner door (read-only); the card wears the tag, the
    // action records skipped: ai_unavailable and the rule continues (action isolation)
    try {
        const res = await fetch('/admin/ai/banner', { headers: { Accept: 'application/json' } });
        if (!res.ok) return;
        const data = (await res.json()) as { off?: { surface?: string }[] };
        aiOff.value = (data.off ?? []).length > 0;
    } catch {
        aiOff.value = false;
    }
}
onMounted(() => {
    loadAiBanner();
    if (window.location.hash === '#dry-run') drawerOpen.value = true;
    try {
        const stamp = sessionStorage.getItem('wf-draft-saved');
        if (stamp) {
            savedAt.value = stamp;
            sessionStorage.removeItem('wf-draft-saved');
        }
    } catch {
        /* storage may be unavailable */
    }
});
</script>

<template>
    <WorkflowShell
        :copy="c"
        active="rules"
        :title="rule.name"
        :crumb="[
            { label: c.nav.backoffice },
            { label: c.nav.workflow, href: '/admin/workflow' },
            { label: c.nav.automation, href: '/admin/workflow/rules' },
            { label: c.rules.editor_title },
        ]"
        :read-note="readonly ? c.hub.readonly_notice : undefined"
    >
        <template #sub>
            <span class="wf-st" data-testid="wf-editor-state">{{
                rule.active ? '● ' + c.rules.state_active_rev : '○ ' + c.rules.state_draft
            }}</span>
            <span v-if="rule.has_draft && rule.active" class="wf-rev">{{
                c.rules.editing_draft_rev
            }}</span>
            <span class="bo-stamp"
                >{{
                    tr(c.rules.position_of, {
                        n: rule.position,
                        total: rule_count ?? rule.position,
                    })
                }}<template v-if="savedAt">
                    · {{ tr(c.rules.draft_saved_at, { time: savedAt }) }}</template
                ><template v-else-if="dirty"> · {{ c.rules.unsaved_changes }}</template
                ><template v-if="rule.active"> · {{ c.rules.active_keeps_firing }}</template></span
            >
        </template>
        <template #actions>
            <a class="bo-btn" href="#log">{{ c.rules.log_title }}</a>
            <button
                v-if="!readonly"
                type="button"
                class="bo-btn"
                :aria-expanded="drawerOpen"
                data-testid="wf-dry-run-toggle"
                @click="drawerOpen = !drawerOpen"
            >
                {{ c.rules.dry_run_title }} ▸
            </button>
            <button
                v-if="!readonly && can['automation.rules.edit']"
                type="button"
                class="bo-btn"
                data-testid="wf-save-draft"
                @click="saveDraft"
            >
                {{ c.common.save_draft }}
            </button>
            <button
                v-if="!readonly && can['automation.rules.activate']"
                type="button"
                class="bo-btn p"
                data-testid="wf-save-activate"
                @click="activate"
            >
                {{ c.rules.activate_revision }}
            </button>
        </template>
        <template #banner>
            <p v-if="savedAt" class="bo-banner" role="status" data-testid="wf-draft-saved">
                ✓ {{ tr(c.rules.draft_saved_banner, { time: savedAt }) }}
            </p>
            <p v-if="!readonly && !dryResult" class="bo-banner" data-testid="wf-dry-nudge">
                ⓘ {{ c.rules.dry_nudge }}
                <button type="button" class="bo-link" @click="drawerOpen = true">
                    {{ c.rules.run_one_now }}
                </button>
            </p>
        </template>

        <!-- READ posture below 1024 px: the rule as sentences (F01) -->
        <div v-if="readonly" class="wf-flow" data-testid="wf-read-posture">
            <section class="wf-pnl">
                <div class="ph2">
                    <span class="num">1</span>
                    <h3>{{ c.rules.panel_trigger }}</h3>
                </div>
                <div class="pb">
                    <p class="sent">{{ triggerSentence }}</p>
                </div>
            </section>
            <section class="wf-pnl">
                <div class="ph2">
                    <span class="num">2</span>
                    <h3>{{ c.rules.panel_conditions }}</h3>
                </div>
                <div class="pb">
                    <p class="sent">{{ conditionsSentence }}</p>
                </div>
            </section>
            <section class="wf-pnl">
                <div class="ph2">
                    <span class="num">3</span>
                    <h3>{{ c.rules.panel_actions }} · {{ c.rules.in_order }}</h3>
                </div>
                <div class="pb">
                    <ol class="sent-list">
                        <li v-for="(a, i) in actions" :key="i">{{ actionSentence(a) }}</li>
                    </ol>
                </div>
            </section>
            <section class="wf-pnl">
                <div class="ph2">
                    <h3>{{ c.rules.last_dry_run }}</h3>
                </div>
                <div class="pb">
                    <p class="sent">
                        {{
                            log.executions[0]
                                ? (log.executions[0].matched
                                      ? '✓ ' + c.rules.log_matched
                                      : '✗ ' + c.rules.log_unmatched) +
                                  ' · ' +
                                  hhmm(log.executions[0].executed_at)
                                : c.rules.log_empty
                        }}
                    </p>
                </div>
            </section>
        </div>

        <div v-else class="wf-ed2" :class="{ nodrawer: !drawerOpen }">
            <div class="wf-flow">
                <!-- 1 Trigger -->
                <section class="wf-pnl" data-testid="wf-panel-trigger">
                    <div class="ph2">
                        <span class="num">1</span>
                        <h3>{{ c.rules.panel_trigger }}</h3>
                        <span class="n">{{ c.rules.trigger_sub }}</span>
                    </div>
                    <div class="pb">
                        <div class="wf-g2">
                            <label class="bo-fld"
                                >{{ c.common.name
                                }}<input
                                    v-model="name"
                                    data-testid="wf-editor-name"
                                    class="bo-in"
                                    @input="dirty = true"
                            /></label>
                            <label class="bo-fld"
                                >{{ c.common.description
                                }}<input v-model="description" class="bo-in" @input="dirty = true"
                            /></label>
                        </div>
                        <label class="bo-fld"
                            >{{ c.rules.trigger_label
                            }}<select
                                v-model="trigger"
                                data-testid="wf-editor-trigger"
                                class="bo-in bo-mono"
                                @change="dirty = true"
                            >
                                <option v-for="t in triggers" :key="t" :value="t">{{ t }}</option>
                            </select></label
                        >
                        <div
                            class="wf-trgs"
                            :aria-label="c.rules.the_triggers"
                            data-testid="wf-trigger-chips"
                        >
                            <span v-for="t in triggers" :key="t" :class="{ on: t === trigger }">{{
                                t
                            }}</span>
                        </div>
                        <div class="wf-g3">
                            <template v-if="trigger === 'schedule'">
                                <div class="bo-fld">
                                    <label for="wf-cron">{{ c.rules.schedule_label }}</label>
                                    <input
                                        id="wf-cron"
                                        v-model="cron"
                                        data-testid="wf-editor-cron"
                                        class="bo-in bo-mono"
                                        @input="dirty = true"
                                    />
                                    <p class="bo-hint">{{ c.rules.schedule_floor_hint }}</p>
                                </div>
                                <div class="bo-fld">
                                    <span class="lab">{{
                                        tr(c.rules.next_run_label, { tz: timezone ?? '' })
                                    }}</span>
                                    <p class="bo-in" data-testid="wf-next-run">
                                        {{
                                            rule.schedule_next_run_at ?? c.rules.next_run_after_save
                                        }}
                                    </p>
                                    <p class="bo-hint">{{ c.rules.next_run_hint }}</p>
                                </div>
                            </template>
                            <div v-else class="bo-fld">
                                <label for="wf-cooldown">{{ c.rules.cooldown_label }}</label>
                                <input
                                    id="wf-cooldown"
                                    v-model.number="cooldown"
                                    type="number"
                                    min="0"
                                    max="86400"
                                    class="bo-in"
                                    @input="dirty = true"
                                />
                                <p class="bo-hint">{{ c.rules.cooldown_hint }}</p>
                            </div>
                            <div class="bo-fld">
                                <span class="lab">{{ c.rules.run_window_label }}</span>
                                <p class="bo-in">
                                    {{
                                        rule.run_window
                                            ? valueText(rule.run_window)
                                            : c.rules.run_window_none
                                    }}
                                </p>
                                <p class="bo-hint">{{ c.rules.run_window_hint }}</p>
                            </div>
                            <div class="bo-fld">
                                <span class="lab">{{ c.common.position }}</span>
                                <p class="bo-in">
                                    {{
                                        tr(c.rules.position_of, {
                                            n: rule.position,
                                            total: rule_count ?? rule.position,
                                        })
                                    }}
                                </p>
                                <p class="bo-hint">{{ c.rules.position_hint }}</p>
                            </div>
                        </div>
                    </div>
                </section>
                <div class="wf-conn" aria-hidden="true">↓</div>
                <!-- 2 Conditions -->
                <section class="wf-pnl" data-testid="wf-panel-conditions">
                    <div class="ph2">
                        <span class="num">2</span>
                        <h3>{{ c.rules.panel_conditions }}</h3>
                        <span class="n">{{ c.rules.conditions_sub }}</span>
                    </div>
                    <div class="pb">
                        <ConditionTree
                            v-model="conditions"
                            :copy="c"
                            :facts="facts"
                            :keyword-sets="keyword_sets"
                            @update:model-value="dirty = true"
                        />
                        <p class="bo-hint">{{ c.rules.facts_sentence }}</p>
                    </div>
                </section>
                <div class="wf-conn" aria-hidden="true">↓</div>
                <!-- 3 Actions -->
                <section class="wf-pnl" data-testid="wf-panel-actions">
                    <div class="ph2">
                        <span class="num">3</span>
                        <h3>{{ c.rules.panel_actions }}</h3>
                        <span class="n">{{ c.rules.actions_sub }}</span>
                    </div>
                    <div class="pb">
                        <ol class="wf-acards">
                            <li
                                v-for="(a, i) in actions"
                                :key="i"
                                class="wf-acard"
                                :class="{
                                    closed: CLOSED.includes(a.type),
                                    bad: cardProblem(a) !== '',
                                }"
                                :data-testid="`wf-action-${i}`"
                            >
                                <div class="ah">
                                    <span class="grip" aria-hidden="true">⋮⋮</span>
                                    <span class="num">{{ i + 1 }}</span>
                                    <span class="title">{{ actionWord(a.type) }}</span>
                                    <span v-if="CLOSED.includes(a.type)" class="closedtag">{{
                                        c.rules.closed_card
                                    }}</span>
                                    <span class="lb">
                                        <button
                                            type="button"
                                            :aria-label="c.rules.move_up_action"
                                            @click="moveAction(i, -1)"
                                        >
                                            ↑
                                        </button>
                                        <button
                                            type="button"
                                            :aria-label="c.rules.move_down_action"
                                            @click="moveAction(i, 1)"
                                        >
                                            ↓
                                        </button>
                                        <button
                                            type="button"
                                            :aria-label="c.rules.remove"
                                            @click="removeAction(i)"
                                        >
                                            ✕
                                        </button>
                                    </span>
                                </div>
                                <div class="ab">
                                    <template v-if="a.type === 'set_status'">
                                        <select
                                            :value="a.status"
                                            class="bo-in"
                                            :aria-label="actionWord(a.type)"
                                            @change="
                                                setField(
                                                    i,
                                                    'status',
                                                    ($event.target as HTMLSelectElement).value,
                                                )
                                            "
                                        >
                                            <option
                                                v-for="s in [
                                                    'new',
                                                    'open',
                                                    'pending_customer',
                                                    'pending_internal',
                                                    'resolved',
                                                    'closed',
                                                ]"
                                                :key="s"
                                                :value="s"
                                            >
                                                {{ s }}
                                            </option>
                                        </select>
                                    </template>
                                    <template v-else-if="a.type === 'set_priority'">
                                        <select
                                            :value="a.priority"
                                            class="bo-in"
                                            :aria-label="actionWord(a.type)"
                                            @change="
                                                setField(
                                                    i,
                                                    'priority',
                                                    ($event.target as HTMLSelectElement).value,
                                                )
                                            "
                                        >
                                            <option
                                                v-for="p in ['low', 'normal', 'high', 'urgent']"
                                                :key="p"
                                                :value="p"
                                            >
                                                {{ p }}
                                            </option>
                                        </select>
                                    </template>
                                    <template v-else-if="a.type === 'assign'">
                                        <select
                                            :value="a.queue_id"
                                            class="bo-in"
                                            :aria-label="actionWord(a.type)"
                                            @change="
                                                setField(
                                                    i,
                                                    'queue_id',
                                                    ($event.target as HTMLSelectElement).value,
                                                )
                                            "
                                        >
                                            <option value="">—</option>
                                            <option v-for="q in queues" :key="q.id" :value="q.id">
                                                {{ q.name }}
                                            </option>
                                        </select>
                                    </template>
                                    <template v-else-if="a.type === 'transfer_department'">
                                        <select
                                            :value="a.department_id"
                                            class="bo-in"
                                            :aria-label="actionWord(a.type)"
                                            @change="
                                                setField(
                                                    i,
                                                    'department_id',
                                                    ($event.target as HTMLSelectElement).value,
                                                )
                                            "
                                        >
                                            <option value="">—</option>
                                            <option
                                                v-for="d in departments"
                                                :key="d.id"
                                                :value="d.id"
                                            >
                                                {{ d.name }}
                                            </option>
                                        </select>
                                    </template>
                                    <template
                                        v-else-if="
                                            a.type === 'add_tags' || a.type === 'remove_tags'
                                        "
                                    >
                                        <input
                                            :value="list(a.tags).join(', ')"
                                            class="bo-in"
                                            :aria-label="actionWord(a.type)"
                                            :placeholder="c.rules.tags_placeholder"
                                            @change="
                                                setField(
                                                    i,
                                                    'tags',
                                                    splitList(
                                                        ($event.target as HTMLInputElement).value,
                                                    ),
                                                )
                                            "
                                        />
                                    </template>
                                    <template v-else-if="a.type === 'set_custom_field'">
                                        <div class="wf-g2">
                                            <select
                                                :value="a.key"
                                                class="bo-in"
                                                :aria-label="c.rules.fact"
                                                @change="
                                                    setField(
                                                        i,
                                                        'key',
                                                        ($event.target as HTMLSelectElement).value,
                                                    )
                                                "
                                            >
                                                <option
                                                    v-for="f in fields"
                                                    :key="f.id"
                                                    :value="f.key"
                                                >
                                                    {{ f.entity }} · {{ f.key }}
                                                </option>
                                            </select>
                                            <input
                                                :value="a.value"
                                                class="bo-in"
                                                :aria-label="c.rules.value"
                                                @change="
                                                    setField(
                                                        i,
                                                        'value',
                                                        ($event.target as HTMLInputElement).value,
                                                    )
                                                "
                                            />
                                        </div>
                                    </template>
                                    <template v-else-if="a.type === 'send_macro_reply'">
                                        <select
                                            :value="a.macro_id"
                                            class="bo-in"
                                            :aria-label="actionWord(a.type)"
                                            @change="
                                                setField(
                                                    i,
                                                    'macro_id',
                                                    ($event.target as HTMLSelectElement).value,
                                                )
                                            "
                                        >
                                            <option value="">—</option>
                                            <option v-for="m in macros" :key="m.id" :value="m.id">
                                                {{ m.scope }} · {{ m.name }}
                                            </option>
                                        </select>
                                    </template>
                                    <template
                                        v-else-if="
                                            a.type === 'add_note' ||
                                            a.type === 'escalate' ||
                                            a.type === 'suppress'
                                        "
                                    >
                                        <input
                                            :value="a.body ?? a.reason"
                                            class="bo-in"
                                            :aria-label="actionWord(a.type)"
                                            @change="
                                                setField(
                                                    i,
                                                    a.type === 'add_note' ? 'body' : 'reason',
                                                    ($event.target as HTMLInputElement).value,
                                                )
                                            "
                                        />
                                    </template>
                                    <template v-else-if="a.type === 'notify'">
                                        <div class="wf-g2">
                                            <input
                                                :value="list(a.user_ids).join(', ')"
                                                :placeholder="c.rules.notify_user_ids"
                                                :aria-label="c.rules.notify_user_ids"
                                                class="bo-in"
                                                @change="
                                                    setField(
                                                        i,
                                                        'user_ids',
                                                        splitList(
                                                            ($event.target as HTMLInputElement)
                                                                .value,
                                                        ),
                                                    )
                                                "
                                            />
                                            <input
                                                :value="list(a.role_ids).join(', ')"
                                                :placeholder="c.rules.notify_role_ids"
                                                :aria-label="c.rules.notify_role_ids"
                                                class="bo-in"
                                                @change="
                                                    setField(
                                                        i,
                                                        'role_ids',
                                                        splitList(
                                                            ($event.target as HTMLInputElement)
                                                                .value,
                                                        ),
                                                    )
                                                "
                                            />
                                        </div>
                                    </template>
                                    <template v-else-if="a.type === 'send_sms_template'">
                                        <input
                                            :value="a.template_ref"
                                            data-testid="wf-sms-template-ref"
                                            class="bo-in bo-mono"
                                            :class="{ bad: cardProblem(a) !== '' }"
                                            :aria-label="actionWord(a.type)"
                                            :placeholder="c.rules.sms_template_placeholder"
                                            @change="
                                                setField(
                                                    i,
                                                    'template_ref',
                                                    ($event.target as HTMLInputElement).value,
                                                )
                                            "
                                        />
                                    </template>
                                    <template v-else-if="a.type === 'webhook_out'">
                                        <select
                                            :value="a.destination_id"
                                            data-testid="wf-webhook-destination"
                                            class="bo-in"
                                            :aria-label="actionWord(a.type)"
                                            @change="
                                                setField(
                                                    i,
                                                    'destination_id',
                                                    ($event.target as HTMLSelectElement).value,
                                                )
                                            "
                                        >
                                            <option value="">—</option>
                                            <option
                                                v-for="d in destinations"
                                                :key="d.id"
                                                :value="d.id"
                                                :disabled="!!d.auto_disabled_at"
                                            >
                                                {{ d.name }} · {{ d.url
                                                }}{{
                                                    d.auto_disabled_at
                                                        ? ' ⚠ ' + c.rules.auto_disabled_short
                                                        : ''
                                                }}
                                            </option>
                                        </select>
                                    </template>
                                    <template v-else-if="a.type === 'run_ai_task'">
                                        <p
                                            v-if="aiOff"
                                            class="bo-warnbox wf-aioff"
                                            data-testid="wf-ai-off"
                                        >
                                            <span class="bo-tag ai"
                                                >✦ {{ c.rules.ai_off_tag }}</span
                                            >
                                            <b>{{ c.rules.ai_off_title }}</b>
                                            {{ c.rules.ai_off_body }}
                                        </p>
                                        <select
                                            :value="a.task"
                                            class="bo-in"
                                            :aria-label="actionWord(a.type)"
                                            @change="
                                                setField(
                                                    i,
                                                    'task',
                                                    ($event.target as HTMLSelectElement).value,
                                                )
                                            "
                                        >
                                            <option v-for="t in ai_tasks" :key="t" :value="t">
                                                {{ c.rules.ai_task[t] ?? t }}
                                            </option>
                                        </select>
                                    </template>
                                    <p v-if="helpFor(a.type)" class="help">{{ helpFor(a.type) }}</p>
                                    <p v-if="cardProblem(a)" class="bad-line">
                                        ✕ {{ cardProblem(a) }}
                                    </p>
                                </div>
                            </li>
                        </ol>
                        <div class="wf-addact">
                            <label
                                ><span class="sr-only">{{ c.rules.add_action }}</span
                                ><select
                                    data-testid="wf-action-add"
                                    class="bo-sel"
                                    @change="
                                        addAction(($event.target as HTMLSelectElement).value);
                                        ($event.target as HTMLSelectElement).value = '';
                                    "
                                >
                                    <option value="">+ {{ c.rules.add_action }} ▾</option>
                                    <option v-for="t in action_types" :key="t" :value="t">
                                        {{ actionWord(t) }}
                                    </option>
                                </select></label
                            >
                            <span>{{
                                tr(c.rules.types_sentence, {
                                    n: action_types.length,
                                    list: action_types.map(actionWord).join(' · '),
                                })
                            }}</span>
                        </div>
                        <ul
                            v-if="validation.length"
                            aria-live="assertive"
                            class="wf-alertl"
                            data-testid="wf-validation-summary"
                        >
                            <li
                                v-for="p in validation"
                                :key="p.index"
                                data-testid="wf-inline-validation"
                            >
                                {{
                                    tr(c.rules.cannot_save_yet, {
                                        n: p.index + 1,
                                        action: actionWord(actions[p.index]?.type ?? ''),
                                        problem: p.text,
                                    })
                                }}
                            </li>
                        </ul>
                        <WorkflowRefusal :text="refusal" />
                        <div class="wf-savebar">
                            <button
                                v-if="can['automation.rules.edit']"
                                type="button"
                                class="bo-btn"
                                data-testid="wf-save-draft-bar"
                                @click="saveDraft"
                            >
                                {{ c.common.save_draft }}
                            </button>
                            <button
                                v-if="can['automation.rules.activate']"
                                type="button"
                                class="bo-btn p"
                                data-testid="wf-activate-bar"
                                @click="activateOpen = true"
                            >
                                {{ c.rules.activate_revision }}
                            </button>
                            <span class="bo-hint">{{ blockedLine || c.rules.savebar_note }}</span>
                        </div>
                    </div>
                </section>
            </div>

            <!-- The dry-run drawer (FR-05) + the execution log -->
            <aside
                v-if="drawerOpen"
                id="dry-run"
                class="wf-dry"
                :aria-label="c.rules.dry_run_title"
                data-testid="wf-dry-run"
            >
                <div class="dh">
                    <b>{{ c.rules.dry_run_title }}</b
                    ><span class="bo-hint">{{ c.rules.dry_sub }}</span
                    ><button
                        type="button"
                        class="x"
                        :aria-label="c.common.cancel"
                        @click="drawerOpen = false"
                    >
                        ✕
                    </button>
                </div>
                <div class="db2">
                    <label class="bo-fld"
                        >{{ c.rules.dry_run_pick
                        }}<select v-model="dryEvent" data-testid="wf-dry-event" class="bo-in">
                            <option
                                v-for="e in recent_events"
                                :key="e.event_id"
                                :value="e.event_id"
                            >
                                {{ eventLabel(e) }}
                            </option>
                        </select></label
                    >
                    <p class="bo-hint">{{ c.rules.dry_run_help }}</p>
                    <p>
                        <button
                            type="button"
                            data-testid="wf-dry-run-go"
                            class="bo-btn sm p"
                            @click="runDry"
                        >
                            {{ c.rules.dry_run_run }}
                        </button>
                        <span class="bo-hint">{{ c.rules.zero_side_effects }}</span>
                    </p>
                    <div v-if="dryResult" data-testid="wf-dry-result">
                        <p
                            class="wf-verdict"
                            :class="dryResult.matched ? 'yes' : 'no'"
                            :data-matched="dryResult.matched"
                        >
                            {{
                                dryResult.matched
                                    ? '✓ ' + c.rules.dry_run_matched
                                    : '✗ ' + c.rules.dry_run_unmatched
                            }}<span v-for="g in dryResult.guards" :key="g" class="wf-gb">
                                ⛨ {{ tr(c.rules.dry_run_guard, { guard: g }) }}</span
                            >
                        </p>
                        <div class="wf-truth">
                            <div
                                v-for="(leaf, i) in dryResult.trace"
                                :key="i"
                                class="tr"
                                :data-testid="`wf-truth-${i}`"
                            >
                                <span class="tb" :class="leaf.matched ? 'y' : 'n'">{{
                                    leaf.matched
                                        ? '✓ ' + c.rules.dry_run_truth_true
                                        : '✗ ' + c.rules.dry_run_truth_false
                                }}</span>
                                <div>
                                    <div class="f">
                                        {{ leaf.fact }} {{ leaf.op }} {{ valueText(leaf.value) }}
                                    </div>
                                    <div class="ev">
                                        {{
                                            tr(c.rules.dry_run_actual, {
                                                value: valueText(leaf.actual),
                                            })
                                        }}
                                    </div>
                                </div>
                            </div>
                        </div>
                        <p v-if="dryResult.actions.length" class="bo-hint">
                            {{ c.rules.actions_in_order }}
                        </p>
                        <ol class="wf-dacts">
                            <li
                                v-for="(a, i) in dryResult.actions"
                                :key="i"
                                :data-testid="`wf-dry-action-${i}`"
                            >
                                <span class="k">{{ i + 1 }}</span> {{ actionWord(a.type)
                                }}<span class="st2">{{
                                    a.would_run
                                        ? c.rules.dry_run_would_run
                                        : tr(c.rules.dry_run_skipped, {
                                              note:
                                                  a.note ??
                                                  (dryResult.matched
                                                      ? ''
                                                      : c.rules.skipped_no_match),
                                          })
                                }}</span>
                            </li>
                        </ol>
                        <p
                            v-if="!dryResult.matched && firstMiss"
                            class="bo-hint"
                            data-testid="wf-first-miss"
                        >
                            {{ tr(c.rules.first_miss, { fact: firstMiss.fact }) }}
                        </p>
                        <p class="bo-hint">
                            {{
                                dryResult.guards.length
                                    ? tr(c.rules.guards_tripped, {
                                          list: dryResult.guards.join(', '),
                                      })
                                    : tr(c.rules.guards_none, {
                                          actions: actions.length,
                                          rules: guards.rules,
                                          limit_actions: guards.actions,
                                          depth: guards.depth,
                                      })
                            }}
                        </p>
                    </div>
                </div>
                <div class="foot">{{ c.rules.dry_foot }}</div>
            </aside>
        </div>

        <!-- Execution log (FR-06) -->
        <section id="log" class="wf-log" data-testid="wf-log">
            <div class="lh">
                <span>{{ c.rules.log_when }}</span
                ><span>{{ c.rules.log_event }}</span
                ><span>{{ c.rules.log_result }}</span
                ><span>{{ c.rules.panel_actions }}</span
                ><span>{{ c.rules.log_guards }}</span>
            </div>
            <p v-if="log.executions.length === 0" class="bo-empty">
                <b>{{ c.rules.log_empty }}</b>
            </p>
            <template v-for="x in log.executions" :key="x.id">
                <button
                    type="button"
                    class="lr"
                    :class="{ open: openLog === x.id }"
                    :aria-expanded="openLog === x.id"
                    :data-testid="`wf-log-row-${x.id}`"
                    @click="openLog = openLog === x.id ? null : x.id"
                >
                    <span>{{ x.executed_at.replace('T', ' ').slice(0, 16) }}</span>
                    <span class="bo-mono">{{
                        x.event_id ? x.event_id.slice(0, 12) + '…' : '—'
                    }}</span>
                    <span :class="x.matched ? 'd-cur' : 'd-mis'">{{
                        x.matched ? '✓ ' + c.rules.log_matched : '✗ ' + c.rules.log_unmatched
                    }}</span>
                    <span class="oc"
                        ><span v-for="(o, j) in x.action_outcomes" :key="j" :class="outcomeClass(o)"
                            >{{ o.action }}{{ o.outcome === 'failed' ? ' ✕' : '' }}</span
                        ><span v-if="!x.action_outcomes.length" class="skip">{{
                            c.rules.log_no_actions
                        }}</span></span
                    >
                    <span
                        ><span v-for="g in x.guard_trips" :key="g" class="wf-gb"
                            >⛨ {{ g }}</span
                        ></span
                    >
                </button>
                <div v-if="openLog === x.id" class="ldet" :data-testid="`wf-log-detail-${x.id}`">
                    <dl class="bo-kv2">
                        <dt>{{ c.rules.log_event }}</dt>
                        <dd>
                            <span class="bo-mono">{{ x.event_id ?? '—' }}</span
                            ><template v-if="x.ticket_id">
                                ·
                                <a class="bo-link" :href="`/inbox?ticket=${x.ticket_id}`">{{
                                    c.rules.open_ticket
                                }}</a></template
                            >
                            ·
                            <a
                                class="bo-link"
                                :href="`/admin/workflow/rules/${rule.id}/executions/${x.id}`"
                                :data-testid="`wf-log-open-${x.id}`"
                                >{{ c.rules.open_execution }}</a
                            >
                        </dd>
                        <dt>{{ c.rules.panel_conditions }}</dt>
                        <dd>
                            <span v-for="(l, i) in x.conditions ?? []" :key="i" class="cline"
                                >{{ l.matched ? '✓' : '✗' }} {{ l.fact }} {{ l.op }}
                                {{ valueText(l.value) }} → {{ valueText(l.actual) }}</span
                            >
                        </dd>
                        <dt>{{ c.rules.panel_actions }}</dt>
                        <dd>
                            <span v-for="(o, j) in x.action_outcomes" :key="j" class="cline"
                                >{{ j + 1 }} {{ o.action }} → {{ o.outcome
                                }}{{ o.reason ? ' — ' + o.reason : '' }}</span
                            >
                        </dd>
                        <dt v-if="x.guard_trips.length">{{ c.rules.log_guards }}</dt>
                        <dd v-if="x.guard_trips.length">{{ x.guard_trips.join(' · ') }}</dd>
                    </dl>
                </div>
            </template>
            <p class="bo-hint wf-log-note">
                {{
                    can['automation.rules.view']
                        ? c.rules.log_privileged_note
                        : c.rules.log_elided_note
                }}
            </p>
        </section>

        <!-- Activate confirmation (F08) -->
        <div v-if="activateOpen" class="bo-scrim" @click.self="activateOpen = false">
            <div
                class="bo-dlg"
                role="dialog"
                aria-modal="true"
                :aria-label="c.rules.activate_revision"
                data-testid="wf-activate-dialog"
            >
                <h3 class="dt">{{ c.rules.activate_revision }}</h3>
                <div class="db">
                    <p>{{ tr(c.rules.activate_swap, { name: rule.name }) }}</p>
                    <dl class="bo-row2">
                        <dt>{{ c.rules.row_changed }}</dt>
                        <dd>{{ dirty ? c.rules.changed_unsaved : c.rules.changed_saved }}</dd>
                        <dt>{{ c.rules.row_dry_run }}</dt>
                        <dd>
                            <span class="bo-chip" :class="dryResult ? 'ok' : 'warn'">{{
                                dryResult
                                    ? tr(c.rules.dry_run_done_chip, {
                                          verdict: dryResult.matched
                                              ? c.rules.log_matched
                                              : c.rules.log_unmatched,
                                          time: dryRanAt,
                                      })
                                    : c.rules.dry_run_not_run
                            }}</span>
                            {{ c.rules.review_habit }}
                        </dd>
                        <dt>{{ c.rules.row_capability }}</dt>
                        <dd>{{ capabilityLine }}</dd>
                        <dt>{{ c.rules.row_history }}</dt>
                        <dd>{{ c.rules.history_kept }}</dd>
                        <dt>{{ c.rules.row_audited }}</dt>
                        <dd>{{ c.rules.audited_activate }}</dd>
                    </dl>
                    <p v-if="validation.length" class="bo-refuse" role="alert">{{ blockedLine }}</p>
                </div>
                <div class="df">
                    <button type="button" class="bo-btn" @click="activateOpen = false">
                        {{ c.common.cancel }}
                    </button>
                    <button
                        type="button"
                        class="bo-btn p"
                        data-testid="wf-activate-confirm"
                        @click="activate"
                    >
                        {{ c.rules.activate_verb }}
                    </button>
                </div>
            </div>
        </div>
    </WorkflowShell>
</template>

<style>
.wf-ed2 {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 380px;
    gap: 16px;
    align-items: start;
}
.wf-ed2.nodrawer {
    grid-template-columns: minmax(0, 1fr);
}
.wf-flow {
    display: grid;
    gap: 0;
    min-width: 0;
}
.wf-pnl {
    background: var(--ds-panel-bg);
    border: 1px solid var(--ds-border);
    border-radius: 8px;
}
.wf-pnl .ph2 {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 14px;
    border-block-end: 1px solid var(--ds-border-soft);
}
.wf-pnl .ph2 .num {
    width: 24px;
    height: 24px;
    border-radius: 50%;
    background: var(--ds-accent);
    color: var(--ds-accent-contrast);
    display: grid;
    place-items: center;
    font-size: 11.5px;
    font-weight: 700;
    flex: none;
}
.wf-pnl .ph2 h3 {
    margin: 0;
    font-size: 13.5px;
    font-weight: 600;
}
.wf-pnl .ph2 .n {
    margin-inline-start: auto;
    font-size: 11px;
    color: var(--ds-text-muted);
    text-align: end;
}
.wf-pnl .pb {
    padding: 12px 14px;
    display: grid;
    gap: 10px;
}
.wf-pnl .sent {
    font-size: 13px;
    line-height: 1.55;
    margin: 0;
}
.wf-pnl .sent-list {
    margin: 0;
    padding-inline-start: 18px;
    font-size: 13px;
    line-height: 1.55;
}
.wf-conn {
    height: 22px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--ds-text-muted);
    font-size: 14px;
}
.wf-g2 {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
}
.wf-g3 {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    gap: 10px;
}
.wf-pnl .bo-fld {
    display: grid;
    gap: 4px;
    font-size: 11.5px;
    font-weight: 600;
    color: var(--ds-text-secondary);
}
.wf-pnl .bo-fld .lab {
    font-size: 11.5px;
    font-weight: 600;
}
.wf-pnl .bo-in {
    font-weight: 400;
    margin: 0;
}
.wf-trgs {
    display: flex;
    gap: 4px;
    flex-wrap: wrap;
}
.wf-trgs span {
    font-family: ui-monospace, monospace;
    font-size: 10.5px;
    padding: 3px 7px;
    border: 1px solid var(--ds-border);
    border-radius: 999px;
    color: var(--ds-text-secondary);
}
.wf-trgs span.on {
    background: var(--ds-accent);
    color: var(--ds-accent-contrast);
    border-color: var(--ds-accent);
}
.wf-acards {
    list-style: none;
    margin: 0;
    padding: 0;
    display: grid;
    gap: 8px;
}
.wf-acard {
    border: 1px solid var(--ds-border);
    border-radius: 8px;
    background: var(--ds-panel-soft);
}
.wf-acard.closed {
    border-style: dashed;
}
.wf-acard.bad {
    border-color: var(--ds-danger-border);
}
.wf-acard .ah {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 10px;
    border-block-end: 1px solid var(--ds-border-soft);
    font-size: 12.5px;
    font-weight: 600;
    flex-wrap: wrap;
}
.wf-acard .ah .num {
    font-family: ui-monospace, monospace;
    font-size: 11px;
    color: var(--ds-text-muted);
}
.wf-acard .ah .grip {
    color: var(--ds-text-muted);
    cursor: grab;
}
.wf-acard .ah .closedtag {
    font-size: 10px;
    font-weight: 600;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--ds-text-muted);
}
.wf-acard .ah .lb {
    margin-inline-start: auto;
    display: flex;
    gap: 4px;
}
.wf-acard .ah .lb button {
    width: 26px;
    height: 26px;
    border: 1px solid var(--ds-border-strong);
    border-radius: 4px;
    background: var(--ds-panel-bg);
    font-size: 11px;
    color: var(--ds-text-primary);
    cursor: pointer;
}
.wf-acard .ab {
    padding: 10px;
    display: grid;
    gap: 8px;
    font-size: 12px;
}
.wf-acard .ab .bo-in {
    min-height: 34px;
    padding: 6px 9px;
    font-size: 12px;
    background: var(--ds-panel-bg);
}
.wf-acard .help {
    font-size: 11px;
    color: var(--ds-text-muted);
    line-height: 1.45;
    margin: 0;
}
.wf-acard .bad-line {
    display: flex;
    gap: 6px;
    font-size: 11.5px;
    color: var(--ds-danger-text);
    margin: 0;
}
.wf-addact {
    display: flex;
    gap: 8px;
    align-items: center;
    flex-wrap: wrap;
    font-size: 11.5px;
    color: var(--ds-text-muted);
}
.wf-alertl {
    border: 1px solid var(--ds-danger-border);
    background: var(--ds-danger-bg);
    border-radius: 8px;
    padding: 8px 12px;
    font-size: 12px;
    color: var(--ds-text-primary);
    margin: 0;
    list-style: none;
}
.wf-savebar {
    display: flex;
    gap: 8px;
    align-items: center;
    flex-wrap: wrap;
    padding: 6px 0 0;
}
.wf-dry {
    background: var(--ds-panel-bg);
    border: 1px solid var(--ds-border);
    border-radius: 8px;
    display: flex;
    flex-direction: column;
    min-height: 560px;
}
.wf-dry .dh {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 12px;
    border-block-end: 1px solid var(--ds-border-soft);
}
.wf-dry .dh b {
    font-size: 12.5px;
}
.wf-dry .dh .x {
    margin-inline-start: auto;
    width: 28px;
    height: 28px;
    border: 1px solid var(--ds-border);
    border-radius: 6px;
    display: grid;
    place-items: center;
    font-size: 12px;
    background: var(--ds-panel-bg);
    cursor: pointer;
}
.wf-dry .db2 {
    padding: 10px 12px;
    display: grid;
    gap: 10px;
    font-size: 12px;
}
.wf-dry .db2 p {
    margin: 0;
}
.wf-dry .foot {
    margin-top: auto;
    padding: 10px 12px;
    border-block-start: 1px solid var(--ds-border-soft);
    font-size: 11px;
    color: var(--ds-text-muted);
}
.wf-verdict {
    padding: 8px 10px;
    border-radius: 6px;
    font-weight: 700;
    font-size: 12.5px;
    display: flex;
    gap: 8px;
    align-items: center;
    flex-wrap: wrap;
}
.wf-verdict.yes {
    background: var(--ds-success-bg);
    color: var(--ds-success-text);
}
.wf-verdict.no {
    background: var(--ds-inset-bg);
    color: var(--ds-text-secondary);
}
.wf-truth {
    display: grid;
    gap: 6px;
    margin-top: 8px;
}
.wf-truth .tr {
    display: grid;
    grid-template-columns: auto minmax(0, 1fr);
    gap: 8px;
    align-items: start;
    padding: 6px 8px;
    border: 1px solid var(--ds-border-soft);
    border-radius: 6px;
}
.tb {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    height: 20px;
    padding: 0 7px;
    border-radius: 999px;
    font-size: 10.5px;
    font-weight: 700;
    white-space: nowrap;
}
.tb.y {
    background: var(--ds-success-bg);
    color: var(--ds-success-text);
}
.tb.n {
    background: var(--ds-inset-bg);
    color: var(--ds-text-muted);
}
.wf-truth .f {
    font-family: ui-monospace, monospace;
    font-size: 11px;
    overflow-wrap: anywhere;
}
.wf-truth .ev {
    font-size: 11px;
    color: var(--ds-text-muted);
    margin-top: 2px;
}
.wf-dacts {
    list-style: none;
    margin: 0;
    padding: 0;
    display: grid;
    gap: 4px;
}
.wf-dacts li {
    display: flex;
    gap: 8px;
    align-items: center;
    font-size: 12px;
    padding: 4px 0;
    border-block-end: 1px dashed var(--ds-border-soft);
}
.wf-dacts .k {
    font-family: ui-monospace, monospace;
    font-size: 10.5px;
    color: var(--ds-text-muted);
    width: 16px;
}
.wf-dacts .st2 {
    margin-inline-start: auto;
    font-size: 11px;
    font-weight: 600;
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
.wf-st {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 11.5px;
    font-weight: 600;
}
.wf-log {
    background: var(--ds-panel-bg);
    border: 1px solid var(--ds-border);
    border-radius: 8px;
    overflow: hidden;
}
.wf-log .lh,
.wf-log .lr {
    display: grid;
    grid-template-columns: 130px 150px 100px minmax(0, 1fr) 130px;
    gap: 10px;
    padding: 0 12px;
    align-items: center;
    width: 100%;
    text-align: start;
}
.wf-log .lh {
    height: 32px;
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.07em;
    color: var(--ds-text-label);
    background: var(--ds-panel-soft);
    border-block-end: 1px solid var(--ds-border-soft);
}
.wf-log .lr {
    min-height: 44px;
    padding-top: 8px;
    padding-bottom: 8px;
    border: 0;
    border-block-end: 1px solid var(--ds-border-soft);
    font-size: 12px;
    background: var(--ds-panel-bg);
    color: var(--ds-text-primary);
    cursor: pointer;
    font-family: inherit;
}
.wf-log .lr.open {
    background: var(--ds-inset-bg);
}
.wf-log .oc {
    display: flex;
    gap: 4px;
    flex-wrap: wrap;
}
.wf-log .oc span {
    font-size: 10.5px;
    padding: 1px 6px;
    border-radius: 4px;
    border: 1px solid var(--ds-border);
}
.wf-log .oc .ok {
    color: var(--ds-success-text);
    border-color: transparent;
    background: var(--ds-success-bg);
}
.wf-log .oc .fail {
    color: var(--ds-danger-text);
    border-color: transparent;
    background: var(--ds-danger-bg);
}
.wf-log .oc .skip {
    color: var(--ds-text-muted);
}
.wf-log .ldet {
    padding: 10px 12px 12px;
    border-block-end: 1px solid var(--ds-border-soft);
    background: var(--ds-panel-soft);
    font-size: 12px;
}
.wf-log .ldet .bo-kv2 {
    grid-template-columns: 110px 1fr;
}
.wf-log .cline {
    display: block;
}
.wf-log-note {
    padding: 8px 12px;
    margin: 0;
}
@media (max-width: 1023px) {
    .wf-ed2 {
        grid-template-columns: 1fr;
    }
    .wf-g2,
    .wf-g3 {
        grid-template-columns: 1fr;
    }
    .wf-log .lh {
        display: none;
    }
    .wf-log .lr {
        grid-template-columns: 1fr;
        gap: 4px;
    }
}
</style>
