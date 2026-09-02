<script setup lang="ts">
// Doc 12 §8 business hours (S12 step 14): the week-grid painter (click-to-toggle 30-minute cells —
// the ENHANCEMENT) with the INTERVAL-LIST editor as the accessible requirement (§13: both edit the
// same hours), holiday list with recurring toggles, department-override diff view, the BR-03/EC-05
// prospective-only notice, tenant timezone labelled explicitly (§14). [B12-batch].
import { computed, ref } from 'vue';
import WorkflowShell from '../../Components/Workflow/WorkflowShell.vue';
import WorkflowRefusal from '../../Components/Workflow/WorkflowRefusal.vue';
import { post, tr } from '../../Components/Workflow/workflowApi';

type Interval = { open: string; close: string };
type Calendar = {
    id: string | null;
    name: string;
    timezone: string;
    is_tenant_default: boolean;
    department_id: string | null;
    active: boolean;
    weekdays: Record<string, Interval[]>;
    holidays: { id: string; date: string; name: string; recurring_yearly: boolean }[];
    updated_at?: string | null;
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
const editing = ref<Calendar | null>(null);
const refusal = ref('');
const holiday = ref({ date: '', name: '', recurring_yearly: false });
const DAYS = ['1', '2', '3', '4', '5', '6', '7'];
const SLOTS = Array.from(
    { length: 48 },
    (_, i) => `${String(Math.floor(i / 2)).padStart(2, '0')}:${i % 2 ? '30' : '00'}`,
);

function scopeLabel(k: Calendar): string {
    if (k.is_tenant_default) return c.hours.tenant_default;
    return tr(c.hours.department_override, {
        department: props.departments.find((d) => d.id === k.department_id)?.name ?? '',
    });
}
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
    };
}
const defaultCalendar = computed(() => props.calendars.find((k) => k.is_tenant_default) ?? null);
function toMin(t: string): number {
    const [h, m] = t.split(':').map(Number);
    return (h ?? 0) * 60 + (m ?? 0);
}
function painted(day: string, slot: string): boolean {
    return (editing.value?.weekdays[day] ?? []).some(
        (iv) => toMin(iv.open) <= toMin(slot) && toMin(slot) < toMin(iv.close),
    );
}
function normalize(list: Interval[]): Interval[] {
    const sorted = [...list]
        .filter((iv) => toMin(iv.close) > toMin(iv.open))
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
function toggle(day: string, slot: string): void {
    if (!editing.value) return;
    const end = SLOTS[SLOTS.indexOf(slot) + 1] ?? '24:00';
    const list = editing.value.weekdays[day] ?? [];
    let next: Interval[];
    if (painted(day, slot)) {
        next = [];
        for (const iv of list) {
            if (toMin(iv.open) <= toMin(slot) && toMin(slot) < toMin(iv.close)) {
                if (toMin(iv.open) < toMin(slot)) next.push({ open: iv.open, close: slot });
                if (toMin(end) < toMin(iv.close)) next.push({ open: end, close: iv.close });
            } else next.push(iv);
        }
    } else next = normalize([...list, { open: slot, close: end }]);
    editing.value = { ...editing.value, weekdays: { ...editing.value.weekdays, [day]: next } };
}
function setInterval(day: string, index: number, key: 'open' | 'close', value: string): void {
    if (!editing.value) return;
    const list = [...(editing.value.weekdays[day] ?? [])];
    list[index] = { ...(list[index] as Interval), [key]: value };
    editing.value = { ...editing.value, weekdays: { ...editing.value.weekdays, [day]: list } };
}
function addInterval(day: string): void {
    if (!editing.value) return;
    editing.value = {
        ...editing.value,
        weekdays: {
            ...editing.value.weekdays,
            [day]: [...(editing.value.weekdays[day] ?? []), { open: '09:00', close: '17:00' }],
        },
    };
}
function removeInterval(day: string, index: number): void {
    if (!editing.value) return;
    editing.value = {
        ...editing.value,
        weekdays: {
            ...editing.value.weekdays,
            [day]: (editing.value.weekdays[day] ?? []).filter((_, i) => i !== index),
        },
    };
}
function diff(k: Calendar): string[] {
    const base = defaultCalendar.value;
    if (!base || k.is_tenant_default) return [];
    return DAYS.filter(
        (d) =>
            JSON.stringify(normalize(k.weekdays[d] ?? [])) !==
            JSON.stringify(normalize(base.weekdays[d] ?? [])),
    ).map(
        (d) =>
            `${c.hours.weekday[d]}: ${(k.weekdays[d] ?? []).map((iv) => `${iv.open}–${iv.close}`).join(', ') || c.hours.closed_all_day}`,
    );
}
async function save(): Promise<void> {
    if (!editing.value) return;
    refusal.value = '';
    const body = {
        name: editing.value.name,
        timezone: editing.value.timezone,
        weekdays: editing.value.weekdays,
        is_tenant_default: editing.value.is_tenant_default,
        department_id: editing.value.department_id,
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
</script>

<template>
    <WorkflowShell
        :copy="c"
        active="hours"
        :title="c.hours.title"
        :summary="tr(c.hours.summary, { count: calendars.length, timezone })"
    >
        <button
            v-if="can['sla.policies.edit']"
            type="button"
            data-testid="wf-hours-new"
            class="mb-3 rounded-lg px-3 py-2 text-xs font-semibold"
            :style="{ background: 'var(--ds-accent)', color: 'var(--ds-accent-contrast)' }"
            @click="editing = blank()"
        >
            {{ c.hours.new }}
        </button>
        <p
            v-if="calendars.length === 0 && !editing"
            class="text-sm"
            :style="{ color: 'var(--ds-text-muted)' }"
        >
            {{ c.hours.empty }}
        </p>
        <ul class="flex flex-col gap-2">
            <li
                v-for="k in calendars"
                :key="k.id ?? ''"
                :data-calendar-row="k.id"
                class="rounded-lg border p-3 text-sm"
                :style="{ background: 'var(--ds-panel-bg)', borderColor: 'var(--ds-border)' }"
            >
                <div class="flex flex-wrap items-center gap-3">
                    <span class="font-semibold">{{ k.name }}</span>
                    <span class="text-xs" :style="{ color: 'var(--ds-text-muted)' }">{{
                        k.timezone
                    }}</span>
                    <span
                        class="rounded px-1.5 py-0.5 text-[10.5px] font-semibold"
                        :style="{ background: 'var(--ds-inset-bg)' }"
                        >{{ scopeLabel(k) }}</span
                    >
                    <button
                        v-if="can['sla.policies.edit']"
                        type="button"
                        :data-testid="`wf-hours-edit-${k.id}`"
                        class="ms-auto rounded-md border px-2 py-1 text-xs"
                        :style="{ borderColor: 'var(--ds-border)' }"
                        @click="editing = JSON.parse(JSON.stringify(k))"
                    >
                        {{ c.common.save }} …
                    </button>
                </div>
                <div
                    v-if="!k.is_tenant_default"
                    class="mt-2 text-xs"
                    :data-testid="`wf-hours-diff-${k.id}`"
                >
                    <span class="font-semibold">{{ c.hours.diff_title }}:</span>
                    <span v-if="diff(k).length === 0" :style="{ color: 'var(--ds-text-muted)' }">{{
                        c.hours.diff_none
                    }}</span
                    ><span v-else>{{ diff(k).join(' · ') }}</span>
                </div>
            </li>
        </ul>
        <section
            v-if="editing"
            class="mt-4 rounded-lg border p-4"
            :style="{ background: 'var(--ds-panel-bg)', borderColor: 'var(--ds-border-strong)' }"
            data-testid="wf-hours-editor"
        >
            <p
                role="status"
                class="mb-3 rounded-md border px-3 py-2 text-xs"
                :style="{ borderColor: 'var(--ds-border)', background: 'var(--ds-inset-bg)' }"
            >
                {{ c.hours.prospective_notice }}
            </p>
            <div class="grid grid-cols-1 gap-3 md:grid-cols-3 text-xs">
                <label
                    ><span class="block" :style="{ color: 'var(--ds-text-label)' }">{{
                        c.common.name
                    }}</span
                    ><input
                        v-model="editing.name"
                        data-testid="wf-hours-name"
                        class="mt-1 w-full rounded-md border px-2 py-1.5 text-sm"
                        :style="{
                            borderColor: 'var(--ds-border)',
                            background: 'var(--ds-inset-bg)',
                        }"
                /></label>
                <label
                    ><span class="block" :style="{ color: 'var(--ds-text-label)' }">{{
                        c.hours.timezone
                    }}</span
                    ><input
                        v-model="editing.timezone"
                        data-testid="wf-hours-tz"
                        class="mt-1 w-full rounded-md border px-2 py-1.5 text-sm"
                        :style="{
                            borderColor: 'var(--ds-border)',
                            background: 'var(--ds-inset-bg)',
                        }"
                /></label>
                <label
                    ><span class="block" :style="{ color: 'var(--ds-text-label)' }">{{
                        c.hours.department_override.replace(' :department', '')
                    }}</span
                    ><select
                        v-model="editing.department_id"
                        :disabled="editing.is_tenant_default"
                        class="mt-1 w-full rounded-md border px-2 py-1.5 text-sm"
                        :style="{
                            borderColor: 'var(--ds-border)',
                            background: 'var(--ds-inset-bg)',
                        }"
                    >
                        <option :value="null">—</option>
                        <option v-for="d in departments" :key="d.id" :value="d.id">
                            {{ d.name }}
                        </option>
                    </select></label
                >
                <label class="flex items-center gap-2"
                    ><input v-model="editing.is_tenant_default" type="checkbox" />
                    {{ c.hours.tenant_default }}</label
                >
            </div>
            <h4 class="mt-4 text-xs font-semibold">{{ c.hours.grid_title }}</h4>
            <p class="text-xs" :style="{ color: 'var(--ds-text-muted)' }">
                {{ c.hours.grid_help }}
            </p>
            <div class="mt-2 overflow-x-auto">
                <table class="text-[10px]" data-testid="wf-hours-grid" aria-hidden="true">
                    <tbody>
                        <tr v-for="d in DAYS" :key="d">
                            <th class="pe-2 text-start font-normal">{{ c.hours.weekday[d] }}</th>
                            <td v-for="s in SLOTS" :key="s" class="p-0">
                                <button
                                    type="button"
                                    tabindex="-1"
                                    :title="s"
                                    class="h-4 w-2 border-e"
                                    :style="{
                                        background: painted(d, s)
                                            ? 'var(--ds-accent)'
                                            : 'var(--ds-inset-bg)',
                                        borderColor: 'var(--ds-border-soft)',
                                    }"
                                    @click="toggle(d, s)"
                                ></button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <h4 class="mt-4 text-xs font-semibold">{{ c.hours.list_title }}</h4>
            <div
                class="mt-1 grid grid-cols-1 gap-2 md:grid-cols-2 text-xs"
                data-testid="wf-hours-intervals"
            >
                <div
                    v-for="d in DAYS"
                    :key="d"
                    class="rounded-md border p-2"
                    :style="{ borderColor: 'var(--ds-border-soft)' }"
                >
                    <div class="flex items-center gap-2">
                        <span class="font-semibold">{{ c.hours.weekday[d] }}</span
                        ><button
                            type="button"
                            class="ms-auto rounded border px-2 py-0.5"
                            :style="{ borderColor: 'var(--ds-border)' }"
                            :data-testid="`wf-hours-add-${d}`"
                            @click="addInterval(d)"
                        >
                            {{ c.hours.add_interval }}
                        </button>
                    </div>
                    <p
                        v-if="(editing.weekdays[d] ?? []).length === 0"
                        :style="{ color: 'var(--ds-text-muted)' }"
                    >
                        {{ c.hours.closed_all_day }}
                    </p>
                    <div
                        v-for="(iv, i) in editing.weekdays[d] ?? []"
                        :key="i"
                        class="mt-1 flex items-center gap-2"
                    >
                        <label
                            >{{ c.hours.open }}
                            <input
                                type="time"
                                :value="iv.open"
                                :data-testid="`wf-hours-open-${d}-${i}`"
                                class="rounded-md border px-1 py-0.5"
                                :style="{ borderColor: 'var(--ds-border)' }"
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
                            >{{ c.hours.close }}
                            <input
                                type="time"
                                :value="iv.close"
                                class="rounded-md border px-1 py-0.5"
                                :style="{ borderColor: 'var(--ds-border)' }"
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
                            class="rounded border px-1.5 py-0.5"
                            :style="{ borderColor: 'var(--ds-border)' }"
                            :aria-label="c.hours.remove_interval"
                            @click="removeInterval(d, i)"
                        >
                            ✕
                        </button>
                    </div>
                </div>
            </div>
            <template v-if="editing.id">
                <h4 class="mt-4 text-xs font-semibold">{{ c.hours.holidays }}</h4>
                <ul class="mt-1 text-xs">
                    <li
                        v-for="h in editing.holidays"
                        :key="h.id"
                        class="flex items-center gap-2 py-0.5"
                        :data-holiday-row="h.id"
                    >
                        <span class="tabular-nums">{{ h.date }}</span
                        ><span>{{ h.name }}</span
                        ><span
                            v-if="h.recurring_yearly"
                            class="rounded px-1"
                            :style="{ background: 'var(--ds-inset-bg)' }"
                            >{{ c.hours.holiday_recurring }}</span
                        ><button
                            type="button"
                            class="ms-auto rounded border px-1.5"
                            :style="{ borderColor: 'var(--ds-border)' }"
                            @click="removeHoliday(h.id)"
                        >
                            ✕
                        </button>
                    </li>
                </ul>
                <div class="mt-2 flex flex-wrap items-end gap-2 text-xs">
                    <label
                        >{{ c.hours.holiday_date }}
                        <input
                            v-model="holiday.date"
                            type="date"
                            data-testid="wf-holiday-date"
                            class="rounded-md border px-2 py-1"
                            :style="{ borderColor: 'var(--ds-border)' }"
                    /></label>
                    <label
                        >{{ c.hours.holiday_name }}
                        <input
                            v-model="holiday.name"
                            data-testid="wf-holiday-name"
                            class="rounded-md border px-2 py-1"
                            :style="{ borderColor: 'var(--ds-border)' }"
                    /></label>
                    <label class="flex items-center gap-1"
                        ><input v-model="holiday.recurring_yearly" type="checkbox" />
                        {{ c.hours.holiday_recurring }}</label
                    >
                    <button
                        type="button"
                        data-testid="wf-holiday-add"
                        class="rounded-md border px-2 py-1"
                        :style="{ borderColor: 'var(--ds-border)' }"
                        @click="addHoliday"
                    >
                        {{ c.hours.holiday_add }}
                    </button>
                </div>
            </template>
            <WorkflowRefusal :text="refusal" />
            <div class="mt-3 flex gap-2">
                <button
                    type="button"
                    data-testid="wf-hours-save"
                    class="rounded-lg px-3 py-2 text-xs font-semibold"
                    :style="{ background: 'var(--ds-accent)', color: 'var(--ds-accent-contrast)' }"
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
        </section>
    </WorkflowShell>
</template>
