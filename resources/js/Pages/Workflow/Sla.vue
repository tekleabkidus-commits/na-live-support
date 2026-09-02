<script setup lang="ts">
// Doc 12 §8 SLA policies (S12 step 14): ordered list with match summaries; the editor shows the LIVE
// EXAMPLE computed by the clock library on the server (the §8 sentence — §13's accessible
// explanation of clock math), pause-condition toggles with plain captions, the re-target choice
// explained (EC-03), the BR-03 snapshot notice. [B12-batch].
import { ref, watch } from 'vue';
import WorkflowShell from '../../Components/Workflow/WorkflowShell.vue';
import WorkflowRefusal from '../../Components/Workflow/WorkflowRefusal.vue';
import { post, tr } from '../../Components/Workflow/workflowApi';

type Policy = {
    id: string | null;
    name: string;
    position: number;
    active: boolean;
    match_conditions: Record<string, unknown>;
    targets: Record<string, Record<string, number>>;
    clock_mode: string;
    calendar_id: string | null;
    warning_pct: number;
    pause_conditions: Record<string, boolean>;
    retarget_mode: string;
    breach_escalation: unknown;
    updated_at?: string | null;
};
const props = defineProps<{
    copy: Record<string, unknown>;
    can: Record<string, boolean>;
    policies: Policy[];
    calendars: {
        id: string;
        name: string;
        timezone: string;
        is_tenant_default: boolean;
        department_id: string | null;
    }[];
    departments: { id: string; name: string }[];
    timezone: string;
}>();
// eslint-disable-next-line @typescript-eslint/no-explicit-any
const c = props.copy as any;
const PRIORITIES = ['low', 'normal', 'high', 'urgent'];
const STAGES = ['first_response_s', 'next_response_s', 'resolution_s'];
const editing = ref<Policy | null>(null);
const refusal = ref('');
const example = ref('');

function blank(): Policy {
    const targets: Record<string, Record<string, number>> = {};
    for (const p of PRIORITIES)
        targets[p] = { first_response_s: 2400, next_response_s: 3600, resolution_s: 86400 };
    return {
        id: null,
        name: '',
        position: props.policies.length + 1,
        active: true,
        match_conditions: {},
        targets,
        clock_mode: 'calendar',
        calendar_id: props.calendars.find((k) => k.is_tenant_default)?.id ?? null,
        warning_pct: 80,
        pause_conditions: {
            pending_customer: true,
            pending_internal: false,
            channel_unreachable: true,
        },
        retarget_mode: 'keep',
        breach_escalation: null,
    };
}
function summary(p: Policy): string {
    const parts = Object.entries(p.match_conditions).map(
        ([k, v]) => `${k} = ${Array.isArray(v) ? v.join('/') : String(v)}`,
    );
    return parts.length ? parts.join(' · ') : c.sla.match_any;
}
function minutes(p: Policy, prio: string, stage: string): number {
    return Math.round((p.targets[prio]?.[stage] ?? 0) / 60);
}
function setMinutes(prio: string, stage: string, value: string): void {
    if (!editing.value) return;
    const targets = {
        ...editing.value.targets,
        [prio]: {
            ...(editing.value.targets[prio] ?? {}),
            [stage]: Math.max(60, Number(value) * 60),
        },
    };
    editing.value = { ...editing.value, targets };
}
async function refreshExample(): Promise<void> {
    if (!editing.value) return;
    const result = await post<{ sentence: string }>('/admin/workflow/sla/example', {
        calendar_id: editing.value.clock_mode === 'calendar' ? editing.value.calendar_id : null,
        target_seconds: editing.value.targets.high?.first_response_s ?? 2400,
        created_at: '2026-09-01T17:00:00+03:00',
    });
    example.value = result.ok ? result.data.sentence : '';
}
watch(
    () =>
        editing.value && [
            editing.value.calendar_id,
            editing.value.clock_mode,
            editing.value.targets.high?.first_response_s,
        ],
    refreshExample,
    { deep: true },
);
async function save(): Promise<void> {
    if (!editing.value) return;
    refusal.value = '';
    const body = { ...editing.value };
    const result = await post(
        editing.value.id
            ? `/admin/workflow/sla/policies/${editing.value.id}`
            : '/admin/workflow/sla/policies',
        body as Record<string, unknown>,
    );
    if (!result.ok) {
        refusal.value = tr(c.common.refused, { detail: result.refusal });
        return;
    }
    window.location.reload();
}
</script>

<template>
    <WorkflowShell
        :copy="c"
        active="sla"
        :title="c.sla.title"
        :summary="tr(c.sla.summary, { count: policies.length })"
    >
        <button
            v-if="can['sla.policies.edit']"
            type="button"
            data-testid="wf-sla-new"
            class="mb-3 rounded-lg px-3 py-2 text-xs font-semibold"
            :style="{ background: 'var(--ds-accent)', color: 'var(--ds-accent-contrast)' }"
            @click="
                editing = blank();
                refreshExample();
            "
        >
            {{ c.sla.new }}
        </button>
        <p
            v-if="policies.length === 0 && !editing"
            class="text-sm"
            :style="{ color: 'var(--ds-text-muted)' }"
        >
            {{ c.sla.empty }}
        </p>
        <ol class="flex flex-col gap-2">
            <li
                v-for="p in policies"
                :key="p.id ?? ''"
                :data-policy-row="p.id"
                class="flex flex-wrap items-center gap-3 rounded-lg border p-3 text-sm"
                :style="{ background: 'var(--ds-panel-bg)', borderColor: 'var(--ds-border)' }"
            >
                <span class="tabular-nums" :style="{ color: 'var(--ds-text-muted)' }">{{
                    p.position
                }}</span>
                <span class="font-semibold">{{ p.name }}</span>
                <span class="text-xs" :style="{ color: 'var(--ds-text-muted)' }">{{
                    tr(c.sla.match_summary, { summary: summary(p) })
                }}</span>
                <span
                    class="rounded px-1.5 py-0.5 text-[10.5px] font-semibold"
                    :style="{
                        background: p.active ? 'var(--ds-status-open-bg)' : 'var(--ds-inset-bg)',
                    }"
                    >{{ p.active ? c.common.active : c.common.inactive }}</span
                >
                <button
                    v-if="can['sla.policies.edit']"
                    type="button"
                    :data-testid="`wf-sla-edit-${p.id}`"
                    class="ms-auto rounded-md border px-2 py-1 text-xs"
                    :style="{ borderColor: 'var(--ds-border)' }"
                    @click="
                        editing = JSON.parse(JSON.stringify(p));
                        refreshExample();
                    "
                >
                    {{ c.common.save }} …
                </button>
            </li>
        </ol>
        <section
            v-if="editing"
            class="mt-4 rounded-lg border p-4"
            :style="{ background: 'var(--ds-panel-bg)', borderColor: 'var(--ds-border-strong)' }"
            data-testid="wf-sla-editor"
        >
            <p
                role="status"
                class="mb-3 rounded-md border px-3 py-2 text-xs"
                :style="{ borderColor: 'var(--ds-border)', background: 'var(--ds-inset-bg)' }"
            >
                {{ c.sla.snapshot_notice }}
            </p>
            <div class="grid grid-cols-1 gap-3 md:grid-cols-2 text-xs">
                <label
                    ><span class="block" :style="{ color: 'var(--ds-text-label)' }">{{
                        c.common.name
                    }}</span
                    ><input
                        v-model="editing.name"
                        data-testid="wf-sla-name"
                        class="mt-1 w-full rounded-md border px-2 py-1.5 text-sm"
                        :style="{
                            borderColor: 'var(--ds-border)',
                            background: 'var(--ds-inset-bg)',
                        }"
                /></label>
                <label
                    ><span class="block" :style="{ color: 'var(--ds-text-label)' }">{{
                        c.sla.clock_mode
                    }}</span
                    ><select
                        v-model="editing.clock_mode"
                        data-testid="wf-sla-clock"
                        class="mt-1 w-full rounded-md border px-2 py-1.5 text-sm"
                        :style="{
                            borderColor: 'var(--ds-border)',
                            background: 'var(--ds-inset-bg)',
                        }"
                    >
                        <option value="calendar">{{ c.sla.clock_calendar }}</option>
                        <option value="24x7">{{ c.sla.clock_24x7 }}</option>
                    </select></label
                >
                <label v-if="editing.clock_mode === 'calendar'"
                    ><span class="block" :style="{ color: 'var(--ds-text-label)' }">{{
                        c.sla.calendar
                    }}</span
                    ><select
                        v-model="editing.calendar_id"
                        data-testid="wf-sla-calendar"
                        class="mt-1 w-full rounded-md border px-2 py-1.5 text-sm"
                        :style="{
                            borderColor: 'var(--ds-border)',
                            background: 'var(--ds-inset-bg)',
                        }"
                    >
                        <option :value="null">—</option>
                        <option v-for="k in calendars" :key="k.id" :value="k.id">
                            {{ k.name }} ({{ k.timezone }})
                        </option>
                    </select></label
                >
                <label
                    ><span class="block" :style="{ color: 'var(--ds-text-label)' }">{{
                        c.sla.warning_pct
                    }}</span
                    ><input
                        v-model.number="editing.warning_pct"
                        type="number"
                        min="1"
                        max="99"
                        class="mt-1 w-full rounded-md border px-2 py-1.5 text-sm"
                        :style="{
                            borderColor: 'var(--ds-border)',
                            background: 'var(--ds-inset-bg)',
                        }"
                /></label>
            </div>
            <h4 class="mt-4 text-xs font-semibold">{{ c.sla.targets }}</h4>
            <table class="mt-1 text-xs" data-testid="wf-sla-targets">
                <thead>
                    <tr>
                        <th class="pe-3 text-start">{{ c.sla.priority }}</th>
                        <th class="pe-3 text-start">
                            {{ c.sla.first_response }} ({{ c.sla.minutes }})
                        </th>
                        <th class="pe-3 text-start">
                            {{ c.sla.next_response }} ({{ c.sla.minutes }})
                        </th>
                        <th class="pe-3 text-start">
                            {{ c.sla.resolution }} ({{ c.sla.minutes }})
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="prio in PRIORITIES" :key="prio">
                        <td class="pe-3 py-1 capitalize">{{ prio }}</td>
                        <td v-for="stage in STAGES" :key="stage" class="pe-3 py-1">
                            <input
                                type="number"
                                min="1"
                                :value="minutes(editing, prio, stage)"
                                :data-testid="`wf-sla-target-${prio}-${stage}`"
                                class="w-24 rounded-md border px-2 py-1"
                                :style="{
                                    borderColor: 'var(--ds-border)',
                                    background: 'var(--ds-inset-bg)',
                                }"
                                @change="
                                    setMinutes(
                                        prio,
                                        stage,
                                        ($event.target as HTMLInputElement).value,
                                    )
                                "
                            />
                        </td>
                    </tr>
                </tbody>
            </table>
            <h4 class="mt-4 text-xs font-semibold">{{ c.sla.pause_title }}</h4>
            <div class="mt-1 flex flex-wrap gap-4 text-xs">
                <label
                    v-for="k in ['pending_customer', 'pending_internal', 'channel_unreachable']"
                    :key="k"
                    class="flex items-center gap-2"
                    ><input
                        v-model="editing.pause_conditions[k]"
                        type="checkbox"
                        :data-testid="`wf-sla-pause-${k}`"
                    />
                    {{ c.sla['pause_' + k] }}</label
                >
            </div>
            <h4 class="mt-4 text-xs font-semibold">{{ c.sla.retarget_title }}</h4>
            <div class="mt-1 flex flex-col gap-1 text-xs">
                <label class="flex items-center gap-2"
                    ><input v-model="editing.retarget_mode" type="radio" value="keep" />
                    {{ c.sla.retarget_keep }}</label
                >
                <label class="flex items-center gap-2"
                    ><input
                        v-model="editing.retarget_mode"
                        type="radio"
                        value="retarget_remaining"
                    />
                    {{ c.sla.retarget_remaining }}</label
                >
                <p :style="{ color: 'var(--ds-text-muted)' }">{{ c.sla.retarget_explain }}</p>
            </div>
            <h4 class="mt-4 text-xs font-semibold">{{ c.sla.example_title }}</h4>
            <p
                data-testid="wf-sla-example"
                class="mt-1 rounded-md border px-3 py-2 text-sm"
                :style="{ borderColor: 'var(--ds-border)', background: 'var(--ds-inset-bg)' }"
            >
                {{ example }}
            </p>
            <WorkflowRefusal :text="refusal" />
            <div class="mt-3 flex gap-2">
                <button
                    type="button"
                    data-testid="wf-sla-save"
                    class="rounded-lg px-3 py-2 text-xs font-semibold"
                    :style="{ background: 'var(--ds-accent)', color: 'var(--ds-accent-contrast)' }"
                    @click="save"
                >
                    {{ c.common.save }}
                </button>
                <button
                    type="button"
                    class="rounded-lg border px-3 py-2 text-xs"
                    :style="{ borderColor: 'var(--ds-border)' }"
                    @click="editing = null"
                >
                    {{ c.common.cancel }}
                </button>
            </div>
        </section>
    </WorkflowShell>
</template>
