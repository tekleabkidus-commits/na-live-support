<script setup lang="ts">
// Doc 12 §8 macros manager (S12 step 14): grouped by scope with usage counts; the editor with the
// variable palette (pills — §9: uneditable inner text, delete-as-unit), LIVE PREVIEW against a sample
// ticket through PaletteReader::preview (the ONE resolver — T-06 parity), bundled-action chips.
// Insert-never-sends holds by construction: this page has no send door at all. [B12-batch].
import { computed, ref } from 'vue';
import WorkflowShell from '../../Components/Workflow/WorkflowShell.vue';
import WorkflowRefusal from '../../Components/Workflow/WorkflowRefusal.vue';
import { post, tr } from '../../Components/Workflow/workflowApi';

type Macro = {
    id: string | null;
    name: string;
    scope: string;
    kind: string;
    body: string;
    owner_id: string | null;
    team_id: string | null;
    variables: string[];
    bundled_actions: { type: string; value: unknown }[];
    usage_count: number;
    updated_at?: string | null;
};
const props = defineProps<{
    copy: Record<string, unknown>;
    can: Record<string, boolean>;
    macros: Macro[];
    palette: unknown[];
    sample_tickets: { id: string; number: number; subject: string | null }[];
    variables: string[];
}>();
// eslint-disable-next-line @typescript-eslint/no-explicit-any
const c = props.copy as any;
const editing = ref<Macro | null>(null);
const refusal = ref('');
const sampleTicket = ref(props.sample_tickets[0]?.id ?? '');
const preview = ref<{
    text: string;
    variables: Record<string, string | null>;
    unresolved: string[];
} | null>(null);
const body = ref<HTMLTextAreaElement | null>(null);
const groups = computed(() => ({
    personal: props.macros.filter((m) => m.scope === 'personal'),
    team: props.macros.filter((m) => m.scope === 'team'),
    tenant: props.macros.filter((m) => m.scope === 'tenant'),
}));
const pills = computed(() =>
    editing.value
        ? Array.from(editing.value.body.matchAll(/\{\{\s*([a-z_.][a-z0-9_.]*)\s*\}\}/gi)).map(
              (m) => m[1] as string,
          )
        : [],
);

function blank(): Macro {
    return {
        id: null,
        name: '',
        scope: 'personal',
        kind: 'macro',
        body: '',
        owner_id: null,
        team_id: null,
        variables: [],
        bundled_actions: [],
        usage_count: 0,
    };
}
function insertVariable(name: string): void {
    if (!editing.value) return;
    const el = body.value;
    const token = `{{${name}}}`;
    if (el) {
        const start = el.selectionStart ?? editing.value.body.length;
        editing.value.body =
            editing.value.body.slice(0, start) +
            token +
            editing.value.body.slice(el.selectionEnd ?? start);
    } else editing.value.body += token;
}
function removePill(name: string): void {
    if (!editing.value) return;
    editing.value.body = editing.value.body.replace(
        new RegExp(`\\{\\{\\s*${name.replace('.', '\\.')}\\s*\\}\\}`, 'g'),
        '',
    );
}
function toggleBundled(type: string): void {
    if (!editing.value) return;
    const has = editing.value.bundled_actions.some((b) => b.type === type);
    editing.value.bundled_actions = has
        ? editing.value.bundled_actions.filter((b) => b.type !== type)
        : [
              ...editing.value.bundled_actions,
              {
                  type,
                  value:
                      type === 'set_status'
                          ? 'pending_customer'
                          : type === 'set_priority'
                            ? 'high'
                            : ['macro'],
              },
          ];
}
async function save(): Promise<void> {
    if (!editing.value) return;
    refusal.value = '';
    const payload = {
        name: editing.value.name,
        scope: editing.value.scope,
        kind: editing.value.kind,
        body: editing.value.body,
        team_id: editing.value.team_id,
        bundled_actions: editing.value.bundled_actions,
    };
    const result = await post(
        editing.value.id ? `/admin/workflow/macros/${editing.value.id}` : '/admin/workflow/macros',
        payload,
    );
    if (!result.ok) {
        refusal.value = tr(c.common.refused, { detail: result.refusal });
        return;
    }
    window.location.reload();
}
async function runPreview(): Promise<void> {
    if (!editing.value?.id) return;
    refusal.value = '';
    const result = await post<{
        text: string;
        variables: Record<string, string | null>;
        unresolved: string[];
    }>(`/admin/workflow/macros/${editing.value.id}/preview`, { ticket_id: sampleTicket.value });
    if (!result.ok) {
        refusal.value = tr(c.common.refused, { detail: result.refusal });
        return;
    }
    preview.value = result.data;
}
</script>

<template>
    <WorkflowShell
        :copy="c"
        active="macros"
        :title="c.macros.title"
        :summary="
            tr(c.macros.summary, {
                personal: groups.personal.length,
                team: groups.team.length,
                tenant: groups.tenant.length,
            })
        "
    >
        <button
            type="button"
            data-testid="wf-macro-new"
            class="mb-3 rounded-lg px-3 py-2 text-xs font-semibold"
            :style="{ background: 'var(--ds-accent)', color: 'var(--ds-accent-contrast)' }"
            @click="editing = blank()"
        >
            {{ c.macros.new }}
        </button>
        <p
            v-if="macros.length === 0 && !editing"
            class="text-sm"
            :style="{ color: 'var(--ds-text-muted)' }"
        >
            {{ c.macros.empty }}
        </p>
        <section v-for="(list, scope) in groups" :key="scope" class="mb-3">
            <h3
                v-if="list.length"
                class="text-xs font-semibold uppercase tracking-wider"
                :style="{ color: 'var(--ds-text-label)' }"
            >
                {{ c.macros['scope_' + scope] }}
            </h3>
            <ul class="mt-1 flex flex-col gap-1">
                <li
                    v-for="m in list"
                    :key="m.id ?? ''"
                    :data-macro-row="m.id"
                    class="flex flex-wrap items-center gap-3 rounded-lg border p-2 text-sm"
                    :style="{ background: 'var(--ds-panel-bg)', borderColor: 'var(--ds-border)' }"
                >
                    <span class="font-semibold">{{ m.name }}</span>
                    <span
                        class="rounded px-1.5 py-0.5 text-[10.5px]"
                        :style="{ background: 'var(--ds-inset-bg)' }"
                        >{{ c.macros['kind_' + m.kind] ?? m.kind }}</span
                    >
                    <span
                        v-for="b in m.bundled_actions"
                        :key="b.type"
                        class="rounded px-1.5 py-0.5 text-[10.5px]"
                        :style="{ background: 'var(--ds-status-pending-internal-bg)' }"
                        >{{ b.type }}</span
                    >
                    <span class="text-xs" :style="{ color: 'var(--ds-text-muted)' }">{{
                        tr(c.macros.usage, { count: m.usage_count })
                    }}</span>
                    <button
                        type="button"
                        :data-testid="`wf-macro-edit-${m.id}`"
                        class="ms-auto rounded-md border px-2 py-1 text-xs"
                        :style="{ borderColor: 'var(--ds-border)' }"
                        @click="
                            editing = JSON.parse(JSON.stringify(m));
                            preview = null;
                        "
                    >
                        {{ c.common.save }} …
                    </button>
                </li>
            </ul>
        </section>
        <section
            v-if="editing"
            class="mt-4 grid grid-cols-1 gap-4 rounded-lg border p-4 xl:grid-cols-2"
            :style="{ background: 'var(--ds-panel-bg)', borderColor: 'var(--ds-border-strong)' }"
            data-testid="wf-macro-editor"
        >
            <div class="text-xs">
                <label
                    ><span class="block" :style="{ color: 'var(--ds-text-label)' }">{{
                        c.common.name
                    }}</span
                    ><input
                        v-model="editing.name"
                        data-testid="wf-macro-name"
                        class="mt-1 w-full rounded-md border px-2 py-1.5 text-sm"
                        :style="{
                            borderColor: 'var(--ds-border)',
                            background: 'var(--ds-inset-bg)',
                        }"
                /></label>
                <div class="mt-2 flex gap-3">
                    <label
                        ><span class="block" :style="{ color: 'var(--ds-text-label)' }">{{
                            c.macros.scope
                        }}</span
                        ><select
                            v-model="editing.scope"
                            :disabled="!!editing.id"
                            class="mt-1 rounded-md border px-2 py-1.5 text-sm"
                            :style="{
                                borderColor: 'var(--ds-border)',
                                background: 'var(--ds-inset-bg)',
                            }"
                        >
                            <option value="personal">{{ c.macros.scope_personal }}</option>
                            <option value="team">{{ c.macros.scope_team }}</option>
                            <option v-if="can['macros.shared.manage']" value="tenant">
                                {{ c.macros.scope_tenant }}
                            </option>
                        </select></label
                    >
                    <label
                        ><span class="block" :style="{ color: 'var(--ds-text-label)' }">{{
                            c.macros.kind
                        }}</span
                        ><select
                            v-model="editing.kind"
                            class="mt-1 rounded-md border px-2 py-1.5 text-sm"
                            :style="{
                                borderColor: 'var(--ds-border)',
                                background: 'var(--ds-inset-bg)',
                            }"
                        >
                            <option value="macro">{{ c.macros.kind_macro }}</option>
                            <option value="saved_reply">{{ c.macros.kind_saved_reply }}</option>
                        </select></label
                    >
                </div>
                <label class="mt-2 block"
                    ><span class="block" :style="{ color: 'var(--ds-text-label)' }">{{
                        c.macros.body
                    }}</span
                    ><textarea
                        ref="body"
                        v-model="editing.body"
                        data-testid="wf-macro-body"
                        rows="6"
                        class="mt-1 w-full rounded-md border px-2 py-1.5 font-mono text-sm"
                        :style="{
                            borderColor: 'var(--ds-border)',
                            background: 'var(--ds-inset-bg)',
                        }"
                    ></textarea>
                </label>
                <div
                    class="mt-2 flex flex-wrap items-center gap-1"
                    :aria-label="c.macros.variables"
                >
                    <span
                        v-for="p in pills"
                        :key="p"
                        class="inline-flex items-center gap-1 rounded-full border px-2 py-0.5"
                        :style="{
                            borderColor: 'var(--ds-border-strong)',
                            background: 'var(--ds-inset-bg)',
                        }"
                        :data-pill="p"
                        ><span aria-hidden="true" v-text="'{{' + p + '}}'"></span
                        ><span class="sr-only"
                            >{{ p }} —
                            {{
                                preview?.variables?.[p] === undefined
                                    ? ''
                                    : preview.variables[p] === null
                                      ? c.macros.preview_unresolved.split(':')[0]
                                      : 'ok'
                            }}</span
                        ><button
                            type="button"
                            :aria-label="c.rules.remove + ' ' + p"
                            class="text-[10px]"
                            @click="removePill(p)"
                        >
                            ✕
                        </button></span
                    >
                </div>
                <div class="mt-2 flex flex-wrap gap-1">
                    <span class="me-1" :style="{ color: 'var(--ds-text-label)' }"
                        >{{ c.macros.insert_variable }}:</span
                    ><button
                        v-for="v in variables"
                        :key="v"
                        type="button"
                        :data-testid="`wf-var-${v}`"
                        class="rounded-md border px-2 py-0.5"
                        :style="{ borderColor: 'var(--ds-border)' }"
                        @click="insertVariable(v)"
                    >
                        {{ v }}
                    </button>
                </div>
                <div class="mt-3">
                    <span class="block" :style="{ color: 'var(--ds-text-label)' }">{{
                        c.macros.bundled
                    }}</span>
                    <div class="mt-1 flex gap-2">
                        <label
                            v-for="t in ['set_status', 'set_priority', 'add_tags']"
                            :key="t"
                            class="flex items-center gap-1"
                            ><input
                                type="checkbox"
                                :checked="editing.bundled_actions.some((b) => b.type === t)"
                                @change="toggleBundled(t)"
                            />
                            {{ c.rules.action_card[t] }}</label
                        >
                    </div>
                </div>
                <WorkflowRefusal :text="refusal" />
                <div class="mt-3 flex gap-2">
                    <button
                        type="button"
                        data-testid="wf-macro-save"
                        class="rounded-lg px-3 py-2 text-xs font-semibold"
                        :style="{
                            background: 'var(--ds-accent)',
                            color: 'var(--ds-accent-contrast)',
                        }"
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
            </div>
            <div class="text-xs">
                <h4 class="text-sm font-semibold">{{ c.macros.preview_title }}</h4>
                <label class="mt-2 block"
                    ><span class="block" :style="{ color: 'var(--ds-text-label)' }">{{
                        c.macros.preview_ticket
                    }}</span
                    ><select
                        v-model="sampleTicket"
                        data-testid="wf-macro-sample"
                        class="mt-1 w-full rounded-md border px-2 py-1.5"
                        :style="{
                            borderColor: 'var(--ds-border)',
                            background: 'var(--ds-inset-bg)',
                        }"
                    >
                        <option v-for="t in sample_tickets" :key="t.id" :value="t.id">
                            #{{ t.number }} {{ t.subject ?? '' }}
                        </option>
                    </select></label
                >
                <button
                    type="button"
                    :disabled="!editing.id"
                    data-testid="wf-macro-preview"
                    class="mt-2 rounded-lg border px-3 py-2 font-semibold"
                    :style="{ borderColor: 'var(--ds-border)' }"
                    @click="runPreview"
                >
                    {{ c.macros.preview_run }}
                </button>
                <div
                    v-if="preview"
                    class="mt-3 rounded-md border p-3"
                    :style="{ borderColor: 'var(--ds-border)', background: 'var(--ds-inset-bg)' }"
                    data-testid="wf-macro-preview-out"
                >
                    <p class="whitespace-pre-wrap text-sm">{{ preview.text }}</p>
                    <p
                        v-if="preview.unresolved.length"
                        role="alert"
                        class="mt-2"
                        :style="{ color: 'var(--ds-danger-text)' }"
                    >
                        {{
                            tr(c.macros.preview_unresolved, {
                                variables: preview.unresolved.join(', '),
                            })
                        }}
                    </p>
                    <p v-else class="mt-2" :style="{ color: 'var(--ds-text-muted)' }">
                        {{ c.macros.preview_clean }}
                    </p>
                </div>
            </div>
        </section>
    </WorkflowShell>
</template>
