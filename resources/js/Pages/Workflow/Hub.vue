<script setup lang="ts">
// Doc 12 §8 (S12 step 14) — Backoffice → Workflow hub: the seven surface groups, counts, the
// R-04 paused banner. [B12-batch]: functional/themed/i18n-keyed from DS components.
import WorkflowShell from '../../Components/Workflow/WorkflowShell.vue';
import { tr } from '../../Components/Workflow/workflowApi';

const props = defineProps<{
    copy: Record<string, unknown>;
    can: Record<string, boolean>;
    paused: boolean;
    counts: { rules: number; policies: number; forms: number; macros: number };
}>();
// eslint-disable-next-line @typescript-eslint/no-explicit-any
const c = props.copy as any;
const groups = ['rules', 'sla', 'hours', 'macros', 'fields', 'forms', 'csat'] as const;
const navKey: Record<(typeof groups)[number], string> = {
    rules: 'automation',
    sla: 'sla',
    hours: 'hours',
    macros: 'macros',
    fields: 'fields',
    forms: 'forms',
    csat: 'csat',
};
</script>

<template>
    <WorkflowShell
        :copy="props.copy"
        active="hub"
        :title="c.hub.title"
        :summary="tr(c.hub.summary, props.counts)"
    >
        <p
            v-if="paused"
            role="status"
            data-testid="wf-paused"
            class="mb-3 rounded-md border px-3 py-2 text-xs font-semibold"
            :style="{
                background: 'var(--ds-danger-bg)',
                borderColor: 'var(--ds-danger-border)',
                color: 'var(--ds-danger-text)',
            }"
        >
            {{ c.hub.paused_banner }}
        </p>
        <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
            <a
                v-for="key in groups"
                :key="key"
                :href="`/admin/workflow/${key}`"
                :data-testid="`wf-hub-${key}`"
                class="rounded-lg border p-4 text-sm font-semibold"
                :style="{ background: 'var(--ds-panel-bg)', borderColor: 'var(--ds-border)' }"
            >
                {{ c.nav[navKey[key]] }}
            </a>
        </div>
    </WorkflowShell>
</template>
