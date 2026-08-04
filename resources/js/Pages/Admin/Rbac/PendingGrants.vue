<script setup lang="ts">
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import BackofficeShell from '../../../Components/Rbac/BackofficeShell.vue';
import CountdownChip from '../../../Components/Rbac/CountdownChip.vue';
import RiskBadge from '../../../Components/Rbac/RiskBadge.vue';

// B6-S10 — the four-eyes surface: a waiting grant is a DASHED box with the
// word beside it, never a ticked box in a different colour (the drawn
// contract); approve/reject for a DISTINCT admin; the requester sees their
// own request without decision buttons; the "why a second person" and "if
// nobody answers" explainer cards; 24h self-removal countdown.
interface PendingRow {
    id: string;
    subject_type: string;
    permission: string;
    requested_by: string;
    reason: string;
    expires_at: string;
}
const props = defineProps<{
    can: Record<string, boolean>;
    copy: Record<string, string>;
    chrome: Record<string, string>;
    initials: string;
    four_eyes_enabled: boolean;
    pending: PendingRow[];
    me: string;
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

function mayDecide(row: PendingRow): boolean {
    if (row.requested_by === props.me) return false;
    // Ruling R-9: role grants need admin.roles.manage, override grants
    // admin.overrides.manage. The server re-checks; hiding is UX (FR-11).
    return row.subject_type === 'role'
        ? props.can['admin.roles.manage'] === true
        : props.can['admin.overrides.manage'] === true;
}

async function decide(row: PendingRow, action: 'approve' | 'reject'): Promise<void> {
    const response = await fetch(`/api/staff/admin/pending-grants/${row.id}/${action}`, {
        method: 'POST',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    });
    if (response.ok) router.reload();
}
</script>

<template>
    <BackofficeShell
        :title="copy.title ?? ''"
        :breadcrumb="copy.breadcrumb ?? ''"
        permission-chip="admin.roles.manage"
        :chrome="chromeProps"
        :initials="initials"
        :nav-badge="pending.length || undefined"
    >
        <p
            v-if="pending.length === 0"
            class="mt-4 text-[12.5px]"
            :style="{ color: 'var(--ds-text-muted)' }"
            data-testid="pending-empty"
        >
            {{ copy.empty }}
        </p>

        <section
            v-for="row in pending"
            :key="row.id"
            class="mt-3 rounded-[10px] border p-3.5"
            :style="{ background: 'var(--ds-panel-bg)', borderColor: 'var(--ds-border)' }"
            :data-testid="`pending-${row.permission}`"
        >
            <div class="flex flex-wrap items-center gap-2.5">
                <span
                    class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-bold"
                    :style="{ background: 'var(--ds-inset-bg)', color: 'var(--ds-text-secondary)' }"
                    >⏳ {{ copy.waiting_chip }}</span
                >
                <span class="text-[13px] font-semibold">{{
                    (copy.requested_line ?? '').replace(':name', row.requested_by)
                }}</span>
                <CountdownChip
                    class="ms-auto"
                    :expires-at="row.expires_at"
                    :prefix="(copy.self_expires ?? '').replace(':time', '').trim()"
                />
            </div>

            <!-- The waiting grant: DASHED, never ticked (the drawn contract) -->
            <div
                class="mt-2.5 rounded-lg border border-dashed p-3"
                :style="{ borderColor: 'var(--ds-border-strong)' }"
                data-testid="pending-dashed"
            >
                <div class="flex items-center gap-2">
                    <RiskBadge :label="'high-risk'" />
                    <span class="font-mono text-[11.5px] font-semibold">{{ row.permission }}</span>
                </div>
                <p class="mt-1.5 text-[11.5px]" :style="{ color: 'var(--ds-text-secondary)' }">
                    {{ row.reason }}
                </p>
                <div
                    class="mt-2.5 rounded-e-md border-s-[3px] px-2.5 py-2 text-[11.5px]"
                    :style="{
                        background: 'var(--ds-panel-soft)',
                        borderColor: 'var(--ds-accent)',
                        color: 'var(--ds-text-secondary)',
                    }"
                >
                    {{ copy.inert_note }}
                </div>
                <div v-if="mayDecide(row)" class="mt-2.5 flex items-center gap-2">
                    <button
                        type="button"
                        class="min-h-9 rounded-md px-3.5 text-[12px] font-semibold"
                        :style="{
                            background: 'var(--ds-accent)',
                            color: 'var(--ds-accent-contrast)',
                        }"
                        :data-testid="`pending-approve-${row.permission}`"
                        @click="decide(row, 'approve')"
                    >
                        {{ copy.approve }}
                    </button>
                    <button
                        type="button"
                        class="min-h-9 rounded-md border px-3.5 text-[12px] font-medium"
                        :style="{
                            borderColor: 'var(--ds-danger-border)',
                            color: 'var(--ds-danger-text)',
                        }"
                        :data-testid="`pending-reject-${row.permission}`"
                        @click="decide(row, 'reject')"
                    >
                        {{ copy.reject }}
                    </button>
                    <span class="ms-auto text-[11px]" :style="{ color: 'var(--ds-text-muted)' }">{{
                        copy.decision_recorded
                    }}</span>
                </div>
            </div>
        </section>

        <!-- The two explainer cards (I03) -->
        <div class="mt-3 grid gap-3.5 md:grid-cols-2">
            <div
                class="rounded-[10px] border p-3.5"
                :style="{ background: 'var(--ds-panel-bg)', borderColor: 'var(--ds-border)' }"
            >
                <h2 class="text-[12.5px] font-semibold">{{ copy.why_title }}</h2>
                <p
                    class="mt-1 text-[11.5px] leading-relaxed"
                    :style="{ color: 'var(--ds-text-muted)' }"
                >
                    {{ copy.why_body }}
                </p>
            </div>
            <div
                class="rounded-[10px] border p-3.5"
                :style="{ background: 'var(--ds-panel-bg)', borderColor: 'var(--ds-border)' }"
            >
                <h2 class="text-[12.5px] font-semibold">{{ copy.nobody_title }}</h2>
                <p
                    class="mt-1 text-[11.5px] leading-relaxed"
                    :style="{ color: 'var(--ds-text-muted)' }"
                >
                    {{ copy.nobody_body }}
                </p>
            </div>
        </div>
    </BackofficeShell>
</template>
