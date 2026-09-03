<script setup lang="ts">
// Doc 12 §8 form builder (S12 step 14, redrawn to the frozen B9-S11 record in SESSION-F1 [B12]):
// the B5-S07 builder chrome — LEFT the 13 field types and the existing custom fields as REFs (a ref
// binds the ticket field; inline fields are form-only), CENTER the canvas with step tabs and
// completeness dots, field cards with the PLACEMENT MENU and ↑↓ (every drag has a menu, §13), the
// branch glyph and sentence; RIGHT the inspector (labels/help per language with dots, validation,
// required / required-when-visible with EC-07's help, the conditional builder that never offers a
// later field, the EC-08 note); the DUAL LIVE PREVIEW (the B3-S05 widget pre-chat render, Telegram
// direct with inline buttons, Telegram business with numbered text) drawn from the schema in the
// contract renderers' idiom; the publish dialog with the SchemaValidator's complete block list, the
// usage banner and EC-06; version history with diffs and never an edit affordance (BR-06); 375 READ.
import { computed, onMounted, ref } from 'vue';
import WorkflowShell from '../../Components/Workflow/WorkflowShell.vue';
import WorkflowRefusal from '../../Components/Workflow/WorkflowRefusal.vue';
import { post, tr } from '../../Components/Workflow/workflowApi';

type Option = { value: string; label: Record<string, string> };
type Field = {
    key: string;
    type?: string;
    ref?: string;
    required?: boolean;
    required_when_visible?: boolean;
    label?: Record<string, string>;
    help?: Record<string, string>;
    validation?: Record<string, unknown>;
    visible_when?: { field: string; op: string; value: unknown } | null;
    options?: Option[];
};
type Step = { key: string; title: Record<string, string>; fields: Field[] };
type Schema = { languages: string[]; steps: Step[]; completion: Record<string, unknown> };
type Block = {
    code: string;
    detail?: string;
    at?: string;
    field?: string;
    language?: string;
    channel?: string;
};
type Version = {
    id: string;
    version: number;
    schema: Schema;
    published_at: string | null;
    published_by_name?: string | null;
};
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
        entity_bindings?: Record<string, unknown>;
        seeded?: boolean;
    };
    versions: Version[];
    schema: Schema;
    field_types: string[];
    custom_fields: {
        id: string;
        entity: string;
        key: string;
        label: string | Record<string, string>;
        type: string;
        options?: Option[];
    }[];
    usage: Record<string, { id: string; name: string }[]>;
    departments?: { id: string; name: string }[];
    render_strings?: Record<string, Record<string, string>>;
}>();
// eslint-disable-next-line @typescript-eslint/no-explicit-any
const c = props.copy as any;
const DRAFT_KEY = `wf-form-draft:${props.form.id}`;
const readonly = ref(window.innerWidth < 1024); // K01 — READ posture below 1024
function normalise(sc: Schema): Schema {
    // the built page wrote `show_when`; the domain reads `visible_when` (ConditionEval, SchemaValidator)
    for (const st of sc.steps ?? [])
        for (const f of st.fields ?? []) {
            const legacy = (f as unknown as Record<string, unknown>).show_when;
            if (legacy !== undefined) {
                if (!f.visible_when && legacy) f.visible_when = legacy as Field['visible_when'];
                delete (f as unknown as Record<string, unknown>).show_when;
            }
        }
    return sc;
}
const schema = ref<Schema>(normalise(JSON.parse(JSON.stringify(props.schema))));
const step = ref(0);
const selected = ref<number | null>(null);
const refusal = ref('');
const previewLang = ref(schema.value.languages[0] ?? 'en');
const previewMode = ref<'widget' | 'telegram' | 'telegram_business'>('widget');
const preview = ref<{
    blocks: Block[];
    usage: Record<string, { id: string; name: string }[]>;
} | null>(null);
const publishing = ref(false);
const historyOpen = ref(true);
const jsonOpen = ref(false);
const menuFor = ref<number | null>(null);
const addMenu = ref(false);
const draftRestored = ref(false);
const draftSavedAt = ref('');

const current = computed(() => schema.value.steps[step.value]);
const field = computed(() =>
    selected.value !== null ? (current.value?.fields[selected.value] ?? null) : null,
);
const languages = computed(() => schema.value.languages);
const liveVersion = computed(
    () =>
        props.versions.find((v) => v.id === props.form.current_version_id) ??
        props.versions[0] ??
        null,
);
const nextVersion = computed(() => (props.versions[0]?.version ?? 0) + 1);
const dirty = computed(
    () =>
        JSON.stringify(schema.value) !== JSON.stringify(props.versions[0]?.schema ?? props.schema),
);
const langWord = (l: string): string => c.common['language_' + l] ?? l;
// the render chrome (step counter, Continue, the bot's words) follows the PREVIEW language, not the staff UI's
const R = (key: string): string =>
    props.render_strings?.[previewLang.value]?.[key] ?? c.forms[key] ?? key;
const typeWord = (t: string): string => c.forms.type_words?.[t] ?? t.replace('_', ' ');
function labelIn(obj: Record<string, string> | string | undefined, lang: string): string {
    if (typeof obj === 'string') return obj;
    return obj?.[lang] ?? obj?.en ?? '';
}
function refDef(f: Field): (typeof props.custom_fields)[number] | undefined {
    return f.ref ? props.custom_fields.find((x) => x.key === f.ref) : undefined;
}
function fieldLabel(f: Field, lang = previewLang.value): string {
    const own = labelIn(f.label, lang);
    if (own) return own;
    const ref = refDef(f);
    return ref ? labelIn(ref.label, lang) || f.ref || f.key : f.key;
}
function fieldType(f: Field): string {
    return f.type ?? refDef(f)?.type ?? 'text';
}
function fieldOptions(f: Field): Option[] {
    return f.options?.length ? f.options : (refDef(f)?.options ?? []);
}
function completeness(obj: Record<string, string> | undefined): number {
    return languages.value.filter((l) => (obj?.[l] ?? '').trim() !== '').length;
}
const dot = (n: number): string => (n === languages.value.length ? '●' : '○');
function fieldComplete(f: Field): number {
    if (f.ref && !f.label) return languages.value.length;
    return completeness(f.label);
}
function stepFieldCount(s: Step): string {
    return tr(c.forms.n_fields, { n: s.fields.length });
}

// ── the head line ──
const bindingsLine = computed(() => {
    const parts: string[] = [];
    const dept = props.departments?.find((d) => d.id === props.form.entity_bindings?.department_id);
    parts.push(dept ? tr(c.forms.creates_in, { department: dept.name }) : c.forms.creates_ticket);
    const completion = schema.value.completion ?? {};
    const tags = Array.isArray(completion.tags) ? completion.tags.join(', ') : '';
    const bits: string[] = [];
    if (tags) bits.push(tr(c.forms.completion_tag, { tags }));
    if (completion.route_tag) bits.push(c.forms.completion_route);
    if (bits.length) parts.push(tr(c.forms.completion_line, { actions: bits.join(', ') }));
    parts.push(tr(c.forms.languages_line, { list: languages.value.join(' + ') }));
    return parts.join(' · ');
});

// ── editing the canvas ──
function addStep(): void {
    schema.value.steps.push({
        key: `step_${schema.value.steps.length + 1}`,
        title: Object.fromEntries(languages.value.map((l) => [l, ''])),
        fields: [],
    });
    step.value = schema.value.steps.length - 1;
    selected.value = null;
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
                  label: Object.fromEntries(languages.value.map((l) => [l, ''])),
              },
    );
    selected.value = target.fields.length - 1;
    addMenu.value = false;
}
function place(index: number, delta: number): void {
    const list = current.value?.fields;
    if (!list) return;
    const j = index + delta;
    if (j < 0 || j >= list.length) return;
    [list[index], list[j]] = [list[j] as Field, list[index] as Field];
    selected.value = j;
    menuFor.value = null;
}
function moveToStep(index: number, target: number): void {
    const from = current.value;
    if (!from) return;
    if (target >= schema.value.steps.length) {
        addStep();
        target = schema.value.steps.length - 1;
        step.value = schema.value.steps.indexOf(from);
    }
    const [f] = from.fields.splice(index, 1);
    if (f) (schema.value.steps[target] as Step).fields.push(f);
    selected.value = null;
    menuFor.value = null;
}
function duplicateField(index: number): void {
    const list = current.value?.fields;
    const f = list?.[index];
    if (!list || !f) return;
    const copy: Field = JSON.parse(JSON.stringify(f));
    if (!copy.ref) copy.key = `${f.key}_copy`;
    list.splice(index + 1, 0, copy);
    selected.value = index + 1;
    menuFor.value = null;
}
function removeField(index: number): void {
    current.value?.fields.splice(index, 1);
    selected.value = null;
    menuFor.value = null;
}
function onCardKey(e: KeyboardEvent, index: number): void {
    if (!e.altKey) return;
    if (e.key === 'ArrowUp') {
        e.preventDefault();
        place(index, -1);
    } else if (e.key === 'ArrowDown') {
        e.preventDefault();
        place(index, 1);
    }
}

// ── the inspector ──
const earlierFields = computed(() => {
    const out: { key: string; label: string; options: Option[] }[] = [];
    schema.value.steps.forEach((s, si) => {
        s.fields.forEach((f, fi) => {
            const before =
                si < step.value ||
                (si === step.value && selected.value !== null && fi < selected.value);
            if (before) out.push({ key: f.key, label: fieldLabel(f), options: fieldOptions(f) });
        });
    });
    return out;
});
const condTarget = computed(
    () => earlierFields.value.find((x) => x.key === field.value?.visible_when?.field) ?? null,
);
const OPS = ['equals', 'not_equals', 'in', 'is_empty', 'not_empty'];
const opWord = (op: string): string => c.forms.op_words?.[op] ?? op;
function setCond(part: 'field' | 'op' | 'value', value: string): void {
    if (!field.value) return;
    const cur = field.value.visible_when ?? { field: '', op: 'equals', value: '' };
    field.value.visible_when = { ...cur, [part]: value };
}
function condValueLabel(): string {
    const v = String(field.value?.visible_when?.value ?? '');
    const opt = condTarget.value?.options.find((o) => o.value === v);
    return opt ? labelIn(opt.label, previewLang.value) || v : v;
}
const sentence = computed(() =>
    field.value?.visible_when?.field
        ? tr(c.forms.conditional_sentence, {
              field: condTarget.value?.label ?? field.value.visible_when.field,
              op: opWord(field.value.visible_when.op),
              value: ['is_empty', 'not_empty'].includes(field.value.visible_when.op)
                  ? ''
                  : condValueLabel(),
          }).trim()
        : '',
);
function sentenceFor(f: Field): string {
    if (!f.visible_when?.field) return '';
    const target = schema.value.steps
        .flatMap((s) => s.fields)
        .find((x) => x.key === f.visible_when?.field);
    const v = String(f.visible_when.value ?? '');
    const opt = target ? fieldOptions(target).find((o) => o.value === v) : undefined;
    return tr(c.forms.conditional_sentence, {
        field: target ? fieldLabel(target) : f.visible_when.field,
        op: opWord(f.visible_when.op),
        value: ['is_empty', 'not_empty'].includes(f.visible_when.op)
            ? ''
            : opt
              ? labelIn(opt.label, previewLang.value) || v
              : v,
    }).trim();
}
const condValueMissing = computed(() => {
    const t = condTarget.value;
    const v = String(field.value?.visible_when?.value ?? '');
    return !!t && t.options.length > 0 && v !== '' && !t.options.some((o) => o.value === v);
});
const inspectorHead = computed(() => {
    const f = field.value;
    if (!f) return '';
    return `${f.key} · ${f.ref ? c.forms.ref_word : c.forms.inline_word} · ${fieldType(f)}`;
});
function setHelp(l: string, value: string): void {
    if (!field.value) return;
    field.value.help = { ...(field.value.help ?? {}), [l]: value };
}
function setPattern(value: string): void {
    if (!field.value) return;
    const v = { ...(field.value.validation ?? {}) };
    if (value === '') delete v.pattern;
    else v.pattern = value;
    field.value.validation = v;
}

// ── the previews (the contract renderers' idiom, drawn from the schema) ──
const allFields = computed(() => schema.value.steps.flatMap((s) => s.fields));
const stepIntro = (s: Step): string =>
    labelIn(s.title, previewLang.value) ||
    tr(c.forms.step, { n: schema.value.steps.indexOf(s) + 1 });
function tgPrompt(f: Field, i: number): string {
    const help = labelIn(f.help, previewLang.value);
    const base = `🤖 ${i + 1}/${allFields.value.length} — ${fieldLabel(f)}?`;
    const opt = f.required ? '' : ` ${R('tg_optional')}`;
    return help ? `${base}${opt} ${help}` : `${base}${opt}`;
}
const tgSampleField = computed(
    () => allFields.value.find((f) => fieldType(f) === 'text' && f.validation?.pattern) ?? null,
);

// ── draft · publish · history ──
function saveDraft(): void {
    try {
        localStorage.setItem(DRAFT_KEY, JSON.stringify(schema.value));
        draftSavedAt.value = new Date().toTimeString().slice(0, 5);
    } catch {
        /* storage may be unavailable */
    }
}
function discardDraft(): void {
    try {
        localStorage.removeItem(DRAFT_KEY);
    } catch {
        /* storage may be unavailable */
    }
    schema.value = JSON.parse(JSON.stringify(props.schema));
    draftRestored.value = false;
    draftSavedAt.value = '';
    selected.value = null;
}
onMounted(() => {
    try {
        const raw = localStorage.getItem(DRAFT_KEY);
        if (raw) {
            schema.value = normalise(JSON.parse(raw) as Schema);
            draftRestored.value = true;
        }
    } catch {
        /* storage may be unavailable */
    }
});
async function refreshPreview(): Promise<void> {
    refusal.value = '';
    const result = await post<{
        blocks: Block[];
        usage: Record<string, { id: string; name: string }[]>;
    }>(`/admin/workflow/forms/${props.form.id}/preview`, { schema: schema.value });
    if (!result.ok) {
        refusal.value = tr(c.common.refused, { detail: result.refusal });
        return;
    }
    preview.value = result.data;
}
async function openPublish(): Promise<void> {
    publishing.value = true;
    preview.value = null;
    await refreshPreview();
}
async function publish(): Promise<void> {
    refusal.value = '';
    const result = await post<{ version: number }>(
        `/admin/workflow/forms/${props.form.id}/publish`,
        { schema: schema.value },
    );
    if (!result.ok) {
        refusal.value = tr(c.common.refused, { detail: result.refusal });
        await refreshPreview();
        return;
    }
    try {
        localStorage.removeItem(DRAFT_KEY);
    } catch {
        /* storage may be unavailable */
    }
    publishing.value = false;
    window.location.reload();
}
function blockText(b: Block): string {
    return tr(props.blocks[b.code] ?? b.code, {
        detail: b.detail ?? '',
        at: b.at ?? '',
        field: b.field ?? '',
        language: b.language ?? '',
        channel: b.channel ?? '',
    });
}
function jumpTo(b: Block): void {
    const key = b.field ?? '';
    schema.value.steps.forEach((s, si) => {
        const fi = s.fields.findIndex((f) => f.key === key);
        if (fi >= 0) {
            step.value = si;
            selected.value = fi;
        }
    });
    publishing.value = false;
}
function fixRequiredHidden(b: Block): void {
    const f = allFields.value.find((x) => x.key === b.field);
    if (!f) return;
    f.required = false;
    f.required_when_visible = true;
    refreshPreview();
}
const usageEntries = computed(() => {
    const u = preview.value?.usage ?? props.usage;
    return Object.entries(u).filter(([, list]) => list.length);
});
function diff(
    a: Schema | null,
    b: Schema,
): { added: string[]; removed: string[]; changed: string[] } {
    const keys = (s: Schema | null): Record<string, string> =>
        Object.fromEntries(
            (s?.steps ?? []).flatMap((st) => st.fields.map((f) => [f.key, JSON.stringify(f)])),
        );
    const ka = keys(a);
    const kb = keys(b);
    return {
        added: Object.keys(kb).filter((k) => !(k in ka)),
        removed: Object.keys(ka).filter((k) => !(k in kb)),
        changed: Object.keys(kb).filter((k) => k in ka && ka[k] !== kb[k]),
    };
}
function diffLines(a: Schema | null, b: Schema): string[] {
    const d = diff(a, b);
    const out: string[] = [];
    if (d.added.length) out.push(`+ ${tr(c.forms.diff_added, { fields: d.added.join(' · ') })}`);
    if (d.removed.length)
        out.push(`− ${tr(c.forms.diff_removed, { fields: d.removed.join(' · ') })}`);
    if (d.changed.length)
        out.push(`± ${tr(c.forms.diff_changed, { fields: d.changed.join(' · ') })}`);
    return out;
}
const draftDiff = computed(() => diffLines(props.versions[0]?.schema ?? null, schema.value));
const firstVersionLine = (v: Version): string =>
    `+ ${tr(c.forms.diff_added, { fields: v.schema.steps.flatMap((st) => st.fields.map((f) => f.key)).join(' · ') })}`;
function versionStamp(v: Version): string {
    const parts = [tr(c.forms.published_on, { date: fmtDate(v.published_at) })];
    if (v.published_by_name) parts.push(v.published_by_name);
    if (props.form.seeded && v.version === 1) parts.push(c.forms.seeded_tag);
    return parts.join(' · ');
}
function fmtDate(iso: string | null | undefined): string {
    if (!iso) return '';
    const d = new Date(iso.replace(' ', 'T'));
    return Number.isNaN(d.getTime())
        ? iso
        : d.toLocaleString(undefined, {
              day: 'numeric',
              month: 'short',
              hour: '2-digit',
              minute: '2-digit',
          });
}
const publishSentence = computed(() =>
    tr(c.forms.publish_confirm, { version: nextVersion.value, name: props.form.name }),
);
const usageLabel = (k: string): string => props.usage_labels[k] ?? k;
</script>

<template>
    <WorkflowShell
        :copy="c"
        active="forms"
        :title="c.forms.builder_title"
        :summary="form.name"
        :read-note="readonly ? c.hub.readonly_notice : undefined"
    >
        <template #actions>
            <template v-if="!readonly">
                <button
                    type="button"
                    class="bo-btn"
                    data-testid="wf-history-toggle"
                    :aria-expanded="historyOpen"
                    @click="historyOpen = !historyOpen"
                >
                    {{ c.forms.history }}
                </button>
                <button
                    type="button"
                    class="bo-btn"
                    :aria-expanded="jsonOpen"
                    @click="jsonOpen = !jsonOpen"
                >
                    {{ c.forms.schema_json }}
                </button>
                <button type="button" class="bo-btn" data-testid="wf-save-draft" @click="saveDraft">
                    {{ c.forms.save_draft }}
                </button>
                <button
                    v-if="can['forms.publish']"
                    type="button"
                    class="bo-btn p"
                    data-testid="wf-publish"
                    @click="openPublish"
                >
                    {{ c.forms.publish_ellipsis }}
                </button>
            </template>
        </template>

        <!-- the head line -->
        <div class="fb-head">
            <h2>{{ form.name }}</h2>
            <span v-if="liveVersion" class="bo-chip ok" data-testid="wf-form-live">{{
                tr(c.forms.v_published, { version: liveVersion.version })
            }}</span>
            <span v-else class="bo-chip">{{ c.forms.never_published }}</span>
            <span v-if="dirty || draftRestored" class="bo-chip warn" data-testid="wf-form-draft"
                >✎ {{ tr(c.forms.editing_draft, { version: nextVersion }) }}</span
            >
            <span v-if="draftSavedAt" class="bo-hint">{{
                tr(c.forms.draft_saved, { time: draftSavedAt })
            }}</span>
            <span class="bo-hint fb-bind" data-testid="wf-form-bindings">{{ bindingsLine }}</span>
        </div>
        <p v-if="draftRestored" class="bo-banner" role="status" data-testid="wf-draft-restored">
            {{ c.forms.draft_restored }}
            <button type="button" class="bo-link" @click="discardDraft">
                {{ c.forms.discard_draft }}
            </button>
        </p>
        <WorkflowRefusal :text="refusal" />

        <!-- K01 · 375 read posture -->
        <div v-if="readonly" class="fb-read" data-testid="wf-form-read">
            <p v-if="!schema.steps.length" class="bo-hint">{{ c.forms.no_steps }}</p>
            <section v-for="(s, si) in schema.steps" :key="s.key" class="bo-card">
                <h3>
                    {{ tr(c.forms.step, { n: si + 1 }) }} · {{ labelIn(s.title, previewLang) }}
                    <span aria-hidden="true">{{ dot(completeness(s.title)) }}</span>
                </h3>
                <ol>
                    <li v-for="f in s.fields" :key="f.key">
                        <b>{{ fieldLabel(f, 'en') }}</b>
                        <span lang="am">{{ fieldLabel(f, 'am') }}</span> · <code>{{ f.key }}</code>
                        <span class="bo-tag">{{ fieldType(f) }}</span>
                        <span v-if="f.visible_when" class="fb-branch">⑂ {{ sentenceFor(f) }}</span>
                    </li>
                </ol>
            </section>
        </div>

        <div v-else class="fb-grid" data-testid="wf-form-builder">
            <!-- K01 · palette -->
            <aside class="bo-pane fb-palette" :aria-label="c.forms.palette">
                <h3>
                    {{ c.forms.palette }} <span class="bo-hint">· {{ field_types.length }}</span>
                </h3>
                <div class="fb-types">
                    <button
                        v-for="t in field_types"
                        :key="t"
                        type="button"
                        class="fb-pal"
                        :data-testid="`wf-palette-${t}`"
                        @click="addField(t)"
                    >
                        <span>{{ typeWord(t) }}</span
                        ><code>{{ t }}</code>
                    </button>
                </div>
                <h3>
                    {{ c.forms.palette_existing }}
                    <span class="bo-hint">· {{ c.forms.refs_word }}</span>
                </h3>
                <div class="fb-types">
                    <button
                        v-for="f in custom_fields"
                        :key="f.id"
                        type="button"
                        class="fb-pal ref"
                        :data-testid="`wf-palette-ref-${f.key}`"
                        @click="addField(f.type, f.key)"
                    >
                        <code>{{ f.key }}</code
                        ><span>{{ labelIn(f.label, 'en') }}</span
                        ><small>{{ f.entity }} · {{ f.type }}</small>
                    </button>
                </div>
                <p class="bo-hint">{{ c.forms.palette_hint }}</p>
            </aside>

            <!-- K02 · canvas -->
            <section class="bo-pane fb-canvas" :aria-label="c.forms.canvas">
                <div class="fb-tabs">
                    <div class="fb-tablist" role="tablist">
                        <button
                            v-for="(s, i) in schema.steps"
                            :key="s.key"
                            role="tab"
                            type="button"
                            :aria-selected="step === i ? 'true' : 'false'"
                            :data-testid="`wf-step-${i}`"
                            @click="
                                step = i;
                                selected = null;
                            "
                        >
                            <span
                                >{{ tr(c.forms.step, { n: i + 1 }) }} ·
                                {{ labelIn(s.title, previewLang) || c.forms.untitled_step }}</span
                            >
                            <small
                                >{{ stepFieldCount(s) }}
                                <span aria-hidden="true">{{ dot(completeness(s.title)) }}</span
                                ><span class="sr-only">{{
                                    tr(c.forms.completeness, {
                                        done: completeness(s.title),
                                        total: languages.length,
                                    })
                                }}</span></small
                            >
                        </button>
                    </div>
                    <button
                        type="button"
                        class="bo-btn sm"
                        data-testid="wf-step-add"
                        @click="addStep"
                    >
                        + {{ c.forms.add_step }}
                    </button>
                </div>
                <div v-if="current" class="fb-step">
                    <div class="fb-titles">
                        <label v-for="l in languages" :key="l" class="bo-fld"
                            ><span class="lab"
                                >{{ tr(c.forms.step_title, { language: langWord(l) }) }}
                                <span aria-hidden="true">{{
                                    (current.title[l] ?? '').trim() ? '●' : '○'
                                }}</span></span
                            ><input
                                v-model="current.title[l]"
                                :data-testid="`wf-step-title-${l}`"
                                class="bo-in"
                                :lang="l"
                        /></label>
                    </div>
                    <ol class="fb-cards">
                        <li
                            v-for="(f, i) in current.fields"
                            :key="f.key"
                            :data-testid="`wf-canvas-field-${f.key}`"
                            class="fb-card"
                            :class="{ sel: selected === i }"
                            tabindex="0"
                            @focusin="selected = i"
                            @keydown="onCardKey($event, i)"
                        >
                            <span class="grip" aria-hidden="true">⋮⋮</span>
                            <button type="button" class="body" @click="selected = i">
                                <span class="lb"
                                    >{{ fieldLabel(f, 'en') }}
                                    <span v-if="f.required" class="bo-tag">{{
                                        c.forms.required_word
                                    }}</span
                                    ><span v-else-if="f.required_when_visible" class="bo-tag">{{
                                        c.forms.rwv_word
                                    }}</span></span
                                >
                                <span class="sub" lang="am"
                                    >{{ fieldLabel(f, 'am') || '○' }} ·
                                    <code>{{ f.key }}</code></span
                                >
                                <span class="meta"
                                    ><span class="bo-chip">{{
                                        f.ref
                                            ? tr(c.forms.ref_of, { key: f.ref })
                                            : c.forms.inline_form_only
                                    }}</span
                                    ><span class="bo-tag">{{ fieldType(f) }}</span></span
                                >
                                <span
                                    v-if="f.visible_when"
                                    class="fb-branch"
                                    :data-testid="`wf-branch-${f.key}`"
                                    >⑂ {{ sentenceFor(f) }}</span
                                >
                            </button>
                            <span class="mv">
                                <button
                                    type="button"
                                    :aria-label="c.common.move_up"
                                    @click="place(i, -1)"
                                >
                                    ↑
                                </button>
                                <button
                                    type="button"
                                    :aria-label="c.common.move_down"
                                    @click="place(i, 1)"
                                >
                                    ↓
                                </button>
                            </span>
                            <span class="menu">
                                <button
                                    type="button"
                                    class="bo-btn sm"
                                    :data-testid="`wf-place-${f.key}`"
                                    :aria-expanded="menuFor === i"
                                    aria-haspopup="menu"
                                    @click="menuFor = menuFor === i ? null : i"
                                >
                                    {{ c.forms.place_menu }}
                                </button>
                                <div
                                    v-if="menuFor === i"
                                    class="bo-menu"
                                    role="menu"
                                    :data-testid="`wf-place-menu-${f.key}`"
                                >
                                    <button type="button" role="menuitem" @click="place(i, -1)">
                                        {{ c.common.move_up }} <kbd>{{ c.forms.kbd_up }}</kbd>
                                    </button>
                                    <button type="button" role="menuitem" @click="place(i, 1)">
                                        {{ c.common.move_down }} <kbd>{{ c.forms.kbd_down }}</kbd>
                                    </button>
                                    <button
                                        v-for="(s2, j) in schema.steps"
                                        v-show="j !== step"
                                        :key="s2.key"
                                        type="button"
                                        role="menuitem"
                                        @click="moveToStep(i, j)"
                                    >
                                        {{
                                            tr(c.forms.move_to_step, {
                                                n: j + 1,
                                                title: labelIn(s2.title, previewLang),
                                            })
                                        }}
                                    </button>
                                    <button
                                        type="button"
                                        role="menuitem"
                                        @click="moveToStep(i, schema.steps.length)"
                                    >
                                        {{ c.forms.move_to_new_step }}
                                    </button>
                                    <button
                                        type="button"
                                        role="menuitem"
                                        @click="duplicateField(i)"
                                    >
                                        {{ c.forms.duplicate }}
                                    </button>
                                    <button
                                        type="button"
                                        role="menuitem"
                                        class="danger"
                                        @click="removeField(i)"
                                    >
                                        {{ c.forms.remove_from_form }}
                                    </button>
                                </div>
                            </span>
                        </li>
                    </ol>
                    <div class="fb-addrow">
                        <button
                            type="button"
                            class="bo-btn sm"
                            data-testid="wf-add-field-menu"
                            :aria-expanded="addMenu"
                            aria-haspopup="menu"
                            @click="addMenu = !addMenu"
                        >
                            + {{ c.forms.add_field_here }} ▾
                        </button>
                        <div v-if="addMenu" class="bo-menu" role="menu">
                            <button
                                v-for="t in field_types"
                                :key="t"
                                type="button"
                                role="menuitem"
                                @click="addField(t)"
                            >
                                {{ typeWord(t) }}
                            </button>
                        </div>
                    </div>
                    <p class="bo-hint">{{ c.forms.lookback_law }}</p>
                </div>
                <p v-else class="bo-empty">{{ c.forms.no_steps }}</p>
                <details v-if="jsonOpen" class="fb-json" open>
                    <summary>{{ c.forms.schema_json }}</summary>
                    <textarea
                        :value="JSON.stringify(schema, null, 2)"
                        rows="12"
                        class="bo-in bo-mono"
                        :aria-label="c.forms.schema_json"
                        @change="schema = JSON.parse(($event.target as HTMLTextAreaElement).value)"
                    ></textarea>
                </details>
            </section>

            <!-- K03 · inspector -->
            <aside class="bo-pane fb-inspector" :aria-label="c.forms.inspector">
                <h3>{{ c.forms.inspector }}</h3>
                <template v-if="field">
                    <p class="bo-mono fb-ihead" data-testid="wf-inspector-head">
                        {{ inspectorHead }}
                    </p>
                    <p class="bo-hint">
                        {{
                            tr(c.forms.completeness, {
                                done: fieldComplete(field),
                                total: languages.length,
                            })
                        }}
                    </p>
                    <template v-for="l in languages" :key="'l' + l">
                        <label class="bo-fld"
                            ><span class="lab"
                                >{{ tr(c.forms.label_per_language, { language: langWord(l) }) }}
                                <span aria-hidden="true">{{
                                    (field.label?.[l] ?? '').trim() ? '●' : '○'
                                }}</span></span
                            ><input
                                :value="field.label?.[l] ?? ''"
                                :data-testid="`wf-inspector-label-${l}`"
                                class="bo-in"
                                :lang="l"
                                @input="
                                    field.label = {
                                        ...(field.label ?? {}),
                                        [l]: ($event.target as HTMLInputElement).value,
                                    }
                                "
                        /></label>
                        <label class="bo-fld"
                            ><span class="lab">{{
                                tr(c.forms.help_per_language, { language: langWord(l) })
                            }}</span
                            ><input
                                :value="field.help?.[l] ?? ''"
                                class="bo-in"
                                :lang="l"
                                @input="setHelp(l, ($event.target as HTMLInputElement).value)"
                        /></label>
                    </template>
                    <label
                        v-if="['text', 'email', 'phone', 'url'].includes(fieldType(field))"
                        class="bo-fld"
                        ><span class="lab">{{ c.forms.validation }}</span
                        ><input
                            :value="String(field.validation?.pattern ?? '')"
                            class="bo-in bo-mono"
                            data-testid="wf-inspector-pattern"
                            :placeholder="c.forms.pattern_placeholder"
                            @change="setPattern(($event.target as HTMLInputElement).value)"
                        />
                        <span class="bo-hint">{{ c.forms.validation_hint }}</span></label
                    >
                    <label class="bo-check"
                        ><input
                            v-model="field.required"
                            type="checkbox"
                            data-testid="wf-inspector-required"
                        />
                        <b>{{ c.forms.required }}</b></label
                    >
                    <p class="bo-hint fb-ind">{{ c.forms.required_help }}</p>
                    <label class="bo-check"
                        ><input
                            v-model="field.required_when_visible"
                            type="checkbox"
                            data-testid="wf-inspector-required-when-visible"
                        />
                        <b>{{ c.forms.required_when_visible }}</b></label
                    >
                    <p class="bo-hint fb-ind">{{ c.forms.rwv_help }}</p>
                    <div class="fb-cond">
                        <span class="lab">{{ c.forms.conditional }}</span>
                        <div class="row">
                            <select
                                class="bo-in"
                                data-testid="wf-cond-field"
                                :value="field.visible_when?.field ?? ''"
                                :aria-label="c.forms.conditional"
                                @change="
                                    setCond('field', ($event.target as HTMLSelectElement).value)
                                "
                            >
                                <option value="">—</option>
                                <option v-for="e in earlierFields" :key="e.key" :value="e.key">
                                    {{ e.label }}
                                </option>
                            </select>
                            <select
                                class="bo-in"
                                data-testid="wf-cond-op"
                                :value="field.visible_when?.op ?? 'equals'"
                                :aria-label="c.rules.operator"
                                @change="setCond('op', ($event.target as HTMLSelectElement).value)"
                            >
                                <option v-for="op in OPS" :key="op" :value="op">
                                    {{ opWord(op) }}
                                </option>
                            </select>
                            <select
                                v-if="condTarget && condTarget.options.length"
                                class="bo-in"
                                data-testid="wf-cond-value"
                                :value="String(field.visible_when?.value ?? '')"
                                :aria-label="c.rules.value"
                                @change="
                                    setCond('value', ($event.target as HTMLSelectElement).value)
                                "
                            >
                                <option value="">—</option>
                                <option
                                    v-for="o in condTarget.options"
                                    :key="o.value"
                                    :value="o.value"
                                >
                                    {{ labelIn(o.label, previewLang) || o.value }}
                                </option>
                            </select>
                            <input
                                v-else
                                class="bo-in"
                                data-testid="wf-cond-value"
                                :value="String(field.visible_when?.value ?? '')"
                                :placeholder="c.rules.value"
                                :aria-label="c.rules.value"
                                @change="
                                    setCond('value', ($event.target as HTMLInputElement).value)
                                "
                            />
                            <button
                                type="button"
                                class="bo-btn sm"
                                :aria-label="c.rules.remove"
                                @click="field.visible_when = null"
                            >
                                ✕
                            </button>
                        </div>
                        <p
                            v-if="sentence"
                            class="fb-sentence"
                            data-testid="wf-conditional-sentence"
                        >
                            {{ sentence }}
                        </p>
                        <p class="bo-hint">{{ c.forms.cond_hint }}</p>
                        <p v-if="condValueMissing" class="bo-warnbox" data-testid="wf-cond-ec08">
                            {{
                                tr(c.forms.cond_removed_option, {
                                    value: String(field.visible_when?.value ?? ''),
                                })
                            }}
                        </p>
                    </div>
                    <div class="row">
                        <button
                            type="button"
                            class="bo-btn sm"
                            @click="selected !== null && duplicateField(selected)"
                        >
                            {{ c.forms.duplicate }}
                        </button>
                        <button
                            type="button"
                            class="bo-btn sm"
                            @click="selected !== null && removeField(selected)"
                        >
                            {{ c.forms.remove_from_form }}
                        </button>
                    </div>
                </template>
                <p v-else class="bo-hint">{{ c.forms.pick_field }}</p>
            </aside>

            <!-- K04 · the dual live preview -->
            <section class="bo-pane fb-preview" :aria-label="c.forms.preview_title">
                <div class="fb-ph">
                    <h3>{{ c.forms.preview_title }}</h3>
                    <div class="bo-tabs" role="tablist">
                        <button
                            type="button"
                            role="tab"
                            :aria-selected="previewMode === 'widget' ? 'true' : 'false'"
                            data-testid="wf-preview-widget"
                            @click="previewMode = 'widget'"
                        >
                            {{ c.forms.preview_widget }}
                        </button>
                        <button
                            type="button"
                            role="tab"
                            :aria-selected="previewMode === 'telegram' ? 'true' : 'false'"
                            data-testid="wf-preview-telegram"
                            @click="previewMode = 'telegram'"
                        >
                            {{ c.forms.preview_telegram_direct }}
                        </button>
                        <button
                            type="button"
                            role="tab"
                            :aria-selected="previewMode === 'telegram_business' ? 'true' : 'false'"
                            data-testid="wf-preview-telegram-business"
                            @click="previewMode = 'telegram_business'"
                        >
                            {{ c.forms.preview_telegram_business }}
                        </button>
                    </div>
                    <label class="bo-hint"
                        >{{ c.forms.preview_language }}
                        <select v-model="previewLang" class="bo-sel" data-testid="wf-preview-lang">
                            <option v-for="l in languages" :key="l" :value="l">{{ l }}</option>
                        </select></label
                    >
                    <button
                        type="button"
                        class="bo-btn sm"
                        data-testid="wf-preview-refresh"
                        @click="refreshPreview"
                    >
                        {{ c.forms.check_blocks }} ↻
                    </button>
                </div>
                <p class="bo-hint">{{ c.forms.preview_law }}</p>
                <div class="fb-frames" data-testid="wf-preview-pane">
                    <!-- widget · B3-S05 pre-chat -->
                    <div
                        v-if="previewMode === 'widget'"
                        class="fb-widget"
                        data-testid="wf-widget-render"
                    >
                        <div class="wh">
                            <b>{{ form.name }}</b
                            ><span>{{
                                tr(R('step_of'), { n: step + 1, total: schema.steps.length })
                            }}</span>
                        </div>
                        <p v-if="current" class="intro">{{ stepIntro(current) }}</p>
                        <div v-for="f in current?.fields ?? []" :key="f.key" class="wf">
                            <span class="l"
                                >{{ fieldLabel(f) }}<span v-if="f.required"> *</span></span
                            >
                            <div
                                v-if="fieldOptions(f).length && fieldType(f) !== 'dropdown'"
                                class="opts"
                            >
                                <span v-for="o in fieldOptions(f)" :key="o.value" class="opt">{{
                                    labelIn(o.label, previewLang) || o.value
                                }}</span>
                            </div>
                            <span v-else class="in">{{
                                fieldOptions(f).length
                                    ? labelIn(fieldOptions(f)[0]?.label, previewLang) || '—'
                                    : labelIn(f.help, previewLang) || ''
                            }}</span>
                        </div>
                        <span class="cta">{{ R('widget_continue') }}</span>
                    </div>
                    <!-- telegram · the device frame (Doc 05 §9) -->
                    <div
                        v-else
                        class="fb-tg"
                        :data-testid="
                            previewMode === 'telegram'
                                ? 'wf-telegram-direct'
                                : 'wf-telegram-business'
                        "
                    >
                        <div class="th">
                            <b>{{ R('tg_bot') }}</b
                            ><span class="bo-tag">{{
                                previewMode === 'telegram' ? R('tg_direct') : R('tg_business')
                            }}</span>
                        </div>
                        <template v-for="(f, i) in allFields" :key="f.key">
                            <p class="bot">{{ tgPrompt(f, i) }}</p>
                            <template v-if="fieldOptions(f).length">
                                <div v-if="previewMode === 'telegram'" class="ib">
                                    <span v-for="o in fieldOptions(f)" :key="o.value">{{
                                        labelIn(o.label, previewLang) || o.value
                                    }}</span>
                                </div>
                                <p v-else class="bot num">
                                    <span v-for="(o, k) in fieldOptions(f)" :key="o.value"
                                        >{{ k + 1 }}.
                                        {{ labelIn(o.label, previewLang) || o.value }}</span
                                    >
                                    <em>{{ R('tg_reply_number') }}</em>
                                </p>
                            </template>
                            <p v-if="tgSampleField && f.key === tgSampleField.key" class="user">
                                {{ c.forms.tg_sample_reply }}
                            </p>
                            <p
                                v-if="tgSampleField && f.key === tgSampleField.key"
                                class="bot retry"
                            >
                                {{ R('tg_retry') }} <small>{{ R('tg_attempt') }}</small>
                            </p>
                        </template>
                        <p class="bo-hint">
                            {{
                                previewMode === 'telegram'
                                    ? c.forms.tg_direct_note
                                    : c.forms.tg_business_note
                            }}
                        </p>
                    </div>
                </div>
                <div
                    v-if="preview && !publishing"
                    class="fb-blocks"
                    data-testid="wf-preview-blocks"
                >
                    <p v-if="preview.blocks.length" class="bo-warnbox">
                        {{ c.forms.publish_blocked }}
                    </p>
                    <p v-else class="bo-hint">✓ {{ c.forms.zero_blocks }}</p>
                    <ul>
                        <li v-for="(b, i) in preview.blocks" :key="i" :data-block="b.code">
                            <code>{{ b.code }}</code> — {{ blockText(b) }}
                        </li>
                    </ul>
                </div>
            </section>
        </div>

        <!-- K06 · version history -->
        <section
            v-if="historyOpen && !readonly"
            class="bo-pane fb-history"
            data-testid="wf-history"
            :aria-label="c.forms.history"
        >
            <h3>
                {{ c.forms.history }} · {{ form.name }}
                <span class="bo-hint">· {{ c.forms.history_sub }}</span>
            </h3>
            <ol>
                <li v-if="dirty" class="draft" data-version-row="draft">
                    <span class="v">{{ tr(c.forms.version, { version: nextVersion }) }}</span>
                    <span class="bo-chip warn">{{ c.forms.current_draft }}</span>
                    <span class="bo-hint">{{ c.forms.draft_line }}</span>
                    <span v-for="d in draftDiff" :key="d" class="dl">{{ d }}</span>
                </li>
                <li v-for="(v, i) in versions" :key="v.id" :data-version-row="v.version">
                    <span class="v">{{ tr(c.forms.version, { version: v.version }) }}</span>
                    <span v-if="v.id === form.current_version_id" class="bo-chip ok">{{
                        c.forms.live_word
                    }}</span>
                    <span class="bo-hint">{{ versionStamp(v) }}</span>
                    <span
                        v-for="d in diffLines(versions[i + 1]?.schema ?? null, v.schema)"
                        :key="d"
                        class="dl"
                        >{{ d }}</span
                    >
                    <span
                        v-if="i === versions.length - 1 && !diffLines(null, v.schema).length"
                        class="dl"
                        >{{ firstVersionLine(v) }}</span
                    >
                </li>
            </ol>
            <p class="bo-hint">{{ c.forms.history_law }}</p>
        </section>

        <!-- K05 · the publish dialog -->
        <div v-if="publishing" class="bo-scrim" @click.self="publishing = false">
            <div
                class="bo-dlg wide"
                role="dialog"
                aria-modal="true"
                :aria-label="c.forms.publish_verb"
                data-testid="wf-publish-dialog"
            >
                <template v-if="!preview">
                    <p class="bo-hint">{{ c.forms.checking }}</p>
                </template>
                <template v-else-if="preview.blocks.length">
                    <h3>{{ tr(c.forms.publish_blocked_n, { n: preview.blocks.length }) }}</h3>
                    <p>{{ c.forms.publish_blocked }}</p>
                    <ul class="fb-blist" data-testid="wf-publish-blocks">
                        <li v-for="(b, i) in preview.blocks" :key="i" :data-block="b.code">
                            <code>{{ b.code }}</code> — {{ blockText(b) }}
                            <span class="row">
                                <button
                                    v-if="b.field"
                                    type="button"
                                    class="bo-link"
                                    @click="jumpTo(b)"
                                >
                                    {{ b.at || b.field }}
                                </button>
                                <button
                                    v-if="b.code === 'required_hidden'"
                                    type="button"
                                    class="bo-btn sm"
                                    data-testid="wf-fix-required-hidden"
                                    @click="fixRequiredHidden(b)"
                                >
                                    {{ c.forms.fix_it }}
                                </button>
                            </span>
                        </li>
                    </ul>
                    <p class="bo-hint">{{ c.forms.blocks_law }}</p>
                </template>
                <template v-else>
                    <h3>{{ c.forms.publish_verb }}</h3>
                    <p>{{ publishSentence }}</p>
                    <dl class="fb-kv">
                        <dt>{{ c.forms.becomes }}</dt>
                        <dd>{{ c.forms.becomes_body }}</dd>
                        <dt>
                            {{ tr(c.forms.changed_since, { version: versions[0]?.version ?? 0 }) }}
                        </dt>
                        <dd>{{ draftDiff.length ? draftDiff.join(' · ') : c.forms.no_changes }}</dd>
                        <dt>✓ {{ c.forms.zero_blocks_short }}</dt>
                        <dd>{{ c.forms.zero_blocks }}</dd>
                        <dt>{{ c.forms.audited }}</dt>
                        <dd class="bo-mono">forms.published — {{ c.forms.high_risk }}</dd>
                    </dl>
                </template>
                <div v-if="preview" class="fb-usage" data-testid="wf-publish-usage">
                    <h4>{{ c.forms.usage_banner }}</h4>
                    <p v-if="!usageEntries.length" class="bo-hint">{{ c.forms.usage_none }}</p>
                    <div v-for="[k, list] in usageEntries" :key="k" class="ug">
                        <b>{{ usageLabel(k) }}</b>
                        <span v-for="x in list" :key="x.id">{{ x.name }}</span>
                    </div>
                    <p class="bo-hint">{{ c.forms.usage_law }}</p>
                </div>
                <div class="bo-dlg-actions">
                    <button type="button" class="bo-btn" @click="publishing = false">
                        {{
                            preview && preview.blocks.length
                                ? c.forms.back_to_builder
                                : c.common.cancel
                        }}
                    </button>
                    <button
                        v-if="preview && !preview.blocks.length"
                        type="button"
                        class="bo-btn p"
                        data-testid="wf-publish-confirm"
                        @click="publish"
                    >
                        {{ tr(c.forms.publish_v, { version: nextVersion }) }}
                    </button>
                </div>
            </div>
        </div>
    </WorkflowShell>
</template>

<style scoped>
.fb-head {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
    margin-block-end: 10px;
}
.fb-head h2 {
    margin: 0;
    font-size: 17px;
}
.fb-bind {
    flex-basis: 100%;
}
.fb-grid {
    display: grid;
    grid-template-columns: 200px minmax(0, 1fr) 300px;
    gap: 14px;
    align-items: start;
}
.fb-preview {
    grid-column: 1 / -1;
}
.fb-palette,
.fb-inspector,
.fb-canvas,
.fb-preview,
.fb-history {
    padding: 12px 14px;
}
.fb-palette h3,
.fb-inspector h3,
.fb-preview h3,
.fb-history h3 {
    margin: 0 0 6px;
    font-size: 13px;
}
.fb-palette h3 + .fb-types {
    margin-block-end: 12px;
}
.fb-types {
    display: grid;
    gap: 4px;
}
.fb-pal {
    display: grid;
    grid-template-columns: 1fr auto;
    gap: 6px;
    align-items: center;
    min-height: 32px;
    padding: 4px 8px;
    border: 1px solid var(--ds-border);
    border-radius: 8px;
    background: var(--ds-panel-bg);
    color: var(--ds-text);
    font: inherit;
    font-size: 12px;
    text-align: start;
    cursor: pointer;
}
.fb-pal code {
    font-size: 10.5px;
    color: var(--ds-text-muted);
}
.fb-pal.ref {
    grid-template-columns: 1fr;
    border-style: dashed;
}
.fb-pal.ref small {
    color: var(--ds-text-muted);
}
.fb-tablist {
    display: contents;
}
.fb-tabs {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    align-items: center;
    border-block-end: 1px solid var(--ds-border);
    padding-block-end: 8px;
}
.fb-tabs [role='tab'] {
    display: grid;
    min-height: 44px;
    padding: 4px 10px;
    border: 1px solid var(--ds-border);
    border-radius: 8px;
    background: var(--ds-panel-bg);
    color: var(--ds-text);
    font: inherit;
    font-size: 12.5px;
    font-weight: 600;
    text-align: start;
    cursor: pointer;
}
.fb-tabs [role='tab'][aria-selected='true'] {
    border-color: var(--ds-accent);
    box-shadow: inset 0 -3px 0 var(--ds-accent);
}
.fb-tabs small {
    font-weight: 400;
    color: var(--ds-text-muted);
}
.fb-step {
    margin-block-start: 10px;
}
.fb-titles {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 10px;
}
.fb-cards {
    list-style: none;
    margin: 10px 0 0;
    padding: 0;
    display: grid;
    gap: 6px;
}
.fb-card {
    display: grid;
    grid-template-columns: 16px minmax(0, 1fr) auto auto;
    gap: 8px;
    align-items: start;
    padding: 8px 10px;
    border: 1px solid var(--ds-border);
    border-radius: 10px;
    background: var(--ds-panel-bg);
    position: relative;
}
.fb-card.sel {
    border-color: var(--ds-accent);
    box-shadow: inset 3px 0 0 var(--ds-accent);
}
.fb-card:focus-visible {
    outline: 2px solid var(--ds-accent);
}
.fb-card .grip {
    color: var(--ds-text-muted);
    letter-spacing: -2px;
    cursor: grab;
}
.fb-card .body {
    display: grid;
    gap: 2px;
    background: none;
    border: 0;
    padding: 0;
    color: inherit;
    font: inherit;
    text-align: start;
    cursor: pointer;
    min-inline-size: 0;
}
.fb-card .lb {
    font-weight: 600;
}
.fb-card .sub {
    font-size: 11.5px;
    color: var(--ds-text-muted);
}
.fb-card .meta {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
}
.fb-branch {
    display: block;
    font-size: 11.5px;
    color: var(--ds-text-label);
}
.fb-card .mv {
    display: flex;
    flex-direction: column;
}
.fb-card .mv button {
    min-width: 32px;
    min-height: 22px;
    border: 1px solid var(--ds-border);
    background: var(--ds-panel-bg);
    color: var(--ds-text);
    border-radius: 6px;
    font-size: 12px;
    cursor: pointer;
}
.fb-card .menu {
    position: relative;
}
.bo-menu {
    position: absolute;
    inset-inline-end: 0;
    inset-block-start: 100%;
    z-index: 5;
    min-inline-size: 220px;
    display: grid;
    padding: 4px;
    border: 1px solid var(--ds-border-strong);
    border-radius: 10px;
    background: var(--ds-panel-bg);
    box-shadow: 0 8px 24px rgb(0 0 0 / 0.12);
}
.bo-menu button {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    min-height: 36px;
    padding: 0 10px;
    border: 0;
    background: none;
    color: var(--ds-text);
    font: inherit;
    font-size: 12.5px;
    text-align: start;
    cursor: pointer;
    border-radius: 6px;
}
.bo-menu button:hover,
.bo-menu button:focus-visible {
    background: var(--ds-hover-bg);
}
.bo-menu button.danger {
    color: var(--ds-danger-text);
}
.bo-menu kbd {
    font-size: 10.5px;
    color: var(--ds-text-muted);
}
.fb-addrow {
    position: relative;
    margin-block-start: 8px;
}
.fb-addrow .bo-menu {
    inset-inline-end: auto;
    inset-inline-start: 0;
}
.fb-json textarea {
    inline-size: 100%;
    margin-block-start: 6px;
}
.fb-ihead {
    margin: 0;
    font-size: 12px;
}
.fb-ind {
    margin: 0 0 6px 26px;
}
.fb-cond {
    display: grid;
    gap: 6px;
    margin-block-start: 8px;
}
.fb-cond .row,
.fb-inspector .row {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}
.fb-cond .bo-in {
    inline-size: auto;
    min-inline-size: 80px;
    flex: 1;
}
.fb-sentence {
    margin: 0;
    font-weight: 600;
}
.fb-ph {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 12px;
}
.fb-frames {
    display: grid;
    grid-template-columns: minmax(280px, 420px);
    margin-block-start: 8px;
}
.fb-widget {
    border: 1px solid var(--ds-border-strong);
    border-radius: 14px;
    padding: 14px;
    background: var(--ds-panel-bg);
    display: grid;
    gap: 8px;
    font-size: 13px;
}
.fb-widget .wh {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
}
.fb-widget .wh span {
    font-size: 11.5px;
    color: var(--ds-text-muted);
}
.fb-widget .intro {
    margin: 0;
    color: var(--ds-text-muted);
}
.fb-widget .wf {
    display: grid;
    gap: 3px;
}
.fb-widget .l {
    font-size: 12px;
    color: var(--ds-text-label);
}
.fb-widget .in {
    min-height: 34px;
    border: 1px solid var(--ds-border);
    border-radius: 8px;
    padding: 6px 10px;
    background: var(--ds-inset-bg);
    color: var(--ds-text-muted);
}
.fb-widget .opts {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}
.fb-widget .opt {
    border: 1px solid var(--ds-border);
    border-radius: 999px;
    padding: 4px 10px;
    background: var(--ds-inset-bg);
}
.fb-widget .cta {
    justify-self: end;
    min-height: 36px;
    padding: 8px 14px;
    border-radius: 10px;
    background: var(--ds-accent);
    color: var(--ds-accent-contrast);
    font-weight: 600;
}
.fb-tg {
    border: 6px solid var(--ds-border-strong);
    border-radius: 22px;
    padding: 12px;
    background: var(--ds-inset-bg);
    display: grid;
    gap: 6px;
    font-size: 12.5px;
}
.fb-tg .th {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding-block-end: 6px;
    border-block-end: 1px solid var(--ds-border);
}
.fb-tg .bot,
.fb-tg .user {
    margin: 0;
    max-inline-size: 92%;
    padding: 6px 10px;
    border-radius: 12px;
    background: var(--ds-panel-bg);
    border: 1px solid var(--ds-border);
}
.fb-tg .user {
    justify-self: end;
    background: var(--ds-accent);
    color: var(--ds-accent-contrast);
    border-color: transparent;
}
.fb-tg .bot.num {
    display: grid;
    gap: 2px;
}
.fb-tg .bot.num em {
    font-style: normal;
    color: var(--ds-text-muted);
}
.fb-tg .retry small {
    display: block;
    color: var(--ds-text-muted);
}
.fb-tg .ib {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}
.fb-tg .ib span {
    border: 1px solid var(--ds-accent);
    color: var(--ds-accent);
    border-radius: 8px;
    padding: 4px 10px;
    background: var(--ds-panel-bg);
}
.fb-blocks ul,
.fb-blist {
    list-style: none;
    margin: 6px 0 0;
    padding: 0;
    display: grid;
    gap: 6px;
    font-size: 12.5px;
}
.fb-blist li {
    padding: 6px 10px;
    border-inline-start: 3px solid var(--ds-danger);
    background: var(--ds-inset-bg);
    border-radius: 6px;
}
.fb-blist .row {
    display: flex;
    gap: 8px;
    align-items: center;
    margin-block-start: 4px;
}
.bo-dlg.wide {
    max-width: 680px;
}
.fb-kv {
    margin: 8px 0;
    display: grid;
    grid-template-columns: auto 1fr;
    gap: 4px 12px;
    font-size: 12.5px;
}
.fb-kv dt {
    font-weight: 600;
}
.fb-kv dd {
    margin: 0;
}
.fb-usage {
    margin-block-start: 8px;
    padding: 8px 10px;
    border: 1px solid var(--ds-border);
    border-radius: 8px;
    background: var(--ds-inset-bg);
    font-size: 12.5px;
}
.fb-usage h4 {
    margin: 0 0 4px;
    font-size: 12px;
}
.fb-usage .ug {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}
.fb-history {
    margin-block-start: 14px;
}
.fb-history ol {
    list-style: none;
    margin: 0;
    padding: 0;
    display: grid;
    gap: 6px;
}
.fb-history li {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    align-items: center;
    padding: 6px 0;
    border-block-end: 1px solid var(--ds-border-soft);
    font-size: 12.5px;
}
.fb-history .v {
    font-weight: 700;
}
.fb-history .dl {
    flex-basis: 100%;
    color: var(--ds-text-muted);
}
.fb-read {
    display: grid;
    gap: 10px;
}
.fb-read .bo-card {
    padding: 10px 12px;
}
.fb-read h3 {
    margin: 0 0 6px;
    font-size: 13px;
}
.fb-read ol {
    margin: 0;
    padding-inline-start: 18px;
    display: grid;
    gap: 4px;
    font-size: 12.5px;
}
@media (max-width: 1279px) {
    .fb-grid {
        grid-template-columns: 180px minmax(0, 1fr);
    }
    .fb-inspector {
        grid-column: 1 / -1;
    }
}
</style>
