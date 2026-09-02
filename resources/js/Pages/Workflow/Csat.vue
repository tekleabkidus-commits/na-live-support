<script setup lang="ts">
// Doc 12 §8 CSAT settings (S12 step 14): the settings-row idiom (B6-X01 J03–J08) over the three
// registered csat.* keys — consequence before save, the per-language button labels shown as the
// shipped strings (operator overrides are Doc 16's screen), the event note for Doc 15. [B12-batch].
import { ref } from 'vue';
import WorkflowShell from '../../Components/Workflow/WorkflowShell.vue';
import WorkflowRefusal from '../../Components/Workflow/WorkflowRefusal.vue';
import { post, tr } from '../../Components/Workflow/workflowApi';

const props = defineProps<{
    copy: Record<string, unknown>;
    can: Record<string, boolean>;
    settings: { cooldown_hours: number; window_hours: number; comment_prompt_enabled: boolean };
    labels: Record<string, Record<string, string>>;
}>();
// eslint-disable-next-line @typescript-eslint/no-explicit-any
const c = props.copy as any;
const form = ref({ ...props.settings });
const refusal = ref('');
const saved = ref('');
async function save(): Promise<void> {
    refusal.value = '';
    saved.value = '';
    const result = await post('/admin/workflow/csat', { ...form.value });
    if (!result.ok) {
        refusal.value = tr(c.common.refused, { detail: result.refusal });
        return;
    }
    saved.value = c.common.saved;
}
</script>

<template>
    <WorkflowShell
        :copy="c"
        active="csat"
        :title="c.csat.title"
        :summary="tr(c.csat.summary, { cooldown: form.cooldown_hours, window: form.window_hours })"
    >
        <div
            class="max-w-2xl rounded-lg border text-sm"
            :style="{ background: 'var(--ds-panel-bg)', borderColor: 'var(--ds-border)' }"
            data-testid="wf-csat-settings"
        >
            <div
                class="flex items-center gap-4 border-b p-3"
                :style="{ borderColor: 'var(--ds-border-soft)' }"
            >
                <label class="flex-1">{{ c.csat.cooldown }}</label
                ><input
                    v-model.number="form.cooldown_hours"
                    type="number"
                    min="0"
                    :disabled="!can['settings.edit']"
                    data-testid="wf-csat-cooldown"
                    class="w-24 rounded-md border px-2 py-1"
                    :style="{ borderColor: 'var(--ds-border)', background: 'var(--ds-inset-bg)' }"
                />
            </div>
            <div
                class="flex items-center gap-4 border-b p-3"
                :style="{ borderColor: 'var(--ds-border-soft)' }"
            >
                <label class="flex-1">{{ c.csat.window }}</label
                ><input
                    v-model.number="form.window_hours"
                    type="number"
                    min="1"
                    :disabled="!can['settings.edit']"
                    data-testid="wf-csat-window"
                    class="w-24 rounded-md border px-2 py-1"
                    :style="{ borderColor: 'var(--ds-border)', background: 'var(--ds-inset-bg)' }"
                />
            </div>
            <div
                class="flex items-center gap-4 border-b p-3"
                :style="{ borderColor: 'var(--ds-border-soft)' }"
            >
                <label class="flex-1">{{ c.csat.comment_prompt }}</label
                ><input
                    v-model="form.comment_prompt_enabled"
                    type="checkbox"
                    :disabled="!can['settings.edit']"
                    data-testid="wf-csat-comment"
                />
            </div>
            <div class="p-3">
                <h3 class="text-xs font-semibold">{{ c.csat.labels_title }}</h3>
                <p class="text-xs" :style="{ color: 'var(--ds-text-muted)' }">
                    {{ c.csat.labels_help }}
                </p>
                <table class="mt-2 text-xs">
                    <tbody>
                        <tr v-for="key in Object.keys(labels.en ?? {})" :key="key">
                            <th
                                class="pe-3 text-start font-normal"
                                :style="{ color: 'var(--ds-text-label)' }"
                            >
                                {{ key }}
                            </th>
                            <td v-for="l in ['en', 'am']" :key="l" class="pe-3">
                                {{ labels[l]?.[key] }}
                            </td>
                        </tr>
                    </tbody>
                </table>
                <p class="mt-2 text-xs" :style="{ color: 'var(--ds-text-muted)' }">
                    {{ c.csat.event_note }}
                </p>
            </div>
        </div>
        <WorkflowRefusal :text="refusal" />
        <p
            v-if="saved"
            role="status"
            class="mt-2 text-xs"
            :style="{ color: 'var(--ds-text-muted)' }"
        >
            {{ saved }}
        </p>
        <button
            v-if="can['settings.edit']"
            type="button"
            data-testid="wf-csat-save"
            class="mt-3 rounded-lg px-3 py-2 text-xs font-semibold"
            :style="{ background: 'var(--ds-accent)', color: 'var(--ds-accent-contrast)' }"
            @click="save"
        >
            {{ c.common.save }}
        </button>
    </WorkflowShell>
</template>
