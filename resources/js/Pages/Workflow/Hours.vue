<script setup lang="ts">
// Doc 12 §8 business hours (S12 step 14, redrawn to the frozen B9-S08 record in SESSION-F1 [B12]):
// calendar cards with the hours line and the override diff; the editor with the BR-03/EC-05 banner
// naming both facts, the tenant timezone labelled explicitly (§14), THE PAINTER (a drag selection
// over 30-minute cells with a live announcement — the enhancement) and THE INTERVAL LIST (the
// accessible requirement, §13: same hours, split shifts, 24:00 closes), the holiday table with
// recurring toggles, the side-by-side override diff (H04), the empty state's honest A-53 default,
// and the 375 read posture. Doors unchanged; every string an i18n key; every colour a token.
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import WorkflowShell from '../../Components/Workflow/WorkflowShell.vue';
import WorkflowRefusal from '../../Components/Workflow/WorkflowRefusal.vue';
import { post, tr } from '../../Components/Workflow/workflowApi';

type Interval = { open: string; close: string };
type Holiday = { id: string; date: string; name: string; recurring_yearly: boolean };
type Calendar = {
    id: string | null;
    name: string;
    timezone: string;
    is_tenant_default: boolean;
    department_id: string | null;
    active: boolean;
    weekdays: Record<string, Interval[]>;
    holidays: Holiday[];
    updated_at?: string | null;
    referenced_by?: number;
};
const props = defineProps<{
    copy: Record<string, unknown>;
    can: Record<string, boolean>;
    calendars: Calendar[];
    departments: { id: string; name: string }[];
    timezone: string;
}>();
// eslint-disable-next-line @typescript-eslint/no-explicit-any
const c = props.copy as any;
const DAYS = ['1', '2', '3', '4', '5', '6', '7']; // ISO weekdays, Monday first
const SLOTS = Array.from(
    { length: 48 },
    (_, i) => `${String(Math.floor(i / 2)).padStart(2, '0')}:${i % 2 ? '30' : '00'}`,
);
const HOURS = Array.from({ length: 12 }, (_, i) => `${String(i * 2).padStart(2, '0')}:00`);

const readonly = ref(window.innerWidth < 1024); // H07 — phone-readable, edit on tablet+
const editing = ref<Calendar | null>(null);
const refusal = ref('');
const holiday = ref({ date: '', name: '', recurring_yearly: true });
const removeOpen = ref(false);
const canEdit = computed(() => props.can['sla.policies.edit'] === true);
const defaultCalendar = computed(() => props.calendars.find((k) => k.is_tenant_default) ?? null);

// ── time helpers ──
function toMin(t: string): number {
    const [h, m] = t.split(':').map(Number);
    return (h ?? 0) * 60 + (m ?? 0);
}
const HHMM = /^([01]\d|2[0-3]):[0-5]\d$|^24:00$/;
function normalize(list: Interval[]): Interval[] {
    const sorted = [...list]
        .filter(
            (iv) => HHMM.test(iv.open) && HHMM.test(iv.close) && toMin(iv.close) > toMin(iv.open),
        )
        .sort((a, b) => toMin(a.open) - toMin(b.open));
    const out: Interval[] = [];
    for (const iv of sorted) {
        const last = out[out.length - 1];
        if (last && toMin(iv.open) <= toMin(last.close))
            last.close = toMin(iv.close) > toMin(last.close) ? iv.close : last.close;
        else out.push({ ...iv });
    }
    return out;
}
function dayName(d: string): string {
    return c.hours.weekday[d] ?? d;
}
function dayShort(d: string): string {
    return c.hours.weekday_short?.[d] ?? dayName(d);
}
function ivText(list: Interval[]): string {
    return list.length
        ? list.map((iv) => `${iv.open}–${iv.close}`).join(' · ')
        : c.hours.closed_word;
}
// The card's hours line: "Mon–Fri 08:00–17:00 (Wed split 08:00–12:00 · 14:00–20:00) · Sat 09:00–13:00 · Sun closed · 10 holidays"
function hoursLine(k: Calendar): string {
    const sig = DAYS.map((d) => JSON.stringify(normalize(k.weekdays[d] ?? [])));
    const parts: string[] = [];
    let i = 0;
    while (i < DAYS.length) {
        let j = i;
        while (j + 1 < DAYS.length && sig[j + 1] === sig[i]) j++;
        const list = normalize(k.weekdays[DAYS[i] ?? '1'] ?? []);
        const label =
            j > i
                ? `${dayShort(DAYS[i] ?? '1')}–${dayShort(DAYS[j] ?? '1')}`
                : dayShort(DAYS[i] ?? '1');
        parts.push(`${label} ${ivText(list)}`);
        i = j + 1;
    }
    if (k.holidays.length) parts.push(tr(c.hours.n_holidays, { n: k.holidays.length }));
    return parts.join(' · ');
}
function everyDay(k: Calendar): boolean {
    const sig = DAYS.map((d) => JSON.stringify(normalize(k.weekdays[d] ?? [])));
    return sig.every((s) => s === sig[0]) && (k.weekdays['1'] ?? []).length > 0;
}
function cardLine(k: Calendar): string {
    if (everyDay(k)) {
        const base = tr(c.hours.every_day, { hours: ivText(normalize(k.weekdays['1'] ?? [])) });
        return (k.referenced_by ?? 0) === 0 ? `${base} · ${c.hours.not_referenced}` : base;
    }
    return hoursLine(k);
}
function tzLabel(tz: string): string {
    try {
        const parts = new Intl.DateTimeFormat('en', {
            timeZone: tz,
            timeZoneName: 'short',
        }).formatToParts(new Date());
        const abbr = parts.find((p) => p.type === 'timeZoneName')?.value ?? '';
        const offParts = new Intl.DateTimeFormat('en', {
            timeZone: tz,
            timeZoneName: 'longOffset',
        }).formatToParts(new Date());
        const off = (offParts.find((p) => p.type === 'timeZoneName')?.value ?? '').replace(
            'GMT',
            'UTC',
        );
        return abbr && off ? `${tz} · ${abbr} (${off})` : tz;
    } catch {
        return tz;
    }
}
function tzAbbr(tz: string): string {
    try {
        return (
            new Intl.DateTimeFormat('en', { timeZone: tz, timeZoneName: 'short' })
                .formatToParts(new Date())
                .find((p) => p.type === 'timeZoneName')?.value ?? ''
        );
    } catch {
        return '';
    }
}
function nowIn(tz: string): string {
    try {
        return new Date().toLocaleTimeString('en-GB', {
            timeZone: tz,
            hour: '2-digit',
            minute: '2-digit',
        });
    } catch {
        return '';
    }
}
function scopeLabel(k: Calendar): string {
    if (k.is_tenant_default) return c.hours.tenant_default;
    return tr(c.hours.department_override, { department: departmentName(k.department_id) });
}
function departmentName(id: string | null): string {
    return props.departments.find((d) => d.id === id)?.name ?? '';
}
function edited(k: Calendar): string {
    if (!k.updated_at) return '';
    const d = new Date(k.updated_at.replace(' ', 'T'));
    return Number.isNaN(d.getTime())
        ? ''
        : tr(c.hours.edited_on, {
              date: d.toLocaleDateString(undefined, { day: 'numeric', month: 'short' }),
          });
}
function headStamp(k: Calendar): string {
    const parts = [k.is_tenant_default ? c.hours.tenant_default : scopeLabel(k)];
    const e = edited(k);
    if (e) parts.push(e);
    parts.push(tr(c.hours.referenced_by, { n: k.referenced_by ?? 0 }));
    return parts.join(' · ');
}
function holidayDate(h: Holiday): string {
    const d = new Date(`${h.date}T00:00:00`);
    if (Number.isNaN(d.getTime())) return h.date;
    return h.recurring_yearly
        ? d.toLocaleDateString(undefined, { day: '2-digit', month: 'short' })
        : d.toLocaleDateString(undefined, { day: '2-digit', month: 'short', year: 'numeric' });
}

// ── H04 · the override diff ──
type DiffRow = { day: string; base: string; mine: string; changed: boolean };
function diffRows(k: Calendar): DiffRow[] {
    const base = defaultCalendar.value;
    if (!base || k.is_tenant_default) return [];
    return DAYS.map((d) => {
        const b = normalize(base.weekdays[d] ?? []);
        const m = normalize(k.weekdays[d] ?? []);
        return {
            day: dayName(d),
            base: ivText(b),
            mine: ivText(m),
            changed: JSON.stringify(b) !== JSON.stringify(m),
        };
    });
}
function diff(k: Calendar): string[] {
    return diffRows(k)
        .filter((r) => r.changed)
        .map((r) => `${r.day}: ${r.mine}`);
}
function diffSentence(k: Calendar): string {
    const rows = diffRows(k).filter((r) => r.changed);
    if (!rows.length) return c.hours.diff_none;
    return tr(c.hours.diff_sentence, {
        department: departmentName(k.department_id),
        changes: rows
            .map((r) => `${r.day} ${r.mine} (${tr(c.hours.diff_default, { hours: r.base })})`)
            .join(' · '),
    });
}
const departmentsWithoutOverride = computed(() =>
    props.departments.filter((d) => !props.calendars.some((k) => k.department_id === d.id)),
);

// ── editing ──
function blank(): Calendar {
    return {
        id: null,
        name: '',
        timezone: props.timezone,
        is_tenant_default: props.calendars.length === 0,
        department_id: null,
        active: true,
        weekdays: {
            '1': [{ open: '09:00', close: '17:00' }],
            '2': [{ open: '09:00', close: '17:00' }],
            '3': [{ open: '09:00', close: '17:00' }],
            '4': [{ open: '09:00', close: '17:00' }],
            '5': [{ open: '09:00', close: '17:00' }],
        },
        holidays: [],
        referenced_by: 0,
    };
}
function open(k: Calendar): void {
    if (readonly.value) return;
    editing.value = JSON.parse(JSON.stringify(k));
    refusal.value = '';
}
function setScope(scope: 'default' | 'override'): void {
    if (!editing.value) return;
    editing.value = { ...editing.value, is_tenant_default: scope === 'default' };
}
function painted(day: string, slot: string): boolean {
    return (editing.value?.weekdays[day] ?? []).some(
        (iv) => toMin(iv.open) <= toMin(slot) && toMin(slot) < toMin(iv.close),
    );
}
function setDay(day: string, next: Interval[]): void {
    if (!editing.value) return;
    editing.value = { ...editing.value, weekdays: { ...editing.value.weekdays, [day]: next } };
}
function applyRange(day: string, from: number, to: number, on: boolean): void {
    const lo = Math.min(from, to);
    const hi = Math.max(from, to);
    const start = SLOTS[lo] ?? '00:00';
    const end = SLOTS[hi + 1] ?? '24:00';
    const list = editing.value?.weekdays[day] ?? [];
    if (on) {
        setDay(day, normalize([...list, { open: start, close: end }]));
        return;
    }
    const next: Interval[] = [];
    for (const iv of list) {
        if (toMin(iv.close) <= toMin(start) || toMin(iv.open) >= toMin(end)) next.push(iv);
        else {
            if (toMin(iv.open) < toMin(start)) next.push({ open: iv.open, close: start });
            if (toMin(end) < toMin(iv.close)) next.push({ open: end, close: iv.close });
        }
    }
    setDay(day, next);
}
// THE PAINTER — pointerdown starts a band, pointerenter extends it, release applies, Esc cancels.
const paint = ref<{ day: string; from: number; to: number; on: boolean } | null>(null);
function startPaint(day: string, idx: number): void {
    if (!editing.value) return;
    paint.value = { day, from: idx, to: idx, on: !painted(day, SLOTS[idx] ?? '00:00') };
}
function extendPaint(day: string, idx: number): void {
    if (!paint.value || paint.value.day !== day) return;
    paint.value = { ...paint.value, to: idx };
}
function finishPaint(): void {
    if (!paint.value) return;
    const { day, from, to, on } = paint.value;
    paint.value = null;
    applyRange(day, from, to, on);
}
function cancelPaint(e: KeyboardEvent): void {
    if (e.key === 'Escape' && paint.value) paint.value = null;
}
function inBand(day: string, idx: number): boolean {
    const p = paint.value;
    return !!p && p.day === day && idx >= Math.min(p.from, p.to) && idx <= Math.max(p.from, p.to);
}
const paintingLine = computed(() => {
    const p = paint.value;
    if (!p) return '';
    const lo = Math.min(p.from, p.to);
    const hi = Math.max(p.from, p.to);
    return tr(p.on ? c.hours.painting_line : c.hours.clearing_line, {
        day: dayName(p.day),
        from: SLOTS[lo] ?? '',
        to: SLOTS[hi + 1] ?? '24:00',
        cells: hi - lo + 1,
    });
});
function dayText(day: string): string {
    return ivText(normalize(editing.value?.weekdays[day] ?? []));
}
// the interval list — the accessible requirement
function setInterval(day: string, index: number, key: 'open' | 'close', value: string): void {
    const list = [...(editing.value?.weekdays[day] ?? [])];
    list[index] = { ...(list[index] as Interval), [key]: value.trim() };
    setDay(day, list);
}
function addInterval(day: string): void {
    const list = editing.value?.weekdays[day] ?? [];
    const last = list[list.length - 1];
    const open = last ? last.close : '09:00';
    const close = last
        ? toMin(last.close) + 240 <= 1440
            ? (SLOTS[(toMin(last.close) + 240) / 30] ?? '24:00')
            : '24:00'
        : '17:00';
    setDay(day, [
        ...list,
        { open: open === '24:00' ? '09:00' : open, close: open === '24:00' ? '17:00' : close },
    ]);
}
function removeInterval(day: string, index: number): void {
    setDay(
        day,
        (editing.value?.weekdays[day] ?? []).filter((_, i) => i !== index),
    );
}
function badTime(v: string): boolean {
    return !HHMM.test(v);
}
async function save(): Promise<void> {
    if (!editing.value) return;
    refusal.value = '';
    const weekdays: Record<string, Interval[]> = {};
    for (const d of DAYS) {
        const list = normalize(editing.value.weekdays[d] ?? []);
        if (list.length) weekdays[d] = list;
    }
    const body = {
        name: editing.value.name,
        timezone: editing.value.timezone,
        weekdays,
        is_tenant_default: editing.value.is_tenant_default,
        department_id: editing.value.is_tenant_default ? null : editing.value.department_id,
        active: editing.value.active,
    };
    const result = await post(
        editing.value.id
            ? `/admin/workflow/hours/calendars/${editing.value.id}`
            : '/admin/workflow/hours/calendars',
        body,
    );
    if (!result.ok) {
        refusal.value = tr(c.common.refused, { detail: result.refusal });
        return;
    }
    window.location.reload();
}
async function removeOverride(): Promise<void> {
    if (!editing.value?.id) return;
    refusal.value = '';
    const result = await post(`/admin/workflow/hours/calendars/${editing.value.id}`, {
        active: false,
    });
    removeOpen.value = false;
    if (!result.ok) {
        refusal.value = tr(c.common.refused, { detail: result.refusal });
        return;
    }
    window.location.reload();
}
async function addHoliday(): Promise<void> {
    if (!editing.value?.id) return;
    refusal.value = '';
    const result = await post(`/admin/workflow/hours/calendars/${editing.value.id}/holidays`, {
        ...holiday.value,
    });
    if (!result.ok) {
        refusal.value = tr(c.common.refused, { detail: result.refusal });
        return;
    }
    window.location.reload();
}
async function removeHoliday(id: string): Promise<void> {
    const result = await post(`/admin/workflow/hours/holidays/${id}/remove`, {});
    if (!result.ok) {
        refusal.value = tr(c.common.refused, { detail: result.refusal });
        return;
    }
    window.location.reload();
}
onMounted(() => {
    window.addEventListener('pointerup', finishPaint);
    window.addEventListener('keydown', cancelPaint);
});
onBeforeUnmount(() => {
    window.removeEventListener('pointerup', finishPaint);
    window.removeEventListener('keydown', cancelPaint);
});
const summaryLine = computed(() =>
    tr(c.hours.summary, { count: props.calendars.length, timezone: props.timezone }),
);
const tzLine = computed(() => (editing.value ? tzLabel(editing.value.timezone) : ''));
const nowLine = computed(() =>
    editing.value
        ? tr(c.hours.all_times, {
              tz: editing.value.timezone,
              abbr: tzAbbr(editing.value.timezone),
              now: nowIn(editing.value.timezone),
          })
        : '',
);
const holidayCountLine = computed(() =>
    tr(c.hours.holidays_sub, { n: editing.value?.holidays.length ?? 0 }),
);
const referencedFoot = computed(() =>
    tr(c.hours.referenced_foot, { n: editing.value?.referenced_by ?? 0 }),
);
</script>

<template>
    <WorkflowShell
        :copy="c"
        active="hours"
        :title="c.hours.title"
        :summary="summaryLine"
        :read-note="readonly ? c.hours.readnote : undefined"
    >
        <template #actions>
            <a class="bo-btn" href="/admin/workflow/sla">{{ c.nav.sla }}</a>
            <button
                v-if="canEdit && !readonly"
                type="button"
                data-testid="wf-hours-new"
                class="bo-btn p"
                @click="editing = blank()"
            >
                {{ c.hours.new }}
            </button>
        </template>

        <!-- H06 · empty -->
        <section
            v-if="calendars.length === 0 && !editing"
            class="bo-empty hr-empty"
            data-testid="wf-hours-empty"
        >
            <h3>{{ c.hours.empty }}</h3>
            <p>{{ c.hours.empty_a53 }}</p>
            <div class="row">
                <button
                    v-if="canEdit && !readonly"
                    type="button"
                    class="bo-btn p"
                    @click="editing = blank()"
                >
                    {{ c.hours.new }}
                </button>
                <a class="bo-btn" href="/admin/workflow/sla">{{ c.hours.see_sla }}</a>
            </div>
        </section>

        <div v-else class="hr-panes" :class="{ ro: readonly }">
            <!-- the calendar cards -->
            <section class="hr-cards" :aria-label="c.hours.title">
                <article
                    v-for="k in calendars"
                    :key="k.id ?? ''"
                    :data-calendar-row="k.id"
                    class="bo-card hr-card"
                    :class="{ sel: editing && editing.id === k.id, off: !k.active }"
                >
                    <div class="hd">
                        <button
                            type="button"
                            class="name"
                            :data-testid="`wf-hours-open-${k.id}`"
                            @click="open(k)"
                        >
                            {{ k.name }}
                        </button>
                        <span class="bo-chip" :class="k.is_tenant_default ? 'ok' : ''">{{
                            k.is_tenant_default ? c.hours.chip_default : c.hours.chip_override
                        }}</span>
                        <span v-if="!k.is_tenant_default && k.department_id" class="bo-hint"
                            >· {{ departmentName(k.department_id) }}</span
                        >
                        <span v-if="!k.active" class="bo-chip warn">{{
                            c.hours.chip_inactive
                        }}</span>
                        <button
                            v-if="canEdit && !readonly"
                            type="button"
                            class="sr-only"
                            :data-testid="`wf-hours-edit-${k.id}`"
                            @click="open(k)"
                        >
                            {{ c.common.save }}
                        </button>
                    </div>
                    <p class="tz">{{ tzLabel(k.timezone) }}</p>
                    <p class="line">{{ cardLine(k) }}</p>
                    <div
                        v-if="!k.is_tenant_default"
                        class="diff"
                        :data-testid="`wf-hours-diff-${k.id}`"
                    >
                        <b>{{ c.hours.diff_title }}:</b>
                        <span v-if="diff(k).length === 0" class="bo-hint">{{
                            c.hours.diff_none
                        }}</span>
                        <span v-else>{{ diff(k).join(' · ') }}</span>
                    </div>
                    <!-- H07 · the phone reads the hours per day and the holidays -->
                    <dl v-if="readonly" class="hr-phone">
                        <template v-for="d in DAYS" :key="d">
                            <dt>{{ dayName(d) }}</dt>
                            <dd>{{ ivText(normalize(k.weekdays[d] ?? [])) }}</dd>
                        </template>
                    </dl>
                    <p v-if="readonly && k.holidays.length" class="hr-phone-h">
                        <b>{{ tr(c.hours.holidays_n, { n: k.holidays.length }) }}</b>
                        <span v-for="h in k.holidays" :key="h.id"
                            >{{ h.name }} ·
                            {{
                                h.recurring_yearly ? c.hours.every_year_short : holidayDate(h)
                            }}</span
                        >
                    </p>
                </article>
                <!-- H04 · departments that keep the default -->
                <article
                    v-for="d in departmentsWithoutOverride"
                    :key="d.id"
                    class="bo-card hr-card none"
                    :data-no-override="d.id"
                >
                    <div class="hd">
                        <span class="name plain">{{ d.name }}</span>
                        <span class="bo-chip">{{ c.hours.chip_no_override }}</span>
                    </div>
                    <p class="line">{{ c.hours.diff_none }}</p>
                    <p class="bo-hint">
                        {{ tr(c.hours.no_override_help, { department: d.name }) }}
                    </p>
                </article>
            </section>

            <!-- the editor -->
            <section
                v-if="editing && !readonly"
                class="bo-pane hr-editor"
                data-testid="wf-hours-editor"
            >
                <div class="hr-head">
                    <h2>{{ editing.name || c.hours.untitled }}</h2>
                    <span class="bo-stamp">{{
                        editing.id ? headStamp(editing) : c.hours.new_stamp
                    }}</span>
                </div>
                <p role="status" class="bo-banner hr-br03" data-testid="wf-hours-prospective">
                    <b>{{ c.hours.prospective }}</b> {{ c.hours.prospective_a }}
                    <b>{{ c.hours.prospective_b }}</b> {{ c.hours.prospective_c }}
                </p>

                <div class="hr-grid3">
                    <div class="bo-fld">
                        <label for="hr-name">{{ c.common.name }}</label>
                        <input
                            id="hr-name"
                            v-model="editing.name"
                            data-testid="wf-hours-name"
                            class="bo-in"
                        />
                    </div>
                    <div class="bo-fld">
                        <label for="hr-tz">{{ c.hours.timezone }}</label>
                        <input
                            id="hr-tz"
                            v-model="editing.timezone"
                            data-testid="wf-hours-tz"
                            class="bo-in bo-mono"
                        />
                        <span class="bo-hint" data-testid="wf-hours-tz-label"
                            >{{ tzLine }} — {{ c.hours.tz_help }}</span
                        >
                    </div>
                    <fieldset class="bo-fld">
                        <legend class="lab">{{ c.hours.scope }}</legend>
                        <label class="bo-check"
                            ><input
                                type="radio"
                                name="hr-scope"
                                :checked="editing.is_tenant_default"
                                @change="setScope('default')"
                            />
                            {{ c.hours.tenant_default }}</label
                        >
                        <label class="bo-check"
                            ><input
                                type="radio"
                                name="hr-scope"
                                :checked="!editing.is_tenant_default"
                                @change="setScope('override')"
                            />
                            {{ c.hours.scope_override }}</label
                        >
                        <select
                            v-if="!editing.is_tenant_default"
                            v-model="editing.department_id"
                            class="bo-in"
                            data-testid="wf-hours-department"
                            :aria-label="c.hours.scope_override"
                        >
                            <option :value="null">—</option>
                            <option v-for="d in departments" :key="d.id" :value="d.id">
                                {{ d.name }}
                            </option>
                        </select>
                        <span class="bo-hint">{{ c.hours.scope_help }}</span>
                    </fieldset>
                </div>

                <!-- H01 · the painter -->
                <div class="hr-sec">
                    <h4>
                        {{ c.hours.grid_title }}
                        <span class="bo-hint">— {{ c.hours.painter_word }}</span>
                    </h4>
                    <p class="bo-hint" data-testid="wf-hours-times-in">{{ nowLine }}</p>
                    <p class="bo-hint">{{ c.hours.grid_help }}</p>
                </div>
                <div class="hr-painter" @pointerleave="finishPaint">
                    <table class="hr-grid" data-testid="wf-hours-grid" aria-hidden="true">
                        <thead>
                            <tr>
                                <th></th>
                                <th v-for="h in HOURS" :key="h" colspan="4" class="hx">{{ h }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="d in DAYS" :key="d">
                                <th class="dy">
                                    <span>{{ dayName(d) }}</span
                                    ><small>{{ dayText(d) }}</small>
                                </th>
                                <td v-for="(s, i) in SLOTS" :key="s" class="cell">
                                    <button
                                        type="button"
                                        tabindex="-1"
                                        :title="s"
                                        :class="{ on: painted(d, s), pt: inBand(d, i) }"
                                        @pointerdown.prevent="startPaint(d, i)"
                                        @pointerenter="extendPaint(d, i)"
                                        @click="!paint && applyRange(d, i, i, !painted(d, s))"
                                    ></button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p class="hr-live" role="status" aria-live="polite" data-testid="wf-hours-painting">
                    <template v-if="paint"
                        ><b>{{ c.hours.painting_word }}</b> {{ paintingLine }} ·
                        {{ c.hours.painting_verbs }}</template
                    >
                </p>
                <p class="hr-legend">
                    <span><i class="sw on"></i>{{ c.hours.legend_open }}</span>
                    <span><i class="sw"></i>{{ c.hours.legend_closed }}</span>
                    <span><i class="sw pt"></i>{{ c.hours.legend_painting }}</span>
                    <span class="bo-hint">{{ c.hours.cells_law }}</span>
                </p>

                <!-- H02 · the interval list -->
                <div class="hr-sec">
                    <h4>
                        {{ c.hours.list_title }}
                        <span class="bo-hint">— {{ c.hours.list_sub }}</span>
                    </h4>
                </div>
                <div class="hr-list" data-testid="wf-hours-intervals">
                    <div v-for="d in DAYS" :key="d" class="day">
                        <div class="dh">
                            <span class="dn">{{ dayName(d) }}</span>
                            <button
                                type="button"
                                class="bo-btn sm"
                                :data-testid="`wf-hours-add-${d}`"
                                @click="addInterval(d)"
                            >
                                {{ c.hours.add_interval }}
                            </button>
                        </div>
                        <p v-if="(editing.weekdays[d] ?? []).length === 0" class="bo-hint">
                            {{ c.hours.closed_all_day }}
                        </p>
                        <div v-for="(iv, i) in editing.weekdays[d] ?? []" :key="i" class="iv">
                            <label
                                ><span>{{ c.hours.open }}</span
                                ><input
                                    :value="iv.open"
                                    inputmode="numeric"
                                    pattern="[0-2][0-9]:[0-5][0-9]"
                                    :data-testid="`wf-hours-open-${d}-${i}`"
                                    class="bo-in bo-mono"
                                    :class="{ bad: badTime(iv.open) }"
                                    @change="
                                        setInterval(
                                            d,
                                            i,
                                            'open',
                                            ($event.target as HTMLInputElement).value,
                                        )
                                    "
                            /></label>
                            <label
                                ><span>{{ c.hours.close }}</span
                                ><input
                                    :value="iv.close"
                                    inputmode="numeric"
                                    pattern="([0-2][0-9]:[0-5][0-9]|24:00)"
                                    :data-testid="`wf-hours-close-${d}-${i}`"
                                    class="bo-in bo-mono"
                                    :class="{ bad: badTime(iv.close) }"
                                    @change="
                                        setInterval(
                                            d,
                                            i,
                                            'close',
                                            ($event.target as HTMLInputElement).value,
                                        )
                                    "
                            /></label>
                            <button
                                type="button"
                                class="bo-btn sm"
                                :data-testid="`wf-hours-remove-${d}-${i}`"
                                :aria-label="c.hours.remove_interval"
                                @click="removeInterval(d, i)"
                            >
                                ✕
                            </button>
                        </div>
                    </div>
                </div>

                <!-- H04 · the override diff, side by side -->
                <div
                    v-if="!editing.is_tenant_default && editing.id && defaultCalendar"
                    class="hr-sec"
                >
                    <h4>{{ c.hours.diff_title }}</h4>
                    <p class="bo-hint" data-testid="wf-hours-diff-sentence">
                        {{ diffSentence(editing) }}
                    </p>
                    <table class="hr-diff" data-testid="wf-hours-diff-table">
                        <thead>
                            <tr>
                                <th>{{ c.hours.day }}</th>
                                <th>{{ c.hours.tenant_default }}</th>
                                <th>
                                    {{
                                        tr(c.hours.override_col, {
                                            department: departmentName(editing.department_id),
                                        })
                                    }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="r in diffRows(editing)"
                                :key="r.day"
                                :class="{ chg: r.changed }"
                            >
                                <th scope="row">{{ r.day }}</th>
                                <td>{{ r.base }}</td>
                                <td>
                                    {{ r.mine
                                    }}<span v-if="r.changed" class="bo-chip warn">{{
                                        c.hours.changed_word
                                    }}</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- H03 · holidays -->
                <div class="hr-sec">
                    <h4>
                        {{ c.hours.holidays }} <span class="bo-hint">— {{ holidayCountLine }}</span>
                    </h4>
                </div>
                <template v-if="editing.id">
                    <table class="hr-hol" data-testid="wf-holidays">
                        <thead>
                            <tr>
                                <th>{{ c.hours.holiday_date }}</th>
                                <th>{{ c.hours.holiday_name }}</th>
                                <th>{{ c.hours.holiday_recurring_col }}</th>
                                <th>
                                    <span class="sr-only">{{ c.hours.actions_col }}</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="h in editing.holidays" :key="h.id" :data-holiday-row="h.id">
                                <td class="bo-mono">{{ holidayDate(h) }}</td>
                                <td>{{ h.name }}</td>
                                <td>
                                    {{
                                        h.recurring_yearly
                                            ? c.hours.holiday_recurring
                                            : c.hours.holiday_once
                                    }}
                                </td>
                                <td>
                                    <button
                                        type="button"
                                        class="bo-btn sm"
                                        :data-testid="`wf-holiday-remove-${h.id}`"
                                        @click="removeHoliday(h.id)"
                                    >
                                        {{ c.hours.remove_interval }}
                                    </button>
                                </td>
                            </tr>
                            <tr class="add">
                                <td>
                                    <input
                                        v-model="holiday.date"
                                        type="date"
                                        data-testid="wf-holiday-date"
                                        class="bo-in bo-mono"
                                        :aria-label="c.hours.holiday_date"
                                    />
                                </td>
                                <td>
                                    <input
                                        v-model="holiday.name"
                                        data-testid="wf-holiday-name"
                                        class="bo-in"
                                        :placeholder="c.hours.holiday_name"
                                        :aria-label="c.hours.holiday_name"
                                    />
                                </td>
                                <td>
                                    <label class="bo-check"
                                        ><input
                                            v-model="holiday.recurring_yearly"
                                            type="checkbox"
                                        />
                                        {{ c.hours.holiday_recurring }}</label
                                    >
                                </td>
                                <td>
                                    <button
                                        type="button"
                                        data-testid="wf-holiday-add"
                                        class="bo-btn sm p"
                                        @click="addHoliday"
                                    >
                                        {{ c.hours.holiday_add }}
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </template>
                <p v-else class="bo-hint">{{ c.hours.holidays_after_save }}</p>

                <WorkflowRefusal :text="refusal" />
                <div class="hr-savebar">
                    <button
                        type="button"
                        data-testid="wf-hours-save"
                        class="bo-btn p"
                        @click="save"
                    >
                        {{ c.hours.save_calendar }}
                    </button>
                    <button type="button" class="bo-btn" @click="editing = null">
                        {{ c.hours.discard }}
                    </button>
                    <button
                        v-if="editing.id && !editing.is_tenant_default && editing.active"
                        type="button"
                        class="bo-btn"
                        data-testid="wf-hours-remove-override"
                        @click="removeOpen = true"
                    >
                        {{ c.hours.remove_override }}
                    </button>
                    <span class="bo-hint">{{ referencedFoot }}</span>
                </div>
            </section>
            <section v-else-if="!readonly" class="bo-pane hr-editor hr-pick" aria-live="polite">
                <p class="bo-hint">{{ c.hours.pick_one }}</p>
            </section>
        </div>

        <div v-if="removeOpen" class="bo-scrim" @click.self="removeOpen = false">
            <div
                class="bo-dlg"
                role="dialog"
                aria-modal="true"
                :aria-label="c.hours.remove_override"
                data-testid="wf-hours-remove-dialog"
            >
                <h3>{{ c.hours.remove_override }}</h3>
                <p>{{ c.hours.remove_override_body }}</p>
                <div class="bo-dlg-actions">
                    <button type="button" class="bo-btn" @click="removeOpen = false">
                        {{ c.common.cancel }}
                    </button>
                    <button
                        type="button"
                        class="bo-btn p"
                        data-testid="wf-hours-remove-confirm"
                        @click="removeOverride"
                    >
                        {{ c.hours.remove_override_verb }}
                    </button>
                </div>
            </div>
        </div>
    </WorkflowShell>
</template>

<style scoped>
.hr-panes {
    display: grid;
    grid-template-columns: minmax(280px, 360px) minmax(0, 1fr);
    gap: 16px;
    align-items: start;
}
.hr-panes.ro {
    grid-template-columns: minmax(0, 1fr);
}
.hr-cards {
    display: grid;
    gap: 10px;
}
.hr-card {
    padding: 12px 14px;
    display: grid;
    gap: 4px;
}
.hr-card.sel {
    box-shadow: inset 0 0 0 2px var(--ds-accent);
}
.hr-card.off .line {
    color: var(--ds-text-muted);
}
.hr-card.none {
    background: var(--ds-inset-bg);
}
.hr-card .hd {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 6px;
}
.hr-card .name {
    font-weight: 700;
    background: none;
    border: 0;
    padding: 0;
    min-height: 44px;
    color: inherit;
    font: inherit;
    font-weight: 700;
    cursor: pointer;
    text-align: start;
}
.hr-card .name.plain {
    cursor: default;
    min-height: 0;
}
.hr-card .tz {
    margin: 0;
    font-size: 11.5px;
    color: var(--ds-text-muted);
}
.hr-card .line {
    margin: 0;
    font-size: 12.5px;
}
.hr-card .diff {
    font-size: 12px;
}
.hr-phone {
    margin: 6px 0 0;
    display: grid;
    grid-template-columns: auto 1fr;
    gap: 2px 10px;
    font-size: 12px;
}
.hr-phone dt {
    color: var(--ds-text-label);
}
.hr-phone dd {
    margin: 0;
}
.hr-phone-h {
    margin: 6px 0 0;
    font-size: 12px;
    display: grid;
    gap: 2px;
}
.hr-editor {
    padding: 16px 18px;
}
.hr-head h2 {
    margin: 0;
    font-size: 17px;
}
.hr-br03 {
    margin: 10px 0 14px;
}
.hr-grid3 {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 12px;
}
.hr-grid3 fieldset {
    border: 0;
    margin: 0;
    padding: 0;
    min-inline-size: 0;
    display: grid;
    gap: 4px;
}
.hr-sec {
    margin-block-start: 18px;
}
.hr-sec h4 {
    margin: 0 0 4px;
    font-size: 13px;
}
.hr-painter {
    overflow-x: auto;
    margin-block-start: 8px;
    user-select: none;
    touch-action: none;
}
.hr-grid {
    border-collapse: collapse;
    table-layout: fixed;
    min-inline-size: 760px;
}
.hr-grid .hx {
    font-size: 10px;
    font-weight: 500;
    color: var(--ds-text-muted);
    text-align: start;
    padding: 0 0 2px;
}
.hr-grid .dy {
    text-align: start;
    font-weight: 500;
    font-size: 11.5px;
    padding-inline-end: 8px;
    white-space: nowrap;
    inline-size: 150px;
}
.hr-grid .dy small {
    display: block;
    font-size: 10px;
    color: var(--ds-text-muted);
}
.hr-grid .cell {
    padding: 0;
}
.hr-grid .cell button {
    display: block;
    inline-size: 100%;
    block-size: 22px;
    border: 0;
    border-inline-end: 1px solid var(--ds-border-soft);
    border-block-end: 1px solid var(--ds-border-soft);
    background: var(--ds-inset-bg);
    padding: 0;
    cursor: crosshair;
}
.hr-grid .cell button.on {
    background: var(--ds-accent);
}
.hr-grid .cell button.pt {
    background: var(--ds-sla-soon-bg);
    box-shadow: inset 0 0 0 1px var(--ds-sla-soon-fg);
}
.hr-live {
    min-height: 20px;
    margin: 6px 0 0;
    font-size: 12px;
}
.hr-legend {
    display: flex;
    flex-wrap: wrap;
    gap: 14px;
    margin: 4px 0 0;
    font-size: 11.5px;
    align-items: center;
}
.hr-legend .sw {
    display: inline-block;
    inline-size: 12px;
    block-size: 12px;
    border: 1px solid var(--ds-border);
    background: var(--ds-inset-bg);
    margin-inline-end: 4px;
    vertical-align: -2px;
}
.hr-legend .sw.on {
    background: var(--ds-accent);
}
.hr-legend .sw.pt {
    background: var(--ds-sla-soon-bg);
    border-color: var(--ds-sla-soon-fg);
}
.hr-list {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 8px 14px;
    margin-block-start: 6px;
}
.hr-list .day {
    border: 1px solid var(--ds-border-soft);
    border-radius: 8px;
    padding: 8px 10px;
}
.hr-list .dh {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
}
.hr-list .dn {
    font-weight: 600;
    font-size: 12.5px;
}
.hr-list .iv {
    display: flex;
    align-items: end;
    gap: 8px;
    margin-block-start: 6px;
}
.hr-list .iv label {
    display: grid;
    gap: 2px;
    font-size: 11px;
    color: var(--ds-text-label);
}
.hr-list .iv .bo-in {
    inline-size: 80px;
}
.hr-list .iv .bo-in.bad {
    border-color: var(--ds-danger);
}
.hr-diff,
.hr-hol {
    border-collapse: collapse;
    font-size: 12.5px;
    inline-size: 100%;
    margin-block-start: 6px;
}
.hr-diff th,
.hr-hol th {
    text-align: start;
    font-weight: 600;
    color: var(--ds-text-label);
    padding: 4px 8px;
    border-block-end: 1px solid var(--ds-border);
}
.hr-diff td,
.hr-hol td {
    padding: 5px 8px;
    border-block-end: 1px solid var(--ds-border-soft);
    vertical-align: middle;
}
.hr-diff tr.chg td {
    background: var(--ds-warn-bg);
}
.hr-diff .bo-chip {
    margin-inline-start: 8px;
}
.hr-hol .add td {
    background: var(--ds-inset-bg);
}
.hr-savebar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
    margin-block-start: 18px;
    padding-block-start: 12px;
    border-block-start: 1px solid var(--ds-border);
}
.hr-pick {
    display: grid;
    place-items: center;
    min-height: 200px;
}
.hr-empty {
    display: grid;
    gap: 10px;
    justify-items: start;
    max-width: 640px;
}
.hr-empty .row {
    display: flex;
    gap: 8px;
}
@media (max-width: 1023px) {
    .hr-panes {
        grid-template-columns: minmax(0, 1fr);
    }
}
@media (prefers-reduced-motion: reduce) {
    .hr-grid .cell button {
        transition: none;
    }
}
</style>
