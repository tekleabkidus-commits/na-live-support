<script setup lang="ts">
// Doc 12 §8 fields manager (S12 step 14, redrawn to the frozen B9-S10 record in SESSION-F1 [B12]):
// entity tabs with counts; the ordered list (↑↓ moves, label in both languages, the immutable key,
// the 🔒 type-locked badge, the flags, the used-in counts); the editor with the key derived at birth,
// the 13 types chosen once (no type control after the first save — FR-18, X9), option rows with
// the EC-08 removal dialog and the removed snapshot, validation per type, the where-used panel
// live and complete; the deactivate dialog with EC-14's consequence under every entry; the retired
// section (keys never re-mint); the Doc 08 seam stated; 375 read posture. Doors unchanged.
import { computed, onMounted, ref, watch } from 'vue';
import WorkflowShell from '../../Components/Workflow/WorkflowShell.vue';
import WorkflowRefusal from '../../Components/Workflow/WorkflowRefusal.vue';
import { post, tr } from '../../Components/Workflow/workflowApi';

type Option = {
    id?: string;
    value: string;
    label: Record<string, string>;
    position?: number;
    active?: boolean;
    label_snapshot?: Record<string, string> | null;
    updated_at?: string | null;
};
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
    created_at?: string | null;
    stored_values?: number | null;
};
type Used = {
    forms: { id: string; name: string }[];
    rules: { id: string; name: string }[];
    views: { id: string; name: string | null }[];
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
const LANGS = ['en', 'am'];
const readonly = ref(window.innerWidth < 1024); // J07 — read posture on a phone
const tab = ref<'ticket' | 'customer'>('ticket');
const editing = ref<Field | null>(null);
const keyTouched = ref(false);
const refusal = ref('');
const whereUsed = ref<Used | null>(null);
const confirming = ref<Field | null>(null);
const removing = ref<Option | null>(null);
const used = ref<Record<string, Used>>({});
const canEdit = computed(() => props.can['fields.manage'] === true);

const live = computed(() =>
    props.fields
        .filter((f) => f.entity === tab.value && f.active)
        .sort((a, b) => a.position - b.position),
);
const retired = computed(() => props.fields.filter((f) => f.entity === tab.value && !f.active));
const counts = computed(() => ({
    ticket: props.fields.filter((f) => f.entity === 'ticket' && f.active).length,
    customer: props.fields.filter((f) => f.entity === 'customer' && f.active).length,
    retired: props.fields.filter((f) => !f.active).length,
}));
const summaryLine = computed(() => {
    const base = tr(c.fields.summary, {
        ticket: counts.value.ticket,
        customer: counts.value.customer,
    });
    return counts.value.retired
        ? `${base} · ${tr(c.fields.n_retired, { n: counts.value.retired })}`
        : base;
});
function tabSub(t: 'ticket' | 'customer'): string {
    const r = props.fields.filter((f) => f.entity === t && !f.active).length;
    const n = counts.value[t];
    return r ? `${n} · ${tr(c.fields.n_retired, { n: r })}` : String(n);
}
function labelOf(f: Field): string {
    return f.label.en || f.label.am || f.key;
}
function usedLine(f: Field): string {
    const u = used.value[f.key];
    if (!u) return c.fields.used_loading;
    return tr(c.fields.used_in, {
        forms: u.forms.length,
        rules: u.rules.length,
        views: u.views.length,
    });
}
function dateOf(iso: string | null | undefined): string {
    if (!iso) return '';
    const d = new Date(iso.replace(' ', 'T'));
    return Number.isNaN(d.getTime())
        ? ''
        : d.toLocaleDateString(undefined, { day: 'numeric', month: 'short' });
}
function retiredLine(f: Field): string {
    const parts = [tr(c.fields.retired_on, { date: dateOf(f.deactivated_at) })];
    if (f.stored_values !== null && f.stored_values !== undefined)
        parts.push(tr(c.fields.values_stay, { n: f.stored_values.toLocaleString() }));
    return parts.join(' · ');
}
async function fetchUsed(f: Field): Promise<Used | null> {
    const response = await fetch(`/admin/workflow/fields/${f.entity}/${f.key}/where-used`, {
        headers: { Accept: 'application/json' },
    });
    if (!response.ok) return null;
    const data = (await response.json()) as Used;
    return { forms: data.forms ?? [], rules: data.rules ?? [], views: data.views ?? [] };
}
async function loadCounts(): Promise<void> {
    for (const f of props.fields.filter((x) => x.active)) {
        if (used.value[f.key]) continue;
        const u = await fetchUsed(f);
        if (u) used.value = { ...used.value, [f.key]: u };
    }
}
async function loadWhereUsed(f: Field): Promise<void> {
    whereUsed.value = null;
    const u = await fetchUsed(f);
    whereUsed.value = u;
    if (u) used.value = { ...used.value, [f.key]: u };
}

// ── editing ──
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
function startNew(): void {
    editing.value = blank();
    keyTouched.value = false;
    whereUsed.value = null;
    refusal.value = '';
}
function open(f: Field): void {
    if (readonly.value) return;
    editing.value = JSON.parse(JSON.stringify(f));
    keyTouched.value = true;
    refusal.value = '';
    loadWhereUsed(f);
}
const isChoice = computed(
    () => editing.value !== null && props.choice_types.includes(editing.value.type),
);
function snake(s: string): string {
    return s
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '_')
        .replace(/^_+|_+$/g, '')
        .replace(/^[0-9]/, 'f$&')
        .slice(0, 48);
}
watch(
    () => editing.value?.label.en,
    (label) => {
        if (editing.value && !editing.value.id && !keyTouched.value)
            editing.value.key = snake(label ?? '');
    },
);
const amComplete = computed(() => !!editing.value?.label.am?.trim());
function setValidation(key: string, raw: string): void {
    if (!editing.value) return;
    const v = { ...editing.value.validation };
    if (raw === '') delete v[key];
    else v[key] = key === 'min' || key === 'max' || key === 'max_length' ? Number(raw) : raw;
    editing.value = { ...editing.value, validation: v };
}
const validationKind = computed<'pattern' | 'range' | 'length' | 'options' | 'none'>(() => {
    const t = editing.value?.type ?? 'text';
    if (['text', 'email', 'phone', 'url'].includes(t)) return 'pattern';
    if (t === 'number') return 'range';
    if (t === 'rich_text') return 'length';
    if (props.choice_types.includes(t)) return 'options';
    return 'none';
});
const vText = (k: string): string => String(editing.value?.validation[k] ?? '');
const placedIn = computed(() => (whereUsed.value?.forms ?? []).map((f) => f.name).join(' · '));
const retiredWithKey = computed(() =>
    editing.value && !editing.value.id
        ? (props.fields.find(
              (f) =>
                  f.key === editing.value?.key && f.entity === editing.value?.entity && !f.active,
          ) ?? null)
        : null,
);
// the door's refusal for a taken key (the unique on the key — it is never re-minted); a retired
// field with this key is the case the frame draws, whatever the door's own detail text says
const keyRefused = computed(
    () =>
        refusal.value !== '' &&
        (retiredWithKey.value !== null ||
            /duplicate|unique|exists|retired|reuse/i.test(refusal.value)),
);
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
async function move(f: Field, delta: number): Promise<void> {
    const result = await post(`/admin/workflow/fields/${f.id}`, { position: f.position + delta });
    if (!result.ok) {
        refusal.value = tr(c.common.refused, { detail: result.refusal });
        return;
    }
    window.location.reload();
}
function askRemove(o: Option): void {
    if (!editing.value?.id || !o.id) {
        if (editing.value) editing.value.options = editing.value.options.filter((x) => x !== o);
        return;
    }
    removing.value = o;
}
async function removeOption(): Promise<void> {
    const o = removing.value;
    if (!editing.value?.id || !o?.id) return;
    const result = await post(`/admin/workflow/fields/${editing.value.id}`, {
        remove_option_id: o.id,
    });
    removing.value = null;
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
const optionLabel = (o: Option, lang: string): string =>
    o.label?.[lang] ?? o.label_snapshot?.[lang] ?? '';
const removeTitle = computed(() =>
    removing.value
        ? tr(c.fields.remove_title, {
              label: optionLabel(removing.value, 'en') || removing.value.value,
              value: removing.value.value,
          })
        : '',
);
const removeRules = computed(() =>
    tr(c.fields.remove_rules, { n: whereUsed.value?.rules.length ?? 0 }),
);
const removeForms = computed(() =>
    tr(c.fields.remove_forms, { n: whereUsed.value?.forms.length ?? 0 }),
);
const confirmSentence = computed(() =>
    confirming.value ? tr(c.fields.deactivate_confirm, { label: labelOf(confirming.value) }) : '',
);
const confirmScope = computed(() =>
    confirming.value ? `(${confirming.value.entity} · ${confirming.value.key})` : '',
);
const keptLine = computed(() => {
    const f = confirming.value;
    if (!f) return '';
    const n =
        f.stored_values !== null && f.stored_values !== undefined
            ? tr(c.fields.kept_values, { n: f.stored_values.toLocaleString() })
            : c.fields.kept_values_unknown;
    return `${n} · ${tr(c.fields.kept_key, { key: f.key })} · ${c.fields.kept_reactivate}`;
});
const editorStamp = computed(() => {
    const f = editing.value;
    if (!f) return '';
    if (!f.id) return c.fields.new_stamp;
    const parts = [f.entity, f.key, f.type];
    const d = dateOf(f.created_at);
    if (d) parts.push(tr(c.fields.created_on, { date: d }));
    return parts.join(' · ');
});
const customerFields = computed(() => props.fields.filter((f) => f.entity === 'customer'));
onMounted(loadCounts);
</script>

<template>
    <WorkflowShell
        :copy="c"
        active="fields"
        :title="c.fields.title"
        :summary="summaryLine"
        :read-note="readonly ? c.fields.readnote : undefined"
    >
        <template #actions>
            <button
                v-if="canEdit && !readonly"
                type="button"
                data-testid="wf-field-new"
                class="bo-btn p"
                @click="startNew"
            >
                {{ c.fields.new }}
            </button>
        </template>

        <div class="fd-tabs bo-tabs" role="tablist" :aria-label="c.fields.entity">
            <button
                v-for="t in ['ticket', 'customer'] as const"
                :key="t"
                role="tab"
                type="button"
                :aria-selected="tab === t ? 'true' : 'false'"
                :data-testid="`wf-fields-tab-${t}`"
                @click="
                    tab = t;
                    editing = null;
                "
            >
                {{ c.fields['tab_' + t] }} <span class="bo-hint">{{ tabSub(t) }}</span>
            </button>
        </div>
        <WorkflowRefusal :text="refusal" />

        <div class="fd-panes" :class="{ ro: readonly }">
            <section class="bo-pane fd-list" role="tabpanel">
                <div class="fd-lh">
                    <span>{{ c.fields.col_field }}</span
                    ><span>{{ c.fields.type }}</span
                    ><span></span>
                </div>
                <p v-if="live.length === 0" class="bo-empty" data-testid="wf-fields-empty">
                    {{ c.fields.empty_tab }}
                </p>
                <ol class="fd-rows">
                    <li
                        v-for="(f, i) in live"
                        :key="f.id ?? ''"
                        :data-field-row="f.key"
                        class="fd-row"
                        :class="{ sel: editing && editing.id === f.id }"
                    >
                        <div class="pos">
                            <span v-if="canEdit && !readonly" class="grip" aria-hidden="true"
                                >⋮⋮</span
                            >
                            <span class="n">{{ f.position }}</span>
                            <span v-if="canEdit && !readonly" class="mv">
                                <button
                                    type="button"
                                    :data-testid="`wf-field-up-${f.key}`"
                                    :aria-label="c.common.move_up"
                                    :disabled="i === 0"
                                    @click="move(f, -1)"
                                >
                                    ↑
                                </button>
                                <button
                                    type="button"
                                    :data-testid="`wf-field-down-${f.key}`"
                                    :aria-label="c.common.move_down"
                                    :disabled="i === live.length - 1"
                                    @click="move(f, 1)"
                                >
                                    ↓
                                </button>
                            </span>
                        </div>
                        <div class="main">
                            <span class="lb">{{ f.label.en || f.key }}</span>
                            <span class="lb am" lang="am">{{ f.label.am || '—' }}</span>
                            <code class="bo-mono key">{{ f.key }}</code>
                            <span class="flags">
                                <span v-if="f.agent_editable" class="bo-chip">{{
                                    c.fields.flag_agents
                                }}</span>
                                <span v-if="f.customer_visible" class="bo-chip">{{
                                    c.fields.flag_customer
                                }}</span>
                                <span class="bo-hint" :data-testid="`wf-used-${f.key}`">{{
                                    usedLine(f)
                                }}</span>
                            </span>
                        </div>
                        <span class="type bo-tag" :title="c.fields.type_locked"
                            >🔒 {{ f.type }}</span
                        >
                        <span v-if="canEdit && !readonly" class="acts">
                            <button
                                type="button"
                                class="bo-btn sm"
                                :data-testid="`wf-field-edit-${f.key}`"
                                @click="open(f)"
                            >
                                {{ c.fields.edit }}
                            </button>
                            <button
                                type="button"
                                class="bo-btn sm"
                                :data-testid="`wf-field-deactivate-${f.key}`"
                                @click="
                                    confirming = f;
                                    loadWhereUsed(f);
                                "
                            >
                                {{ c.fields.deactivate_ellipsis }}
                            </button>
                        </span>
                    </li>
                </ol>
                <!-- J04 · retired -->
                <section v-if="retired.length" class="fd-retired" data-testid="wf-fields-retired">
                    <h4>
                        {{ c.fields.retired_section }}
                        <span class="bo-hint">— {{ c.fields.retired_sub }}</span>
                    </h4>
                    <ul>
                        <li
                            v-for="f in retired"
                            :key="f.id ?? ''"
                            :data-field-row="f.key"
                            class="fd-row off"
                        >
                            <span class="bo-tag">{{ c.fields.retired_word }}</span>
                            <div class="main">
                                <span class="lb">{{ f.label.en || f.key }}</span>
                                <span class="lb am" lang="am">{{ f.label.am || '—' }}</span>
                                <code class="bo-mono key">{{ f.key }}</code>
                                <span class="bo-chip">{{ c.fields.read_only }}</span>
                                <span class="bo-hint">{{ retiredLine(f) }}</span>
                            </div>
                            <span class="type bo-tag">🔒 {{ f.type }}</span>
                            <button
                                v-if="canEdit && !readonly"
                                type="button"
                                class="bo-btn sm"
                                :data-testid="`wf-field-reactivate-${f.key}`"
                                @click="reactivate(f)"
                            >
                                {{ c.fields.reactivate }}
                            </button>
                        </li>
                    </ul>
                </section>
                <!-- J06 · the Doc 08 seam -->
                <section
                    v-if="tab === 'customer'"
                    class="bo-card fd-seam"
                    data-testid="wf-doc08-seam"
                >
                    <h4>{{ c.fields.seam_title }}</h4>
                    <ul>
                        <li v-for="f in customerFields" :key="f.id ?? ''">
                            <span>{{ labelOf(f) }}</span>
                            <span class="bo-mono">—</span>
                            <span class="bo-chip">{{
                                f.active ? c.fields.staff_collected : c.fields.retired_word
                            }}</span>
                        </li>
                    </ul>
                    <p class="bo-hint">{{ c.fields.seam_body }}</p>
                </section>
            </section>

            <!-- J02 · the editor -->
            <section
                v-if="editing && !readonly"
                class="bo-pane fd-editor"
                data-testid="wf-field-editor"
            >
                <div class="fd-head">
                    <h2>
                        {{
                            editing.id
                                ? labelOf(editing)
                                : tr(c.fields.new_title, { entity: editing.entity })
                        }}
                    </h2>
                    <span class="bo-stamp">{{ editorStamp }}</span>
                </div>
                <p class="bo-hint bo-mono">fields.manage · {{ c.fields.high_risk }}</p>
                <div class="fd-grid2">
                    <div class="bo-fld">
                        <label for="fd-key"
                            >{{ c.fields.key_word }}
                            <span class="bo-hint"
                                >·
                                {{
                                    editing.id ? c.fields.immutable : c.fields.immutable_after
                                }}</span
                            ></label
                        >
                        <input
                            id="fd-key"
                            v-model="editing.key"
                            :readonly="!!editing.id"
                            data-testid="wf-field-key"
                            pattern="[a-z][a-z0-9_]*"
                            class="bo-in bo-mono"
                            @input="keyTouched = true"
                        />
                        <p class="bo-hint">
                            {{ editing.id ? c.fields.key_hint_saved : c.fields.key_hint_new }}
                        </p>
                        <div v-if="keyRefused" class="bo-refuse" data-testid="wf-key-refused">
                            <b>{{ retiredWithKey ? c.fields.key_retired : c.fields.key_taken }}</b>
                            <p>
                                {{
                                    retiredWithKey
                                        ? tr(c.fields.key_retired_body, {
                                              label: labelOf(retiredWithKey),
                                          })
                                        : c.fields.key_taken_body
                                }}
                            </p>
                            <div class="row">
                                <button
                                    v-if="retiredWithKey"
                                    type="button"
                                    class="bo-btn sm p"
                                    data-testid="wf-key-reactivate"
                                    @click="reactivate(retiredWithKey)"
                                >
                                    {{ c.fields.key_reactivate }}
                                </button>
                                <button type="button" class="bo-btn sm" @click="refusal = ''">
                                    {{ c.fields.key_pick_another }}
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="bo-fld">
                        <span class="lab"
                            >{{ c.fields.type }}
                            <span v-if="editing.id" class="bo-hint"
                                >· 🔒 {{ c.fields.type_locked }}</span
                            ></span
                        >
                        <template v-if="editing.id">
                            <p class="bo-in ro bo-mono" data-testid="wf-field-type-locked">
                                {{ editing.type }}
                            </p>
                            <p class="bo-hint" data-testid="wf-type-lock-law">
                                {{ c.fields.type_lock_law }}
                                {{ tr(c.fields.type_lock_how, { key: editing.key }) }}
                            </p>
                        </template>
                        <template v-else>
                            <select
                                v-model="editing.type"
                                data-testid="wf-field-type"
                                class="bo-in"
                            >
                                <option v-for="t in types" :key="t" :value="t">{{ t }}</option>
                            </select>
                            <p class="bo-hint">{{ c.fields.types_hint }}</p>
                        </template>
                    </div>
                    <div v-for="lang in LANGS" :key="lang" class="bo-fld">
                        <label :for="`fd-label-${lang}`"
                            >{{ c.fields.label }} · {{ c.common['language_' + lang] }}
                            <span class="bo-hint" aria-hidden="true">{{
                                lang === 'am' ? (amComplete ? '●' : '○') : '●'
                            }}</span></label
                        >
                        <input
                            :id="`fd-label-${lang}`"
                            v-model="editing.label[lang]"
                            :data-testid="`wf-field-label-${lang}`"
                            class="bo-in"
                            :lang="lang"
                        />
                    </div>
                </div>

                <!-- J03 · options -->
                <div v-if="isChoice" class="fd-sec">
                    <h4>
                        {{ c.fields.options }}
                        <span class="bo-hint">· {{ c.fields.options_sub }}</span>
                    </h4>
                    <table class="fd-opts" data-testid="wf-field-options">
                        <thead>
                            <tr>
                                <th>
                                    {{ c.fields.option_value }}
                                    <span class="bo-hint">· {{ c.fields.stable }}</span>
                                </th>
                                <th>{{ c.fields.option_label }} · en</th>
                                <th>{{ c.fields.option_label }} · am</th>
                                <th>
                                    <span class="sr-only">{{ c.fields.option_remove }}</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="(o, i) in editing.options"
                                :key="o.id ?? i"
                                :class="{ removed: o.active === false }"
                                :data-option="o.value"
                            >
                                <td>
                                    <input
                                        v-model="o.value"
                                        :readonly="!!o.id"
                                        class="bo-in bo-mono"
                                        :aria-label="c.fields.option_value"
                                    />
                                </td>
                                <td>
                                    <input
                                        v-model="o.label.en"
                                        :readonly="!!o.id"
                                        class="bo-in"
                                        :aria-label="`${c.fields.option_label} en`"
                                    />
                                </td>
                                <td>
                                    <input
                                        v-model="o.label.am"
                                        :readonly="!!o.id"
                                        class="bo-in"
                                        lang="am"
                                        :aria-label="`${c.fields.option_label} am`"
                                    />
                                </td>
                                <td>
                                    <span v-if="o.active === false" class="bo-hint">{{
                                        tr(c.fields.option_removed, { date: dateOf(o.updated_at) })
                                    }}</span>
                                    <button
                                        v-else
                                        type="button"
                                        class="bo-btn sm"
                                        :data-testid="`wf-option-remove-${o.value}`"
                                        @click="askRemove(o)"
                                    >
                                        {{ c.fields.option_remove }}
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <button
                        v-if="!editing.id"
                        type="button"
                        data-testid="wf-option-add"
                        class="bo-btn sm"
                        @click="editing.options.push({ value: '', label: { en: '', am: '' } })"
                    >
                        {{ c.fields.option_add }}
                    </button>
                    <p class="bo-hint">
                        {{ c.fields.option_stability_warning }} {{ c.fields.options_law }}
                    </p>
                </div>

                <!-- validation per type -->
                <div class="fd-sec">
                    <h4>
                        {{ c.fields.validation }}
                        <span class="bo-hint">· {{ c.fields.per_type }}</span>
                    </h4>
                    <div class="fd-grid2">
                        <div class="bo-fld">
                            <span class="lab">{{ c.fields.required_in }}</span>
                            <p class="bo-in ro">{{ placedIn || c.fields.required_none }}</p>
                            <p class="bo-hint">{{ c.fields.required_hint }}</p>
                        </div>
                        <div class="bo-fld">
                            <span class="lab">{{ c.fields.rule }}</span>
                            <template v-if="validationKind === 'pattern'">
                                <input
                                    class="bo-in bo-mono"
                                    data-testid="wf-validation-pattern"
                                    :value="vText('pattern')"
                                    :placeholder="c.fields.pattern_placeholder"
                                    :aria-label="c.fields.rule"
                                    @change="
                                        setValidation(
                                            'pattern',
                                            ($event.target as HTMLInputElement).value,
                                        )
                                    "
                                />
                            </template>
                            <template v-else-if="validationKind === 'range'">
                                <div class="row">
                                    <input
                                        class="bo-in num"
                                        type="number"
                                        :value="vText('min')"
                                        :placeholder="c.fields.min"
                                        :aria-label="c.fields.min"
                                        @change="
                                            setValidation(
                                                'min',
                                                ($event.target as HTMLInputElement).value,
                                            )
                                        "
                                    />
                                    <input
                                        class="bo-in num"
                                        type="number"
                                        :value="vText('max')"
                                        :placeholder="c.fields.max"
                                        :aria-label="c.fields.max"
                                        @change="
                                            setValidation(
                                                'max',
                                                ($event.target as HTMLInputElement).value,
                                            )
                                        "
                                    />
                                </div>
                            </template>
                            <template v-else-if="validationKind === 'length'">
                                <input
                                    class="bo-in num"
                                    type="number"
                                    :value="vText('max_length')"
                                    :placeholder="c.fields.max_length"
                                    :aria-label="c.fields.max_length"
                                    @change="
                                        setValidation(
                                            'max_length',
                                            ($event.target as HTMLInputElement).value,
                                        )
                                    "
                                />
                            </template>
                            <p v-else class="bo-in ro">
                                {{
                                    validationKind === 'options'
                                        ? c.fields.rule_options
                                        : c.fields.rule_none
                                }}
                            </p>
                        </div>
                    </div>
                </div>

                <div class="fd-sec fd-flags">
                    <label class="bo-check"
                        ><input
                            v-model="editing.agent_editable"
                            type="checkbox"
                            role="switch"
                            :aria-checked="editing.agent_editable ? 'true' : 'false'"
                            data-testid="wf-field-agent-editable"
                        />
                        <b>{{ c.fields.agent_editable }}</b></label
                    >
                    <p class="bo-hint">{{ c.fields.agent_editable_help }}</p>
                    <label class="bo-check"
                        ><input
                            v-model="editing.customer_visible"
                            type="checkbox"
                            role="switch"
                            :aria-checked="editing.customer_visible ? 'true' : 'false'"
                            data-testid="wf-field-customer-visible"
                        />
                        <b>{{ c.fields.customer_visible }}</b></label
                    >
                    <p class="bo-hint" data-testid="wf-n06">{{ c.fields.customer_visible_help }}</p>
                </div>

                <!-- J05 · where used, live and complete -->
                <div v-if="editing.id" class="fd-sec" data-testid="wf-where-used-panel">
                    <h4>
                        {{ c.fields.where_used_title }}
                        <span class="bo-hint">· {{ c.fields.where_used_live }}</span>
                    </h4>
                    <p v-if="!whereUsed" class="bo-hint">{{ c.fields.used_loading }}</p>
                    <template v-else>
                        <p
                            v-if="
                                !whereUsed.forms.length &&
                                !whereUsed.rules.length &&
                                !whereUsed.views.length
                            "
                            class="bo-hint"
                        >
                            {{ c.fields.where_used_none }}
                        </p>
                        <dl class="fd-used">
                            <template
                                v-for="kind in ['forms', 'rules', 'views'] as const"
                                :key="kind"
                            >
                                <dt v-if="whereUsed[kind].length">
                                    {{ c.fields['where_used_' + kind] }} ·
                                    {{ whereUsed[kind].length }}
                                </dt>
                                <dd v-for="x in whereUsed[kind]" :key="x.id">
                                    {{ x.name ?? x.id }}
                                </dd>
                            </template>
                        </dl>
                    </template>
                </div>

                <div class="fd-savebar">
                    <button
                        type="button"
                        data-testid="wf-field-save"
                        class="bo-btn p"
                        @click="save"
                    >
                        {{ editing.id ? c.fields.save_field : c.fields.create_field }}
                    </button>
                    <button type="button" class="bo-btn" @click="editing = null">
                        {{ c.common.cancel }}
                    </button>
                    <span class="bo-hint">{{ c.fields.audit_note }}</span>
                </div>
            </section>
            <section
                v-else-if="!readonly && !confirming"
                class="bo-pane fd-editor fd-pick"
                aria-live="polite"
            >
                <p class="bo-hint">{{ c.fields.pick_one }}</p>
            </section>
        </div>

        <!-- J03 · the EC-08 removal dialog -->
        <div v-if="removing" class="bo-scrim" @click.self="removing = null">
            <div
                class="bo-dlg"
                role="dialog"
                aria-modal="true"
                :aria-label="c.fields.option_remove"
                data-testid="wf-option-remove-dialog"
            >
                <h3>{{ removeTitle }}</h3>
                <p>{{ c.fields.option_stability_warning }}</p>
                <p>
                    <b>{{ removeRules }}</b> {{ c.fields.remove_rules_body }}
                    <b>{{ removeForms }}</b> {{ c.fields.remove_forms_body }}
                </p>
                <div class="bo-dlg-actions">
                    <button type="button" class="bo-btn" @click="removing = null">
                        {{ c.fields.keep_it }}
                    </button>
                    <button
                        type="button"
                        class="bo-btn danger"
                        data-testid="wf-option-remove-confirm"
                        @click="removeOption"
                    >
                        {{ c.fields.option_remove }}
                    </button>
                </div>
            </div>
        </div>

        <!-- J05 · the deactivate dialog with EC-14's consequences -->
        <div v-if="confirming" class="bo-scrim" @click.self="confirming = null">
            <div
                class="bo-dlg wide"
                role="dialog"
                aria-modal="true"
                :aria-label="c.fields.deactivate_verb"
                data-testid="wf-field-confirm"
            >
                <h3>{{ c.fields.deactivate_verb }}</h3>
                <p>
                    {{ confirmSentence }} <span class="bo-mono">{{ confirmScope }}</span>
                </p>
                <div class="fd-conseq" data-testid="wf-where-used">
                    <h4>{{ c.fields.consequences_title }}</h4>
                    <p v-if="!whereUsed" class="bo-hint">{{ c.fields.used_loading }}</p>
                    <template v-else>
                        <p
                            v-if="
                                !whereUsed.forms.length &&
                                !whereUsed.rules.length &&
                                !whereUsed.views.length
                            "
                            class="bo-hint"
                        >
                            {{ c.fields.where_used_none }}
                        </p>
                        <section v-if="whereUsed.forms.length">
                            <h5>{{ c.fields.where_used_forms }} · {{ whereUsed.forms.length }}</h5>
                            <div v-for="x in whereUsed.forms" :key="x.id" class="ent">
                                <b>{{ x.name }}</b
                                ><span>{{ c.fields.ec14_form }}</span>
                            </div>
                        </section>
                        <section v-if="whereUsed.rules.length">
                            <h5>{{ c.fields.where_used_rules }} · {{ whereUsed.rules.length }}</h5>
                            <div v-for="x in whereUsed.rules" :key="x.id" class="ent">
                                <b>{{ x.name }}</b
                                ><span>{{ c.fields.ec14_rule }}</span>
                            </div>
                        </section>
                        <section v-if="whereUsed.views.length">
                            <h5>{{ c.fields.where_used_views }} · {{ whereUsed.views.length }}</h5>
                            <div v-for="x in whereUsed.views" :key="x.id" class="ent">
                                <b>{{ x.name ?? x.id }}</b
                                ><span>{{ c.fields.ec14_view }}</span>
                            </div>
                        </section>
                    </template>
                    <dl class="fd-kv">
                        <dt>{{ c.fields.kept }}</dt>
                        <dd>{{ keptLine }}</dd>
                        <dt>{{ c.fields.audited }}</dt>
                        <dd class="bo-mono">fields.deactivated — {{ c.fields.high_risk_short }}</dd>
                    </dl>
                </div>
                <div class="bo-dlg-actions">
                    <button type="button" class="bo-btn" @click="confirming = null">
                        {{ c.common.cancel }}
                    </button>
                    <button
                        type="button"
                        class="bo-btn danger"
                        data-testid="wf-field-deactivate-confirm"
                        @click="deactivate"
                    >
                        {{ c.fields.deactivate_verb }}
                    </button>
                </div>
            </div>
        </div>
    </WorkflowShell>
</template>

<style scoped>
.fd-tabs {
    margin-block-end: 12px;
}
.fd-panes {
    display: grid;
    grid-template-columns: minmax(360px, 1fr) minmax(0, 1fr);
    gap: 16px;
    align-items: start;
}
.fd-panes.ro {
    grid-template-columns: minmax(0, 1fr);
}
.fd-lh {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 110px 150px;
    gap: 8px;
    padding: 8px 12px;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--ds-text-label);
    border-block-end: 1px solid var(--ds-border);
}
.fd-rows,
.fd-retired ul {
    list-style: none;
    margin: 0;
    padding: 0;
}
.fd-row {
    display: grid;
    grid-template-columns: 52px minmax(0, 1fr) 110px auto;
    gap: 8px;
    align-items: start;
    padding: 10px 12px;
    border-block-end: 1px solid var(--ds-border-soft);
    min-height: 44px;
}
.fd-row.sel {
    background: var(--ds-hover-bg);
    box-shadow: inset 3px 0 0 var(--ds-accent);
}
.fd-row.off {
    grid-template-columns: auto minmax(0, 1fr) 110px auto;
    color: var(--ds-text-muted);
}
.fd-row .pos {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 2px;
}
.fd-row .grip {
    color: var(--ds-text-muted);
    font-size: 11px;
    letter-spacing: -2px;
}
.fd-row .n {
    font-variant-numeric: tabular-nums;
    font-weight: 700;
    color: var(--ds-text-muted);
}
.fd-row .mv {
    display: flex;
    flex-direction: column;
}
.fd-row .mv button {
    min-width: 44px;
    min-height: 22px;
    border: 1px solid var(--ds-border);
    background: var(--ds-panel-bg);
    color: var(--ds-text);
    border-radius: 6px;
    font-size: 12px;
    cursor: pointer;
}
.fd-row .mv button:disabled {
    opacity: 0.4;
    cursor: default;
}
.fd-row .main {
    display: grid;
    gap: 2px;
    min-inline-size: 0;
}
.fd-row .lb {
    font-weight: 600;
}
.fd-row .lb.am {
    font-weight: 400;
    color: var(--ds-text-muted);
}
.fd-row .key {
    font-size: 11.5px;
    color: var(--ds-text-muted);
}
.fd-row .flags {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    align-items: center;
    font-size: 11.5px;
}
.fd-row .type {
    justify-self: start;
    white-space: nowrap;
}
.fd-row .acts {
    display: flex;
    gap: 6px;
}
.fd-retired {
    margin-block-start: 12px;
    padding-block-start: 8px;
    border-block-start: 1px solid var(--ds-border);
}
.fd-retired h4 {
    margin: 0;
    padding: 4px 12px;
    font-size: 12px;
}
.fd-seam {
    margin: 12px;
    padding: 12px 14px;
}
.fd-seam h4 {
    margin: 0 0 6px;
    font-size: 12px;
}
.fd-seam ul {
    list-style: none;
    margin: 0 0 6px;
    padding: 0;
    display: grid;
    gap: 4px;
    font-size: 12.5px;
}
.fd-seam li {
    display: flex;
    gap: 8px;
    align-items: center;
}
.fd-editor {
    padding: 16px 18px;
}
.fd-head h2 {
    margin: 0;
    font-size: 17px;
}
.fd-grid2 {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
    margin-block-start: 10px;
}
.bo-in.ro {
    background: var(--ds-inset-bg);
}
.bo-in.num {
    inline-size: 110px;
}
.row {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}
.fd-sec {
    margin-block-start: 16px;
}
.fd-sec h4 {
    margin: 0 0 6px;
    font-size: 13px;
}
.fd-opts {
    border-collapse: collapse;
    inline-size: 100%;
    font-size: 12.5px;
    margin-block-end: 6px;
}
.fd-opts th {
    text-align: start;
    font-weight: 600;
    color: var(--ds-text-label);
    padding: 4px 8px 4px 0;
}
.fd-opts td {
    padding: 3px 8px 3px 0;
}
.fd-opts tr.removed input {
    text-decoration: line-through;
}
.fd-flags {
    display: grid;
    gap: 4px;
}
.fd-flags .bo-hint {
    margin: 0 0 6px 26px;
}
.fd-used {
    margin: 0;
    display: grid;
    gap: 2px;
    font-size: 12.5px;
}
.fd-used dt {
    font-weight: 600;
    margin-block-start: 6px;
}
.fd-used dd {
    margin: 0 0 0 12px;
}
.fd-savebar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
    margin-block-start: 18px;
    padding-block-start: 12px;
    border-block-start: 1px solid var(--ds-border);
}
.fd-pick {
    display: grid;
    place-items: center;
    min-height: 200px;
}
.bo-btn.danger {
    background: var(--ds-danger);
    color: var(--ds-accent-contrast);
    border-color: transparent;
}
.bo-dlg.wide {
    max-width: 680px;
}
.fd-conseq {
    margin: 10px 0;
    padding: 10px 12px;
    border: 1px solid var(--ds-border);
    border-radius: 10px;
    background: var(--ds-inset-bg);
    display: grid;
    gap: 6px;
    max-height: 50vh;
    overflow: auto;
}
.fd-conseq h4 {
    margin: 0;
    font-size: 12.5px;
}
.fd-conseq h5 {
    margin: 6px 0 2px;
    font-size: 12px;
}
.fd-conseq .ent {
    display: grid;
    gap: 1px;
    padding: 4px 0 4px 10px;
    border-inline-start: 2px solid var(--ds-warn-border);
    font-size: 12px;
}
.fd-conseq .ent span {
    color: var(--ds-text-muted);
}
.fd-kv {
    margin: 6px 0 0;
    display: grid;
    grid-template-columns: auto 1fr;
    gap: 2px 10px;
    font-size: 12px;
}
.fd-kv dt {
    font-weight: 600;
}
.fd-kv dd {
    margin: 0;
}
@media (max-width: 1023px) {
    .fd-panes {
        grid-template-columns: minmax(0, 1fr);
    }
    .fd-lh {
        display: none;
    }
    .fd-row,
    .fd-row.off {
        grid-template-columns: 32px minmax(0, 1fr);
    }
    .fd-row .type,
    .fd-row .acts {
        grid-column: 2;
    }
}
</style>
