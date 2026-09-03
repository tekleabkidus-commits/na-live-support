<script setup lang="ts">
// Doc 12 §8 SLA policies (S12 step 14, redrawn to the frozen B9-S07 record in SESSION-F1 [B12]):
// the two-pane page — the ordered list with match summaries, target lines and ↑↓ moves (first
// match wins, BR-01) beside the editor: the BR-03 prospective-only banner, the FR-08 match pickers,
// targets per priority × stage, the clock + calendar + warning %, THE LIVE EXAMPLE in en + am computed
// by the clock library on the server (AC-04) with the unreachable honesty line, the pause switches
// with plain captions, the EC-03 retarget DIAGRAM, the breach hook, the FR-11 chip strip. 375 is
// read-only. Every string is an i18n key; every colour a token.
import { computed, onMounted, ref, watch } from 'vue';
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
    breach_escalation: { path: string; reason: string } | null;
    updated_at?: string | null;
};
type Calendar = {
    id: string;
    name: string;
    timezone: string;
    is_tenant_default: boolean;
    department_id: string | null;
    weekdays?: Record<string, { open: string; close: string }[]>;
};
const props = defineProps<{
    copy: Record<string, unknown>;
    can: Record<string, boolean>;
    policies: Policy[];
    calendars: Calendar[];
    departments: { id: string; name: string }[];
    timezone: string;
}>();
// eslint-disable-next-line @typescript-eslint/no-explicit-any
const c = props.copy as any;
const PRIORITIES = ['low', 'normal', 'high', 'urgent'];
const STAGES = ['first_response_s', 'next_response_s', 'resolution_s'];
const PAUSES = ['pending_customer', 'pending_internal', 'channel_unreachable'];
const CHANNELS = ['telegram', 'webchat'];
const DAY_KEYS = ['1', '2', '3', '4', '5', '6', '7']; // ISO weekdays, Monday first

const readonly = ref(window.innerWidth < 1024); // G08 — phone-readable, edit on tablet+
const editing = ref<Policy | null>(null);
const selectedId = ref<string | null>(props.policies[0]?.id ?? null);
const refusal = ref('');
const example = ref<{ en: string; am: string; reachable: boolean } | null>(null);
const phoneExample = ref<{ en: string; am: string } | null>(null);
const deactivateOpen = ref(false);
const moving = ref(false);

const canEdit = computed(() => props.can['sla.policies.edit'] === true);
const total = computed(() => props.policies.length);
const selected = computed(() => props.policies.find((p) => p.id === selectedId.value) ?? null);

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
function departmentName(id: unknown): string {
    return props.departments.find((d) => d.id === id)?.name ?? String(id ?? '');
}
function list(v: unknown): string[] {
    return Array.isArray(v)
        ? v.map(String)
        : v === undefined || v === null || v === ''
          ? []
          : [String(v)];
}
// The FR-08 match summary — "department = Payments · priority = high / urgent"; empty = every ticket.
function summary(p: Policy): string {
    const m = p.match_conditions;
    const parts: string[] = [];
    if (m.department_id)
        parts.push(tr(c.sla.m_department, { value: departmentName(m.department_id) }));
    if (list(m.priority).length)
        parts.push(tr(c.sla.m_priority, { value: list(m.priority).join(' / ') }));
    if (list(m.channel).length)
        parts.push(tr(c.sla.m_channel, { value: list(m.channel).join(' / ') }));
    if (list(m.tags).length) parts.push(tr(c.sla.m_tags, { value: list(m.tags).join(', ') }));
    if (m.vip_source) parts.push(c.sla.m_vip);
    return parts.length ? parts.join(' · ') : c.sla.match_any;
}
function isCatchAll(p: Policy): boolean {
    return summary(p) === c.sla.match_any;
}
function minutes(p: Policy, prio: string, stage: string): number {
    return Math.round((p.targets[prio]?.[stage] ?? 0) / 60);
}
function fmtMin(n: number): string {
    return n.toLocaleString();
}
// The target line: one line when every priority shares the targets, else one per matched priority.
function targetLine(p: Policy): string {
    const prios = list(p.match_conditions.priority).filter((x) => PRIORITIES.includes(x));
    const rows = (prios.length ? prios : PRIORITIES).map(
        (prio) => `${STAGES.map((s) => fmtMin(minutes(p, prio, s))).join(' · ')}`,
    );
    const same = rows.every((r) => r === rows[0]);
    const body = same
        ? tr(c.sla.targets_all, { line: rows[0] ?? '' })
        : (prios.length ? prios : PRIORITIES)
              .map((prio, i) =>
                  `${rows[i]} ${i === 0 ? c.sla.minutes : ''} (${prio})`.replace('  ', ' '),
              )
              .join(' · ');
    return p.clock_mode === '24x7' ? `${body} · ${c.sla.clock_24x7}` : body;
}
function stateWord(p: Policy): string {
    return p.active ? `● ${c.common.active}` : `○ ${c.common.inactive}`;
}
function edited(p: Policy): string {
    if (!p.updated_at) return '';
    const d = new Date(p.updated_at.replace(' ', 'T'));
    return Number.isNaN(d.getTime())
        ? ''
        : tr(c.sla.edited_on, {
              date: d.toLocaleDateString(undefined, { day: 'numeric', month: 'short' }),
          });
}
function headStamp(p: Policy): string {
    const parts = [
        tr(c.sla.position_of, { n: p.position, total: total.value }),
        p.active ? c.common.active : c.common.inactive,
    ];
    const e = edited(p);
    if (e) parts.push(e);
    return parts.join(' · ');
}

// ── the calendar's hours line for the example title ("Mon–Fri 08:00–17:00 · Sat 09:00–13:00") ──
function dayName(i: number): string {
    return (c.sla.days as string[])[i % 7] ?? String(i);
}
function hoursSummary(k: Calendar | undefined): string {
    if (!k?.weekdays) return '';
    const sig: string[] = [''];
    for (const d of DAY_KEYS)
        sig.push((k.weekdays?.[d] ?? []).map((iv) => `${iv.open}–${iv.close}`).join(', '));
    const out: string[] = [];
    let i = 1;
    const order = [1, 2, 3, 4, 5, 6, 7];
    let idx = 0;
    while (idx < order.length) {
        i = order[idx] ?? 0;
        const s = sig[i] ?? '';
        if (!s) {
            idx++;
            continue;
        }
        let j = idx;
        while (j + 1 < order.length && sig[order[j + 1] ?? 0] === s) j++;
        const a = dayName(order[idx] ?? 0);
        const b = dayName(order[j] ?? 0);
        out.push(`${j > idx ? `${a}–${b}` : a} ${s}`);
        idx = j + 1;
    }
    return out.join(' · ');
}
const editingCalendar = computed(() =>
    editing.value ? props.calendars.find((k) => k.id === editing.value?.calendar_id) : undefined,
);
const exampleTitle = computed(() => {
    if (!editing.value) return '';
    if (editing.value.clock_mode !== 'calendar') return c.sla.example_24x7_title;
    const k = editingCalendar.value;
    if (!k) return c.sla.example_title;
    if (example.value && !example.value.reachable)
        return tr(c.sla.example_unreachable_title, { name: k.name });
    return tr(c.sla.example_calendar_title, {
        name: k.name,
        hours: hoursSummary(k),
        tz: k.timezone,
    });
});
const exampleFoot = computed(() => {
    if (!editing.value || !example.value) return '';
    if (editing.value.clock_mode !== 'calendar') return c.sla.example_wall_clock;
    if (!example.value.reachable) return c.sla.example_honesty;
    return tr(c.sla.example_business, { n: minutes(editing.value, 'high', 'first_response_s') });
});
async function fetchExample(
    p: Policy,
    locale: 'en' | 'am',
): Promise<{ sentence: string; reachable: boolean } | null> {
    const result = await post<{ sentence: string; reachable?: boolean }>(
        '/admin/workflow/sla/example',
        {
            calendar_id: p.clock_mode === 'calendar' ? p.calendar_id : null,
            target_seconds: p.targets.high?.first_response_s ?? 2400,
            created_at: '2026-09-01T17:00:00+03:00',
            locale,
        },
    );
    return result.ok
        ? { sentence: result.data.sentence, reachable: result.data.reachable !== false }
        : null;
}
async function refreshExample(): Promise<void> {
    if (!editing.value) return;
    const [en, am] = await Promise.all([
        fetchExample(editing.value, 'en'),
        fetchExample(editing.value, 'am'),
    ]);
    example.value = en
        ? { en: en.sentence, am: am?.sentence ?? '', reachable: en.reachable }
        : null;
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

// ── editing ──
function open(p: Policy): void {
    selectedId.value = p.id;
    if (readonly.value) return;
    editing.value = JSON.parse(JSON.stringify(p));
    refusal.value = '';
    refreshExample();
}
function startNew(): void {
    editing.value = blank();
    selectedId.value = null;
    refusal.value = '';
    refreshExample();
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
function setMatch(key: string, value: unknown): void {
    if (!editing.value) return;
    const m = { ...editing.value.match_conditions };
    const empty =
        value === '' ||
        value === null ||
        (Array.isArray(value) && value.length === 0) ||
        value === false;
    if (empty) delete m[key];
    else m[key] = value;
    editing.value = { ...editing.value, match_conditions: m };
}
function togglePriority(prio: string, on: boolean): void {
    const cur = list(editing.value?.match_conditions.priority);
    setMatch('priority', on ? [...new Set([...cur, prio])] : cur.filter((x) => x !== prio));
}
function toggleChannel(ch: string, on: boolean): void {
    const cur = list(editing.value?.match_conditions.channel);
    setMatch('channel', on ? [...new Set([...cur, ch])] : cur.filter((x) => x !== ch));
}
function setTags(raw: string): void {
    setMatch(
        'tags',
        raw
            .split(',')
            .map((s) => s.trim())
            .filter(Boolean),
    );
}
function setBreach(on: boolean): void {
    if (!editing.value) return;
    editing.value = {
        ...editing.value,
        breach_escalation: on
            ? {
                  path: 'ticket.escalate',
                  reason: editing.value.breach_escalation?.reason ?? c.sla.breach_reason_default,
              }
            : null,
    };
}
function setBreachReason(reason: string): void {
    if (!editing.value?.breach_escalation) return;
    editing.value = {
        ...editing.value,
        breach_escalation: { ...editing.value.breach_escalation, reason },
    };
}
const priorityOn = (prio: string): boolean =>
    list(editing.value?.match_conditions.priority).includes(prio);
const channelOn = (ch: string): boolean =>
    list(editing.value?.match_conditions.channel).includes(ch);
const tagsText = computed(() => list(editing.value?.match_conditions.tags).join(', '));
const vipOn = computed(() => editing.value?.match_conditions.vip_source === true);
const departmentValue = computed(() => String(editing.value?.match_conditions.department_id ?? ''));
const calendarNote = computed(() => {
    const overrides = props.calendars.filter((k) => k.department_id);
    return overrides.length
        ? tr(c.sla.calendar_overrides, { n: overrides.length })
        : c.sla.calendar_no_overrides;
});
const warnSentence = computed(() =>
    tr(c.sla.warn_sentence, { pct: editing.value?.warning_pct ?? 80 }),
);

// ── the EC-03 diagram, live from the targets (high → urgent, first response, half elapsed) ──
const diagram = computed(() => {
    const p = editing.value ?? selected.value;
    const before = p ? minutes(p, 'high', 'first_response_s') : 40;
    const after = p ? minutes(p, 'urgent', 'first_response_s') : 20;
    const elapsed = Math.round(before / 2);
    const remaining = before - elapsed;
    const pct = before > 0 ? Math.round((remaining / before) * 100) : 50;
    const rescaled = Math.round((after * pct) / 100);
    const scale = Math.max(before, after, 1);
    return { before, after, elapsed, remaining, pct, rescaled, scale };
});
const wPct = (n: number): string => `${Math.round((n / diagram.value.scale) * 100)}%`;

// ── save · deactivate · move ──
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
async function setActive(active: boolean): Promise<void> {
    if (!editing.value?.id) return;
    refusal.value = '';
    const result = await post(`/admin/workflow/sla/policies/${editing.value.id}`, { active });
    deactivateOpen.value = false;
    if (!result.ok) {
        refusal.value = tr(c.common.refused, { detail: result.refusal });
        return;
    }
    window.location.reload();
}
// ↑↓ = swap positions with the neighbour through the update door (audited twice, once per policy).
async function move(p: Policy, dir: -1 | 1): Promise<void> {
    const idx = props.policies.findIndex((x) => x.id === p.id);
    const other = props.policies[idx + dir];
    if (!p.id || !other?.id || moving.value) return;
    moving.value = true;
    refusal.value = '';
    const a = await post(`/admin/workflow/sla/policies/${p.id}`, { position: other.position });
    const b = a.ok
        ? await post(`/admin/workflow/sla/policies/${other.id}`, { position: p.position })
        : a;
    moving.value = false;
    const failed = !a.ok ? a : !b.ok ? b : null;
    if (failed) {
        refusal.value = tr(c.common.refused, { detail: failed.refusal });
        return;
    }
    try {
        sessionStorage.setItem('wf-sla-select', p.id);
    } catch {
        /* storage may be unavailable */
    }
    window.location.reload();
}

onMounted(async () => {
    try {
        const keep = sessionStorage.getItem('wf-sla-select');
        if (keep) {
            sessionStorage.removeItem('wf-sla-select');
            const p = props.policies.find((x) => x.id === keep);
            if (p) open(p);
        }
    } catch {
        /* storage may be unavailable */
    }
    if (readonly.value && canEdit.value && props.policies[0]) {
        const first = props.policies[0];
        const [en, am] = await Promise.all([fetchExample(first, 'en'), fetchExample(first, 'am')]);
        if (en) phoneExample.value = { en: en.sentence, am: am?.sentence ?? '' };
    }
});
const summaryLine = computed(() =>
    total.value === 0 ? c.sla.summary_zero : tr(c.sla.summary, { count: total.value }),
);
</script>

<template>
    <WorkflowShell
        :copy="c"
        active="sla"
        :title="c.sla.title"
        :summary="summaryLine"
        :read-note="readonly ? c.sla.readnote : undefined"
    >
        <template #actions>
            <a class="bo-btn" href="/admin/workflow/hours">{{ c.nav.hours }}</a>
            <button
                v-if="canEdit && !readonly"
                type="button"
                data-testid="wf-sla-new"
                class="bo-btn p"
                @click="startNew"
            >
                {{ c.sla.new }}
            </button>
        </template>

        <!-- G01 · empty -->
        <section
            v-if="policies.length === 0 && !editing"
            class="bo-empty sla-empty"
            data-testid="wf-sla-empty"
        >
            <h3>{{ c.sla.empty }}</h3>
            <p>{{ c.sla.empty_consequence }}</p>
            <button v-if="canEdit && !readonly" type="button" class="bo-btn p" @click="startNew">
                {{ c.sla.new }}
            </button>
            <div class="bo-card sla-what">
                <h4>{{ c.sla.what_title }}</h4>
                <p>{{ c.sla.what_body }}</p>
                <p class="bo-hint">{{ c.sla.what_foot }}</p>
            </div>
        </section>

        <div v-else class="sla-panes" :class="{ ro: readonly }">
            <!-- G01 · the ordered list -->
            <section class="bo-pane sla-list" :aria-label="c.sla.list_title">
                <div class="bo-pane-head">
                    <h3>{{ c.sla.list_title }}</h3>
                    <span class="bo-hint">{{ tr(c.sla.list_sub, { count: total }) }}</span>
                </div>
                <ol class="sla-rows">
                    <li
                        v-for="(p, i) in policies"
                        :key="p.id ?? ''"
                        :data-policy-row="p.id"
                        class="sla-row"
                        :class="{ sel: p.id === selectedId, off: !p.active }"
                    >
                        <div class="pos">
                            <span class="n">{{ p.position }}</span>
                            <span v-if="canEdit && !readonly && p.id === selectedId" class="mv">
                                <button
                                    type="button"
                                    :data-testid="`wf-sla-up-${p.id}`"
                                    :aria-label="c.sla.move_up"
                                    :disabled="i === 0 || moving"
                                    @click="move(p, -1)"
                                >
                                    ↑
                                </button>
                                <button
                                    type="button"
                                    :data-testid="`wf-sla-down-${p.id}`"
                                    :aria-label="c.sla.move_down"
                                    :disabled="i === policies.length - 1 || moving"
                                    @click="move(p, 1)"
                                >
                                    ↓
                                </button>
                            </span>
                        </div>
                        <button
                            type="button"
                            class="body"
                            :data-testid="`wf-sla-row-${p.id}`"
                            :aria-current="p.id === selectedId ? 'true' : undefined"
                            @click="open(p)"
                        >
                            <span class="name">{{ p.name }}</span>
                            <span class="match"
                                >{{ tr(c.sla.match_summary, { summary: summary(p) }) }}
                                <em v-if="isCatchAll(p) && i === policies.length - 1">{{
                                    c.sla.catch_all
                                }}</em></span
                            >
                            <span class="tl">{{ targetLine(p) }}</span>
                        </button>
                        <span class="st">{{ stateWord(p) }}</span>
                        <button
                            v-if="canEdit && !readonly"
                            type="button"
                            class="sr-only"
                            :data-testid="`wf-sla-edit-${p.id}`"
                            @click="open(p)"
                        >
                            {{ c.common.save }}
                        </button>
                    </li>
                </ol>
                <p class="bo-hint sla-foot">{{ c.sla.list_foot }}</p>
                <!-- G08 · the phone's live example for policy 1 -->
                <div
                    v-if="readonly && phoneExample"
                    class="bo-card sla-phone-ex"
                    data-testid="wf-sla-phone-example"
                >
                    <h4>{{ tr(c.sla.phone_example_title, { n: policies[0]?.position ?? 1 }) }}</h4>
                    <p class="ex-en">{{ phoneExample.en }}</p>
                    <p class="ex-am" lang="am">{{ phoneExample.am }}</p>
                </div>
            </section>

            <!-- G02…G06 · the editor -->
            <section
                v-if="editing && !readonly"
                class="bo-pane sla-editor"
                data-testid="wf-sla-editor"
            >
                <div class="sla-head">
                    <h2>{{ editing.name || c.sla.untitled }}</h2>
                    <span class="bo-stamp">{{
                        editing.id
                            ? headStamp(editing)
                            : tr(c.sla.position_of, { n: editing.position, total: total + 1 })
                    }}</span>
                </div>
                <p role="status" class="bo-banner sla-br03" data-testid="wf-sla-prospective">
                    <b>{{ c.sla.prospective }}</b> {{ c.sla.snapshot_notice }}
                    {{ c.sla.snapshot_contract }}
                </p>

                <div class="sla-grid2">
                    <div class="bo-fld">
                        <label for="sla-name">{{ c.common.name }}</label>
                        <input
                            id="sla-name"
                            v-model="editing.name"
                            data-testid="wf-sla-name"
                            class="bo-in"
                        />
                    </div>
                    <div class="bo-fld">
                        <span class="lab">{{ c.sla.position }}</span>
                        <p class="bo-in ro">
                            {{
                                tr(c.sla.position_short, {
                                    n: editing.position,
                                    total: editing.id ? total : total + 1,
                                })
                            }}
                        </p>
                        <p class="bo-hint">{{ c.sla.position_hint }}</p>
                    </div>
                </div>

                <h4 class="sla-h">{{ c.sla.match_title }}</h4>
                <div class="sla-match" data-testid="wf-sla-match">
                    <label class="bo-fld"
                        ><span class="lab">{{ c.sla.f_department }}</span
                        ><select
                            class="bo-in"
                            data-testid="wf-sla-match-department"
                            :value="departmentValue"
                            @change="
                                setMatch(
                                    'department_id',
                                    ($event.target as HTMLSelectElement).value,
                                )
                            "
                        >
                            <option value="">{{ c.sla.any }}</option>
                            <option v-for="d in departments" :key="d.id" :value="d.id">
                                {{ d.name }}
                            </option>
                        </select></label
                    >
                    <fieldset class="bo-fld">
                        <legend class="lab">{{ c.sla.f_priority }}</legend>
                        <div class="chk-row">
                            <label v-for="prio in PRIORITIES" :key="prio" class="bo-check"
                                ><input
                                    type="checkbox"
                                    :data-testid="`wf-sla-match-priority-${prio}`"
                                    :checked="priorityOn(prio)"
                                    @change="
                                        togglePriority(
                                            prio,
                                            ($event.target as HTMLInputElement).checked,
                                        )
                                    "
                                />
                                {{ prio }}</label
                            >
                        </div>
                    </fieldset>
                    <fieldset class="bo-fld">
                        <legend class="lab">{{ c.sla.f_channel }}</legend>
                        <div class="chk-row">
                            <label v-for="ch in CHANNELS" :key="ch" class="bo-check"
                                ><input
                                    type="checkbox"
                                    :checked="channelOn(ch)"
                                    @change="
                                        toggleChannel(
                                            ch,
                                            ($event.target as HTMLInputElement).checked,
                                        )
                                    "
                                />
                                {{ ch }}</label
                            >
                        </div>
                    </fieldset>
                    <label class="bo-fld"
                        ><span class="lab">{{ c.sla.f_tags }}</span
                        ><input
                            class="bo-in"
                            data-testid="wf-sla-match-tags"
                            :value="tagsText"
                            :placeholder="c.sla.tags_placeholder"
                            @change="setTags(($event.target as HTMLInputElement).value)"
                    /></label>
                    <label class="bo-check sla-vip"
                        ><input
                            type="checkbox"
                            data-testid="wf-sla-match-vip"
                            :checked="vipOn"
                            @change="
                                setMatch('vip_source', ($event.target as HTMLInputElement).checked)
                            "
                        />
                        {{ c.sla.f_vip }}</label
                    >
                </div>
                <p class="bo-hint">{{ c.sla.match_hint }}</p>

                <h4 class="sla-h">{{ c.sla.targets_title }}</h4>
                <table class="sla-targets" data-testid="wf-sla-targets">
                    <thead>
                        <tr>
                            <th>{{ c.sla.priority }}</th>
                            <th>{{ c.sla.first_response }}</th>
                            <th>{{ c.sla.next_response }}</th>
                            <th>{{ c.sla.resolution }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="prio in PRIORITIES" :key="prio">
                            <th scope="row">{{ prio }}</th>
                            <td v-for="stage in STAGES" :key="stage">
                                <input
                                    type="number"
                                    min="1"
                                    :value="minutes(editing, prio, stage)"
                                    :data-testid="`wf-sla-target-${prio}-${stage}`"
                                    :aria-label="`${prio} ${stage}`"
                                    class="bo-in num"
                                    @change="
                                        setMinutes(
                                            prio,
                                            stage,
                                            ($event.target as HTMLInputElement).value,
                                        )
                                    "
                                />
                                <span class="unit">{{ c.sla.minutes }}</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <p class="bo-hint">{{ c.sla.targets_hint }}</p>

                <div class="sla-grid3">
                    <label class="bo-fld"
                        ><span class="lab">{{ c.sla.clock_mode }}</span
                        ><select
                            v-model="editing.clock_mode"
                            data-testid="wf-sla-clock"
                            class="bo-in"
                        >
                            <option value="calendar">{{ c.sla.clock_calendar }}</option>
                            <option value="24x7">{{ c.sla.clock_24x7 }}</option>
                        </select></label
                    >
                    <label v-if="editing.clock_mode === 'calendar'" class="bo-fld"
                        ><span class="lab">{{ c.sla.calendar }}</span
                        ><select
                            v-model="editing.calendar_id"
                            data-testid="wf-sla-calendar"
                            class="bo-in"
                        >
                            <option :value="null">—</option>
                            <option v-for="k in calendars" :key="k.id" :value="k.id">
                                {{ k.name }} · {{ k.timezone }}
                            </option>
                        </select>
                        <span class="bo-hint">{{ calendarNote }}</span></label
                    >
                    <label class="bo-fld"
                        ><span class="lab">{{ c.sla.warning_pct }}</span
                        ><input
                            v-model.number="editing.warning_pct"
                            type="number"
                            min="1"
                            max="99"
                            data-testid="wf-sla-warning"
                            class="bo-in num"
                        />
                        <span class="bo-hint">{{ warnSentence }}</span></label
                    >
                </div>

                <!-- G03 · the live example -->
                <section
                    class="sla-example"
                    :class="{ bad: example && !example.reachable }"
                    data-testid="wf-sla-example-card"
                >
                    <h4>{{ exampleTitle }}</h4>
                    <p v-if="example && example.reachable" class="who">{{ c.sla.example_who }}</p>
                    <p data-testid="wf-sla-example" class="ex-en">{{ example?.en ?? '' }}</p>
                    <p data-testid="wf-sla-example-am" class="ex-am" lang="am">
                        {{ example?.am ?? '' }}
                    </p>
                    <p class="bo-hint" data-testid="wf-sla-example-foot">{{ exampleFoot }}</p>
                    <p v-if="example && !example.reachable" class="bo-hint">
                        {{ c.sla.example_fix }}
                    </p>
                </section>

                <!-- G04 · pauses -->
                <h4 class="sla-h">{{ c.sla.pause_title }}</h4>
                <ul class="sla-pauses">
                    <li v-for="k in PAUSES" :key="k">
                        <label class="bo-check"
                            ><input
                                v-model="editing.pause_conditions[k]"
                                type="checkbox"
                                role="switch"
                                :aria-checked="editing.pause_conditions[k] ? 'true' : 'false'"
                                :data-testid="`wf-sla-pause-${k}`"
                            />
                            <b>{{ c.sla['pause_' + k] }}</b></label
                        >
                        <p class="bo-hint">{{ c.sla['pause_' + k + '_help'] }}</p>
                    </li>
                    <li class="implied">
                        <b>{{ c.sla.pause_outside }}</b>
                        <p class="bo-hint">{{ c.sla.pause_outside_help }}</p>
                    </li>
                </ul>
                <p class="bo-hint">{{ c.sla.pause_union }}</p>

                <!-- G05 · retarget + the diagram -->
                <h4 class="sla-h">{{ c.sla.retarget_title }}</h4>
                <div class="sla-retarget">
                    <label class="bo-check"
                        ><input
                            v-model="editing.retarget_mode"
                            type="radio"
                            value="keep"
                            data-testid="wf-sla-retarget-keep"
                        />
                        <b>{{ c.sla.retarget_keep }}</b></label
                    >
                    <p class="bo-hint">{{ c.sla.retarget_keep_help }}</p>
                    <label class="bo-check"
                        ><input
                            v-model="editing.retarget_mode"
                            type="radio"
                            value="retarget_remaining"
                            data-testid="wf-sla-retarget-rescale"
                        />
                        <b>{{ c.sla.retarget_remaining }}</b></label
                    >
                    <p class="bo-hint">{{ c.sla.retarget_remaining_help }}</p>
                </div>
                <figure
                    class="sla-diagram"
                    data-testid="wf-sla-retarget-diagram"
                    :aria-label="c.sla.diagram_aria"
                >
                    <div class="dg-row">
                        <span class="dg-lab">{{ c.sla.dg_before }}</span>
                        <span class="dg-cap">{{
                            tr(c.sla.dg_before_cap, {
                                target: diagram.before,
                                elapsed: diagram.elapsed,
                            })
                        }}</span>
                        <div class="dg-bar">
                            <span class="el" :style="{ width: wPct(diagram.elapsed) }">{{
                                tr(c.sla.dg_elapsed, { n: diagram.elapsed })
                            }}</span>
                            <span class="rem" :style="{ width: wPct(diagram.remaining) }">{{
                                tr(c.sla.dg_remaining, { n: diagram.remaining })
                            }}</span>
                            <i class="tgt" :style="{ insetInlineStart: wPct(diagram.before) }"
                                ><b>{{ c.sla.dg_target }}</b
                                >{{ tr(c.sla.dg_min, { n: diagram.before }) }}</i
                            >
                        </div>
                    </div>
                    <p class="dg-q">
                        {{
                            tr(c.sla.dg_question, {
                                after: diagram.after,
                                elapsed: diagram.elapsed,
                            })
                        }}
                    </p>
                    <div class="dg-row" :class="{ chosen: editing.retarget_mode === 'keep' }">
                        <span class="dg-lab">{{ c.sla.dg_keep }}</span>
                        <span class="dg-cap">{{ c.sla.dg_keep_cap }}</span>
                        <div class="dg-bar">
                            <span class="el" :style="{ width: wPct(diagram.elapsed) }">{{
                                diagram.elapsed
                            }}</span>
                            <span class="rem" :style="{ width: wPct(diagram.remaining) }">{{
                                tr(c.sla.dg_keep_rem, { n: diagram.remaining })
                            }}</span>
                            <i class="tgt" :style="{ insetInlineStart: wPct(diagram.before) }"
                                ><b>{{ c.sla.dg_remaining_word }}</b
                                >{{ tr(c.sla.dg_min, { n: diagram.remaining }) }}</i
                            >
                        </div>
                    </div>
                    <div
                        class="dg-row"
                        :class="{ chosen: editing.retarget_mode === 'retarget_remaining' }"
                    >
                        <span class="dg-lab">{{ c.sla.dg_rescale }}</span>
                        <span class="dg-cap">{{ c.sla.dg_rescale_cap }}</span>
                        <div class="dg-bar">
                            <span
                                class="el"
                                :style="{ width: wPct(diagram.after - diagram.rescaled) }"
                                >{{ tr(c.sla.dg_pct_elapsed, { pct: 100 - diagram.pct }) }}</span
                            >
                            <span class="rem" :style="{ width: wPct(diagram.rescaled) }">{{
                                tr(c.sla.dg_rescale_math, {
                                    pct: diagram.pct,
                                    after: diagram.after,
                                    n: diagram.rescaled,
                                })
                            }}</span>
                            <i class="tgt" :style="{ insetInlineStart: wPct(diagram.after) }"
                                ><b>{{ c.sla.dg_remaining_word }}</b
                                >{{ tr(c.sla.dg_min, { n: diagram.rescaled }) }}</i
                            >
                        </div>
                    </div>
                    <figcaption class="bo-hint">{{ c.sla.dg_foot }}</figcaption>
                </figure>

                <!-- G06 · the breach hook -->
                <h4 class="sla-h">{{ c.sla.breach_title }}</h4>
                <label class="bo-check"
                    ><input
                        type="checkbox"
                        data-testid="wf-sla-breach"
                        :checked="editing.breach_escalation !== null"
                        @change="setBreach(($event.target as HTMLInputElement).checked)"
                    />
                    <b>{{ c.sla.breach_on }}</b></label
                >
                <p class="bo-hint">{{ c.sla.breach_help }}</p>
                <input
                    v-if="editing.breach_escalation"
                    class="bo-in"
                    data-testid="wf-sla-breach-reason"
                    :aria-label="c.sla.breach_reason_label"
                    :value="editing.breach_escalation.reason"
                    @change="setBreachReason(($event.target as HTMLInputElement).value)"
                />

                <WorkflowRefusal :text="refusal" />
                <div class="sla-savebar">
                    <button type="button" data-testid="wf-sla-save" class="bo-btn p" @click="save">
                        {{ c.sla.save_policy }}
                    </button>
                    <button
                        v-if="editing.id && editing.active"
                        type="button"
                        class="bo-btn"
                        data-testid="wf-sla-deactivate"
                        @click="deactivateOpen = true"
                    >
                        {{ c.sla.deactivate }}
                    </button>
                    <button
                        v-else-if="editing.id && !editing.active"
                        type="button"
                        class="bo-btn"
                        data-testid="wf-sla-activate"
                        @click="setActive(true)"
                    >
                        {{ c.sla.activate }}
                    </button>
                    <button type="button" class="bo-btn" @click="editing = null">
                        {{ c.common.cancel }}
                    </button>
                    <span class="bo-hint">{{ c.sla.audit_note }}</span>
                </div>

                <!-- G07 · the FR-11 chips, in context -->
                <section
                    class="bo-card sla-chips"
                    data-testid="wf-sla-chips"
                    :aria-label="c.sla.chips_title"
                >
                    <h4>{{ c.sla.chips_title }}</h4>
                    <p class="bo-hint">{{ c.sla.chips_sub }}</p>
                    <ul>
                        <li>
                            <span class="sla-chip ok">◷ {{ c.sla.chip_ok }}</span
                            ><span class="bo-hint">{{ c.sla.chip_ok_help }}</span>
                        </li>
                        <li>
                            <span class="sla-chip soon">◷ {{ c.sla.chip_warn }}</span
                            ><span class="bo-hint">{{
                                tr(c.sla.chip_warn_help, { pct: editing.warning_pct })
                            }}</span>
                        </li>
                        <li>
                            <span class="sla-chip breach">▲ {{ c.sla.chip_breach }}</span
                            ><span class="bo-hint">{{ c.sla.chip_breach_help }}</span>
                        </li>
                        <li>
                            <span class="sla-chip paused" :title="c.sla.chip_paused_tip"
                                >⏸ {{ c.sla.chip_paused }}</span
                            ><span class="bo-hint">{{ c.sla.chip_paused_help }}</span>
                        </li>
                    </ul>
                    <p class="bo-hint">{{ c.sla.chips_foot }}</p>
                </section>
            </section>
            <section v-else-if="!readonly" class="bo-pane sla-editor sla-pick" aria-live="polite">
                <p class="bo-hint">{{ c.sla.pick_one }}</p>
            </section>
        </div>

        <!-- the deactivate dialog -->
        <div v-if="deactivateOpen" class="bo-scrim" @click.self="deactivateOpen = false">
            <div
                class="bo-dlg"
                role="dialog"
                aria-modal="true"
                :aria-label="c.sla.deactivate"
                data-testid="wf-sla-deactivate-dialog"
            >
                <h3>{{ c.sla.deactivate_title }}</h3>
                <p>{{ c.sla.deactivate_body }}</p>
                <div class="bo-dlg-actions">
                    <button type="button" class="bo-btn" @click="deactivateOpen = false">
                        {{ c.common.cancel }}
                    </button>
                    <button
                        type="button"
                        class="bo-btn danger"
                        data-testid="wf-sla-deactivate-confirm"
                        @click="setActive(false)"
                    >
                        {{ c.sla.deactivate_verb }}
                    </button>
                </div>
            </div>
        </div>
    </WorkflowShell>
</template>

<style scoped>
.sla-panes {
    display: grid;
    grid-template-columns: minmax(300px, 380px) minmax(0, 1fr);
    gap: 16px;
    align-items: start;
}
.sla-panes.ro {
    grid-template-columns: minmax(0, 1fr);
}
.sla-rows {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
}
.sla-row {
    display: grid;
    grid-template-columns: 44px minmax(0, 1fr) auto;
    gap: 8px;
    align-items: start;
    padding: 10px 12px;
    border-block-end: 1px solid var(--ds-border);
    min-height: 44px;
}
.sla-row.sel {
    background: var(--ds-hover-bg);
    box-shadow: inset 3px 0 0 var(--ds-accent);
}
.sla-row.off .name,
.sla-row.off .tl {
    color: var(--ds-text-muted);
}
.sla-row .pos {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 2px;
}
.sla-row .pos .n {
    font-variant-numeric: tabular-nums;
    font-weight: 700;
    color: var(--ds-text-muted);
}
.sla-row .mv {
    display: flex;
    flex-direction: column;
}
.sla-row .mv button {
    min-width: 44px;
    min-height: 22px;
    border: 1px solid var(--ds-border);
    background: var(--ds-panel-bg);
    color: var(--ds-text);
    border-radius: 6px;
    font-size: 12px;
    line-height: 1;
    cursor: pointer;
}
.sla-row .mv button:disabled {
    opacity: 0.4;
    cursor: default;
}
.sla-row .body {
    text-align: start;
    background: none;
    border: 0;
    padding: 0;
    color: inherit;
    font: inherit;
    cursor: pointer;
    min-height: 44px;
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.sla-row .name {
    font-weight: 600;
}
.sla-row .match {
    font-size: 12px;
    color: var(--ds-text-muted);
}
.sla-row .match em {
    font-style: normal;
    color: var(--ds-text-label);
}
.sla-row .tl {
    font-size: 12px;
    font-variant-numeric: tabular-nums;
}
.sla-row .st {
    font-size: 11.5px;
    font-weight: 600;
    white-space: nowrap;
}
.sla-foot {
    padding: 10px 12px 0;
}
.sla-phone-ex {
    margin: 12px;
}
.sla-editor {
    padding: 16px 18px;
}
.sla-head h2 {
    margin: 0;
    font-size: 17px;
}
.sla-br03 {
    margin: 10px 0 14px;
}
.sla-h {
    margin: 18px 0 6px;
    font-size: 13px;
}
.sla-grid2 {
    display: grid;
    grid-template-columns: minmax(0, 2fr) minmax(0, 1fr);
    gap: 12px;
}
.sla-grid3 {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 12px;
    margin-block-start: 14px;
}
.sla-match {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 10px 14px;
}
.sla-match fieldset {
    border: 0;
    margin: 0;
    padding: 0;
    min-inline-size: 0;
}
.chk-row {
    display: flex;
    flex-wrap: wrap;
    gap: 6px 14px;
}
.sla-vip {
    align-self: end;
}
.bo-in.ro {
    background: var(--ds-inset-bg);
}
.sla-targets {
    border-collapse: collapse;
    font-size: 12.5px;
}
.sla-targets th {
    text-align: start;
    font-weight: 600;
    color: var(--ds-text-label);
    padding: 4px 10px 4px 0;
    text-transform: capitalize;
}
.sla-targets td {
    padding: 3px 10px 3px 0;
    white-space: nowrap;
}
.sla-targets .num {
    width: 88px;
    display: inline-block;
    text-align: end;
    font-variant-numeric: tabular-nums;
}
.sla-targets .unit {
    margin-inline-start: 4px;
    color: var(--ds-text-muted);
    font-size: 11px;
}
.sla-example {
    margin-block-start: 16px;
    padding: 12px 14px;
    border: 1px solid var(--ds-border);
    border-radius: 10px;
    background: var(--ds-inset-bg);
}
.sla-example.bad {
    border-color: var(--ds-warn-border);
    background: var(--ds-warn-bg);
}
.sla-example h4 {
    margin: 0 0 6px;
    font-size: 12px;
    color: var(--ds-text-label);
}
.sla-example .who {
    margin: 0;
    font-size: 11.5px;
    color: var(--ds-text-muted);
}
.sla-example .ex-en {
    margin: 4px 0 0;
    font-size: 15px;
    font-weight: 600;
}
.sla-example .ex-am,
.sla-phone-ex .ex-am {
    margin: 4px 0 6px;
    font-size: 14px;
}
.sla-pauses {
    list-style: none;
    margin: 0;
    padding: 0;
    display: grid;
    gap: 8px;
}
.sla-pauses .bo-hint,
.sla-retarget .bo-hint {
    margin: 2px 0 0 26px;
}
.sla-pauses .implied {
    padding-inline-start: 26px;
    color: var(--ds-text-muted);
}
.sla-pauses .implied .bo-hint {
    margin-inline-start: 0;
}
.sla-diagram {
    margin: 12px 0 0;
    padding: 12px 14px;
    border: 1px solid var(--ds-border);
    border-radius: 10px;
    background: var(--ds-panel-bg);
}
.dg-row {
    display: grid;
    grid-template-columns: 110px minmax(0, 1fr);
    gap: 2px 12px;
    align-items: center;
    padding: 6px 4px;
    border-radius: 8px;
}
.dg-row.chosen {
    background: var(--ds-hover-bg);
    box-shadow: inset 0 0 0 1px var(--ds-accent);
}
.dg-lab {
    font-weight: 700;
    font-size: 12px;
}
.dg-cap {
    grid-column: 2;
    font-size: 11.5px;
    color: var(--ds-text-muted);
}
.dg-bar {
    grid-column: 2;
    position: relative;
    display: flex;
    height: 26px;
    margin-block-end: 18px;
    inline-size: 100%;
}
.dg-bar .el,
.dg-bar .rem {
    display: inline-flex;
    align-items: center;
    padding-inline: 6px;
    font-size: 11px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    min-inline-size: 0;
}
.dg-bar .el {
    background: var(--ds-inset-bg);
    color: var(--ds-text-muted);
    border: 1px solid var(--ds-border);
}
.dg-bar .rem {
    background: var(--ds-sla-soon-bg);
    color: var(--ds-sla-soon-fg);
    border: 1px solid var(--ds-border);
    border-inline-start: 0;
}
.dg-bar .tgt {
    position: absolute;
    top: -2px;
    bottom: -18px;
    border-inline-start: 2px solid var(--ds-sla-breach-fg);
    font-style: normal;
    font-size: 10.5px;
    padding-inline-start: 4px;
    display: flex;
    flex-direction: column;
    justify-content: flex-end;
    color: var(--ds-sla-breach-fg);
    white-space: nowrap;
    transform: translateX(-2px);
}
.dg-q {
    margin: 4px 0 8px;
    font-size: 12px;
}
.sla-savebar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
    margin-block-start: 18px;
    padding-block-start: 12px;
    border-block-start: 1px solid var(--ds-border);
}
.bo-btn.danger {
    background: var(--ds-danger);
    color: var(--ds-accent-contrast);
    border-color: transparent;
}
.sla-chips {
    margin-block-start: 18px;
}
.sla-chips ul {
    list-style: none;
    margin: 8px 0;
    padding: 0;
    display: grid;
    gap: 6px;
}
.sla-chips li {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}
.sla-chip {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    height: 22px;
    padding: 0 8px;
    border-radius: 999px;
    font-size: 11.5px;
    font-weight: 600;
    white-space: nowrap;
}
.sla-chip.ok {
    background: var(--ds-sla-ok-bg);
    color: var(--ds-sla-ok-fg);
}
.sla-chip.soon {
    background: var(--ds-sla-soon-bg);
    color: var(--ds-sla-soon-fg);
}
.sla-chip.breach {
    background: var(--ds-sla-breach-bg);
    color: var(--ds-sla-breach-fg);
}
.sla-chip.paused {
    background: var(--ds-inset-bg);
    color: var(--ds-text-muted);
    border: 1px solid var(--ds-border);
}
.sla-pick {
    display: grid;
    place-items: center;
    min-height: 200px;
}
.sla-empty {
    display: grid;
    gap: 10px;
    justify-items: start;
    max-width: 640px;
}
.sla-what {
    padding: 12px 14px;
}
.sla-what h4 {
    margin: 0 0 4px;
}
@media (max-width: 1023px) {
    .sla-panes {
        grid-template-columns: minmax(0, 1fr);
    }
}
@media (max-width: 639px) {
    .sla-row {
        grid-template-columns: 32px minmax(0, 1fr);
    }
    .sla-row .st {
        grid-column: 2;
    }
}
</style>
