<script setup lang="ts">
// Doc 12 §8 rules list (S12 step 14): position-ordered, MOVE BUTTONS for reorder (§13 keyboard law —
// drag is enhancement, buttons are the requirement), active toggles permission-gated
// (automation.rules.activate), matched count + last run (FR-06), guard-trip badge (§9 amber shield),
// the revision indicator on edited-active rules (FR-07), SEC-01 drift warning. [B12-batch].
import { ref } from 'vue';
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
    drift: string[];
    updated_at: string | null;
};
const props = defineProps<{
    copy: Record<string, unknown>;
    can: Record<string, boolean>;
    rules: Rule[];
    triggers: string[];
}>();
// eslint-disable-next-line @typescript-eslint/no-explicit-any
const c = props.copy as any;
const rules = ref<Rule[]>([...props.rules].sort((a, b) => a.position - b.position));
const refusal = ref('');
const confirming = ref<{ rule: Rule; kind: 'activate' | 'deactivate' } | null>(null);
const creating = ref(false);
const newName = ref('');
const newTrigger = ref(props.triggers[0] ?? 'ticket.created');

async function move(rule: Rule, delta: number): Promise<void> {
    const target = rule.position + delta;
    if (target < 1 || target > rules.value.length) return;
    const result = await post(`/admin/workflow/rules/${rule.id}/reorder`, { position: target });
    if (!result.ok) {
        refusal.value = tr(c.common.refused, { detail: result.refusal });
        return;
    }
    window.location.reload();
}
async function toggle(): Promise<void> {
    if (!confirming.value) return;
    const { rule, kind } = confirming.value;
    const result = await post(`/admin/workflow/rules/${rule.id}/${kind}`, {});
    confirming.value = null;
    if (!result.ok) {
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
        :summary="
            tr(c.rules.summary, {
                active: rules.filter((r) => r.active).length,
                draft: rules.filter((r) => !r.active).length,
            })
        "
    >
        <div class="mb-3 flex items-center gap-2">
            <button
                v-if="can['automation.rules.edit']"
                type="button"
                data-testid="wf-rule-new"
                class="rounded-lg px-3 py-2 text-xs font-semibold"
                :style="{ background: 'var(--ds-accent)', color: 'var(--ds-accent-contrast)' }"
                @click="creating = !creating"
            >
                {{ c.rules.new }}
            </button>
        </div>
        <form
            v-if="creating"
            class="mb-4 flex flex-wrap items-end gap-2 rounded-lg border p-3"
            :style="{ background: 'var(--ds-panel-bg)', borderColor: 'var(--ds-border)' }"
            @submit.prevent="create"
        >
            <label class="text-xs"
                ><span class="block" :style="{ color: 'var(--ds-text-label)' }">{{
                    c.common.name
                }}</span
                ><input
                    v-model="newName"
                    data-testid="wf-rule-name"
                    required
                    class="mt-1 rounded-md border px-2 py-1.5 text-sm"
                    :style="{ borderColor: 'var(--ds-border)', background: 'var(--ds-inset-bg)' }"
            /></label>
            <label class="text-xs"
                ><span class="block" :style="{ color: 'var(--ds-text-label)' }">{{
                    c.rules.trigger_label
                }}</span
                ><select
                    v-model="newTrigger"
                    data-testid="wf-rule-trigger"
                    class="mt-1 rounded-md border px-2 py-1.5 text-sm"
                    :style="{ borderColor: 'var(--ds-border)', background: 'var(--ds-inset-bg)' }"
                >
                    <option v-for="t in triggers" :key="t" :value="t">{{ t }}</option>
                </select></label
            >
            <button
                type="submit"
                data-testid="wf-rule-create"
                class="rounded-lg px-3 py-2 text-xs font-semibold"
                :style="{ background: 'var(--ds-accent)', color: 'var(--ds-accent-contrast)' }"
            >
                {{ c.common.save_draft }}
            </button>
        </form>
        <WorkflowRefusal :text="refusal" />
        <p v-if="rules.length === 0" class="text-sm" :style="{ color: 'var(--ds-text-muted)' }">
            {{ c.rules.empty }}
        </p>
        <table v-else class="w-full border-collapse text-sm" data-testid="wf-rules-table">
            <thead>
                <tr
                    class="text-start text-[11px] uppercase tracking-wider"
                    :style="{ color: 'var(--ds-text-label)' }"
                >
                    <th class="py-2 pe-2 text-start">{{ c.rules.col_position }}</th>
                    <th class="py-2 pe-2 text-start">{{ c.rules.col_name }}</th>
                    <th class="py-2 pe-2 text-start">{{ c.rules.col_trigger }}</th>
                    <th class="py-2 pe-2 text-start">{{ c.rules.col_matched }}</th>
                    <th class="py-2 pe-2 text-start">{{ c.rules.col_last_run }}</th>
                    <th class="py-2 pe-2 text-start">{{ c.rules.col_state }}</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="rule in rules"
                    :key="rule.id"
                    :data-rule-row="rule.id"
                    class="border-t"
                    :style="{ borderColor: 'var(--ds-border-soft)' }"
                >
                    <td class="py-2 pe-2 tabular-nums">
                        <span class="me-1">{{ rule.position }}</span>
                        <button
                            v-if="can['automation.rules.edit']"
                            type="button"
                            :aria-label="c.common.move_up"
                            :data-testid="`wf-rule-up-${rule.id}`"
                            class="rounded px-1 text-xs"
                            :style="{ color: 'var(--ds-text-muted)' }"
                            @click="move(rule, -1)"
                        >
                            ↑
                        </button>
                        <button
                            v-if="can['automation.rules.edit']"
                            type="button"
                            :aria-label="c.common.move_down"
                            :data-testid="`wf-rule-down-${rule.id}`"
                            class="rounded px-1 text-xs"
                            :style="{ color: 'var(--ds-text-muted)' }"
                            @click="move(rule, 1)"
                        >
                            ↓
                        </button>
                    </td>
                    <td class="py-2 pe-2">
                        <a
                            :href="`/admin/workflow/rules/${rule.id}`"
                            class="font-semibold underline-offset-2 hover:underline"
                            >{{ rule.name }}</a
                        >
                        <span
                            v-if="rule.has_draft && rule.active"
                            class="ms-2 rounded px-1.5 py-0.5 text-[10.5px] font-semibold"
                            :style="{ background: 'var(--ds-status-pending-internal-bg)' }"
                            :title="c.rules.revision_pending"
                            >{{ c.common.draft }}</span
                        >
                        <p
                            v-if="rule.drift.length"
                            class="mt-0.5 text-xs"
                            :style="{ color: 'var(--ds-danger-text)' }"
                        >
                            {{ tr(c.rules.floor_warning, { missing: rule.drift.join(', ') }) }}
                        </p>
                    </td>
                    <td class="py-2 pe-2 text-xs" :style="{ color: 'var(--ds-text-muted)' }">
                        {{ rule.trigger }}
                    </td>
                    <td class="py-2 pe-2 tabular-nums">
                        {{ rule.matched_count
                        }}<span
                            v-if="rule.guard_trips > 0"
                            class="ms-2 rounded px-1.5 py-0.5 text-[10.5px] font-semibold"
                            :style="{ background: 'var(--ds-sla-soon-bg)' }"
                            :title="tr(c.rules.guard_trips, { count: rule.guard_trips })"
                            >⛨ {{ rule.guard_trips }}</span
                        >
                    </td>
                    <td class="py-2 pe-2 text-xs" :style="{ color: 'var(--ds-text-muted)' }">
                        {{ rule.last_run_at ?? c.common.never }}
                    </td>
                    <td class="py-2 pe-2">
                        <span
                            class="rounded px-1.5 py-0.5 text-[10.5px] font-semibold"
                            :style="{
                                background: rule.active
                                    ? 'var(--ds-status-open-bg)'
                                    : 'var(--ds-inset-bg)',
                            }"
                            :data-testid="`wf-rule-state-${rule.id}`"
                            >{{ rule.active ? c.common.active : c.common.inactive }}</span
                        >
                    </td>
                    <td class="py-2 text-end">
                        <button
                            v-if="can['automation.rules.activate']"
                            type="button"
                            :data-testid="`wf-rule-toggle-${rule.id}`"
                            class="rounded-md border px-2 py-1 text-xs"
                            :style="{ borderColor: 'var(--ds-border)' }"
                            @click="
                                confirming = { rule, kind: rule.active ? 'deactivate' : 'activate' }
                            "
                        >
                            {{ rule.active ? c.rules.deactivate : c.rules.activate }}
                        </button>
                    </td>
                </tr>
            </tbody>
        </table>
        <div
            v-if="confirming"
            role="dialog"
            aria-modal="true"
            :aria-label="
                confirming.kind === 'activate' ? c.rules.activate_verb : c.rules.deactivate_verb
            "
            class="mt-4 rounded-lg border p-4"
            :style="{ background: 'var(--ds-panel-bg)', borderColor: 'var(--ds-border-strong)' }"
        >
            <p class="text-sm">
                {{
                    tr(
                        confirming.kind === 'activate'
                            ? c.rules.activate_confirm
                            : c.rules.deactivate_confirm,
                        { name: confirming.rule.name, position: confirming.rule.position },
                    )
                }}
            </p>
            <div class="mt-3 flex gap-2">
                <button
                    type="button"
                    data-testid="wf-rule-confirm"
                    class="rounded-lg px-3 py-2 text-xs font-semibold"
                    :style="{
                        background:
                            confirming.kind === 'deactivate'
                                ? 'var(--ds-danger-strong)'
                                : 'var(--ds-accent)',
                        color: 'var(--ds-accent-contrast)',
                    }"
                    @click="toggle"
                >
                    {{
                        confirming.kind === 'activate'
                            ? c.rules.activate_verb
                            : c.rules.deactivate_verb
                    }}
                </button>
                <button
                    type="button"
                    class="rounded-lg border px-3 py-2 text-xs"
                    :style="{ borderColor: 'var(--ds-border)' }"
                    @click="confirming = null"
                >
                    {{ c.common.cancel }}
                </button>
            </div>
        </div>
    </WorkflowShell>
</template>
