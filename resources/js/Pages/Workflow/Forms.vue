<script setup lang="ts">
// Doc 12 §8 forms list (S12 step 14): forms with their current version, publish date and the
// usage banner (bot menus / rules / widget shortcuts). [B12-batch].
import { ref } from 'vue';
import WorkflowShell from '../../Components/Workflow/WorkflowShell.vue';
import WorkflowRefusal from '../../Components/Workflow/WorkflowRefusal.vue';
import { post, tr } from '../../Components/Workflow/workflowApi';

type Form = {
    id: string;
    name: string;
    active: boolean;
    current_version: number | null;
    published_at: string | null;
    usage: Record<string, { id: string; name: string }[]>;
    updated_at: string | null;
};
const props = defineProps<{
    copy: Record<string, unknown>;
    usage_labels: Record<string, string>;
    can: Record<string, boolean>;
    forms: Form[];
}>();
// eslint-disable-next-line @typescript-eslint/no-explicit-any
const c = props.copy as any;
const refusal = ref('');
const newName = ref('');
async function create(): Promise<void> {
    const result = await post<{ id: string }>('/admin/workflow/forms', { name: newName.value });
    if (!result.ok) {
        refusal.value = tr(c.common.refused, { detail: result.refusal });
        return;
    }
    window.location.href = `/admin/workflow/forms/${result.data.id}`;
}
function usage(f: Form): string {
    return Object.entries(f.usage)
        .flatMap(([k, list]) => list.map((x) => `${props.usage_labels[k] ?? k}: ${x.name}`))
        .join(' · ');
}
</script>

<template>
    <WorkflowShell
        :copy="c"
        active="forms"
        :title="c.forms.title"
        :summary="tr(c.forms.summary, { count: forms.length })"
    >
        <form
            v-if="can['forms.edit']"
            class="mb-3 flex items-end gap-2 text-xs"
            @submit.prevent="create"
        >
            <label
                ><span class="block" :style="{ color: 'var(--ds-text-label)' }">{{
                    c.common.name
                }}</span
                ><input
                    v-model="newName"
                    required
                    data-testid="wf-form-name"
                    class="mt-1 rounded-md border px-2 py-1.5 text-sm"
                    :style="{ borderColor: 'var(--ds-border)', background: 'var(--ds-inset-bg)' }"
            /></label>
            <button
                type="submit"
                data-testid="wf-form-create"
                class="rounded-lg px-3 py-2 font-semibold"
                :style="{ background: 'var(--ds-accent)', color: 'var(--ds-accent-contrast)' }"
            >
                {{ c.forms.new }}
            </button>
        </form>
        <WorkflowRefusal :text="refusal" />
        <p v-if="forms.length === 0" class="text-sm" :style="{ color: 'var(--ds-text-muted)' }">
            {{ c.forms.empty }}
        </p>
        <ul class="flex flex-col gap-1">
            <li
                v-for="f in forms"
                :key="f.id"
                :data-form-row="f.id"
                class="flex flex-wrap items-center gap-3 rounded-lg border p-2 text-sm"
                :style="{ background: 'var(--ds-panel-bg)', borderColor: 'var(--ds-border)' }"
            >
                <a
                    :href="`/admin/workflow/forms/${f.id}`"
                    class="font-semibold underline-offset-2 hover:underline"
                    >{{ f.name }}</a
                >
                <span
                    v-if="f.current_version"
                    class="rounded px-1.5 py-0.5 text-[10.5px] font-semibold"
                    :style="{ background: 'var(--ds-status-open-bg)' }"
                    >{{ tr(c.forms.version, { version: f.current_version }) }}</span
                >
                <span
                    v-else
                    class="rounded px-1.5 py-0.5 text-[10.5px]"
                    :style="{ background: 'var(--ds-inset-bg)' }"
                    >{{ c.common.draft }}</span
                >
                <span class="text-xs" :style="{ color: 'var(--ds-text-muted)' }">{{
                    f.published_at ?? c.common.never
                }}</span>
                <span v-if="usage(f)" class="text-xs" :style="{ color: 'var(--ds-text-muted)' }"
                    >{{ c.forms.usage_banner }}: {{ usage(f) }}</span
                >
            </li>
        </ul>
    </WorkflowShell>
</template>
