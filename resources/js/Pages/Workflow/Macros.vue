<script setup lang="ts">
// Doc 12 §8 macros manager (S12 step 14, redrawn to the frozen B9-S09 record in SESSION-F1 [B12]):
// the manager grouped by scope (personal before shared — the palette's order) with kind tags,
// bundled chips and usage; the editor with THE PILL BODY (brace-free pills — uneditable inner text,
// delete-as-unit, the resolution state announced, B12-S03's ruling), the variable palette, the
// bundled actions with EC-09's honesty, LIVE PREVIEW through PaletteReader::preview (the ONE
// resolver — T-06 parity) with the missing-variable warning, the B12-S03 consumer referenced.
// Insert-never-sends holds by construction: this page has no send door at all.
import { computed, nextTick, ref } from 'vue';
import WorkflowShell from '../../Components/Workflow/WorkflowShell.vue';
import WorkflowRefusal from '../../Components/Workflow/WorkflowRefusal.vue';
import { post, tr } from '../../Components/Workflow/workflowApi';

type Bundled = { type: string; value: unknown };
type Macro = {
    id: string | null;
    name: string;
    scope: string;
    kind: string;
    body: string;
    owner_id: string | null;
    team_id: string | null;
    variables: string[];
    bundled_actions: Bundled[];
    usage_count: number;
    updated_at?: string | null;
};
type Preview = { text: string; variables: Record<string, string | null>; unresolved: string[] };
const props = defineProps<{
    copy: Record<string, unknown>;
    can: Record<string, boolean>;
    macros: Macro[];
    palette: unknown[];
    sample_tickets: { id: string; number: number; subject: string | null }[];
    variables: string[];
    teams?: { id: string; name: string }[];
    user_id?: string | null;
}>();
// eslint-disable-next-line @typescript-eslint/no-explicit-any
const c = props.copy as any;
const TOKEN = /\{\{\s*([a-z_.][a-z0-9_.]*)\s*\}\}/gi;
const BUNDLED = ['set_status', 'set_priority', 'add_tags'];
const STATUSES = ['open', 'pending_customer', 'pending_internal', 'resolved'];
const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

const readonly = ref(window.innerWidth < 1024); // I07 — read posture on a phone
const editing = ref<Macro | null>(null);
const refusal = ref('');
const sampleTicket = ref(props.sample_tickets[0]?.id ?? '');
const preview = ref<Preview | null>(null);
const bodyEl = ref<HTMLDivElement | null>(null);
const search = ref('');
const sort = ref<'usage' | 'name'>('usage');
const canEdit = computed(() => props.can['macros.personal.manage'] === true);

// ── the manager ──
function matches(m: Macro): boolean {
    const q = search.value.trim().toLowerCase();
    return !q || m.name.toLowerCase().includes(q) || m.body.toLowerCase().includes(q);
}
function ordered(list: Macro[]): Macro[] {
    return [...list]
        .filter(matches)
        .sort((a, b) =>
            sort.value === 'usage'
                ? b.usage_count - a.usage_count || a.name.localeCompare(b.name)
                : a.name.localeCompare(b.name),
        );
}
const groups = computed(() => ({
    personal: ordered(props.macros.filter((m) => m.scope === 'personal')),
    team: ordered(props.macros.filter((m) => m.scope === 'team')),
    tenant: ordered(props.macros.filter((m) => m.scope === 'tenant')),
}));
const counts = computed(() => ({
    personal: props.macros.filter((m) => m.scope === 'personal').length,
    team: props.macros.filter((m) => m.scope === 'team').length,
    tenant: props.macros.filter((m) => m.scope === 'tenant').length,
}));
const summaryLine = computed(() =>
    props.macros.length === 0
        ? c.macros.summary_zero
        : tr(c.macros.summary_full, { total: props.macros.length, ...counts.value }),
);
const listSub = computed(() =>
    tr(c.macros.list_sub, { total: props.macros.length, ...counts.value }),
);
function teamName(id: string | null): string {
    return props.teams?.find((t) => t.id === id)?.name ?? '';
}
function groupTitle(scope: string): string {
    if (scope === 'personal') return c.macros.group_personal;
    if (scope === 'team') return c.macros.group_team;
    return c.macros.group_shared;
}
function groupKey(scope: string): string {
    return scope === 'personal' ? 'macros.personal.manage' : 'macros.shared.manage';
}
const actionWord = (type: string): string => c.rules.action_card?.[type] ?? type;
function valueWord(b: Bundled): string {
    if (b.type === 'set_status') return c.macros.status_words?.[String(b.value)] ?? String(b.value);
    if (b.type === 'set_priority') return String(b.value);
    return Array.isArray(b.value) ? b.value.join(', ') : String(b.value ?? '');
}
function chipText(b: Bundled): string {
    const v = valueWord(b);
    return v ? `⚙ ${actionWord(b.type)} · ${v}` : `⚙ ${actionWord(b.type)}`;
}
function kindWord(m: Macro): string {
    return c.macros['kind_' + m.kind] ?? m.kind;
}
function edited(m: Macro): string {
    if (!m.updated_at) return '';
    const d = new Date(m.updated_at.replace(' ', 'T'));
    if (Number.isNaN(d.getTime())) return '';
    const date = d.toLocaleDateString(undefined, { day: 'numeric', month: 'short' });
    return m.owner_id && m.owner_id === props.user_id
        ? tr(c.macros.edited_by_you, { date })
        : tr(c.macros.edited_on, { date });
}
function headStamp(m: Macro): string {
    const scope =
        m.scope === 'team'
            ? `${c.macros.scope_team} · ${teamName(m.team_id)}`
            : m.scope === 'personal'
              ? c.macros.scope_personal
              : c.macros.scope_tenant;
    const parts = [scope, kindWord(m), tr(c.macros.uses_n, { n: m.usage_count })];
    const e = edited(m);
    if (e) parts.push(e);
    return parts.join(' · ');
}

// ── the pill body: the DOM is the editor, the token string is the record ──
function pillNames(body: string): string[] {
    return Array.from(body.matchAll(TOKEN)).map((m) => m[1] as string);
}
const pills = computed(() => (editing.value ? [...new Set(pillNames(editing.value.body))] : []));
function pillState(name: string): 'unknown' | 'ok' | 'missing' {
    if (!preview.value || preview.value.variables[name] === undefined) return 'unknown';
    return preview.value.variables[name] === null ? 'missing' : 'ok';
}
function pillAria(name: string): string {
    const s = pillState(name);
    return tr(
        s === 'missing'
            ? c.macros.pill_aria_missing
            : s === 'ok'
              ? c.macros.pill_aria_ok
              : c.macros.pill_aria_unknown,
        { name },
    );
}
function makePill(name: string): HTMLSpanElement {
    const pill = document.createElement('span');
    pill.className = 'mc-pill';
    pill.setAttribute('contenteditable', 'false');
    pill.dataset.pill = name;
    pill.setAttribute('data-state', pillState(name));
    const text = document.createElement('span');
    text.className = 'mc-pill-text';
    text.setAttribute('aria-hidden', 'true');
    text.textContent = name;
    const said = document.createElement('span');
    said.className = 'sr-only mc-pill-said';
    said.textContent = pillAria(name);
    const x = document.createElement('button');
    x.type = 'button';
    x.className = 'mc-pill-x';
    x.setAttribute('aria-label', `${c.macros.remove_pill} ${name}`);
    x.textContent = '✕';
    x.addEventListener('click', () => {
        pill.remove();
        syncFromDom();
    });
    pill.append(text, said, x);
    return pill;
}
function renderBody(): void {
    const el = bodyEl.value;
    if (!el || !editing.value) return;
    el.replaceChildren();
    const body = editing.value.body;
    let last = 0;
    for (const m of body.matchAll(TOKEN)) {
        const idx = m.index ?? 0;
        if (idx > last) el.append(document.createTextNode(body.slice(last, idx)));
        el.append(makePill(m[1] as string));
        last = idx + m[0].length;
    }
    if (last < body.length) el.append(document.createTextNode(body.slice(last)));
}
function serialize(el: HTMLElement): string {
    let out = '';
    el.childNodes.forEach((n) => {
        if (n.nodeType === Node.TEXT_NODE) out += n.textContent ?? '';
        else if (n instanceof HTMLElement && n.dataset.pill) out += `{{${n.dataset.pill}}}`;
        else if (n instanceof HTMLElement) out += n.tagName === 'BR' ? '\n' : serialize(n);
    });
    return out;
}
function syncFromDom(): void {
    if (!bodyEl.value || !editing.value) return;
    editing.value.body = serialize(bodyEl.value);
}
function refreshPillStates(): void {
    bodyEl.value?.querySelectorAll<HTMLElement>('[data-pill]').forEach((p) => {
        const name = p.dataset.pill ?? '';
        p.setAttribute('data-state', pillState(name));
        const said = p.querySelector('.mc-pill-said');
        if (said) said.textContent = pillAria(name);
    });
}
function insertVariable(name: string): void {
    const el = bodyEl.value;
    if (!el || !editing.value) return;
    const pill = makePill(name);
    const sel = window.getSelection();
    const range =
        sel && sel.rangeCount > 0 && el.contains(sel.getRangeAt(0).startContainer)
            ? sel.getRangeAt(0)
            : null;
    if (range) {
        range.deleteContents();
        range.insertNode(pill);
        range.setStartAfter(pill);
        range.collapse(true);
        sel?.removeAllRanges();
        sel?.addRange(range);
    } else el.append(pill);
    syncFromDom();
}
function onBodyKey(e: KeyboardEvent): void {
    // Backspace / Delete beside a pill removes the whole pill, never one character (§9)
    if (e.key !== 'Backspace' && e.key !== 'Delete') return;
    const sel = window.getSelection();
    if (!sel || sel.rangeCount === 0 || !sel.isCollapsed) return;
    const r = sel.getRangeAt(0);
    const node = r.startContainer;
    let target: Element | null = null;
    if (node.nodeType === Node.TEXT_NODE) {
        const t = node as Text;
        if (e.key === 'Backspace' && r.startOffset === 0) target = t.previousElementSibling;
        if (e.key === 'Delete' && r.startOffset === t.length) target = t.nextElementSibling;
    } else if (node instanceof HTMLElement) {
        const kids = Array.from(node.childNodes);
        const k = e.key === 'Backspace' ? kids[r.startOffset - 1] : kids[r.startOffset];
        if (k instanceof HTMLElement) target = k;
    }
    if (target instanceof HTMLElement && target.dataset.pill) {
        e.preventDefault();
        target.remove();
        syncFromDom();
    }
}
function onPaste(e: ClipboardEvent): void {
    e.preventDefault();
    const text = e.clipboardData?.getData('text/plain') ?? '';
    document.execCommand('insertText', false, text.replace(TOKEN, '$1'));
    syncFromDom();
}

// ── editing ──
function blank(): Macro {
    return {
        id: null,
        name: '',
        scope: 'personal',
        kind: 'macro',
        body: '',
        owner_id: props.user_id ?? null,
        team_id: props.teams?.[0]?.id ?? null,
        variables: [],
        bundled_actions: [],
        usage_count: 0,
    };
}
async function open(m: Macro | null): Promise<void> {
    if (readonly.value) return;
    editing.value = m ? JSON.parse(JSON.stringify(m)) : blank();
    preview.value = null;
    refusal.value = '';
    await nextTick();
    renderBody();
}
function bundled(type: string): Bundled | undefined {
    return editing.value?.bundled_actions.find((b) => b.type === type);
}
function toggleBundled(type: string): void {
    if (!editing.value) return;
    const has = bundled(type) !== undefined;
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
function setBundledValue(type: string, raw: string): void {
    if (!editing.value) return;
    editing.value.bundled_actions = editing.value.bundled_actions.map((b) =>
        b.type === type
            ? {
                  type,
                  value:
                      type === 'add_tags'
                          ? raw
                                .split(',')
                                .map((s) => s.trim())
                                .filter(Boolean)
                          : raw,
              }
            : b,
    );
}
const bundledTags = computed(() => {
    const b = bundled('add_tags');
    return Array.isArray(b?.value) ? b.value.join(', ') : '';
});
async function save(): Promise<void> {
    if (!editing.value) return;
    syncFromDom();
    refusal.value = '';
    const payload = {
        name: editing.value.name,
        scope: editing.value.scope,
        kind: editing.value.kind,
        body: editing.value.body,
        team_id: editing.value.scope === 'team' ? editing.value.team_id : null,
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
    const result = await post<Preview>(`/admin/workflow/macros/${editing.value.id}/preview`, {
        ticket_id: sampleTicket.value,
    });
    if (!result.ok) {
        refusal.value = tr(c.common.refused, { detail: result.refusal });
        return;
    }
    preview.value = result.data;
    refreshPillStates();
}
// the preview text as segments: resolved values highlighted, missing ones as amber placeholders
const previewSegments = computed(() => {
    const p = preview.value;
    if (!p || !editing.value) return [];
    const out: { kind: 'text' | 'ok' | 'missing'; text: string }[] = [];
    const body = editing.value.body;
    let last = 0;
    for (const m of body.matchAll(TOKEN)) {
        const idx = m.index ?? 0;
        if (idx > last) out.push({ kind: 'text', text: body.slice(last, idx) });
        const name = m[1] as string;
        const v = p.variables[name];
        out.push(
            v === null || v === undefined
                ? { kind: 'missing', text: name }
                : { kind: 'ok', text: v },
        );
        last = idx + m[0].length;
    }
    if (last < body.length) out.push({ kind: 'text', text: body.slice(last) });
    return out;
});
const sampleLabel = (t: { number: number; subject: string | null }): string =>
    `#${t.number}${t.subject ? ` · ${t.subject}` : ''}`;
const unresolvedLine = computed(() =>
    preview.value?.unresolved.length
        ? tr(c.macros.preview_unresolved, { variables: preview.value.unresolved.join(', ') })
        : '',
);
const missingWhy = computed(() =>
    preview.value?.unresolved.length
        ? tr(c.macros.missing_why, { name: preview.value.unresolved[0] ?? '' })
        : '',
);
const ec09Line = computed(
    () =>
        `${tr(c.macros.ec09_skipped, { type: actionWord('set_priority') })} · ${tr(c.macros.ec09_failed, { type: actionWord('set_status') })}`,
);
const standardVars = computed(() => props.variables.filter((v) => !v.startsWith('custom.')));
const customVars = computed(() => props.variables.filter((v) => v.startsWith('custom.')));
</script>

<template>
    <WorkflowShell
        :copy="c"
        active="macros"
        :title="c.macros.title"
        :summary="summaryLine"
        :read-note="readonly ? c.macros.readnote : undefined"
    >
        <template #actions>
            <button
                v-if="canEdit && !readonly"
                type="button"
                data-testid="wf-macro-new"
                class="bo-btn p"
                @click="open(null)"
            >
                {{ c.macros.new }}
            </button>
        </template>

        <!-- I01 · empty -->
        <section
            v-if="macros.length === 0 && !editing"
            class="bo-empty mc-empty"
            data-testid="wf-macros-empty"
        >
            <h3>{{ c.macros.empty }}</h3>
            <p>{{ c.macros.empty_body }}</p>
            <button v-if="canEdit && !readonly" type="button" class="bo-btn p" @click="open(null)">
                {{ c.macros.new }}
            </button>
        </section>

        <div v-else class="mc-panes" :class="{ ro: readonly }">
            <!-- I01 · the manager -->
            <section class="bo-pane mc-list" :aria-label="c.macros.title">
                <div class="bo-pane-head">
                    <h3>{{ c.macros.title }}</h3>
                    <span class="bo-hint">{{ listSub }}</span>
                </div>
                <div class="mc-tools">
                    <input
                        v-model="search"
                        class="bo-in"
                        type="search"
                        data-testid="wf-macro-search"
                        :placeholder="c.macros.search"
                        :aria-label="c.macros.search"
                    />
                    <label class="bo-hint"
                        >{{ c.macros.sort }}
                        <select v-model="sort" class="bo-sel" data-testid="wf-macro-sort">
                            <option value="usage">{{ c.macros.sort_usage }}</option>
                            <option value="name">{{ c.macros.sort_name }}</option>
                        </select></label
                    >
                </div>
                <section
                    v-for="(list, scope) in groups"
                    :key="scope"
                    class="mc-group"
                    :data-scope="scope"
                >
                    <h4 v-if="list.length || counts[scope] > 0">
                        {{ groupTitle(scope) }}
                        <span class="bo-mono">{{ groupKey(scope) }}</span>
                    </h4>
                    <ul>
                        <li
                            v-for="m in list"
                            :key="m.id ?? ''"
                            :data-macro-row="m.id"
                            class="mc-row"
                            :class="{ sel: editing && editing.id === m.id }"
                        >
                            <button
                                type="button"
                                class="body"
                                :data-testid="`wf-macro-row-${m.id}`"
                                @click="open(m)"
                            >
                                <span class="nm">{{ m.name }}</span>
                                <span class="bo-tag">{{ kindWord(m) }}</span>
                                <span
                                    v-for="b in m.bundled_actions"
                                    :key="b.type"
                                    class="bo-chip"
                                    >{{ chipText(b) }}</span
                                >
                            </button>
                            <span class="use" :title="c.macros.uses_word"
                                >{{ m.usage_count.toLocaleString() }}
                                <small>{{ c.macros.uses_word }}</small></span
                            >
                            <button
                                v-if="canEdit && !readonly"
                                type="button"
                                class="sr-only"
                                :data-testid="`wf-macro-edit-${m.id}`"
                                @click="open(m)"
                            >
                                {{ c.common.save }}
                            </button>
                        </li>
                    </ul>
                </section>
                <p class="bo-hint mc-foot">{{ c.macros.order_foot }}</p>
            </section>

            <!-- I02…I06 · the editor -->
            <section
                v-if="editing && !readonly"
                class="bo-pane mc-editor"
                data-testid="wf-macro-editor"
            >
                <div class="mc-head">
                    <h2>{{ editing.name || c.macros.untitled }}</h2>
                    <span class="bo-stamp">{{
                        editing.id ? headStamp(editing) : c.macros.new_stamp
                    }}</span>
                </div>
                <div class="mc-cols">
                    <div class="mc-form">
                        <div class="bo-fld">
                            <label for="mc-name">{{ c.common.name }}</label>
                            <input
                                id="mc-name"
                                v-model="editing.name"
                                data-testid="wf-macro-name"
                                class="bo-in"
                            />
                        </div>
                        <div class="mc-two">
                            <fieldset class="bo-fld">
                                <legend class="lab">
                                    {{ c.macros.scope }}
                                    <span class="bo-hint">· {{ c.macros.scope_fixed }}</span>
                                </legend>
                                <div class="mc-seg" role="radiogroup" :aria-label="c.macros.scope">
                                    <button
                                        type="button"
                                        role="radio"
                                        :aria-checked="editing.scope === 'personal'"
                                        :disabled="!!editing.id"
                                        data-testid="wf-macro-scope-personal"
                                        @click="editing.scope = 'personal'"
                                    >
                                        {{ c.macros.scope_personal }}
                                    </button>
                                    <button
                                        type="button"
                                        role="radio"
                                        :aria-checked="editing.scope === 'team'"
                                        :disabled="!!editing.id"
                                        data-testid="wf-macro-scope-team"
                                        @click="editing.scope = 'team'"
                                    >
                                        {{ c.macros.scope_team }}
                                    </button>
                                    <button
                                        v-if="can['macros.shared.manage']"
                                        type="button"
                                        role="radio"
                                        :aria-checked="editing.scope === 'tenant'"
                                        :disabled="!!editing.id"
                                        data-testid="wf-macro-scope-tenant"
                                        @click="editing.scope = 'tenant'"
                                    >
                                        {{ c.macros.scope_tenant }}
                                    </button>
                                </div>
                                <select
                                    v-if="editing.scope === 'team' && !editing.id"
                                    v-model="editing.team_id"
                                    class="bo-in"
                                    data-testid="wf-macro-team"
                                    :aria-label="c.macros.scope_team"
                                >
                                    <option v-for="t in teams ?? []" :key="t.id" :value="t.id">
                                        {{ t.name }}
                                    </option>
                                </select>
                            </fieldset>
                            <fieldset class="bo-fld">
                                <legend class="lab">{{ c.macros.kind }}</legend>
                                <label class="bo-check"
                                    ><input v-model="editing.kind" type="radio" value="macro" />
                                    {{ c.macros.kind_macro }}</label
                                >
                                <label class="bo-check"
                                    ><input
                                        v-model="editing.kind"
                                        type="radio"
                                        value="saved_reply"
                                    />
                                    {{ c.macros.kind_saved_reply }}</label
                                >
                            </fieldset>
                        </div>

                        <!-- I02 · the pill body -->
                        <div class="bo-fld">
                            <span id="mc-body-label" class="lab"
                                >{{ c.macros.body }}
                                <span class="bo-hint">· {{ c.macros.body_sub }}</span></span
                            >
                            <div
                                ref="bodyEl"
                                class="bo-in mc-body"
                                contenteditable="true"
                                role="textbox"
                                aria-multiline="true"
                                aria-labelledby="mc-body-label"
                                data-testid="wf-macro-body"
                                @input="syncFromDom"
                                @keydown="onBodyKey"
                                @paste="onPaste"
                            ></div>
                        </div>
                        <div class="mc-vars">
                            <span class="lab">{{ c.macros.insert_variable }}:</span>
                            <button
                                v-for="v in standardVars"
                                :key="v"
                                type="button"
                                class="mc-var"
                                :data-testid="`wf-var-${v}`"
                                @click="insertVariable(v)"
                            >
                                {{ v }}
                            </button>
                            <template v-if="customVars.length">
                                <span class="lab">{{ c.macros.custom_fields }}:</span>
                                <button
                                    v-for="v in customVars"
                                    :key="v"
                                    type="button"
                                    class="mc-var cf"
                                    :data-testid="`wf-var-${v}`"
                                    @click="insertVariable(v)"
                                >
                                    {{ v }}
                                </button>
                            </template>
                        </div>
                        <p class="bo-hint" data-testid="wf-pill-law">{{ c.macros.pill_law }}</p>
                        <ul class="sr-only" :aria-label="c.macros.variables">
                            <li v-for="p in pills" :key="p">{{ pillAria(p) }}</li>
                        </ul>

                        <!-- I04 · bundled actions -->
                        <h4 class="mc-h">
                            {{ c.macros.bundled_title }}
                            <span class="bo-hint">· {{ c.macros.bundled_sub }}</span>
                        </h4>
                        <div class="mc-bundled" data-testid="wf-macro-bundled">
                            <div v-for="t in BUNDLED" :key="t" class="row">
                                <label class="bo-check"
                                    ><input
                                        type="checkbox"
                                        :checked="bundled(t) !== undefined"
                                        :data-testid="`wf-macro-bundle-${t}`"
                                        @change="toggleBundled(t)"
                                    />
                                    <b>{{ actionWord(t) }}</b></label
                                >
                                <template v-if="bundled(t)">
                                    <select
                                        v-if="t === 'set_status'"
                                        class="bo-in"
                                        :value="String(bundled(t)?.value)"
                                        :aria-label="actionWord(t)"
                                        @change="
                                            setBundledValue(
                                                t,
                                                ($event.target as HTMLSelectElement).value,
                                            )
                                        "
                                    >
                                        <option v-for="s in STATUSES" :key="s" :value="s">
                                            {{ c.macros.status_words[s] ?? s }}
                                        </option>
                                    </select>
                                    <select
                                        v-else-if="t === 'set_priority'"
                                        class="bo-in"
                                        :value="String(bundled(t)?.value)"
                                        :aria-label="actionWord(t)"
                                        @change="
                                            setBundledValue(
                                                t,
                                                ($event.target as HTMLSelectElement).value,
                                            )
                                        "
                                    >
                                        <option v-for="p in PRIORITIES" :key="p" :value="p">
                                            {{ p }}
                                        </option>
                                    </select>
                                    <input
                                        v-else
                                        class="bo-in"
                                        :value="bundledTags"
                                        :aria-label="actionWord(t)"
                                        :placeholder="c.macros.tags_placeholder"
                                        @change="
                                            setBundledValue(
                                                t,
                                                ($event.target as HTMLInputElement).value,
                                            )
                                        "
                                    />
                                </template>
                            </div>
                        </div>
                        <p class="bo-hint" data-testid="wf-ec09">
                            {{ c.macros.ec09_intro }} {{ ec09Line }}
                        </p>

                        <WorkflowRefusal :text="refusal" />
                        <div class="mc-savebar">
                            <button
                                type="button"
                                data-testid="wf-macro-save"
                                class="bo-btn p"
                                @click="save"
                            >
                                {{ c.macros.save_macro }}
                            </button>
                            <button type="button" class="bo-btn" @click="editing = null">
                                {{ c.common.cancel }}
                            </button>
                            <span class="bo-hint bo-mono"
                                >{{ groupKey(editing.scope) }} · {{ c.macros.audited }}</span
                            >
                        </div>
                    </div>

                    <!-- I03 · the preview, the ONE resolver -->
                    <aside class="mc-preview" :aria-label="c.macros.preview_title">
                        <h4>
                            {{ c.macros.preview_title }}
                            <span class="bo-hint">· {{ c.macros.one_resolver }}</span>
                        </h4>
                        <label class="bo-fld"
                            ><span class="lab">{{ c.macros.preview_ticket }}</span
                            ><select
                                v-model="sampleTicket"
                                data-testid="wf-macro-sample"
                                class="bo-in"
                            >
                                <option v-for="t in sample_tickets" :key="t.id" :value="t.id">
                                    {{ sampleLabel(t) }}
                                </option>
                            </select></label
                        >
                        <p>
                            <button
                                type="button"
                                :disabled="!editing.id"
                                data-testid="wf-macro-preview"
                                class="bo-btn sm"
                                @click="runPreview"
                            >
                                {{ c.macros.preview_run }}
                            </button>
                            <span v-if="!editing.id" class="bo-hint">{{
                                c.macros.preview_after_save
                            }}</span>
                        </p>
                        <div
                            v-if="preview"
                            class="mc-out"
                            :class="{ bad: preview.unresolved.length > 0 }"
                            data-testid="wf-macro-preview-out"
                        >
                            <p class="txt">
                                <template v-for="(s, i) in previewSegments" :key="i">
                                    <mark v-if="s.kind === 'ok'" class="ok">{{ s.text }}</mark>
                                    <mark
                                        v-else-if="s.kind === 'missing'"
                                        class="miss"
                                        :data-missing="s.text"
                                        >{{ s.text }}</mark
                                    >
                                    <template v-else>{{ s.text }}</template>
                                </template>
                            </p>
                            <ul class="vars">
                                <li v-for="p in pills" :key="p" :data-var-state="pillState(p)">
                                    <span class="bo-mono">{{ p }}</span>
                                    <span v-if="pillState(p) === 'ok'">✓</span>
                                    <span v-else class="miss">— {{ c.macros.missing_word }}</span>
                                </li>
                            </ul>
                            <p v-if="preview.unresolved.length" role="alert" class="warn">
                                <b>{{ missingWhy }}</b> {{ unresolvedLine }}
                                {{ c.macros.composer_note }}
                            </p>
                            <p v-else class="bo-hint">
                                {{ c.macros.preview_clean }} {{ c.macros.preview_is_send }}
                            </p>
                        </div>
                        <p class="bo-hint mc-nosend" data-testid="wf-no-send">
                            {{ c.macros.no_send }}
                        </p>

                        <!-- I06 · the consumer, referenced -->
                        <section class="bo-card mc-ref" data-testid="wf-palette-ref">
                            <h5>{{ c.macros.ref_title }}</h5>
                            <div class="sk">
                                <span class="q">⌕ {{ c.macros.ref_search }}</span>
                                <span class="grp">{{ c.macros.ref_yours }}</span>
                                <span class="row"
                                    ><b>{{ editing.name || c.macros.untitled }}</b
                                    ><span class="bo-tag">{{ kindWord(editing) }}</span
                                    ><span v-if="editing.bundled_actions.length" class="bo-chip"
                                        >⚙ {{ editing.bundled_actions.length }}</span
                                    ><span class="ins">↵ {{ c.macros.ref_insert }}</span></span
                                >
                            </div>
                            <p class="bo-hint">{{ c.macros.ref_body }}</p>
                        </section>
                    </aside>
                </div>
            </section>
            <section v-else-if="!readonly" class="bo-pane mc-editor mc-pick" aria-live="polite">
                <p class="bo-hint">{{ c.macros.pick_one }}</p>
            </section>
        </div>
    </WorkflowShell>
</template>

<style scoped>
.mc-panes {
    display: grid;
    grid-template-columns: minmax(300px, 400px) minmax(0, 1fr);
    gap: 16px;
    align-items: start;
}
.mc-panes.ro {
    grid-template-columns: minmax(0, 1fr);
}
.mc-tools {
    display: flex;
    gap: 8px;
    align-items: center;
    padding: 8px 12px;
    border-block-end: 1px solid var(--ds-border);
}
.mc-tools .bo-in {
    flex: 1;
    min-inline-size: 0;
}
.mc-group h4 {
    margin: 0;
    padding: 10px 12px 4px;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--ds-text-label);
    display: flex;
    justify-content: space-between;
    gap: 8px;
}
.mc-group h4 .bo-mono {
    text-transform: none;
    letter-spacing: 0;
    font-weight: 400;
}
.mc-group ul {
    list-style: none;
    margin: 0;
    padding: 0;
}
.mc-row {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 6px 12px;
    border-block-end: 1px solid var(--ds-border-soft);
}
.mc-row.sel {
    background: var(--ds-hover-bg);
    box-shadow: inset 3px 0 0 var(--ds-accent);
}
.mc-row .body {
    flex: 1;
    min-inline-size: 0;
    min-height: 44px;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 6px;
    background: none;
    border: 0;
    padding: 0;
    color: inherit;
    font: inherit;
    text-align: start;
    cursor: pointer;
}
.mc-row .nm {
    font-weight: 600;
}
.mc-row .use {
    font-variant-numeric: tabular-nums;
    font-size: 12px;
    white-space: nowrap;
}
.mc-row .use small {
    color: var(--ds-text-muted);
}
.mc-foot {
    padding: 10px 12px;
}
.mc-editor {
    padding: 16px 18px;
}
.mc-head h2 {
    margin: 0;
    font-size: 17px;
}
.mc-cols {
    display: grid;
    grid-template-columns: minmax(0, 3fr) minmax(280px, 2fr);
    gap: 18px;
    margin-block-start: 12px;
}
.mc-form {
    display: grid;
    gap: 10px;
}
.mc-two {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
}
.mc-two fieldset {
    border: 0;
    margin: 0;
    padding: 0;
    min-inline-size: 0;
    display: grid;
    gap: 4px;
}
.mc-seg {
    display: inline-flex;
    border: 1px solid var(--ds-border);
    border-radius: 8px;
    overflow: hidden;
}
.mc-seg button {
    min-height: 32px;
    padding: 0 10px;
    border: 0;
    background: var(--ds-panel-bg);
    color: var(--ds-text);
    font-size: 12px;
    cursor: pointer;
}
.mc-seg button[aria-checked='true'] {
    background: var(--ds-accent);
    color: var(--ds-accent-contrast);
}
.mc-seg button:disabled {
    cursor: default;
}
.mc-body {
    min-height: 120px;
    line-height: 1.7;
    white-space: pre-wrap;
    font-size: 13.5px;
}
.mc-body:focus {
    outline: 2px solid var(--ds-accent);
    outline-offset: 1px;
}
.mc-body :deep(.mc-pill),
.mc-var {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    height: 22px;
    padding: 0 4px 0 8px;
    border-radius: 999px;
    border: 1px solid var(--ds-border-strong);
    background: var(--ds-inset-bg);
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    font-size: 11.5px;
    vertical-align: middle;
    margin: 0 2px;
    user-select: none;
}
.mc-var {
    padding: 0 8px;
    cursor: pointer;
    color: var(--ds-text);
    min-height: 28px;
}
.mc-var.cf {
    border-style: dashed;
}
.mc-body :deep(.mc-pill[data-state='missing']) {
    background: var(--ds-warn-bg);
    border-color: var(--ds-warn-border);
    color: var(--ds-warn-fg);
}
.mc-body :deep(.mc-pill[data-state='ok']) {
    background: var(--ds-success-bg);
    border-color: var(--ds-success-border);
    color: var(--ds-success-fg);
}
.mc-body :deep(.mc-pill-x) {
    border: 0;
    background: none;
    color: inherit;
    font-size: 10px;
    padding: 0 3px;
    cursor: pointer;
    min-height: 20px;
}
.mc-vars {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    align-items: center;
}
.mc-h {
    margin: 8px 0 0;
    font-size: 13px;
}
.mc-bundled {
    display: grid;
    gap: 6px;
}
.mc-bundled .row {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}
.mc-bundled .bo-in {
    inline-size: auto;
    min-inline-size: 160px;
}
.mc-savebar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
    padding-block-start: 12px;
    border-block-start: 1px solid var(--ds-border);
}
.mc-preview {
    border-inline-start: 1px solid var(--ds-border);
    padding-inline-start: 18px;
    display: grid;
    gap: 8px;
    align-content: start;
}
.mc-preview h4 {
    margin: 0;
    font-size: 13px;
}
.mc-out {
    border: 1px solid var(--ds-border);
    border-radius: 10px;
    background: var(--ds-inset-bg);
    padding: 10px 12px;
    display: grid;
    gap: 8px;
}
.mc-out.bad {
    border-color: var(--ds-warn-border);
}
.mc-out .txt {
    margin: 0;
    white-space: pre-wrap;
    font-size: 13.5px;
    line-height: 1.6;
}
.mc-out mark {
    border-radius: 4px;
    padding: 0 3px;
    color: inherit;
}
.mc-out mark.ok {
    background: var(--ds-success-bg);
    color: var(--ds-success-fg);
}
.mc-out mark.miss {
    background: var(--ds-warn-bg);
    color: var(--ds-warn-fg);
    outline: 1px dashed var(--ds-warn-border);
}
.mc-out .vars {
    list-style: none;
    margin: 0;
    padding: 0;
    display: grid;
    gap: 2px;
    font-size: 12px;
}
.mc-out .vars li {
    display: flex;
    gap: 6px;
}
.mc-out .vars .miss,
.mc-out .warn {
    color: var(--ds-warn-fg);
}
.mc-out .warn {
    margin: 0;
    font-size: 12px;
}
.mc-nosend {
    margin: 0;
}
.mc-ref {
    padding: 10px 12px;
}
.mc-ref h5 {
    margin: 0 0 6px;
    font-size: 12px;
}
.mc-ref .sk {
    display: grid;
    gap: 4px;
    font-size: 12px;
    border: 1px dashed var(--ds-border-strong);
    border-radius: 8px;
    padding: 8px;
}
.mc-ref .q {
    color: var(--ds-text-muted);
}
.mc-ref .grp {
    font-size: 10.5px;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--ds-text-label);
}
.mc-ref .row {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 6px;
}
.mc-ref .ins {
    margin-inline-start: auto;
    color: var(--ds-text-muted);
}
.mc-pick {
    display: grid;
    place-items: center;
    min-height: 200px;
}
.mc-empty {
    display: grid;
    gap: 10px;
    justify-items: start;
    max-width: 640px;
}
@media (max-width: 1279px) {
    .mc-cols {
        grid-template-columns: minmax(0, 1fr);
    }
    .mc-preview {
        border-inline-start: 0;
        padding-inline-start: 0;
        border-block-start: 1px solid var(--ds-border);
        padding-block-start: 12px;
    }
}
@media (max-width: 1023px) {
    .mc-panes {
        grid-template-columns: minmax(0, 1fr);
    }
}
</style>
