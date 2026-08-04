<script setup lang="ts">
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import BackofficeShell from '../../../Components/Rbac/BackofficeShell.vue';
import RiskBadge from '../../../Components/Rbac/RiskBadge.vue';

// B6-S07 — the roles list: name w/ color dot, description, people count,
// high-risk badge, updated; create/clone/compare/delete (guarded); the nine
// SEEDED chips; G20 soft-limit banner; template-gallery empty state;
// delete-confirm and delete-blocked dialogs; 375 read-only per §9's rule.
interface RoleRow {
    id: string;
    name: string;
    description: string | null;
    color: string;
    is_system: boolean;
    users_count: number;
    high_risk_count: number;
    updated_at: string | null;
}
const props = defineProps<{
    can: Record<string, boolean>;
    copy: Record<string, string>;
    chrome: Record<string, string>;
    initials: string;
    roles: RoleRow[];
    soft_limit: number;
}>();

const chromeProps = computed(() => ({
    tenantLogo: props.chrome.tenant_logo ?? '',
    search: props.chrome.search ?? '',
    notifications: props.chrome.notifications ?? '',
    online: props.chrome.online ?? '',
    backoffice: props.chrome.backoffice ?? '',
    people: props.chrome.people ?? '',
    users: props.chrome.users ?? '',
    roles: props.chrome.roles ?? '',
}));

const overLimit = computed(() => props.roles.length > props.soft_limit);
const custom = computed(() => props.roles.filter((role) => !role.is_system));

const deleteTarget = ref<RoleRow | null>(null);
const deleteBlockedCount = ref<number | null>(null);
const compareSelection = ref<string[]>([]);

function fill(template: string, values: Record<string, string | number>): string {
    return Object.entries(values).reduce(
        (out, [k, v]) => out.replaceAll(`:${k}`, String(v)),
        template,
    );
}

function toggleCompare(id: string): void {
    compareSelection.value = compareSelection.value.includes(id)
        ? compareSelection.value.filter((x) => x !== id)
        : [...compareSelection.value, id].slice(-2);
}

async function confirmDelete(): Promise<void> {
    if (!deleteTarget.value) return;
    const response = await fetch(`/api/staff/admin/roles/${deleteTarget.value.id}`, {
        method: 'DELETE',
        headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
    });
    if (response.status === 409) {
        const body = (await response.json()) as { assigned_count?: number };
        deleteBlockedCount.value = body.assigned_count ?? 0;
        return;
    }
    deleteTarget.value = null;
    router.reload();
}
</script>

<template>
    <BackofficeShell
        :title="copy.title ?? ''"
        :breadcrumb="copy.breadcrumb ?? ''"
        permission-chip="admin.roles.view"
        :chrome="chromeProps"
        :initials="initials"
    >
        <!-- G20: the soft-limit banner (EC-10) -->
        <div
            v-if="overLimit"
            class="mt-3 rounded-lg border px-3.5 py-2.5 text-[12px]"
            :style="{
                background: 'var(--ds-warn-bg)',
                borderColor: 'var(--ds-warn-border)',
                color: 'var(--ds-warn-text)',
            }"
            role="status"
        >
            {{ fill(copy.soft_limit_title ?? '', { count: roles.length }) }}
        </div>

        <div class="mt-3 flex items-center gap-2">
            <button
                v-if="can['admin.roles.manage']"
                type="button"
                class="min-h-9 rounded-md px-3.5 text-[12.5px] font-semibold"
                :style="{ background: 'var(--ds-accent)', color: 'var(--ds-accent-contrast)' }"
                data-testid="role-create"
            >
                {{ copy.create }}
            </button>
            <button
                type="button"
                class="min-h-9 rounded-md border px-3 text-[12.5px] font-medium disabled:opacity-50"
                :style="{
                    borderColor: 'var(--ds-border-strong)',
                    color: 'var(--ds-text-secondary)',
                }"
                :disabled="compareSelection.length !== 2"
                data-testid="role-compare"
            >
                {{ copy.compare }}
            </button>
        </div>

        <!-- Empty state: the template gallery explanation (§8) -->
        <div
            v-if="custom.length === 0"
            class="mt-3 rounded-lg border px-4 py-3 text-[12.5px]"
            :style="{
                background: 'var(--ds-panel-soft)',
                borderColor: 'var(--ds-border)',
                color: 'var(--ds-text-secondary)',
            }"
        >
            <div class="font-semibold" :style="{ color: 'var(--ds-text-primary)' }">
                {{ copy.empty }}
            </div>
            <p class="mt-1">{{ copy.empty_hint }}</p>
        </div>

        <!-- The list itself -->
        <div
            class="mt-3 overflow-hidden rounded-lg border max-md:hidden"
            :style="{ background: 'var(--ds-panel-bg)', borderColor: 'var(--ds-border)' }"
        >
            <table class="w-full text-start text-[12.5px]">
                <thead>
                    <tr
                        class="text-[10.5px] font-semibold uppercase tracking-wider"
                        :style="{ color: 'var(--ds-text-label)' }"
                    >
                        <th class="px-3.5 py-2 text-start">{{ copy.col_role }}</th>
                        <th class="px-3.5 py-2 text-start max-lg:hidden">
                            {{ copy.col_description }}
                        </th>
                        <th class="px-3.5 py-2 text-end">{{ copy.col_people }}</th>
                        <th class="px-3.5 py-2 text-end">{{ copy.col_high_risk }}</th>
                        <th class="px-3.5 py-2 text-end max-lg:hidden">{{ copy.col_updated }}</th>
                        <th class="px-3.5 py-2">
                            <span class="sr-only">{{ copy.edit }}</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="role in roles"
                        :key="role.id"
                        class="border-t"
                        :style="{ borderColor: 'var(--ds-border-soft)' }"
                        :data-testid="`role-row-${role.name}`"
                    >
                        <td class="px-3.5 py-2.5">
                            <label class="flex items-center gap-2">
                                <input
                                    type="checkbox"
                                    class="size-3.5"
                                    :checked="compareSelection.includes(role.id)"
                                    :aria-label="`${copy.compare}: ${role.name}`"
                                    @change="toggleCompare(role.id)"
                                />
                                <span
                                    class="size-2 flex-none rounded-full"
                                    :class="`ds-role-dot-${role.color}`"
                                    :style="{ background: 'var(--ds-priority-normal)' }"
                                    aria-hidden="true"
                                ></span>
                                <span
                                    class="font-semibold"
                                    :style="{ color: 'var(--ds-text-primary)' }"
                                    >{{ role.name }}</span
                                >
                                <span
                                    v-if="role.is_system"
                                    class="rounded border px-1 py-px text-[9px] font-bold tracking-wider"
                                    :style="{
                                        borderColor: 'var(--ds-border-strong)',
                                        color: 'var(--ds-text-faint)',
                                    }"
                                    >{{ copy.seeded }}</span
                                >
                            </label>
                        </td>
                        <td
                            class="px-3.5 py-2.5 max-lg:hidden"
                            :style="{ color: 'var(--ds-text-muted)' }"
                        >
                            {{ role.description }}
                        </td>
                        <td class="px-3.5 py-2.5 text-end tabular-nums">{{ role.users_count }}</td>
                        <td class="px-3.5 py-2.5 text-end">
                            <RiskBadge
                                v-if="role.high_risk_count > 0"
                                :label="`${role.high_risk_count} ${copy.high_risk_badge}`"
                            />
                            <span v-else :style="{ color: 'var(--ds-text-faint)' }">—</span>
                        </td>
                        <td
                            class="px-3.5 py-2.5 text-end max-lg:hidden"
                            :style="{ color: 'var(--ds-text-muted)' }"
                        >
                            {{
                                role.updated_at
                                    ? new Date(role.updated_at).toLocaleDateString()
                                    : '—'
                            }}
                        </td>
                        <td class="px-3.5 py-2.5 text-end">
                            <a
                                v-if="can['admin.roles.manage']"
                                :href="`/admin/roles/${role.id}/edit`"
                                class="me-2 text-[12px] font-medium underline-offset-2 hover:underline"
                                :style="{ color: 'var(--ds-text-secondary)' }"
                                >{{ copy.edit }}</a
                            >
                            <button
                                v-if="can['admin.roles.manage']"
                                type="button"
                                class="text-[12px] font-medium underline-offset-2 hover:underline"
                                :style="{ color: 'var(--ds-danger-text)' }"
                                :data-testid="`role-delete-${role.name}`"
                                @click="
                                    deleteTarget = role;
                                    deleteBlockedCount = null;
                                "
                            >
                                {{ copy.delete }}
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- 375: read-only cards + the explicit larger-screen notice (§9) -->
        <div class="mt-3 space-y-2 md:hidden">
            <div
                class="rounded-lg border px-3.5 py-2.5 text-[12px]"
                :style="{
                    background: 'var(--ds-panel-soft)',
                    borderColor: 'var(--ds-border)',
                    color: 'var(--ds-text-secondary)',
                }"
                role="note"
                data-testid="phone-readonly-notice"
            >
                {{ copy.phone_notice ?? '' }}
            </div>
            <div
                v-for="role in roles"
                :key="role.id"
                class="rounded-lg border px-3.5 py-2.5"
                :style="{ background: 'var(--ds-panel-bg)', borderColor: 'var(--ds-border)' }"
            >
                <div class="flex items-center gap-2 text-[12.5px] font-semibold">
                    {{ role.name }}
                    <span
                        v-if="role.is_system"
                        class="rounded border px-1 text-[9px] font-bold"
                        :style="{
                            borderColor: 'var(--ds-border-strong)',
                            color: 'var(--ds-text-faint)',
                        }"
                        >{{ copy.seeded }}</span
                    >
                    <span
                        class="ms-auto text-[11.5px] font-normal tabular-nums"
                        :style="{ color: 'var(--ds-text-muted)' }"
                        >{{ role.users_count }} · {{ copy.col_people }}</span
                    >
                </div>
            </div>
        </div>

        <!-- Delete confirm / blocked dialogs (B6-S07 §C) -->
        <div
            v-if="deleteTarget"
            class="fixed inset-0 z-10 flex items-center justify-center p-4"
            style="background: rgba(0, 0, 0, 0.4)"
            role="dialog"
            aria-modal="true"
            :aria-label="fill(copy.delete_confirm_title ?? '', { name: deleteTarget.name })"
        >
            <div
                class="w-full max-w-sm rounded-lg border p-4"
                :style="{ background: 'var(--ds-panel-bg)', borderColor: 'var(--ds-border)' }"
            >
                <template v-if="deleteBlockedCount === null">
                    <h2 class="text-[14px] font-semibold">
                        {{ fill(copy.delete_confirm_title ?? '', { name: deleteTarget.name }) }}
                    </h2>
                    <p class="mt-1.5 text-[12px]" :style="{ color: 'var(--ds-text-muted)' }">
                        {{ copy.delete_confirm_body }}
                    </p>
                    <div class="mt-3 flex justify-end gap-2">
                        <button
                            type="button"
                            class="min-h-9 rounded-md border px-3 text-[12px]"
                            :style="{ borderColor: 'var(--ds-border-strong)' }"
                            @click="deleteTarget = null"
                        >
                            {{ copy.cancel }}
                        </button>
                        <button
                            type="button"
                            class="min-h-9 rounded-md px-3 text-[12px] font-semibold"
                            :style="{
                                background: 'var(--ds-danger-strong)',
                                color: 'var(--ds-accent-contrast)',
                            }"
                            data-testid="role-delete-confirm"
                            @click="confirmDelete"
                        >
                            {{ copy.confirm_delete }}
                        </button>
                    </div>
                </template>
                <template v-else>
                    <h2
                        class="text-[14px] font-semibold"
                        :style="{ color: 'var(--ds-danger-text)' }"
                        data-testid="delete-blocked-title"
                    >
                        {{ fill(copy.delete_blocked_title ?? '', { count: deleteBlockedCount }) }}
                    </h2>
                    <div class="mt-3 flex justify-end gap-2">
                        <button
                            type="button"
                            class="min-h-9 rounded-md border px-3 text-[12px]"
                            :style="{ borderColor: 'var(--ds-border-strong)' }"
                            @click="deleteTarget = null"
                        >
                            {{ copy.cancel }}
                        </button>
                        <button
                            type="button"
                            class="min-h-9 rounded-md px-3 text-[12px] font-semibold"
                            :style="{
                                background: 'var(--ds-accent)',
                                color: 'var(--ds-accent-contrast)',
                            }"
                        >
                            {{
                                fill(copy.delete_blocked_move ?? '', { count: deleteBlockedCount })
                            }}
                        </button>
                    </div>
                </template>
            </div>
        </div>
    </BackofficeShell>
</template>
