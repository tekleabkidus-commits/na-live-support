<script setup lang="ts">
// Doc 12 §8 fields manager (S12 step 14): entity tabs, MOVE-BUTTON order, TYPE-LOCKED badge (FR-18
// physics), the retired section, option editors with the value-stability warning (EC-08), the
// WHERE-USED panel (forms / rules / views) BEFORE deactivate (EC-14) with the typed confirmation. [B12-batch].
import { computed, ref } from 'vue';
import WorkflowShell from '../../Components/Workflow/WorkflowShell.vue';
import WorkflowRefusal from '../../Components/Workflow/WorkflowRefusal.vue';
import { post, tr } from '../../Components/Workflow/workflowApi';

type Option = { id?: string; value: string; label: Record<string, string>; position?: number };
type Field = {
    id: string | null;
    entity: string;
    key: string;
    label: Record<string, string>;
    type: string;
    validation: Record<string, unknown>;
    agent_editable: boolean;
    customer_visible: boolean;
    position: number;
    active: boolean;
    deactivated_at: string | null;
    options: Option[];
    updated_at?: string | null;
};
const props = defineProps<{
    copy: Record<string, unknown>;
    can: Record<string, boolean>;
    fields: Field[];
    types: string[];
    choice_types: string[];
}>();
// eslint-disable-next-line @typescript-eslint/no-explicit-any
const c = props.copy as any;
const tab = ref<'ticket' | 'customer'>('ticket');
const editing = ref<Field | null>(null);
const refusal = ref('');
const whereUsed = ref<{
    forms: { id: string; name: string }[];
    rules: { id: string; name: string }[];
    views: { id: string; name: string | null }[];
} | null>(null);
const confirming = ref<Field | null>(null);
const live = computed(() =>
    props.fields
        .filter((f) => f.entity === tab.value && f.active)
        .sort((a, b) => a.position - b.position),
);
const retired = computed(() => props.fields.filter((f) => f.entity === tab.value && !f.active));
function blank(): Field {
    return {
        id: null,
        entity: tab.value,
        key: '',
        label: { en: '', am: '' },
        type: 'text',
        validation: {},
        agent_editable: true,
        customer_visible: false,
        position: live.value.length + 1,
        active: true,
        deactivated_at: null,
        options: [],
    };
}
const isChoice = computed(
    () => editing.value !== null && props.choice_types.includes(editing.value.type),
);
async function loadWhereUsed(f: Field): Promise<void> {
    whereUsed.value = null;
    const response = await fetch(`/admin/workflow/fields/${f.entity}/${f.key}/where-used`, {
        headers: { Accept: 'application/json' },
    });
    if (response.ok) whereUsed.value = (await response.json()) as typeof whereUsed.value;
}
async function move(f: Field, delta: number): Promise<void> {
    const result = await post(`/admin/workflow/fields/${f.id}`, { position: f.position + delta });
    if (!result.ok) {
        refusal.value = tr(c.common.refused, { detail: result.refusal });
        return;
    }
    window.location.reload();
}
async function save(): Promise<void> {
    if (!editing.value) return;
    refusal.value = '';
    const body: Record<string, unknown> = editing.value.id
        ? {
              label: editing.value.label,
              validation: editing.value.validation,
              agent_editable: editing.value.agent_editable,
              customer_visible: editing.value.customer_visible,
          }
        : {
              entity: editing.value.entity,
              key: editing.value.key,
              label: editing.value.label,
              type: editing.value.type,
              validation: editing.value.validation,
              agent_editable: editing.value.agent_editable,
              customer_visible: editing.value.customer_visible,
              options: editing.value.options.map((o) => ({ value: o.value, label: o.label })),
          };
    const result = await post(
        editing.value.id ? `/admin/workflow/fields/${editing.value.id}` : '/admin/workflow/fields',
        body,
    );
    if (!result.ok) {
        refusal.value = tr(c.common.refused, { detail: result.refusal });
        return;
    }
    window.location.reload();
}
async function removeOption(o: Option): Promise<void> {
    if (!editing.value?.id || !o.id) {
        if (editing.value) editing.value.options = editing.value.options.filter((x) => x !== o);
        return;
    }
    const result = await post(`/admin/workflow/fields/${editing.value.id}`, {
        remove_option_id: o.id,
    });
    if (!result.ok) {
        refusal.value = tr(c.common.refused, { detail: result.refusal });
        return;
    }
    window.location.reload();
}
async function deactivate(): Promise<void> {
    if (!confirming.value?.id) return;
    const result = await post(`/admin/workflow/fields/${confirming.value.id}/deactivate`, {});
    confirming.value = null;
    if (!result.ok) {
        refusal.value = tr(c.common.refused, { detail: result.refusal });
        return;
    }
    window.location.reload();
}
async function reactivate(f: Field): Promise<void> {
    const result = await post(`/admin/workflow/fields/${f.id}/reactivate`, {});
    if (!result.ok) {
        refusal.value = tr(c.common.refused, { detail: result.refusal });
        return;
    }
    window.location.reload();
}
function names(list: { name: string | null; id?: string }[] | undefined): string {
    return (list ?? []).map((x) => x.name ?? x.id ?? '').join(', ');
}
function usedLine(kind: 'forms' | 'rules' | 'views'): string {
    return c.fields['where_used_' + kind] + ': ' + names(whereUsed.value?.[kind]);
}
function labelOf(f: Field): string {
    return f.label.en ?? f.label.am ?? f.key;
}
</script>

<template>
    <WorkflowShell
        :copy="c"
        active="fields"
        :title="c.fields.title"
        :summary="
            tr(c.fields.summary, {
                ticket: fields.filter((f) => f.entity === 'ticket').length,
                customer: fields.filter((f) => f.entity === 'customer').length,
            })
        "
    >
        <div
            role="tablist"
            class="mb-3 flex gap-1 border-b"
            :style="{ borderColor: 'var(--ds-border)' }"
        >
            <button
                v-for="t in ['ticket', 'customer']"
                :key="t"
                role="tab"
                :aria-selected="tab === t"
                :data-testid="`wf-fields-tab-${t}`"
                class="px-3 py-2 text-xs font-semibold"
                :style="
                    tab === t
                        ? { borderBottom: '2px solid var(--ds-accent)' }
                        : { color: 'var(--ds-text-muted)' }
                "
                @click="
                    tab = t as 'ticket' | 'customer';
                    editing = null;
                "
            >
                {{ c.fields['tab_' + t] }}
            </button>
            <button
                type="button"
                data-testid="wf-field-new"
                class="ms-auto mb-1 rounded-lg px-3 py-1.5 text-xs font-semibold"
                :style="{ background: 'var(--ds-accent)', color: 'var(--ds-accent-contrast)' }"
                @click="editing = blank()"
            >
                {{ c.fields.new }}
            </button>
        </div>
        <WorkflowRefusal :text="refusal" />
        <ul class="flex flex-col gap-1">
            <li
                v-for="f in live"
                :key="f.id ?? ''"
                :data-field-row="f.key"
                class="flex flex-wrap items-center gap-3 rounded-lg border p-2 text-sm"
                :style="{ background: 'var(--ds-panel-bg)', borderColor: 'var(--ds-border)' }"
            >
                <span class="tabular-nums" :style="{ color: 'var(--ds-text-muted)' }">{{
                    f.position
                }}</span>
                <button
                    type="button"
                    :aria-label="c.common.move_up"
                    class="rounded px-1 text-xs"
                    :style="{ color: 'var(--ds-text-muted)' }"
                    @click="move(f, -1)"
                >
                    ↑
                </button>
                <button
                    type="button"
                    :aria-label="c.common.move_down"
                    class="rounded px-1 text-xs"
                    :style="{ color: 'var(--ds-text-muted)' }"
                    @click="move(f, 1)"
                >
                    ↓
                </button>
                <span class="font-semibold">{{ labelOf(f) }}</span>
                <code class="text-xs" :style="{ color: 'var(--ds-text-muted)' }">{{ f.key }}</code>
                <span
                    class="rounded px-1.5 py-0.5 text-[10.5px] font-semibold"
                    :style="{ background: 'var(--ds-inset-bg)' }"
                    :title="c.fields.type_locked"
                    >🔒 {{ f.type }}</span
                >
                <button
                    type="button"
                    :data-testid="`wf-field-edit-${f.key}`"
                    class="ms-auto rounded-md border px-2 py-1 text-xs"
                    :style="{ borderColor: 'var(--ds-border)' }"
                    @click="
                        editing = JSON.parse(JSON.stringify(f));
                        loadWhereUsed(f);
                    "
                >
                    {{ c.common.save }} …
                </button>
                <button
                    type="button"
                    :data-testid="`wf-field-deactivate-${f.key}`"
                    class="rounded-md border px-2 py-1 text-xs"
                    :style="{
                        borderColor: 'var(--ds-danger-border)',
                        color: 'var(--ds-danger-text)',
                    }"
                    @click="
                        confirming = f;
                        loadWhereUsed(f);
                    "
                >
                    {{ c.fields.deactivate }}
                </button>
            </li>
        </ul>
        <section v-if="retired.length" class="mt-4">
            <h3
                class="text-xs font-semibold uppercase tracking-wider"
                :style="{ color: 'var(--ds-text-label)' }"
            >
                {{ c.fields.retired_section }}
            </h3>
            <ul class="mt-1 flex flex-col gap-1">
                <li
                    v-for="f in retired"
                    :key="f.id ?? ''"
                    :data-field-row="f.key"
                    class="flex items-center gap-3 rounded-lg border p-2 text-sm"
                    :style="{ borderColor: 'var(--ds-border-soft)', color: 'var(--ds-text-muted)' }"
                >
                    <span>{{ labelOf(f) }}</span
                    ><code class="text-xs">{{ f.key }}</code
                    ><button
                        type="button"
                        :data-testid="`wf-field-reactivate-${f.key}`"
                        class="ms-auto rounded-md border px-2 py-1 text-xs"
                        :style="{ borderColor: 'var(--ds-border)' }"
                        @click="reactivate(f)"
                    >
                        {{ c.fields.reactivate }}
                    </button>
                </li>
            </ul>
        </section>
        <section
            v-if="confirming"
            role="dialog"
            aria-modal="true"
            :aria-label="c.fields.deactivate_verb"
            class="mt-4 rounded-lg border p-4 text-sm"
            :style="{ background: 'var(--ds-panel-bg)', borderColor: 'var(--ds-border-strong)' }"
            data-testid="wf-field-confirm"
        >
            <p>{{ tr(c.fields.deactivate_confirm, { label: labelOf(confirming) }) }}</p>
            <div class="mt-2 text-xs" data-testid="wf-where-used">
                <h4 class="font-semibold">{{ c.fields.where_used_title }}</h4>
                <p
                    v-if="
                        whereUsed &&
                        !whereUsed.forms.length &&
                        !whereUsed.rules.length &&
                        !whereUsed.views.length
                    "
                    :style="{ color: 'var(--ds-text-muted)' }"
                >
                    {{ c.fields.where_used_none }}
                </p>
                <template v-if="whereUsed"
                    ><p v-if="whereUsed.forms.length">
                        {{ usedLine('forms') }}
                    </p>
                    <p v-if="whereUsed.rules.length">
                        {{ usedLine('rules') }}
                    </p>
                    <p v-if="whereUsed.views.length">
                        {{ usedLine('views') }}
                    </p></template
                >
            </div>
            <div class="mt-3 flex gap-2">
                <button
                    type="button"
                    data-testid="wf-field-deactivate-confirm"
                    class="rounded-lg px-3 py-2 text-xs font-semibold"
                    :style="{
                        background: 'var(--ds-danger-strong)',
                        color: 'var(--ds-accent-contrast)',
                    }"
                    @click="deactivate"
                >
                    {{ c.fields.deactivate_verb }}</button
                ><button
                    type="button"
                    class="rounded-lg border px-3 py-2 text-xs"
                    :style="{ borderColor: 'var(--ds-border)' }"
                    @click="confirming = null"
                >
                    {{ c.common.cancel }}
                </button>
            </div>
        </section>
        <section
            v-if="editing"
            class="mt-4 rounded-lg border p-4 text-xs"
            :style="{ background: 'var(--ds-panel-bg)', borderColor: 'var(--ds-border-strong)' }"
            data-testid="wf-field-editor"
        >
            <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                <label
                    ><span class="block" :style="{ color: 'var(--ds-text-label)' }">{{
                        c.fields.key
                    }}</span
                    ><input
                        v-model="editing.key"
                        :disabled="!!editing.id"
                        data-testid="wf-field-key"
                        pattern="[a-z][a-z0-9_]*"
                        class="mt-1 w-full rounded-md border px-2 py-1.5 font-mono text-sm"
                        :style="{
                            borderColor: 'var(--ds-border)',
                            background: 'var(--ds-inset-bg)',
                        }"
                /></label>
                <label
                    ><span class="block" :style="{ color: 'var(--ds-text-label)' }">{{
                        c.fields.type
                    }}</span
                    ><select
                        v-model="editing.type"
                        :disabled="!!editing.id"
                        data-testid="wf-field-type"
                        class="mt-1 w-full rounded-md border px-2 py-1.5 text-sm"
                        :style="{
                            borderColor: 'var(--ds-border)',
                            background: 'var(--ds-inset-bg)',
                        }"
                    >
                        <option v-for="t in types" :key="t" :value="t">{{ t }}</option></select
                    ><span
                        v-if="editing.id"
                        class="mt-1 block"
                        :style="{ color: 'var(--ds-text-muted)' }"
                        >🔒 {{ c.fields.type_locked }}</span
                    ></label
                >
                <label v-for="lang in ['en', 'am']" :key="lang"
                    ><span class="block" :style="{ color: 'var(--ds-text-label)' }"
                        >{{ c.fields.label }} ({{ c.common['language_' + lang] }})</span
                    ><input
                        v-model="editing.label[lang]"
                        :data-testid="`wf-field-label-${lang}`"
                        class="mt-1 w-full rounded-md border px-2 py-1.5 text-sm"
                        :style="{
                            borderColor: 'var(--ds-border)',
                            background: 'var(--ds-inset-bg)',
                        }"
                /></label>
                <label class="flex items-center gap-2"
                    ><input v-model="editing.agent_editable" type="checkbox" />
                    {{ c.fields.agent_editable }}</label
                >
                <label class="flex items-center gap-2"
                    ><input v-model="editing.customer_visible" type="checkbox" />
                    {{ c.fields.customer_visible }}</label
                >
            </div>
            <div v-if="isChoice" class="mt-3">
                <h4 class="font-semibold">{{ c.fields.options }}</h4>
                <p :style="{ color: 'var(--ds-text-muted)' }">
                    {{ c.fields.option_stability_warning }}
                </p>
                <div
                    v-for="(o, i) in editing.options"
                    :key="o.id ?? i"
                    class="mt-1 flex flex-wrap items-center gap-2"
                >
                    <input
                        v-model="o.value"
                        :disabled="!!o.id"
                        :placeholder="c.fields.option_value"
                        class="rounded-md border px-2 py-1 font-mono"
                        :style="{ borderColor: 'var(--ds-border)' }"
                    />
                    <input
                        v-model="o.label.en"
                        :disabled="!!o.id"
                        :placeholder="c.fields.option_label + ' (en)'"
                        class="rounded-md border px-2 py-1"
                        :style="{ borderColor: 'var(--ds-border)' }"
                    />
                    <input
                        v-model="o.label.am"
                        :disabled="!!o.id"
                        :placeholder="c.fields.option_label + ' (am)'"
                        class="rounded-md border px-2 py-1"
                        :style="{ borderColor: 'var(--ds-border)' }"
                    />
                    <button
                        type="button"
                        class="rounded border px-1.5 py-0.5"
                        :style="{ borderColor: 'var(--ds-border)' }"
                        @click="removeOption(o)"
                    >
                        {{ c.fields.option_remove }}
                    </button>
                </div>
                <button
                    v-if="!editing.id"
                    type="button"
                    data-testid="wf-option-add"
                    class="mt-1 rounded-md border px-2 py-1"
                    :style="{ borderColor: 'var(--ds-border)' }"
                    @click="editing.options.push({ value: '', label: { en: '', am: '' } })"
                >
                    {{ c.fields.option_add }}
                </button>
            </div>
            <div v-if="editing.id && whereUsed" class="mt-3" data-testid="wf-where-used-panel">
                <h4 class="font-semibold">{{ c.fields.where_used_title }}</h4>
                <p
                    v-if="
                        !whereUsed.forms.length &&
                        !whereUsed.rules.length &&
                        !whereUsed.views.length
                    "
                    :style="{ color: 'var(--ds-text-muted)' }"
                >
                    {{ c.fields.where_used_none }}
                </p>
                <p v-if="whereUsed.forms.length">
                    {{ usedLine('forms') }}
                </p>
                <p v-if="whereUsed.rules.length">
                    {{ usedLine('rules') }}
                </p>
            </div>
            <div class="mt-3 flex gap-2">
                <button
                    type="button"
                    data-testid="wf-field-save"
                    class="rounded-lg px-3 py-2 font-semibold"
                    :style="{ background: 'var(--ds-accent)', color: 'var(--ds-accent-contrast)' }"
                    @click="save"
                >
                    {{ c.common.save }}</button
                ><button
                    type="button"
                    class="rounded-lg border px-3 py-2"
                    :style="{ borderColor: 'var(--ds-border)' }"
                    @click="editing = null"
                >
                    {{ c.common.cancel }}
                </button>
            </div>
        </section>
    </WorkflowShell>
</template>
