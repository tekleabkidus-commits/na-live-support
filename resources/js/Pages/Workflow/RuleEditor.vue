<script setup lang="ts">
// Doc 12 §8 rule editor (S12 step 14): three panels — Trigger → Conditions (ConditionTree) → Actions
// (ordered cards, per-type config; the external arms' CLOSED cards render only their grammar's keys —
// an SMS card offers a template reference and nothing else, a webhook card a destination pick) — with
// inline validation, the DRY-RUN DRAWER alongside (FR-05; §9's mandatory ✓/✗ truth badges with the
// evaluated value, amber shield guard badges) and save-as-draft vs activate (FR-07). The execution
// log (FR-06) sits below. J19: editing needs ≥ 1024 px (read-only under it). [B12-batch].
import { computed, ref } from 'vue';
import WorkflowShell from '../../Components/Workflow/WorkflowShell.vue';
import WorkflowRefusal from '../../Components/Workflow/WorkflowRefusal.vue';
import ConditionTree from '../../Components/Workflow/ConditionTree.vue';
import { post, tr } from '../../Components/Workflow/workflowApi';

type Action = Record<string, unknown> & { type: string };
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
    log: {
        matched_count: number;
        last_run_at: string | null;
        executions: {
            id: string;
            matched: boolean;
            executed_at: string;
            action_outcomes: { action: string; outcome: string; reason?: string }[];
            guard_trips: string[];
        }[];
    };
    recent_events: { id: string; name: string; created_at?: string }[];
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
const saved = ref('');
const dryEvent = ref(props.recent_events[0]?.id ?? '');
const dryResult = ref<{
    matched: boolean;
    trace: { fact: string; op: string; value: unknown; actual: unknown; matched: boolean }[];
    actions: { type: string; would_run: boolean; note: string | null }[];
    guards: string[];
} | null>(null);

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
    actions.value = [...actions.value, cardTemplate(type)];
}
function moveAction(i: number, d: number): void {
    const j = i + d;
    if (j < 0 || j >= actions.value.length) return;
    const next = [...actions.value];
    [next[i], next[j]] = [next[j] as Action, next[i] as Action];
    actions.value = next;
}
function setField(i: number, key: string, value: unknown): void {
    const next = [...actions.value];
    next[i] = { ...(next[i] as Action), [key]: value };
    actions.value = next;
}
const validation = computed<string[]>(() => {
    const problems: string[] = [];
    actions.value.forEach((a) => {
        if (a.type === 'send_sms_template' && !String(a.template_ref ?? '').trim())
            problems.push(c.rules.sms_template_missing);
    });
    return problems;
});
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
async function save(activate: boolean): Promise<void> {
    refusal.value = '';
    saved.value = '';
    const result = await post<{ revision_id: string }>(
        `/admin/workflow/rules/${props.rule.id}`,
        payload(),
    );
    if (!result.ok) {
        refusal.value = tr(c.common.refused, { detail: result.refusal });
        return;
    }
    if (activate) {
        const act = await post(`/admin/workflow/rules/${props.rule.id}/activate`, {
            revision_id: result.data.revision_id,
        });
        if (!act.ok) {
            refusal.value = tr(c.common.refused, { detail: act.refusal });
            return;
        }
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
</script>

<template>
    <WorkflowShell :copy="c" active="rules" :title="c.rules.editor_title" :summary="rule.name">
        <p
            v-if="readonly"
            role="status"
            class="mb-3 rounded-md border px-3 py-2 text-xs"
            :style="{ borderColor: 'var(--ds-border)', background: 'var(--ds-inset-bg)' }"
        >
            {{ c.hub.readonly_notice }}
        </p>
        <div class="grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
            <div class="flex flex-col gap-4">
                <!-- Panel 1: trigger -->
                <section
                    class="rounded-lg border p-3"
                    :style="{ background: 'var(--ds-panel-bg)', borderColor: 'var(--ds-border)' }"
                    data-testid="wf-panel-trigger"
                >
                    <h3 class="text-sm font-semibold">{{ c.rules.panel_trigger }}</h3>
                    <div class="mt-2 grid grid-cols-1 gap-2 md:grid-cols-2 text-xs">
                        <label
                            ><span class="block" :style="{ color: 'var(--ds-text-label)' }">{{
                                c.common.name
                            }}</span
                            ><input
                                v-model="name"
                                :disabled="readonly"
                                data-testid="wf-editor-name"
                                class="mt-1 w-full rounded-md border px-2 py-1.5 text-sm"
                                :style="{
                                    borderColor: 'var(--ds-border)',
                                    background: 'var(--ds-inset-bg)',
                                }"
                        /></label>
                        <label
                            ><span class="block" :style="{ color: 'var(--ds-text-label)' }">{{
                                c.rules.trigger_label
                            }}</span
                            ><select
                                v-model="trigger"
                                :disabled="readonly"
                                data-testid="wf-editor-trigger"
                                class="mt-1 w-full rounded-md border px-2 py-1.5 text-sm"
                                :style="{
                                    borderColor: 'var(--ds-border)',
                                    background: 'var(--ds-inset-bg)',
                                }"
                            >
                                <option v-for="t in triggers" :key="t" :value="t">{{ t }}</option>
                            </select></label
                        >
                        <label class="md:col-span-2"
                            ><span class="block" :style="{ color: 'var(--ds-text-label)' }">{{
                                c.common.description
                            }}</span
                            ><input
                                v-model="description"
                                :disabled="readonly"
                                class="mt-1 w-full rounded-md border px-2 py-1.5 text-sm"
                                :style="{
                                    borderColor: 'var(--ds-border)',
                                    background: 'var(--ds-inset-bg)',
                                }"
                        /></label>
                        <label v-if="trigger === 'schedule'"
                            ><span class="block" :style="{ color: 'var(--ds-text-label)' }">{{
                                c.rules.schedule_label
                            }}</span
                            ><input
                                v-model="cron"
                                :disabled="readonly"
                                data-testid="wf-editor-cron"
                                class="mt-1 w-full rounded-md border px-2 py-1.5 font-mono text-sm"
                                :style="{
                                    borderColor: 'var(--ds-border)',
                                    background: 'var(--ds-inset-bg)',
                                }"
                            /><span
                                v-if="rule.schedule_next_run_at"
                                class="mt-1 block"
                                :style="{ color: 'var(--ds-text-muted)' }"
                                >{{
                                    tr(c.rules.schedule_preview, { at: rule.schedule_next_run_at })
                                }}</span
                            ></label
                        >
                        <label
                            ><span class="block" :style="{ color: 'var(--ds-text-label)' }">{{
                                c.rules.cooldown_label
                            }}</span
                            ><input
                                v-model.number="cooldown"
                                type="number"
                                min="0"
                                max="86400"
                                :disabled="readonly"
                                class="mt-1 w-full rounded-md border px-2 py-1.5 text-sm"
                                :style="{
                                    borderColor: 'var(--ds-border)',
                                    background: 'var(--ds-inset-bg)',
                                }"
                        /></label>
                    </div>
                </section>
                <!-- Panel 2: conditions -->
                <section
                    class="rounded-lg border p-3"
                    :style="{ background: 'var(--ds-panel-bg)', borderColor: 'var(--ds-border)' }"
                    data-testid="wf-panel-conditions"
                >
                    <h3 class="text-sm font-semibold">{{ c.rules.panel_conditions }}</h3>
                    <div class="mt-2">
                        <ConditionTree
                            v-model="conditions"
                            :copy="c"
                            :facts="facts"
                            :keyword-sets="keyword_sets"
                        />
                    </div>
                </section>
                <!-- Panel 3: actions -->
                <section
                    class="rounded-lg border p-3"
                    :style="{ background: 'var(--ds-panel-bg)', borderColor: 'var(--ds-border)' }"
                    data-testid="wf-panel-actions"
                >
                    <h3 class="text-sm font-semibold">{{ c.rules.panel_actions }}</h3>
                    <ol class="mt-2 flex flex-col gap-2">
                        <li
                            v-for="(a, i) in actions"
                            :key="i"
                            class="rounded-md border p-2 text-xs"
                            :style="{
                                borderColor: 'var(--ds-border)',
                                background: 'var(--ds-inset-bg)',
                            }"
                            :data-testid="`wf-action-${i}`"
                        >
                            <div class="flex items-center gap-2">
                                <span class="font-semibold"
                                    >{{ i + 1 }}. {{ c.rules.action_card[a.type] ?? a.type }}</span
                                >
                                <span class="ms-auto flex gap-1">
                                    <button
                                        type="button"
                                        :aria-label="c.common.move_up"
                                        class="rounded border px-1.5"
                                        :style="{ borderColor: 'var(--ds-border)' }"
                                        @click="moveAction(i, -1)"
                                    >
                                        ↑
                                    </button>
                                    <button
                                        type="button"
                                        :aria-label="c.common.move_down"
                                        class="rounded border px-1.5"
                                        :style="{ borderColor: 'var(--ds-border)' }"
                                        @click="moveAction(i, 1)"
                                    >
                                        ↓
                                    </button>
                                    <button
                                        type="button"
                                        :aria-label="c.rules.remove"
                                        class="rounded border px-1.5"
                                        :style="{ borderColor: 'var(--ds-border)' }"
                                        @click="actions = actions.filter((_, j) => j !== i)"
                                    >
                                        ✕
                                    </button>
                                </span>
                            </div>
                            <div class="mt-2 flex flex-wrap gap-2">
                                <template v-if="a.type === 'set_status'"
                                    ><select
                                        :value="a.status"
                                        class="rounded-md border px-2 py-1"
                                        :style="{ borderColor: 'var(--ds-border)' }"
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
                                    </select></template
                                >
                                <template v-else-if="a.type === 'set_priority'"
                                    ><select
                                        :value="a.priority"
                                        class="rounded-md border px-2 py-1"
                                        :style="{ borderColor: 'var(--ds-border)' }"
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
                                    </select></template
                                >
                                <template v-else-if="a.type === 'assign'"
                                    ><select
                                        :value="a.queue_id"
                                        class="rounded-md border px-2 py-1"
                                        :style="{ borderColor: 'var(--ds-border)' }"
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
                                    </select></template
                                >
                                <template v-else-if="a.type === 'transfer_department'"
                                    ><select
                                        :value="a.department_id"
                                        class="rounded-md border px-2 py-1"
                                        :style="{ borderColor: 'var(--ds-border)' }"
                                        @change="
                                            setField(
                                                i,
                                                'department_id',
                                                ($event.target as HTMLSelectElement).value,
                                            )
                                        "
                                    >
                                        <option value="">—</option>
                                        <option v-for="d in departments" :key="d.id" :value="d.id">
                                            {{ d.name }}
                                        </option>
                                    </select></template
                                >
                                <template
                                    v-else-if="a.type === 'add_tags' || a.type === 'remove_tags'"
                                    ><input
                                        :value="((a.tags as string[]) ?? []).join(', ')"
                                        class="rounded-md border px-2 py-1"
                                        :style="{ borderColor: 'var(--ds-border)' }"
                                        @change="
                                            setField(
                                                i,
                                                'tags',
                                                ($event.target as HTMLInputElement).value
                                                    .split(',')
                                                    .map((s) => s.trim())
                                                    .filter((s) => s),
                                            )
                                        "
                                /></template>
                                <template v-else-if="a.type === 'set_custom_field'"
                                    ><select
                                        :value="a.key"
                                        class="rounded-md border px-2 py-1"
                                        :style="{ borderColor: 'var(--ds-border)' }"
                                        @change="
                                            setField(
                                                i,
                                                'key',
                                                ($event.target as HTMLSelectElement).value,
                                            )
                                        "
                                    >
                                        <option v-for="f in fields" :key="f.id" :value="f.key">
                                            {{ f.entity }} · {{ f.key }}
                                        </option></select
                                    ><input
                                        :value="a.value"
                                        class="rounded-md border px-2 py-1"
                                        :style="{ borderColor: 'var(--ds-border)' }"
                                        @change="
                                            setField(
                                                i,
                                                'value',
                                                ($event.target as HTMLInputElement).value,
                                            )
                                        "
                                /></template>
                                <template v-else-if="a.type === 'send_macro_reply'"
                                    ><select
                                        :value="a.macro_id"
                                        class="rounded-md border px-2 py-1"
                                        :style="{ borderColor: 'var(--ds-border)' }"
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
                                            {{ m.name }}
                                        </option>
                                    </select></template
                                >
                                <template
                                    v-else-if="
                                        a.type === 'add_note' ||
                                        a.type === 'escalate' ||
                                        a.type === 'suppress'
                                    "
                                    ><input
                                        :value="a.body ?? a.reason"
                                        class="w-full rounded-md border px-2 py-1"
                                        :style="{ borderColor: 'var(--ds-border)' }"
                                        @change="
                                            setField(
                                                i,
                                                a.type === 'add_note' ? 'body' : 'reason',
                                                ($event.target as HTMLInputElement).value,
                                            )
                                        "
                                /></template>
                                <template v-else-if="a.type === 'notify'"
                                    ><input
                                        :value="((a.user_ids as string[]) ?? []).join(', ')"
                                        :placeholder="c.rules.notify_user_ids"
                                        class="rounded-md border px-2 py-1"
                                        :style="{ borderColor: 'var(--ds-border)' }"
                                        @change="
                                            setField(
                                                i,
                                                'user_ids',
                                                ($event.target as HTMLInputElement).value
                                                    .split(',')
                                                    .map((s) => s.trim())
                                                    .filter((s) => s),
                                            )
                                        " /><input
                                        :value="((a.role_ids as string[]) ?? []).join(', ')"
                                        :placeholder="c.rules.notify_role_ids"
                                        class="rounded-md border px-2 py-1"
                                        :style="{ borderColor: 'var(--ds-border)' }"
                                        @change="
                                            setField(
                                                i,
                                                'role_ids',
                                                ($event.target as HTMLInputElement).value
                                                    .split(',')
                                                    .map((s) => s.trim())
                                                    .filter((s) => s),
                                            )
                                        "
                                /></template>
                                <template v-else-if="a.type === 'send_sms_template'">
                                    <input
                                        :value="a.template_ref"
                                        data-testid="wf-sms-template-ref"
                                        class="rounded-md border px-2 py-1"
                                        :style="{ borderColor: 'var(--ds-border)' }"
                                        @change="
                                            setField(
                                                i,
                                                'template_ref',
                                                ($event.target as HTMLInputElement).value,
                                            )
                                        "
                                    />
                                    <span
                                        class="w-full"
                                        :style="{ color: 'var(--ds-text-muted)' }"
                                        >{{ c.rules.sms_template_help }}</span
                                    >
                                </template>
                                <template v-else-if="a.type === 'webhook_out'">
                                    <select
                                        :value="a.destination_id"
                                        data-testid="wf-webhook-destination"
                                        class="rounded-md border px-2 py-1"
                                        :style="{ borderColor: 'var(--ds-border)' }"
                                        @change="
                                            setField(
                                                i,
                                                'destination_id',
                                                ($event.target as HTMLSelectElement).value,
                                            )
                                        "
                                    >
                                        <option value="">—</option>
                                        <option v-for="d in destinations" :key="d.id" :value="d.id">
                                            {{ d.name }}{{ d.auto_disabled_at ? ' ⚠' : '' }}
                                        </option>
                                    </select>
                                    <span
                                        class="w-full"
                                        :style="{ color: 'var(--ds-text-muted)' }"
                                        >{{ c.rules.webhook_destination_help }}</span
                                    >
                                </template>
                                <template v-else-if="a.type === 'run_ai_task'"
                                    ><select
                                        :value="a.task"
                                        class="rounded-md border px-2 py-1"
                                        :style="{ borderColor: 'var(--ds-border)' }"
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
                                    </select></template
                                >
                            </div>
                        </li>
                    </ol>
                    <div class="mt-2 flex flex-wrap items-center gap-2 text-xs">
                        <label
                            ><span class="sr-only">{{ c.rules.add_action }}</span
                            ><select
                                data-testid="wf-action-add"
                                class="rounded-md border px-2 py-1"
                                :style="{ borderColor: 'var(--ds-border)' }"
                                @change="
                                    addAction(($event.target as HTMLSelectElement).value);
                                    ($event.target as HTMLSelectElement).value = '';
                                "
                            >
                                <option value="">{{ c.rules.add_action }}</option>
                                <option v-for="t in action_types" :key="t" :value="t">
                                    {{ c.rules.action_card[t] ?? t }}
                                </option>
                            </select></label
                        >
                    </div>
                    <ul
                        v-if="validation.length"
                        role="alert"
                        class="mt-2 text-xs"
                        :style="{ color: 'var(--ds-danger-text)' }"
                    >
                        <li v-for="p in validation" :key="p" data-testid="wf-inline-validation">
                            {{ p }}
                        </li>
                    </ul>
                </section>
                <WorkflowRefusal :text="refusal" />
                <div v-if="!readonly" class="flex gap-2">
                    <button
                        v-if="can['automation.rules.edit']"
                        type="button"
                        data-testid="wf-save-draft"
                        :disabled="validation.length > 0"
                        class="rounded-lg border px-3 py-2 text-xs font-semibold"
                        :style="{ borderColor: 'var(--ds-border)' }"
                        @click="save(false)"
                    >
                        {{ c.common.save_draft }}
                    </button>
                    <button
                        v-if="can['automation.rules.activate']"
                        type="button"
                        data-testid="wf-save-activate"
                        :disabled="validation.length > 0"
                        class="rounded-lg px-3 py-2 text-xs font-semibold"
                        :style="{
                            background: 'var(--ds-accent)',
                            color: 'var(--ds-accent-contrast)',
                        }"
                        @click="save(true)"
                    >
                        {{ c.rules.activate_verb }}
                    </button>
                </div>
            </div>
            <!-- The dry-run drawer (FR-05) -->
            <aside
                class="rounded-lg border p-3"
                :style="{ background: 'var(--ds-panel-bg)', borderColor: 'var(--ds-border)' }"
                data-testid="wf-dry-run"
                :aria-label="c.rules.dry_run_title"
            >
                <h3 class="text-sm font-semibold">{{ c.rules.dry_run_title }}</h3>
                <p class="mt-1 text-xs" :style="{ color: 'var(--ds-text-muted)' }">
                    {{ c.rules.dry_run_help }}
                </p>
                <label class="mt-2 block text-xs"
                    ><span class="block" :style="{ color: 'var(--ds-text-label)' }">{{
                        c.rules.dry_run_pick
                    }}</span
                    ><select
                        v-model="dryEvent"
                        data-testid="wf-dry-event"
                        class="mt-1 w-full rounded-md border px-2 py-1.5"
                        :style="{
                            borderColor: 'var(--ds-border)',
                            background: 'var(--ds-inset-bg)',
                        }"
                    >
                        <option v-for="e in recent_events" :key="e.id" :value="e.id">
                            {{ e.name }} · {{ e.created_at ?? e.id }}
                        </option>
                    </select></label
                >
                <button
                    type="button"
                    data-testid="wf-dry-run-go"
                    class="mt-2 rounded-lg px-3 py-2 text-xs font-semibold"
                    :style="{ background: 'var(--ds-accent)', color: 'var(--ds-accent-contrast)' }"
                    @click="runDry"
                >
                    {{ c.rules.dry_run_run }}
                </button>
                <div v-if="dryResult" class="mt-3 text-xs" data-testid="wf-dry-result">
                    <p class="font-semibold" :data-matched="dryResult.matched">
                        {{
                            dryResult.matched ? c.rules.dry_run_matched : c.rules.dry_run_unmatched
                        }}
                    </p>
                    <ul class="mt-2 flex flex-col gap-1">
                        <li
                            v-for="(leaf, i) in dryResult.trace"
                            :key="i"
                            class="flex flex-wrap items-center gap-2"
                            :data-testid="`wf-truth-${i}`"
                        >
                            <span
                                class="rounded px-1.5 py-0.5 font-semibold"
                                :style="{
                                    background: leaf.matched
                                        ? 'var(--ds-status-open-bg)'
                                        : 'var(--ds-inset-bg)',
                                    color: leaf.matched
                                        ? 'var(--ds-text-primary)'
                                        : 'var(--ds-text-muted)',
                                }"
                                :aria-label="
                                    leaf.matched
                                        ? c.rules.dry_run_truth_true
                                        : c.rules.dry_run_truth_false
                                "
                                >{{ leaf.matched ? '✓' : '✗' }}
                                {{
                                    leaf.matched
                                        ? c.rules.dry_run_truth_true
                                        : c.rules.dry_run_truth_false
                                }}</span
                            >
                            <span>{{ leaf.fact }} {{ leaf.op }} {{ valueText(leaf.value) }}</span>
                            <span :style="{ color: 'var(--ds-text-muted)' }">{{
                                tr(c.rules.dry_run_actual, { value: valueText(leaf.actual) })
                            }}</span>
                        </li>
                    </ul>
                    <ol class="mt-2 flex flex-col gap-1">
                        <li
                            v-for="(a, i) in dryResult.actions"
                            :key="i"
                            :data-testid="`wf-dry-action-${i}`"
                        >
                            {{ i + 1 }}. {{ c.rules.action_card[a.type] ?? a.type }} —
                            {{
                                a.would_run
                                    ? c.rules.dry_run_would_run
                                    : tr(c.rules.dry_run_skipped, { note: a.note ?? '' })
                            }}
                        </li>
                    </ol>
                    <p
                        v-for="g in dryResult.guards"
                        :key="g"
                        class="mt-1 inline-block rounded px-1.5 py-0.5 font-semibold"
                        :style="{ background: 'var(--ds-sla-soon-bg)' }"
                    >
                        ⛨ {{ tr(c.rules.dry_run_guard, { guard: g }) }}
                    </p>
                </div>
                <h3 class="mt-4 text-sm font-semibold">{{ c.rules.log_title }}</h3>
                <p
                    v-if="log.executions.length === 0"
                    class="mt-1 text-xs"
                    :style="{ color: 'var(--ds-text-muted)' }"
                >
                    {{ c.rules.log_empty }}
                </p>
                <ul v-else class="mt-1 flex flex-col gap-1 text-xs">
                    <li
                        v-for="x in log.executions"
                        :key="x.id"
                        class="flex flex-wrap gap-2 border-t pt-1"
                        :style="{ borderColor: 'var(--ds-border-soft)' }"
                    >
                        <span class="font-semibold">{{
                            x.matched ? c.rules.log_matched : c.rules.log_unmatched
                        }}</span
                        ><span :style="{ color: 'var(--ds-text-muted)' }">{{ x.executed_at }}</span>
                        <span v-for="(o, j) in x.action_outcomes" :key="j"
                            >{{ o.action }}: {{ o.outcome
                            }}{{ o.reason ? ' (' + o.reason + ')' : '' }}</span
                        >
                        <span
                            v-for="g in x.guard_trips"
                            :key="g"
                            class="rounded px-1.5"
                            :style="{ background: 'var(--ds-sla-soon-bg)' }"
                            >⛨ {{ g }}</span
                        >
                    </li>
                </ul>
            </aside>
        </div>
    </WorkflowShell>
</template>
