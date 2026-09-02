<script setup lang="ts">
// Doc 12 §8 form builder (S12 step 14): left PALETTE (field types + existing custom fields as REFs),
// center CANVAS with step tabs and PLACEMENT MENUS (§13 — drag is enhancement), right INSPECTOR
// (labels/help per language with completeness dots, validation, "required when visible", the
// conditional with its plain-sentence render), the DUAL LIVE PREVIEW (widget render / Telegram
// transcript) from the contract renderers on the server (FormPublisher::preview + the blocks), the
// publish dialog with FR-22 blocks + the usage banner, version history with a fields diff. J19: ≥1024.
// [B12-batch].
import { computed, ref } from 'vue';
import WorkflowShell from '../../Components/Workflow/WorkflowShell.vue';
import WorkflowRefusal from '../../Components/Workflow/WorkflowRefusal.vue';
import { post, tr } from '../../Components/Workflow/workflowApi';

type Field = {
    key: string;
    type?: string;
    ref?: string;
    required?: boolean;
    required_when_visible?: boolean;
    label?: Record<string, string>;
    help?: Record<string, string>;
    validation?: Record<string, unknown>;
    show_when?: { field: string; op: string; value: unknown } | null;
    options?: { value: string; label: Record<string, string> }[];
};
type Step = { key: string; title: Record<string, string>; fields: Field[] };
type Schema = { languages: string[]; steps: Step[]; completion: Record<string, unknown> };
const props = defineProps<{
    copy: Record<string, unknown>;
    blocks: Record<string, string>;
    usage_labels: Record<string, string>;
    can: Record<string, boolean>;
    form: {
        id: string;
        name: string;
        active: boolean;
        current_version_id: string | null;
        updated_at: string | null;
    };
    versions: { id: string; version: number; schema: Schema; published_at: string | null }[];
    schema: Schema;
    field_types: string[];
    custom_fields: { id: string; entity: string; key: string; label: string; type: string }[];
    usage: Record<string, { id: string; name: string }[]>;
}>();
// eslint-disable-next-line @typescript-eslint/no-explicit-any
const c = props.copy as any;
const readonly = ref(window.innerWidth < 1024);
const schema = ref<Schema>(JSON.parse(JSON.stringify(props.schema)));
const step = ref(0);
const selected = ref<number | null>(null);
const refusal = ref('');
const previewLang = ref(schema.value.languages[0] ?? 'en');
const previewMode = ref<'widget' | 'telegram'>('widget');
const preview = ref<{
    blocks: { code: string; detail?: string; at?: string }[];
    usage: Record<string, unknown[]>;
    widget?: unknown;
    telegram?: unknown;
} | null>(null);
const publishing = ref(false);
const current = computed(() => schema.value.steps[step.value]);
const field = computed(() =>
    selected.value !== null ? (current.value?.fields[selected.value] ?? null) : null,
);
const cursorLabel = (f: Field): string =>
    f.label?.[previewLang.value] ?? f.label?.en ?? f.ref ?? f.key;

function completeness(obj: Record<string, string> | undefined): number {
    return schema.value.languages.filter((l) => (obj?.[l] ?? '').trim() !== '').length;
}
function addStep(): void {
    schema.value.steps.push({
        key: `step_${schema.value.steps.length + 1}`,
        title: Object.fromEntries(schema.value.languages.map((l) => [l, ''])),
        fields: [],
    });
    step.value = schema.value.steps.length - 1;
}
function addField(type: string, ref?: string): void {
    if (!current.value) addStep();
    const target = schema.value.steps[step.value] as Step;
    const key = ref ?? `${type}_${target.fields.length + 1}`;
    target.fields.push(
        ref
            ? { key, ref, required: false }
            : {
                  key,
                  type,
                  required: false,
                  label: Object.fromEntries(schema.value.languages.map((l) => [l, ''])),
              },
    );
    selected.value = target.fields.length - 1;
}
function place(index: number, delta: number): void {
    const list = current.value?.fields;
    if (!list) return;
    const j = index + delta;
    if (j < 0 || j >= list.length) return;
    [list[index], list[j]] = [list[j] as Field, list[index] as Field];
    selected.value = j;
}
function removeField(index: number): void {
    current.value?.fields.splice(index, 1);
    selected.value = null;
}
const sentence = computed(() =>
    field.value?.show_when
        ? tr(c.forms.conditional_sentence, {
              field: field.value.show_when.field,
              op: field.value.show_when.op,
              value: String(field.value.show_when.value ?? ''),
          })
        : '',
);
async function refreshPreview(): Promise<void> {
    refusal.value = '';
    const result = await post<{
        blocks: { code: string; detail?: string; at?: string }[];
        usage: Record<string, unknown[]>;
        widget?: unknown;
        telegram?: unknown;
    }>(`/admin/workflow/forms/${props.form.id}/preview`, { schema: schema.value });
    if (!result.ok) {
        refusal.value = tr(c.common.refused, { detail: result.refusal });
        return;
    }
    preview.value = result.data;
}
async function publish(): Promise<void> {
    refusal.value = '';
    const result = await post<{ version: number }>(
        `/admin/workflow/forms/${props.form.id}/publish`,
        { schema: schema.value },
    );
    publishing.value = false;
    if (!result.ok) {
        refusal.value = tr(c.common.refused, { detail: result.refusal });
        await refreshPreview();
        return;
    }
    window.location.reload();
}
function diff(
    a: Schema | null,
    b: Schema,
): { added: string[]; removed: string[]; changed: string[] } {
    const keys = (s: Schema | null): Record<string, string> =>
        Object.fromEntries(
            (s?.steps ?? []).flatMap((st) => st.fields.map((f) => [f.key, JSON.stringify(f)])),
        );
    const ka = keys(a),
        kb = keys(b);
    return {
        added: Object.keys(kb).filter((k) => !(k in ka)),
        removed: Object.keys(ka).filter((k) => !(k in kb)),
        changed: Object.keys(kb).filter((k) => k in ka && ka[k] !== kb[k]),
    };
}
function blockText(b: {
    code: string;
    detail?: string;
    at?: string;
    field?: string;
    language?: string;
    channel?: string;
}): string {
    return tr(props.blocks[b.code] ?? b.code, {
        detail: b.detail ?? '',
        at: b.at ?? '',
        field: b.field ?? '',
        language: b.language ?? '',
        channel: b.channel ?? '',
    });
}
</script>

<template>
    <WorkflowShell :copy="c" active="forms" :title="c.forms.builder_title" :summary="form.name">
        <p
            v-if="readonly"
            role="status"
            class="mb-3 rounded-md border px-3 py-2 text-xs"
            :style="{ borderColor: 'var(--ds-border)', background: 'var(--ds-inset-bg)' }"
        >
            {{ c.hub.readonly_notice }}
        </p>
        <div
            class="grid grid-cols-1 gap-3 xl:grid-cols-[200px_minmax(0,1fr)_280px]"
            data-testid="wf-form-builder"
        >
            <!-- palette -->
            <aside
                class="rounded-lg border p-2 text-xs"
                :style="{ background: 'var(--ds-panel-bg)', borderColor: 'var(--ds-border)' }"
                :aria-label="c.forms.palette"
            >
                <h3 class="font-semibold">{{ c.forms.palette }}</h3>
                <div class="mt-1 flex flex-col gap-1">
                    <button
                        v-for="t in field_types"
                        :key="t"
                        type="button"
                        :disabled="readonly"
                        :data-testid="`wf-palette-${t}`"
                        class="rounded-md border px-2 py-1 text-start"
                        :style="{ borderColor: 'var(--ds-border)' }"
                        @click="addField(t)"
                    >
                        {{ tr(c.forms.add_field, { type: t }) }}
                    </button>
                </div>
                <h3 class="mt-3 font-semibold">{{ c.forms.palette_existing }}</h3>
                <div class="mt-1 flex flex-col gap-1">
                    <button
                        v-for="f in custom_fields"
                        :key="f.id"
                        type="button"
                        :disabled="readonly"
                        :data-testid="`wf-palette-ref-${f.key}`"
                        class="rounded-md border px-2 py-1 text-start"
                        :style="{ borderColor: 'var(--ds-border)' }"
                        @click="addField(f.type, f.key)"
                    >
                        {{ f.entity }} · {{ f.key }}
                    </button>
                </div>
            </aside>
            <!-- canvas -->
            <section
                class="rounded-lg border p-3 text-xs"
                :style="{ background: 'var(--ds-panel-bg)', borderColor: 'var(--ds-border)' }"
            >
                <div
                    role="tablist"
                    class="flex flex-wrap items-center gap-1 border-b pb-2"
                    :style="{ borderColor: 'var(--ds-border)' }"
                >
                    <button
                        v-for="(s, i) in schema.steps"
                        :key="s.key"
                        role="tab"
                        :aria-selected="step === i"
                        :data-testid="`wf-step-${i}`"
                        class="rounded-md px-2 py-1 font-semibold"
                        :style="
                            step === i
                                ? {
                                      background: 'var(--ds-accent)',
                                      color: 'var(--ds-accent-contrast)',
                                  }
                                : {}
                        "
                        @click="
                            step = i;
                            selected = null;
                        "
                    >
                        {{ tr(c.forms.step, { n: i + 1 }) }}
                        <span aria-hidden="true">{{
                            completeness(s.title) === schema.languages.length ? '●' : '○'
                        }}</span
                        ><span class="sr-only">{{
                            tr(c.forms.completeness, {
                                done: completeness(s.title),
                                total: schema.languages.length,
                            })
                        }}</span>
                    </button>
                    <button
                        type="button"
                        :disabled="readonly"
                        data-testid="wf-step-add"
                        class="rounded-md border px-2 py-1"
                        :style="{ borderColor: 'var(--ds-border)' }"
                        @click="addStep"
                    >
                        {{ c.forms.add_step }}
                    </button>
                </div>
                <div v-if="current" class="mt-2">
                    <div class="flex flex-wrap gap-2">
                        <label v-for="l in schema.languages" :key="l"
                            >{{
                                tr(c.forms.label_per_language, {
                                    language: c.common['language_' + l] ?? l,
                                })
                            }}
                            <input
                                v-model="current.title[l]" :data-testid="`wf-step-title-${l}`"
                                :disabled="readonly"
                                class="rounded-md border px-2 py-1"
                                :style="{ borderColor: 'var(--ds-border)' }"
                        /></label>
                    </div>
                    <ol class="mt-2 flex flex-col gap-1">
                        <li
                            v-for="(f, i) in current.fields"
                            :key="f.key"
                            :data-testid="`wf-canvas-field-${f.key}`"
                            class="flex items-center gap-2 rounded-md border p-2"
                            :style="{
                                borderColor:
                                    selected === i ? 'var(--ds-accent)' : 'var(--ds-border-soft)',
                                background: 'var(--ds-inset-bg)',
                            }"
                        >
                            <button type="button" class="flex-1 text-start" @click="selected = i">
                                <span v-if="f.show_when" aria-hidden="true">⑂ </span
                                >{{ cursorLabel(f) }}
                                <code :style="{ color: 'var(--ds-text-muted)' }">{{
                                    f.ref ?? f.type
                                }}</code
                                ><span v-if="f.required"> *</span>
                            </button>
                            <span class="flex gap-1"
                                ><button
                                    type="button"
                                    :aria-label="c.common.move_up"
                                    class="rounded border px-1"
                                    :style="{ borderColor: 'var(--ds-border)' }"
                                    @click="place(i, -1)"
                                >
                                    ↑</button
                                ><button
                                    type="button"
                                    :aria-label="c.common.move_down"
                                    class="rounded border px-1"
                                    :style="{ borderColor: 'var(--ds-border)' }"
                                    @click="place(i, 1)"
                                >
                                    ↓</button
                                ><button
                                    type="button"
                                    :aria-label="c.rules.remove"
                                    class="rounded border px-1"
                                    :style="{ borderColor: 'var(--ds-border)' }"
                                    @click="removeField(i)"
                                >
                                    ✕
                                </button></span
                            >
                        </li>
                    </ol>
                </div>
                <details class="mt-3">
                    <summary class="cursor-pointer" :style="{ color: 'var(--ds-text-muted)' }">
                        {{ c.forms.schema_json }}
                    </summary>
                    <textarea
                        :value="JSON.stringify(schema, null, 2)"
                        :disabled="readonly"
                        rows="10"
                        class="mt-1 w-full rounded-md border p-2 font-mono"
                        :style="{ borderColor: 'var(--ds-border)' }"
                        @change="schema = JSON.parse(($event.target as HTMLTextAreaElement).value)"
                    ></textarea>
                </details>
            </section>
            <!-- inspector -->
            <aside
                class="rounded-lg border p-3 text-xs"
                :style="{ background: 'var(--ds-panel-bg)', borderColor: 'var(--ds-border)' }"
                :aria-label="c.forms.inspector"
            >
                <h3 class="font-semibold">{{ c.forms.inspector }}</h3>
                <template v-if="field">
                    <p class="mt-1">
                        <code>{{ field.key }}</code> · {{ field.ref ?? field.type }}
                    </p>
                    <template v-if="!field.ref">
                        <label v-for="l in schema.languages" :key="'l' + l" class="mt-2 block"
                            >{{
                                tr(c.forms.label_per_language, {
                                    language: c.common['language_' + l] ?? l,
                                })
                            }}
                            <span aria-hidden="true">{{
                                (field.label?.[l] ?? '') ? '●' : '○'
                            }}</span
                            ><input
                                v-model="(field.label as Record<string, string>)[l]"
                                :disabled="readonly"
                                :data-testid="`wf-inspector-label-${l}`"
                                class="mt-1 w-full rounded-md border px-2 py-1"
                                :style="{ borderColor: 'var(--ds-border)' }"
                        /></label>
                        <label v-for="l in schema.languages" :key="'h' + l" class="mt-2 block"
                            >{{
                                tr(c.forms.help_per_language, {
                                    language: c.common['language_' + l] ?? l,
                                })
                            }}<input
                                :value="field.help?.[l] ?? ''"
                                :disabled="readonly"
                                class="mt-1 w-full rounded-md border px-2 py-1"
                                :style="{ borderColor: 'var(--ds-border)' }"
                                @input="
                                    field.help = {
                                        ...(field.help ?? {}),
                                        [l]: ($event.target as HTMLInputElement).value,
                                    }
                                "
                        /></label>
                    </template>
                    <label class="mt-2 flex items-center gap-2"
                        ><input
                            v-model="field.required"
                            type="checkbox"
                            :disabled="readonly"
                            data-testid="wf-inspector-required"
                        />
                        {{ c.forms.required }}</label
                    >
                    <label class="flex items-center gap-2"
                        ><input
                            v-model="field.required_when_visible"
                            type="checkbox"
                            :disabled="readonly"
                            data-testid="wf-inspector-required-when-visible"
                        />
                        {{ c.forms.required_when_visible }}</label
                    >
                    <div class="mt-2">
                        <span class="block" :style="{ color: 'var(--ds-text-label)' }">{{
                            c.forms.conditional
                        }}</span>
                        <div class="mt-1 flex flex-wrap gap-1">
                            <input
                                :value="field.show_when?.field ?? ''"
                                :placeholder="c.rules.fact"
                                class="w-24 rounded-md border px-1 py-0.5"
                                :style="{ borderColor: 'var(--ds-border)' }"
                                @change="
                                    field.show_when = {
                                        field: ($event.target as HTMLInputElement).value,
                                        op: field.show_when?.op ?? 'equals',
                                        value: field.show_when?.value ?? '',
                                    }
                                "
                            /><select
                                :value="field.show_when?.op ?? 'equals'"
                                class="rounded-md border px-1 py-0.5"
                                :style="{ borderColor: 'var(--ds-border)' }"
                                @change="
                                    field.show_when = {
                                        field: field.show_when?.field ?? '',
                                        op: ($event.target as HTMLSelectElement).value,
                                        value: field.show_when?.value ?? '',
                                    }
                                "
                            >
                                <option
                                    v-for="op in ['equals', 'not_equals', 'in', 'is_empty']"
                                    :key="op"
                                    :value="op"
                                >
                                    {{ op }}
                                </option></select
                            ><input
                                :value="String(field.show_when?.value ?? '')"
                                :placeholder="c.rules.value"
                                class="w-24 rounded-md border px-1 py-0.5"
                                :style="{ borderColor: 'var(--ds-border)' }"
                                @change="
                                    field.show_when = {
                                        field: field.show_when?.field ?? '',
                                        op: field.show_when?.op ?? 'equals',
                                        value: ($event.target as HTMLInputElement).value,
                                    }
                                "
                            /><button
                                type="button"
                                class="rounded border px-1"
                                :style="{ borderColor: 'var(--ds-border)' }"
                                @click="field.show_when = null"
                            >
                                ✕
                            </button>
                        </div>
                        <p
                            v-if="sentence"
                            class="mt-1"
                            data-testid="wf-conditional-sentence"
                            :style="{ color: 'var(--ds-text-muted)' }"
                        >
                            {{ sentence }}
                        </p>
                    </div>
                </template>
                <h3 class="mt-4 font-semibold">{{ c.forms.preview_title }}</h3>
                <div class="mt-1 flex gap-1">
                    <button
                        v-for="m in ['widget', 'telegram']"
                        :key="m"
                        type="button"
                        :data-testid="`wf-preview-${m}`"
                        class="rounded-md border px-2 py-1"
                        :style="
                            previewMode === m
                                ? {
                                      background: 'var(--ds-accent)',
                                      color: 'var(--ds-accent-contrast)',
                                  }
                                : { borderColor: 'var(--ds-border)' }
                        "
                        @click="previewMode = m as 'widget' | 'telegram'"
                    >
                        {{ c.forms['preview_' + m] }}</button
                    ><select
                        v-model="previewLang"
                        class="ms-auto rounded-md border px-1"
                        :style="{ borderColor: 'var(--ds-border)' }"
                    >
                        <option v-for="l in schema.languages" :key="l" :value="l">{{ l }}</option>
                    </select>
                </div>
                <button
                    type="button"
                    data-testid="wf-preview-refresh"
                    class="mt-2 rounded-lg border px-3 py-1.5 font-semibold"
                    :style="{ borderColor: 'var(--ds-border)' }"
                    @click="refreshPreview"
                >
                    {{ c.forms.preview_title }} ↻
                </button>
                <div
                    class="mt-2 rounded-md border p-2"
                    :style="{ borderColor: 'var(--ds-border)', background: 'var(--ds-inset-bg)' }"
                    data-testid="wf-preview-pane"
                >
                    <template v-if="previewMode === 'widget'"
                        ><div v-for="(s, si) in schema.steps" :key="s.key" class="mb-2">
                            <p class="font-semibold">
                                {{ s.title[previewLang] || tr(c.forms.step, { n: si + 1 }) }}
                            </p>
                            <div v-for="f in s.fields" :key="f.key" class="mt-1">
                                <label class="block"
                                    >{{ cursorLabel(f) }}<span v-if="f.required"> *</span
                                    ><input
                                        disabled
                                        class="mt-0.5 w-full rounded-md border px-2 py-1"
                                        :style="{ borderColor: 'var(--ds-border)' }"
                                /></label>
                            </div></div
                    ></template>
                    <template v-else
                        ><div
                            class="rounded-xl border p-2 font-mono"
                            :style="{ borderColor: 'var(--ds-border-strong)' }"
                            role="log"
                            :aria-label="c.forms.preview_telegram"
                        >
                            <p
                                v-for="(f, i) in schema.steps.flatMap((s) => s.fields)"
                                :key="f.key"
                                class="mt-1"
                            >
                                🤖 {{ i + 1 }}/{{ schema.steps.flatMap((s) => s.fields).length }} —
                                {{ cursorLabel(f)
                                }}<span v-if="f.options?.length">
                                    ({{
                                        f.options
                                            .map(
                                                (o, k) =>
                                                    `${k + 1}. ${o.label[previewLang] ?? o.value}`,
                                            )
                                            .join(' ')
                                    }})</span
                                >
                            </p>
                        </div></template
                    >
                </div>
                <div v-if="preview" class="mt-2" data-testid="wf-preview-blocks">
                    <p
                        v-if="preview.blocks.length"
                        class="font-semibold"
                        :style="{ color: 'var(--ds-danger-text)' }"
                    >
                        {{ c.forms.publish_blocked }}
                    </p>
                    <ul>
                        <li v-for="(b, i) in preview.blocks" :key="i" :data-block="b.code">
                            {{ blockText(b) }}
                        </li>
                    </ul>
                </div>
                <WorkflowRefusal :text="refusal" />
                <div v-if="!readonly" class="mt-3 flex flex-wrap gap-2">
                    <button
                        v-if="can['forms.publish']"
                        type="button"
                        data-testid="wf-publish"
                        class="rounded-lg px-3 py-2 font-semibold"
                        :style="{
                            background: 'var(--ds-accent)',
                            color: 'var(--ds-accent-contrast)',
                        }"
                        @click="publishing = true"
                    >
                        {{ c.forms.publish }}
                    </button>
                </div>
                <div
                    v-if="publishing"
                    role="dialog"
                    aria-modal="true"
                    :aria-label="c.forms.publish_verb"
                    class="mt-2 rounded-md border p-2"
                    :style="{ borderColor: 'var(--ds-border-strong)' }"
                >
                    <p>
                        {{
                            tr(c.forms.publish_confirm, {
                                version: (versions[0]?.version ?? 0) + 1,
                                name: form.name,
                            })
                        }}
                    </p>
                    <p
                        v-if="Object.values(usage).some((l) => l.length)"
                        class="mt-1"
                        :style="{ color: 'var(--ds-text-muted)' }"
                    >
                        {{ c.forms.usage_banner }}:
                        {{
                            Object.entries(usage)
                                .flatMap(([k, l]) =>
                                    l.map((x) => `${props.usage_labels[k] ?? k}: ${x.name}`),
                                )
                                .join(' · ')
                        }}
                    </p>
                    <div class="mt-2 flex gap-2">
                        <button
                            type="button"
                            data-testid="wf-publish-confirm"
                            class="rounded-lg px-3 py-1.5 font-semibold"
                            :style="{
                                background: 'var(--ds-accent)',
                                color: 'var(--ds-accent-contrast)',
                            }"
                            @click="publish"
                        >
                            {{ c.forms.publish_verb }}</button
                        ><button
                            type="button"
                            class="rounded-lg border px-3 py-1.5"
                            :style="{ borderColor: 'var(--ds-border)' }"
                            @click="publishing = false"
                        >
                            {{ c.common.cancel }}
                        </button>
                    </div>
                </div>
                <h3 class="mt-4 font-semibold">{{ c.forms.history }}</h3>
                <ul class="mt-1 flex flex-col gap-1">
                    <li v-for="(v, i) in versions" :key="v.id" :data-version-row="v.version">
                        <span class="font-semibold">{{
                            tr(c.forms.version, { version: v.version })
                        }}</span>
                        <span :style="{ color: 'var(--ds-text-muted)' }">{{ v.published_at }}</span
                        ><template v-if="versions[i + 1]"
                            ><span
                                v-for="(list, kind) in diff(
                                    versions[i + 1]?.schema ?? null,
                                    v.schema,
                                )"
                                :key="kind"
                                ><span v-if="list.length" class="ms-1">{{
                                    tr(c.forms['diff_' + kind], { fields: list.join(', ') })
                                }}</span></span
                            ></template
                        >
                    </li>
                </ul>
            </aside>
        </div>
    </WorkflowShell>
</template>
