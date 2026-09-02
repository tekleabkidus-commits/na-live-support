<script setup lang="ts">
// Doc 11 §8/§9/§13 — Backoffice → Organization (S11 step 12; B5-S09 J01–J15 at full
// fidelity, tokens only). The page carries its DRAWN chrome (52px topbar + the 200px
// Backoffice nav with Organization active); Doc 16 owns the real Backoffice shell later.
// §13: fully keyboard operable — reorder is MOVE BUTTONS, badges are icon+text, the
// locked starvation row's caption is programmatically associated, countdown chips follow
// the platform SR cadence (CountdownChip).
import { computed, ref } from 'vue';
import CountdownChip from '../../../Components/Rbac/CountdownChip.vue';

type Dept = {
    id: string;
    name: Record<string, string>;
    slug: string;
    description: string | null;
    position: number;
    default_queue_id: string | null;
    default_queue_name: string | null;
    business_hours_override_ref: string | null;
    is_active: boolean;
    queue_count: number;
    updated_at: string | null;
};
type TeamMember = {
    user_id: string;
    name: string;
    is_lead: boolean;
    chips: { department_id: string; department: string }[];
    warnings: { department_id: string; department: string }[];
};
type Team = {
    id: string;
    name: string;
    description: string | null;
    members: TeamMember[];
    served_departments: { id: string; name: string }[];
};
type QueueMember = {
    user_id: string;
    name: string;
    via: string;
    team_id: string | null;
    expires_at: string | null;
    config_error: boolean;
    unreachable_department_id: string | null;
};
type Queue = {
    id: string;
    department_id: string;
    name: string;
    strategy: string;
    capacity_per_agent: number | null;
    boosts: string[];
    skill_matchers: unknown[];
    skill_fallback: string;
    routing_tags: string[];
    is_rg: boolean;
    is_active: boolean;
    updated_at: string | null;
    members: QueueMember[];
    rotation: { user_id: string; name: string; load: number | null; capacity: number | null }[];
    routable: { user_id: string; name: string; load: number; capacity: number }[];
    breakdown: {
        members: number;
        online: number;
        free: number;
        skill_required: boolean;
        waiting: number;
    };
    estimate: { band: string; degraded: boolean };
    rules_targeting: { id: string; name: string }[];
};
const props = defineProps<{
    can: Record<string, boolean>;
    copy: Record<string, unknown>;
    departments: Dept[];
    teams: Team[];
    queues: Queue[];
}>();

// eslint-disable-next-line @typescript-eslint/no-explicit-any
const t = props.copy as any;
const tab = ref<'departments' | 'teams' | 'queues'>('departments');
const openDept = ref<string | null>(null);
const openQueue = ref<string | null>(null);
const conflict = ref<{
    kind: 'department' | 'queue';
    id: string;
    name: string;
    theirs: string;
} | null>(null);
const saving = ref(false);
const savedTick = ref('');

function teamSummary(team: Team): string {
    return tr((t.teams as Record<string, string>).members ?? '', {
        count: team.members.length,
        departments: team.served_departments.map((d) => d.name).join(' · '),
    });
}
function reloadPage(): void {
    window.location.reload();
}
function tr(template: string, replace: Record<string, string | number>): string {
    let out = template ?? '';
    for (const [key, value] of Object.entries(replace))
        out = out.replaceAll(':' + key, String(value));
    return out;
}
function deptName(d: Dept): string {
    return d.name.en ?? Object.values(d.name)[0] ?? d.slug;
}
const orderedDepartments = computed(() =>
    [...props.departments].sort((a, b) => a.position - b.position || a.slug.localeCompare(b.slug)),
);
const queuesByDept = computed(() => {
    const groups = new Map<string, Queue[]>();
    for (const queue of props.queues) {
        const list = groups.get(queue.department_id) ?? [];
        list.push(queue);
        groups.set(queue.department_id, list);
    }
    return groups;
});
function initials(name: string): string {
    return name
        .split(/\s+/)
        .map((part) => part.slice(0, 1))
        .slice(0, 2)
        .join('');
}
async function post(url: string, body: Record<string, unknown>): Promise<Response> {
    saving.value = true;
    try {
        return await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': decodeURIComponent(
                    document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '',
                ),
            },
            body: JSON.stringify(body),
        });
    } finally {
        saving.value = false;
    }
}
async function reorder(dept: Dept, delta: number): Promise<void> {
    const ordered = orderedDepartments.value;
    const index = ordered.findIndex((d) => d.id === dept.id);
    const swap = ordered[index + delta];
    if (!swap) return;
    await post(`/admin/organization/departments/${dept.id}/reorder`, { position: swap.position });
    await post(`/admin/organization/departments/${swap.id}/reorder`, { position: dept.position });
    window.location.reload();
}
async function saveDept(
    dept: Dept,
    changes: Record<string, unknown>,
    keepMine = false,
): Promise<void> {
    const response = await post(`/admin/organization/departments/${dept.id}`, {
        ...changes,
        expected_updated_at: keepMine ? null : dept.updated_at,
    });
    if (response.status === 409) {
        const body = (await response.json()) as { their_updated_at?: string };
        conflict.value = {
            kind: 'department',
            id: dept.id,
            name: deptName(dept),
            theirs: body.their_updated_at ?? '',
        };
        return;
    }
    if (response.ok) savedTick.value = dept.id;
}
async function saveQueue(
    queue: Queue,
    changes: Record<string, unknown>,
    keepMine = false,
): Promise<void> {
    const response = await post(`/admin/organization/queues/${queue.id}`, {
        ...changes,
        expected_updated_at: keepMine ? null : queue.updated_at,
    });
    if (response.status === 409) {
        const body = (await response.json()) as { their_updated_at?: string };
        conflict.value = {
            kind: 'queue',
            id: queue.id,
            name: queue.name,
            theirs: body.their_updated_at ?? '',
        };
        return;
    }
    if (response.ok) savedTick.value = queue.id;
}
const pendingChanges = ref<Record<string, unknown>>({});
function keepMine(): void {
    if (conflict.value === null) return;
    const target = conflict.value;
    conflict.value = null;
    if (target.kind === 'department') {
        const dept = props.departments.find((d) => d.id === target.id);
        if (dept) void saveDept(dept, pendingChanges.value, true);
    } else {
        const queue = props.queues.find((q) => q.id === target.id);
        if (queue) void saveQueue(queue, pendingChanges.value, true);
    }
}
async function moveBoost(queue: Queue, index: number, delta: number): Promise<void> {
    const boosts = [...queue.boosts];
    const target = index + delta;
    const a = boosts[index];
    const b = boosts[target];
    if (a === undefined || b === undefined) return;
    boosts[index] = b;
    boosts[target] = a;
    pendingChanges.value = { priority_rules: boosts };
    await saveQueue(queue, { priority_rules: boosts });
    reloadPage();
}
async function removeTeamMember(teamId: string, userId: string): Promise<void> {
    await post(`/admin/organization/teams/${teamId}/members`, {
        user_id: userId,
        action: 'remove',
    });
    reloadPage();
}
async function deactivateQueue(queue: Queue): Promise<void> {
    const response = await post(`/admin/organization/queues/${queue.id}/deactivate`, {});
    if (response.ok) reloadPage();
}
async function giveAccess(departmentId: string, userId: string): Promise<void> {
    await post(`/admin/organization/departments/${departmentId}/access`, { user_id: userId });
    window.location.reload();
}
function strategyGlyph(strategy: string): string {
    return strategy === 'round_robin' ? '⟳' : strategy === 'load_based' ? '⚖' : '◎';
}
function strategyLabel(strategy: string): string {
    return t.queues['strategy_' + strategy] ?? strategy;
}
</script>

<template>
    <div
        class="flex min-h-screen flex-col"
        :style="{ background: 'var(--ds-page-bg)', color: 'var(--ds-text-primary)' }"
    >
        <!-- J01: the 52px topbar, as drawn (static chrome; Doc 16 owns the live Backoffice shell). -->
        <header
            class="flex h-[52px] flex-none items-center gap-3.5 border-b px-4"
            :style="{ background: 'var(--ds-panel-bg)', borderColor: 'var(--ds-border)' }"
        >
            <span
                class="flex-none rounded-md border border-dashed px-2.5 py-1 text-xs font-semibold"
                :style="{ borderColor: 'var(--ds-border-strong)', color: 'var(--ds-text-muted)' }"
                >{{ t.nav.backoffice }}</span
            >
            <h1 class="text-sm font-semibold">{{ t.title }}</h1>
        </header>
        <div class="flex min-h-0 flex-1">
            <!-- J01: the 200px Backoffice nav — Organization active; siblings drawn, not linked (their docs own them). -->
            <nav
                :aria-label="t.nav.backoffice"
                class="w-[200px] flex-none overflow-y-auto border-r p-2"
                :style="{ background: 'var(--ds-page-bg)', borderColor: 'var(--ds-border)' }"
            >
                <p
                    class="px-2 pb-2 text-[10.5px] font-semibold uppercase tracking-wider"
                    :style="{ color: 'var(--ds-text-label)' }"
                >
                    {{ t.nav.backoffice }}
                </p>
                <div class="flex flex-col gap-px text-[12.5px]">
                    <span
                        class="rounded-md px-2 py-1.5 font-semibold"
                        :style="{
                            background: 'var(--ds-accent)',
                            color: 'var(--ds-accent-contrast)',
                        }"
                        aria-current="page"
                        >{{ t.nav.organization }}</span
                    >
                    <span
                        v-for="key in ['people', 'channels']"
                        :key="key"
                        class="rounded-md px-2 py-1.5"
                        :style="{ color: 'var(--ds-text-primary)' }"
                        >{{ t.nav[key] }}</span
                    >
                    <!-- P-NAV (S12, countersigned): the three siblings Doc 12 now owns are REAL links; the drawn set is unchanged. -->
                    <a
                        v-for="[key, href] in [
                            ['sla', '/admin/workflow/sla'],
                            ['automation', '/admin/workflow/rules'],
                            ['hours', '/admin/workflow/hours'],
                        ]"
                        :key="key"
                        :href="href"
                        :data-testid="`org-nav-${key}`"
                        class="rounded-md px-2 py-1.5"
                        :style="{ color: 'var(--ds-text-primary)' }"
                        >{{ t.nav[key] }}</a
                    >
                </div>
            </nav>
            <main class="flex min-w-0 flex-1 flex-col overflow-hidden">
                <div class="flex-none px-[18px] pt-3.5">
                    <div class="flex items-start gap-3">
                        <div class="min-w-0">
                            <h2 class="text-xl font-semibold tracking-tight">{{ t.title }}</h2>
                            <p class="mt-0.5 text-xs" :style="{ color: 'var(--ds-text-muted)' }">
                                {{
                                    tr(t.summary, {
                                        departments: departments.length,
                                        teams: teams.length,
                                        queues: queues.length,
                                    })
                                }}
                            </p>
                        </div>
                        <button
                            v-if="can['org.queues.manage']"
                            type="button"
                            data-testid="org-new-queue"
                            class="ml-auto flex-none rounded-lg px-3 py-2 text-xs font-semibold"
                            :style="{
                                background: 'var(--ds-accent)',
                                color: 'var(--ds-accent-contrast)',
                            }"
                        >
                            {{ t.new_queue }}
                        </button>
                    </div>
                    <div
                        class="mt-3 flex gap-0.5 border-b"
                        :style="{ borderColor: 'var(--ds-border)' }"
                        role="tablist"
                    >
                        <button
                            v-for="key in ['departments', 'teams', 'queues'] as const"
                            :key="key"
                            type="button"
                            role="tab"
                            :aria-selected="tab === key"
                            :data-testid="'org-tab-' + key"
                            class="-mb-px whitespace-nowrap px-3 py-2 text-[12.5px]"
                            :class="tab === key ? 'border-b-2 font-semibold' : ''"
                            :style="
                                tab === key
                                    ? {
                                          color: 'var(--ds-text-primary)',
                                          borderColor: 'var(--ds-accent)',
                                      }
                                    : { color: 'var(--ds-text-secondary)' }
                            "
                            @click="tab = key"
                        >
                            {{ t.tabs[key] }}
                        </button>
                    </div>
                </div>
                <div class="min-h-0 flex-1 overflow-y-auto p-[18px]">
                    <!-- J20: the conflict card — nothing overwritten, the operator chooses. -->
                    <div
                        v-if="conflict"
                        data-testid="org-conflict"
                        class="mb-4 rounded-lg border p-3"
                        :style="{
                            borderColor: 'var(--ds-danger-border)',
                            background: 'var(--ds-panel-bg)',
                        }"
                    >
                        <p
                            class="text-[12.5px] font-semibold"
                            :style="{ color: 'var(--ds-danger-strong)' }"
                        >
                            {{ tr(t.conflict.title, { name: conflict.name }) }}
                        </p>
                        <p
                            class="mt-1 text-[11.5px] leading-relaxed"
                            :style="{ color: 'var(--ds-text-secondary)' }"
                        >
                            {{ t.conflict.body }}
                        </p>
                        <div class="mt-2 flex gap-2">
                            <button
                                type="button"
                                data-testid="org-conflict-reload"
                                class="rounded-md border px-2.5 py-1.5 text-[11.5px] font-semibold"
                                :style="{
                                    borderColor: 'var(--ds-border-strong)',
                                    color: 'var(--ds-text-primary)',
                                }"
                                @click="reloadPage"
                            >
                                {{ t.conflict.reload }}
                            </button>
                            <button
                                type="button"
                                data-testid="org-conflict-keep"
                                class="rounded-md border px-2.5 py-1.5 text-[11.5px] font-semibold"
                                :style="{
                                    borderColor: 'var(--ds-danger-border)',
                                    color: 'var(--ds-danger-strong)',
                                }"
                                @click="keepMine"
                            >
                                {{ t.conflict.keep }}
                            </button>
                        </div>
                    </div>

                    <!-- ── Departments (J02 + J03 + J05) ─────────────────────────── -->
                    <section v-if="tab === 'departments'" data-testid="org-departments">
                        <div
                            class="overflow-hidden rounded-lg border"
                            :style="{
                                background: 'var(--ds-panel-bg)',
                                borderColor: 'var(--ds-border)',
                            }"
                        >
                            <div
                                v-for="(dept, index) in orderedDepartments"
                                :key="dept.id"
                                class="flex items-center gap-2 border-b px-3 py-2.5 last:border-b-0"
                                :style="{ borderColor: 'var(--ds-border-soft)' }"
                                :data-department-row="dept.slug"
                            >
                                <span aria-hidden="true" :style="{ color: 'var(--ds-text-muted)' }"
                                    >⠿</span
                                >
                                <button
                                    type="button"
                                    class="whitespace-nowrap text-[12.5px] font-semibold underline-offset-2 hover:underline"
                                    @click="openDept = openDept === dept.id ? null : dept.id"
                                >
                                    {{ deptName(dept) }}
                                </button>
                                <span
                                    v-if="dept.default_queue_name"
                                    class="inline-flex flex-none items-center whitespace-nowrap rounded-full border px-2 text-[10px] font-semibold"
                                    :style="{
                                        background: 'var(--ds-inset-bg)',
                                        borderColor: 'var(--ds-border)',
                                        color: 'var(--ds-text-secondary)',
                                    }"
                                    >{{
                                        tr(t.departments.default_badge, {
                                            queue: dept.default_queue_name,
                                        })
                                    }}</span
                                >
                                <span
                                    v-else
                                    class="inline-flex flex-none items-center gap-1 whitespace-nowrap rounded-full border px-2 text-[10px] font-semibold"
                                    :style="{
                                        background: 'var(--ds-warn-bg)',
                                        borderColor: 'var(--ds-warn-border)',
                                        color: 'var(--ds-warn-text)',
                                    }"
                                    data-testid="dept-no-default"
                                    >{{ t.departments.no_default }}</span
                                >
                                <span
                                    class="ml-auto flex-none text-[11.5px]"
                                    :style="{ color: 'var(--ds-text-muted)' }"
                                    >{{
                                        dept.queue_count === 1
                                            ? t.departments.queue_count_one
                                            : tr(t.departments.queue_count, {
                                                  count: dept.queue_count,
                                              })
                                    }}</span
                                >
                                <button
                                    type="button"
                                    :aria-label="
                                        tr(t.departments.move_up, { name: deptName(dept) })
                                    "
                                    :disabled="index === 0"
                                    class="h-[26px] w-[26px] flex-none rounded border text-[11px]"
                                    :style="
                                        index === 0
                                            ? {
                                                  background: 'var(--ds-inset-bg)',
                                                  borderColor: 'var(--ds-border)',
                                                  color: 'var(--ds-text-muted)',
                                              }
                                            : {
                                                  background: 'var(--ds-inset-bg)',
                                                  borderColor: 'var(--ds-border)',
                                                  color: 'var(--ds-text-primary)',
                                              }
                                    "
                                    :data-move-up="dept.slug"
                                    @click="void reorder(dept, -1)"
                                >
                                    ↑
                                </button>
                                <button
                                    type="button"
                                    :aria-label="
                                        tr(t.departments.move_down, { name: deptName(dept) })
                                    "
                                    :disabled="index === orderedDepartments.length - 1"
                                    class="h-[26px] w-[26px] flex-none rounded border text-[11px]"
                                    :style="
                                        index === orderedDepartments.length - 1
                                            ? {
                                                  background: 'var(--ds-inset-bg)',
                                                  borderColor: 'var(--ds-border)',
                                                  color: 'var(--ds-text-muted)',
                                              }
                                            : {
                                                  background: 'var(--ds-inset-bg)',
                                                  borderColor: 'var(--ds-border)',
                                                  color: 'var(--ds-text-primary)',
                                              }
                                    "
                                    :data-move-down="dept.slug"
                                    @click="void reorder(dept, 1)"
                                >
                                    ↓
                                </button>
                            </div>
                        </div>

                        <!-- J03: department detail + the danger zone -->
                        <div v-for="dept in orderedDepartments" :key="'d-' + dept.id">
                            <div
                                v-if="openDept === dept.id"
                                class="mt-3 rounded-lg border p-3.5"
                                :style="{
                                    background: 'var(--ds-panel-bg)',
                                    borderColor: 'var(--ds-border)',
                                }"
                                :data-department-detail="dept.slug"
                            >
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <p
                                            class="mb-1 text-[11px] font-semibold"
                                            :style="{ color: 'var(--ds-text-secondary)' }"
                                        >
                                            {{ t.departments.name }}
                                        </p>
                                        <p
                                            class="rounded-lg border px-3 py-2 text-[12.5px]"
                                            :style="{ borderColor: 'var(--ds-border-strong)' }"
                                        >
                                            {{ deptName(dept) }}
                                        </p>
                                    </div>
                                    <div>
                                        <p
                                            class="mb-1 text-[11px] font-semibold"
                                            :style="{ color: 'var(--ds-text-secondary)' }"
                                        >
                                            {{ t.departments.slug }}
                                        </p>
                                        <p
                                            class="rounded-lg border px-3 py-2 font-mono text-xs"
                                            :style="{
                                                borderColor: 'var(--ds-border-strong)',
                                                color: 'var(--ds-text-secondary)',
                                            }"
                                        >
                                            {{ dept.slug }}
                                        </p>
                                    </div>
                                    <div>
                                        <p
                                            class="mb-1 text-[11px] font-semibold"
                                            :style="{ color: 'var(--ds-text-secondary)' }"
                                        >
                                            {{ t.departments.default_queue }}
                                        </p>
                                        <p
                                            class="rounded-lg border px-3 py-2 text-[12.5px]"
                                            :style="{ borderColor: 'var(--ds-border-strong)' }"
                                        >
                                            {{ dept.default_queue_name ?? '—' }}
                                        </p>
                                    </div>
                                    <div>
                                        <p
                                            class="mb-1 text-[11px] font-semibold"
                                            :style="{ color: 'var(--ds-text-secondary)' }"
                                        >
                                            {{ t.departments.hours }}
                                        </p>
                                        <!-- The hours-override REF, displayed as drawn (J03) — the calendar itself is Doc 12's ([D12]). -->
                                        <p
                                            class="truncate rounded-lg border px-3 py-2 text-[12.5px]"
                                            :style="{ borderColor: 'var(--ds-border-strong)' }"
                                            data-testid="dept-hours-ref"
                                        >
                                            {{
                                                dept.business_hours_override_ref
                                                    ? tr(t.departments.hours_override, {
                                                          window: dept.business_hours_override_ref,
                                                      })
                                                    : t.departments.hours_none
                                            }}
                                        </p>
                                    </div>
                                </div>
                                <!-- J05: the danger zone -->
                                <div
                                    class="mt-3 flex items-start gap-2.5 rounded-lg border p-2.5"
                                    :style="{ borderColor: 'var(--ds-border-strong)' }"
                                >
                                    <span class="min-w-0">
                                        <span class="block text-[12.5px] font-semibold">{{
                                            t.departments.deactivate
                                        }}</span>
                                        <span
                                            class="mt-0.5 block text-[11.5px]"
                                            :style="{ color: 'var(--ds-text-secondary)' }"
                                            >{{ t.departments.deactivate_hint }}</span
                                        >
                                    </span>
                                    <button
                                        type="button"
                                        class="ml-auto flex-none rounded-md border px-2.5 py-1.5 text-[11.5px] font-semibold"
                                        :style="{ borderColor: 'var(--ds-border-strong)' }"
                                        @click="void saveDept(dept, { is_active: false })"
                                    >
                                        {{ t.departments.deactivate }}
                                    </button>
                                </div>
                                <div
                                    class="mt-2 flex items-start gap-2.5 rounded-lg border p-2.5"
                                    :style="{ borderColor: 'var(--ds-danger-border)' }"
                                    data-testid="dept-danger-delete"
                                >
                                    <span class="min-w-0">
                                        <span
                                            class="block text-[12.5px] font-semibold"
                                            :style="{ color: 'var(--ds-danger-strong)' }"
                                            >{{ t.departments.delete }}</span
                                        >
                                        <span
                                            class="mt-0.5 block text-[11.5px] leading-relaxed"
                                            :style="{ color: 'var(--ds-text-secondary)' }"
                                            >{{ t.departments.delete_hint }}</span
                                        >
                                    </span>
                                    <a
                                        :href="
                                            '/admin/organization/departments/' +
                                            dept.id +
                                            '/delete-wizard'
                                        "
                                        class="ml-auto flex-none rounded-md border px-2.5 py-1.5 text-[11.5px] font-semibold no-underline"
                                        :style="{
                                            borderColor: 'var(--ds-danger-border)',
                                            color: 'var(--ds-danger-strong)',
                                        }"
                                        >{{ t.departments.delete_button }}</a
                                    >
                                </div>
                            </div>
                        </div>

                        <!-- J13's sibling: the no-queues department warning (drawn on the Queues tab grouping too) -->
                        <div
                            v-for="dept in orderedDepartments.filter((d) => d.queue_count === 0)"
                            :key="'w-' + dept.id"
                            class="mt-3 rounded-lg border p-3.5"
                            :style="{
                                background: 'var(--ds-warn-bg)',
                                borderColor: 'var(--ds-warn-border)',
                            }"
                            data-testid="dept-no-queues"
                        >
                            <p
                                class="text-[13px] font-semibold"
                                :style="{ color: 'var(--ds-warn-text)' }"
                            >
                                {{ tr(t.departments.no_queues_title, { name: deptName(dept) }) }}
                            </p>
                            <p
                                class="mt-1 text-xs leading-relaxed"
                                :style="{ color: 'var(--ds-warn-text)' }"
                            >
                                {{ t.departments.no_queues_body }}
                            </p>
                        </div>
                    </section>

                    <!-- ── Teams (J04 + J06) ─────────────────────────────────────── -->
                    <section
                        v-if="tab === 'teams'"
                        data-testid="org-teams"
                        class="flex flex-col gap-4"
                    >
                        <div
                            v-for="team in teams"
                            :key="team.id"
                            class="overflow-hidden rounded-lg border"
                            :style="{
                                background: 'var(--ds-panel-bg)',
                                borderColor: 'var(--ds-border)',
                            }"
                            :data-team-row="team.name"
                        >
                            <div
                                class="flex items-center gap-2 border-b px-3 py-2.5"
                                :style="{ borderColor: 'var(--ds-border-soft)' }"
                            >
                                <span class="text-[12.5px] font-semibold">{{ team.name }}</span>
                                <span
                                    class="text-[11px]"
                                    :style="{ color: 'var(--ds-text-muted)' }"
                                    >{{ teamSummary(team) }}</span
                                >
                            </div>
                            <div
                                v-for="member in team.members"
                                :key="member.user_id"
                                class="flex items-center gap-2 border-b px-3 py-2 last:border-b-0"
                                :style="{ borderColor: 'var(--ds-border-soft)' }"
                            >
                                <span
                                    aria-hidden="true"
                                    class="inline-flex h-6 w-6 flex-none items-center justify-center rounded-full text-[9.5px] font-semibold"
                                    :style="{
                                        background: 'var(--ds-inset-bg)',
                                        color: 'var(--ds-text-secondary)',
                                    }"
                                    >{{ initials(member.name) }}</span
                                >
                                <span class="whitespace-nowrap text-[12.5px] font-semibold">{{
                                    member.name
                                }}</span>
                                <!-- role-context chips: one per served department the member can reach -->
                                <span
                                    v-for="chip in member.chips"
                                    :key="chip.department_id"
                                    class="inline-flex flex-none items-center whitespace-nowrap rounded-full border px-2 text-[10px] font-semibold"
                                    :style="{
                                        background: 'var(--ds-inset-bg)',
                                        borderColor: 'var(--ds-border)',
                                        color: 'var(--ds-text-secondary)',
                                    }"
                                    >{{
                                        (member.is_lead ? t.teams.lead + ' · ' : '') +
                                        chip.department
                                    }}</span
                                >
                            </div>
                            <!-- J06: the SPECIFIC warning + J04's one-act grant -->
                            <div v-for="member in team.members" :key="'w-' + member.user_id">
                                <div
                                    v-for="warning in member.warnings"
                                    :key="warning.department_id"
                                    class="border-t px-3 py-2.5"
                                    :style="{
                                        background: 'var(--ds-warn-bg)',
                                        borderColor: 'var(--ds-warn-border)',
                                    }"
                                    data-testid="team-config-warning"
                                >
                                    <div class="flex items-start gap-2">
                                        <span
                                            aria-hidden="true"
                                            :style="{ color: 'var(--ds-warn-text)' }"
                                            >▲</span
                                        >
                                        <span
                                            class="min-w-0 text-[11.5px] leading-relaxed"
                                            :style="{ color: 'var(--ds-warn-text)' }"
                                        >
                                            <strong class="font-semibold">{{
                                                tr(t.teams.warning, {
                                                    name: member.name,
                                                    department: warning.department,
                                                })
                                            }}</strong>
                                            {{
                                                tr(t.teams.warning_body, {
                                                    department: warning.department,
                                                    team: team.name,
                                                })
                                            }}
                                        </span>
                                    </div>
                                    <div class="mt-2 flex gap-2">
                                        <button
                                            v-if="can['org.departments.manage']"
                                            type="button"
                                            class="rounded-md px-2.5 py-1.5 text-[11.5px] font-semibold"
                                            :style="{
                                                background: 'var(--ds-accent)',
                                                color: 'var(--ds-accent-contrast)',
                                            }"
                                            :data-give-access="member.user_id"
                                            @click="
                                                void giveAccess(
                                                    warning.department_id,
                                                    member.user_id,
                                                )
                                            "
                                        >
                                            {{
                                                tr(t.teams.give_access, {
                                                    department: warning.department,
                                                })
                                            }}
                                        </button>
                                        <button
                                            v-if="can['org.teams.manage']"
                                            type="button"
                                            class="rounded-md border px-2.5 py-1.5 text-[11.5px] font-semibold"
                                            :style="{
                                                borderColor: 'var(--ds-warn-border)',
                                                color: 'var(--ds-warn-text)',
                                            }"
                                            @click="void removeTeamMember(team.id, member.user_id)"
                                        >
                                            {{ t.teams.remove }}
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>

                    <!-- ── Queues (J01 grouping + J07–J15) ───────────────────────── -->
                    <section
                        v-if="tab === 'queues'"
                        data-testid="org-queues"
                        class="flex flex-col gap-4"
                    >
                        <div v-for="dept in orderedDepartments" :key="'q-' + dept.id">
                            <div class="mb-2 flex items-baseline gap-2">
                                <h3 class="text-[12.5px] font-semibold">{{ deptName(dept) }}</h3>
                                <span
                                    class="text-[11px]"
                                    :style="{ color: 'var(--ds-text-muted)' }"
                                    >{{
                                        dept.queue_count === 1
                                            ? t.departments.queue_count_one
                                            : tr(t.departments.queue_count, {
                                                  count: dept.queue_count,
                                              })
                                    }}</span
                                >
                            </div>
                            <div
                                v-if="(queuesByDept.get(dept.id) ?? []).length === 0"
                                class="rounded-lg border p-3.5"
                                :style="{
                                    background: 'var(--ds-warn-bg)',
                                    borderColor: 'var(--ds-warn-border)',
                                }"
                            >
                                <p
                                    class="text-[13px] font-semibold"
                                    :style="{ color: 'var(--ds-warn-text)' }"
                                >
                                    {{
                                        tr(t.departments.no_queues_title, { name: deptName(dept) })
                                    }}
                                </p>
                                <p
                                    class="mt-1 text-xs leading-relaxed"
                                    :style="{ color: 'var(--ds-warn-text)' }"
                                >
                                    {{ t.departments.no_queues_body }}
                                </p>
                            </div>
                            <div
                                v-for="queue in queuesByDept.get(dept.id) ?? []"
                                :key="queue.id"
                                class="mb-3 rounded-lg border"
                                :style="{
                                    background: 'var(--ds-panel-bg)',
                                    borderColor: 'var(--ds-border)',
                                }"
                                :data-queue-card="queue.name"
                            >
                                <button
                                    type="button"
                                    class="flex w-full items-center gap-2 px-3 py-2.5 text-left"
                                    @click="openQueue = openQueue === queue.id ? null : queue.id"
                                >
                                    <span class="text-[12.5px] font-semibold">{{
                                        queue.name
                                    }}</span>
                                    <!-- §9: strategy badge, icon + text -->
                                    <span
                                        class="inline-flex flex-none items-center gap-1 whitespace-nowrap rounded-full border px-2 text-[10px] font-semibold"
                                        :style="{
                                            background: 'var(--ds-inset-bg)',
                                            borderColor: 'var(--ds-border)',
                                            color: 'var(--ds-text-secondary)',
                                        }"
                                        :data-strategy-badge="queue.strategy"
                                        ><span aria-hidden="true">{{
                                            strategyGlyph(queue.strategy)
                                        }}</span
                                        >{{ strategyLabel(queue.strategy) }}</span
                                    >
                                    <span
                                        v-if="queue.capacity_per_agent"
                                        class="flex-none font-mono text-[11px]"
                                        :style="{ color: 'var(--ds-text-secondary)' }"
                                        >{{
                                            tr(t.queues.capacity, {
                                                count: queue.capacity_per_agent,
                                            })
                                        }}</span
                                    >
                                    <!-- avatar stack -->
                                    <span
                                        class="ml-1 flex flex-none -space-x-1.5"
                                        aria-hidden="true"
                                    >
                                        <span
                                            v-for="member in queue.members.slice(0, 4)"
                                            :key="member.user_id"
                                            class="inline-flex h-[22px] w-[22px] items-center justify-center rounded-full border text-[9px] font-semibold"
                                            :style="{
                                                background: 'var(--ds-inset-bg)',
                                                borderColor: 'var(--ds-panel-bg)',
                                                color: 'var(--ds-text-secondary)',
                                            }"
                                            >{{ initials(member.name) }}</span
                                        >
                                    </span>
                                    <!-- §9: temporary chips (dashed + countdown), warning badges -->
                                    <span
                                        v-for="member in queue.members.filter(
                                            (m) => m.via === 'temporary' && m.expires_at,
                                        )"
                                        :key="'tmp-' + member.user_id"
                                        class="inline-flex flex-none items-center rounded-full border border-dashed px-2 text-[10px] font-semibold"
                                        :style="{
                                            borderColor: 'var(--ds-border-strong)',
                                            color: 'var(--ds-text-secondary)',
                                        }"
                                        data-testid="temporary-chip"
                                    >
                                        <CountdownChip
                                            :expires-at="member.expires_at!"
                                            :prefix="
                                                t.queues.temporary_prefix + ' ' + member.name + ' ·'
                                            "
                                        />
                                    </span>
                                    <span
                                        v-for="member in queue.members.filter(
                                            (m) => m.config_error,
                                        )"
                                        :key="'ce-' + member.user_id"
                                        class="inline-flex flex-none items-center gap-1 whitespace-nowrap rounded-full border px-2 text-[10px] font-semibold"
                                        :style="{
                                            background: 'var(--ds-warn-bg)',
                                            borderColor: 'var(--ds-warn-border)',
                                            color: 'var(--ds-warn-text)',
                                        }"
                                        :title="
                                            tr(t.queues.config_error, {
                                                department: member.unreachable_department_id ?? '',
                                            })
                                        "
                                        data-testid="queue-config-warning"
                                        >{{
                                            tr(t.queues.config_error, {
                                                department: deptName(dept),
                                            })
                                        }}</span
                                    >
                                    <span
                                        class="ml-auto"
                                        aria-hidden="true"
                                        :style="{ color: 'var(--ds-text-muted)' }"
                                        >{{ openQueue === queue.id ? '▾' : '▸' }}</span
                                    >
                                </button>

                                <div
                                    v-if="openQueue === queue.id"
                                    class="border-t p-3.5"
                                    :style="{ borderColor: 'var(--ds-border-soft)' }"
                                >
                                    <!-- J13: the RG note -->
                                    <p
                                        v-if="queue.is_rg"
                                        class="mb-3 rounded-md border-l-[3px] py-2 pl-3 pr-2 text-[11.5px] leading-relaxed"
                                        :style="{
                                            background: 'var(--ds-inset-bg)',
                                            borderColor: 'var(--ds-accent)',
                                            color: 'var(--ds-text-secondary)',
                                        }"
                                        data-testid="rg-note"
                                    >
                                        {{ t.queues.rg_note }}
                                    </p>

                                    <!-- J07: RR rotation preview (the strategy's REAL order) -->
                                    <div
                                        v-if="queue.strategy === 'round_robin'"
                                        data-testid="rotation-preview"
                                    >
                                        <p class="text-[12.5px] font-semibold">
                                            {{ t.queues.rotation_title }}
                                        </p>
                                        <div class="mt-2 flex flex-col gap-1.5">
                                            <div
                                                v-for="(agent, index) in queue.rotation"
                                                :key="agent.user_id"
                                                class="flex items-center gap-2 rounded-lg border px-2.5 py-2"
                                                :style="
                                                    index === 0
                                                        ? {
                                                              background: 'var(--ds-inset-bg)',
                                                              borderColor:
                                                                  'var(--ds-border-strong)',
                                                          }
                                                        : { borderColor: 'var(--ds-border)' }
                                                "
                                            >
                                                <span
                                                    class="flex-none font-mono text-[11px]"
                                                    :style="{ color: 'var(--ds-text-muted)' }"
                                                    >{{
                                                        index === 0
                                                            ? t.queues.rotation_next
                                                            : t.queues.rotation_then
                                                    }}</span
                                                >
                                                <span
                                                    aria-hidden="true"
                                                    class="inline-flex h-6 w-6 flex-none items-center justify-center rounded-full text-[9.5px] font-semibold"
                                                    :style="{
                                                        background: 'var(--ds-inset-bg)',
                                                        color: 'var(--ds-text-secondary)',
                                                    }"
                                                    >{{ initials(agent.name) }}</span
                                                >
                                                <span
                                                    class="whitespace-nowrap text-[12.5px] font-semibold"
                                                    >{{ agent.name }}</span
                                                >
                                                <span
                                                    class="ml-auto flex-none font-mono text-[11px]"
                                                    :style="{ color: 'var(--ds-text-secondary)' }"
                                                    >{{ agent.load }}/{{ agent.capacity }}</span
                                                >
                                            </div>
                                        </div>
                                    </div>

                                    <!-- J08: load rows + the tiebreak in words -->
                                    <div
                                        v-if="queue.strategy === 'load_based'"
                                        data-testid="load-panel"
                                    >
                                        <div class="flex flex-col gap-1.5">
                                            <div
                                                v-for="agent in queue.routable"
                                                :key="agent.user_id"
                                                class="flex items-center gap-2"
                                            >
                                                <span
                                                    class="w-[104px] flex-none truncate text-[11.5px]"
                                                    :style="{ color: 'var(--ds-text-secondary)' }"
                                                    >{{ agent.name }}</span
                                                >
                                                <span
                                                    class="h-3.5 min-w-0 flex-1 overflow-hidden rounded"
                                                    :style="{ background: 'var(--ds-border-soft)' }"
                                                    ><span
                                                        class="block h-full"
                                                        :style="{
                                                            width:
                                                                Math.round(
                                                                    (agent.load /
                                                                        Math.max(
                                                                            1,
                                                                            agent.capacity,
                                                                        )) *
                                                                        100,
                                                                ) + '%',
                                                            background: 'var(--ds-accent)',
                                                        }"
                                                    ></span
                                                ></span>
                                                <span
                                                    class="w-[74px] flex-none text-right font-mono text-[11px]"
                                                    >{{ agent.load }}/{{ agent.capacity }}</span
                                                >
                                            </div>
                                        </div>
                                        <p
                                            class="mt-2 text-[11.5px] leading-relaxed"
                                            :style="{ color: 'var(--ds-text-secondary)' }"
                                            data-testid="tiebreak-note"
                                        >
                                            {{ t.queues.tiebreak }}
                                        </p>
                                    </div>

                                    <!-- J09: skill matcher + fallback + the live hold state -->
                                    <div
                                        v-if="queue.strategy === 'skill_based'"
                                        data-testid="skill-panel"
                                    >
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span
                                                class="rounded-lg border px-2.5 py-1.5 text-[11.5px]"
                                                :style="{
                                                    background: 'var(--ds-inset-bg)',
                                                    borderColor: 'var(--ds-border-strong)',
                                                }"
                                                >{{ t.queues.matcher_ticket }}</span
                                            >
                                            <span
                                                class="text-[11.5px]"
                                                :style="{ color: 'var(--ds-text-muted)' }"
                                                >{{ t.queues.matcher_must }}</span
                                            >
                                            <span
                                                class="rounded-lg border px-2.5 py-1.5 text-[11.5px]"
                                                :style="{
                                                    background: 'var(--ds-inset-bg)',
                                                    borderColor: 'var(--ds-border-strong)',
                                                }"
                                                >{{ t.queues.matcher_agent }}</span
                                            >
                                            <button
                                                type="button"
                                                class="rounded-lg border border-dashed px-2.5 py-1.5 text-[11.5px]"
                                                :style="{
                                                    borderColor: 'var(--ds-border-strong)',
                                                    color: 'var(--ds-text-muted)',
                                                }"
                                            >
                                                {{ t.queues.matcher_add }}
                                            </button>
                                        </div>
                                        <p
                                            class="mb-1.5 mt-3 text-[11.5px] font-semibold"
                                            :style="{ color: 'var(--ds-text-secondary)' }"
                                        >
                                            {{ t.queues.fallback_title }}
                                        </p>
                                        <div class="flex flex-col gap-2">
                                            <div
                                                class="flex items-start gap-2 rounded-lg p-2.5"
                                                :style="
                                                    queue.skill_fallback === 'wait'
                                                        ? { border: '2px solid var(--ds-accent)' }
                                                        : {
                                                              border: '1px solid var(--ds-border-strong)',
                                                          }
                                                "
                                                data-fallback="wait"
                                            >
                                                <span class="min-w-0"
                                                    ><span
                                                        class="block text-[12.5px] font-semibold"
                                                        >{{ t.queues.fallback_wait }}</span
                                                    ><span
                                                        class="mt-0.5 block text-[11.5px] leading-relaxed"
                                                        :style="{
                                                            color: 'var(--ds-text-secondary)',
                                                        }"
                                                        >{{ t.queues.fallback_wait_body }}</span
                                                    ></span
                                                >
                                            </div>
                                            <div
                                                class="flex items-start gap-2 rounded-lg p-2.5"
                                                :style="
                                                    queue.skill_fallback === 'nearest_skill'
                                                        ? { border: '2px solid var(--ds-accent)' }
                                                        : {
                                                              border: '1px solid var(--ds-border-strong)',
                                                          }
                                                "
                                                data-fallback="nearest_skill"
                                            >
                                                <span class="min-w-0"
                                                    ><span
                                                        class="block text-[12.5px] font-semibold"
                                                        >{{ t.queues.fallback_nearest }}</span
                                                    ><span
                                                        class="mt-0.5 block text-[11.5px] leading-relaxed"
                                                        :style="{
                                                            color: 'var(--ds-text-secondary)',
                                                        }"
                                                        >{{ t.queues.fallback_nearest_body }}</span
                                                    ></span
                                                >
                                            </div>
                                        </div>
                                        <p
                                            v-if="
                                                queue.skill_fallback === 'wait' &&
                                                queue.breakdown.waiting > 0
                                            "
                                            class="mt-2 text-[11.5px] font-semibold"
                                            :style="{ color: 'var(--ds-warn-text)' }"
                                            data-testid="hold-state"
                                        >
                                            {{
                                                tr(t.queues.holding_now, {
                                                    count: queue.breakdown.waiting,
                                                })
                                            }}
                                        </p>
                                    </div>

                                    <!-- J10/J11: boosts with the LOCKED starvation row pinned last (§13: caption programmatically associated) -->
                                    <p
                                        class="mb-1.5 mt-4 text-[11.5px] font-semibold"
                                        :style="{ color: 'var(--ds-text-secondary)' }"
                                    >
                                        {{ t.queues.boosts_title }}
                                    </p>
                                    <div class="flex flex-col gap-1.5" data-testid="boost-list">
                                        <div
                                            v-for="(boost, index) in queue.boosts"
                                            :key="boost"
                                            class="flex items-center gap-2 rounded-lg border px-2.5 py-2"
                                            :style="{
                                                background: 'var(--ds-inset-bg)',
                                                borderColor: 'var(--ds-border-strong)',
                                            }"
                                            :data-boost-row="boost"
                                        >
                                            <span
                                                aria-hidden="true"
                                                :style="{ color: 'var(--ds-text-muted)' }"
                                                >⠿</span
                                            >
                                            <span
                                                class="flex-none font-mono text-[11px]"
                                                :style="{ color: 'var(--ds-text-muted)' }"
                                                >{{ index + 1 }}</span
                                            >
                                            <span
                                                class="whitespace-nowrap text-[12.5px] font-semibold"
                                                >{{ boost }}</span
                                            >
                                            <span
                                                v-if="boost === 'vip'"
                                                class="inline-flex flex-none items-center gap-1 whitespace-nowrap rounded-full border px-2 text-[10px] font-semibold"
                                                :style="{
                                                    background: 'var(--ds-warn-bg)',
                                                    borderColor: 'var(--ds-warn-border)',
                                                    color: 'var(--ds-warn-text)',
                                                }"
                                                data-testid="vip-source-warning"
                                                >{{ t.queues.vip_warning }}</span
                                            >
                                            <button
                                                type="button"
                                                :aria-label="'Move ' + boost + ' up'"
                                                :disabled="index === 0"
                                                class="ml-auto h-[26px] w-[26px] flex-none rounded border text-[11px]"
                                                :style="
                                                    index === 0
                                                        ? {
                                                              color: 'var(--ds-text-muted)',
                                                              borderColor: 'var(--ds-border)',
                                                          }
                                                        : { borderColor: 'var(--ds-border-strong)' }
                                                "
                                                @click="void moveBoost(queue, index, -1)"
                                            >
                                                ↑
                                            </button>
                                            <button
                                                type="button"
                                                :aria-label="'Move ' + boost + ' down'"
                                                :disabled="index === queue.boosts.length - 1"
                                                class="h-[26px] w-[26px] flex-none rounded border text-[11px]"
                                                :style="
                                                    index === queue.boosts.length - 1
                                                        ? {
                                                              color: 'var(--ds-text-muted)',
                                                              borderColor: 'var(--ds-border)',
                                                          }
                                                        : { borderColor: 'var(--ds-border-strong)' }
                                                "
                                                @click="void moveBoost(queue, index, 1)"
                                            >
                                                ↓
                                            </button>
                                        </div>
                                        <!-- THE LOCKED ROW: pinned last, NO controls, caption associated (N-12's face) -->
                                        <div
                                            class="flex items-center gap-2 rounded-lg border px-2.5 py-2"
                                            :style="{ borderColor: 'var(--ds-border-strong)' }"
                                            data-testid="starvation-row"
                                            aria-describedby="starvation-caption"
                                        >
                                            <span
                                                aria-hidden="true"
                                                :style="{ color: 'var(--ds-text-muted)' }"
                                                >🔒</span
                                            >
                                            <span
                                                class="flex-none font-mono text-[11px]"
                                                :style="{ color: 'var(--ds-text-muted)' }"
                                                >{{ queue.boosts.length + 1 }}</span
                                            >
                                            <span
                                                class="whitespace-nowrap text-[12.5px] font-semibold"
                                                >{{ t.queues.starvation_label }}</span
                                            >
                                        </div>
                                        <p
                                            id="starvation-caption"
                                            class="text-[11.5px] leading-relaxed"
                                            :style="{ color: 'var(--ds-text-secondary)' }"
                                        >
                                            {{ t.queues.starvation_caption }}
                                        </p>
                                    </div>

                                    <!-- J12: membership provenance + routing tags -->
                                    <p
                                        class="mb-1.5 mt-4 text-[11px] font-semibold"
                                        :style="{ color: 'var(--ds-text-secondary)' }"
                                    >
                                        {{ tr(t.queues.members_title, { queue: queue.name }) }}
                                    </p>
                                    <div class="flex flex-col gap-1.5">
                                        <div
                                            v-for="member in queue.members"
                                            :key="'m-' + member.user_id"
                                            class="flex items-center gap-2 rounded-lg border px-2.5 py-2"
                                            :style="{ borderColor: 'var(--ds-border)' }"
                                        >
                                            <span
                                                aria-hidden="true"
                                                class="inline-flex h-[22px] w-[22px] flex-none items-center justify-center rounded-full text-[9px] font-semibold"
                                                :style="{
                                                    background: 'var(--ds-inset-bg)',
                                                    color: 'var(--ds-text-secondary)',
                                                }"
                                                >{{ initials(member.name) }}</span
                                            >
                                            <span class="whitespace-nowrap text-[12.5px]">{{
                                                member.name
                                            }}</span>
                                            <span
                                                class="ml-auto flex-none text-[10.5px]"
                                                :style="{ color: 'var(--ds-text-muted)' }"
                                                :data-provenance="member.via"
                                                >{{
                                                    member.via === 'direct'
                                                        ? t.queues.direct
                                                        : member.via === 'team'
                                                          ? tr(t.queues.via_team, {
                                                                team: member.team_id ?? '',
                                                            })
                                                          : t.queues.temporary_prefix
                                                }}</span
                                            >
                                        </div>
                                    </div>
                                    <p
                                        class="mb-1.5 mt-4 text-[11px] font-semibold"
                                        :style="{ color: 'var(--ds-text-secondary)' }"
                                    >
                                        {{ t.queues.tags_title }}
                                    </p>
                                    <div class="flex flex-wrap gap-1.5">
                                        <span
                                            v-for="tag in queue.routing_tags"
                                            :key="tag"
                                            class="inline-flex items-center gap-1 whitespace-nowrap rounded-full border px-2.5 py-1 text-[11px]"
                                            :style="{
                                                background: 'var(--ds-inset-bg)',
                                                borderColor: 'var(--ds-border-strong)',
                                            }"
                                            :data-tag="tag"
                                            >{{ tag
                                            }}<span
                                                aria-hidden="true"
                                                :style="{ color: 'var(--ds-text-muted)' }"
                                                >✕</span
                                            ></span
                                        >
                                    </div>

                                    <!-- J14: the no-eligible banner with the breakdown -->
                                    <div
                                        v-if="queue.breakdown.free === 0"
                                        class="mt-3 rounded-lg border p-3"
                                        :style="{
                                            background: 'var(--ds-warn-bg)',
                                            borderColor: 'var(--ds-warn-border)',
                                        }"
                                        data-testid="no-eligible-banner"
                                    >
                                        <p
                                            class="text-[12.5px] font-semibold"
                                            :style="{ color: 'var(--ds-warn-text)' }"
                                        >
                                            {{ t.queues.no_eligible_title }}
                                        </p>
                                        <p
                                            class="mt-1 text-[11.5px] leading-relaxed"
                                            :style="{ color: 'var(--ds-warn-text)' }"
                                        >
                                            {{
                                                tr(t.queues.no_eligible_body, {
                                                    members: queue.breakdown.members,
                                                    online: queue.breakdown.online,
                                                    free: queue.breakdown.free,
                                                    skill: queue.breakdown.skill_required
                                                        ? t.queues.no_eligible_skill
                                                        : '',
                                                    waiting: queue.breakdown.waiting,
                                                })
                                            }}
                                        </p>
                                        <div class="mt-2 flex gap-2">
                                            <button
                                                v-if="can['org.queues.pull_in']"
                                                type="button"
                                                class="rounded-md px-2.5 py-1.5 text-[11.5px] font-semibold"
                                                :style="{
                                                    background: 'var(--ds-accent)',
                                                    color: 'var(--ds-accent-contrast)',
                                                }"
                                                data-testid="pull-in-button"
                                            >
                                                {{ t.queues.pull_in }}
                                            </button>
                                            <button
                                                type="button"
                                                class="rounded-md border px-2.5 py-1.5 text-[11.5px] font-semibold"
                                                :style="{
                                                    borderColor: 'var(--ds-warn-border)',
                                                    color: 'var(--ds-warn-text)',
                                                }"
                                            >
                                                {{ t.queues.change_fallback }}
                                            </button>
                                        </div>
                                    </div>

                                    <!-- J15: the bands as the customer reads them (SEC-05's fence, quoted) -->
                                    <p
                                        class="mb-1.5 mt-4 text-[11px] font-semibold"
                                        :style="{ color: 'var(--ds-text-secondary)' }"
                                    >
                                        {{ t.queues.estimate_title }}
                                    </p>
                                    <div class="flex flex-col gap-1.5" data-testid="estimate-panel">
                                        <div
                                            v-for="row in [
                                                ['estimate_under', 'estimate_quote_short'],
                                                ['estimate_mid', 'estimate_quote_medium'],
                                                ['estimate_over', 'estimate_quote_long'],
                                                ['estimate_closed', 'estimate_quote_closed'],
                                            ]"
                                            :key="row[0]"
                                            class="flex items-center gap-2.5 rounded-lg px-3 py-2"
                                            :style="{ background: 'var(--ds-inset-bg)' }"
                                        >
                                            <span
                                                class="w-24 flex-none font-mono text-[11px]"
                                                :style="{ color: 'var(--ds-text-muted)' }"
                                                >{{ t.queues[row[0]] }}</span
                                            >
                                            <span class="text-[12.5px]">{{
                                                t.queues[row[1]]
                                            }}</span>
                                        </div>
                                        <p
                                            class="mt-1 rounded-r-lg border-l-[3px] py-2 pl-3 text-[11.5px] leading-relaxed"
                                            :style="{
                                                background: 'var(--ds-inset-bg)',
                                                borderColor: 'var(--ds-accent)',
                                                color: 'var(--ds-text-secondary)',
                                            }"
                                        >
                                            {{ t.queues.estimate_note }}
                                        </p>
                                    </div>

                                    <!-- J17 (S11 step 13, B5-S10 §B): the REVERSIBLE option offered first; delete underneath -->
                                    <div
                                        v-if="queue.is_active"
                                        class="mt-4 rounded-lg border-2 p-3"
                                        :style="{ borderColor: 'var(--ds-accent)' }"
                                        data-testid="queue-deactivate-card"
                                    >
                                        <p class="text-[12.5px] font-semibold">
                                            {{
                                                tr(t.queues.deactivate_title, { name: queue.name })
                                            }}
                                        </p>
                                        <p
                                            class="mt-1 text-[12px] leading-relaxed"
                                            :style="{ color: 'var(--ds-text-secondary)' }"
                                        >
                                            {{
                                                tr(t.queues.deactivate_body, {
                                                    count: queue.breakdown.waiting,
                                                })
                                            }}
                                        </p>
                                        <div v-if="queue.rules_targeting.length > 0" class="mt-1.5">
                                            <p
                                                class="text-[11.5px]"
                                                :style="{ color: 'var(--ds-text-secondary)' }"
                                            >
                                                {{ t.queues.deactivate_rules_note }}
                                            </p>
                                            <ul class="mt-0.5 list-none p-0">
                                                <li
                                                    v-for="rule in queue.rules_targeting"
                                                    :key="rule.id"
                                                    class="text-[11.5px]"
                                                    :style="{ color: 'var(--ds-text-secondary)' }"
                                                >
                                                    · {{ rule.name }}
                                                </li>
                                            </ul>
                                        </div>
                                        <button
                                            type="button"
                                            class="mt-2.5 rounded-md px-3 py-2 text-[12.5px] font-semibold"
                                            :style="{
                                                background: 'var(--ds-accent)',
                                                color: 'var(--ds-accent-contrast)',
                                            }"
                                            data-testid="queue-deactivate-button"
                                            @click="void deactivateQueue(queue)"
                                        >
                                            {{ t.queues.deactivate_button }}
                                        </button>
                                    </div>
                                    <div
                                        class="mt-2.5 rounded-lg border p-3"
                                        :style="{ borderColor: 'var(--ds-danger-border)' }"
                                        data-testid="queue-danger-delete"
                                    >
                                        <p
                                            class="text-[12.5px] font-semibold"
                                            :style="{ color: 'var(--ds-danger-strong)' }"
                                        >
                                            {{ tr(t.queues.qdelete_title, { name: queue.name }) }}
                                        </p>
                                        <p
                                            class="mt-1 text-[12px] leading-relaxed"
                                            :style="{ color: 'var(--ds-text-secondary)' }"
                                        >
                                            {{ t.queues.qdelete_body }}
                                        </p>
                                        <a
                                            :href="
                                                '/admin/organization/queues/' +
                                                queue.id +
                                                '/delete-wizard'
                                            "
                                            class="mt-2.5 inline-block rounded-md border px-3 py-2 text-[12.5px] font-semibold no-underline"
                                            :style="{
                                                borderColor: 'var(--ds-danger-border)',
                                                color: 'var(--ds-danger-strong)',
                                            }"
                                            data-testid="queue-delete-wizard-link"
                                        >
                                            {{ t.queues.qdelete_button }}
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>
                </div>
            </main>
        </div>
    </div>
</template>
