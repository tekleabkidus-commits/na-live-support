<script setup lang="ts">
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import BackofficeShell from '../../Components/Rbac/BackofficeShell.vue';

// Doc 04 FS-05 — the dead-letter ops view (ruling R-17: the session's one
// class-2 design fill). Existing design-system patterns only: the B6 data
// table + an inline confirmation, tokens from the B1-DS lineage. Parked
// poison messages list with full payloads; re-drive is gated tickets.reply
// (A-6) — the server re-checks, hiding is UX.
interface ParkedRow {
    id: string;
    channel: string;
    channel_ref_id: string;
    error_class: string;
    error_message: string;
    attempts: number;
    parked_at: string;
    payload: Record<string, unknown>;
}
const props = defineProps<{
    can: Record<string, boolean>;
    copy: Record<string, string>;
    chrome: Record<string, string>;
    initials: string;
    parked: ParkedRow[];
    next_cursor: string | null;
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

const mayRedrive = computed(() => props.can['tickets.reply'] === true);

const openPayload = ref<string | null>(null);
const confirming = ref<string | null>(null);
const conflictRow = ref<string | null>(null);

function togglePayload(id: string): void {
    openPayload.value = openPayload.value === id ? null : id;
}

async function redrive(row: ParkedRow): Promise<void> {
    const response = await fetch(`/api/staff/conversations/dead-letters/${row.id}/redrive`, {
        method: 'POST',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    });
    confirming.value = null;
    if (response.ok) {
        router.reload();
    } else if (response.status === 409) {
        conflictRow.value = row.id;
    }
}

function loadMore(): void {
    if (props.next_cursor) {
        router.get('/conversations/dead-letters', { cursor: props.next_cursor });
    }
}

function localTime(iso: string): string {
    return new Date(iso).toLocaleString();
}
</script>

<template>
    <BackofficeShell
        :title="copy.title ?? ''"
        :breadcrumb="copy.breadcrumb ?? ''"
        permission-chip="conversations.view"
        :chrome="chromeProps"
        :initials="initials"
    >
        <p class="mt-4 text-[12.5px]" :style="{ color: 'var(--ds-text-secondary)' }">
            {{ copy.intro }}
        </p>

        <p
            v-if="parked.length === 0"
            class="mt-4 text-[12.5px]"
            :style="{ color: 'var(--ds-text-muted)' }"
            data-testid="dead-letters-empty"
        >
            {{ copy.empty }}
        </p>

        <div
            v-else
            class="mt-3 overflow-x-auto rounded-lg border"
            :style="{ background: 'var(--ds-panel-bg)', borderColor: 'var(--ds-border)' }"
        >
            <table class="w-full text-start text-[12.5px]" data-testid="dead-letters-table">
                <thead>
                    <tr
                        class="text-[10.5px] font-semibold uppercase tracking-wider"
                        :style="{ color: 'var(--ds-text-label)' }"
                    >
                        <th class="px-3.5 py-2 text-start">{{ copy.col_parked_at }}</th>
                        <th class="px-3.5 py-2 text-start">{{ copy.col_channel }}</th>
                        <th class="px-3.5 py-2 text-start">{{ copy.col_error }}</th>
                        <th class="px-3.5 py-2 text-end">{{ copy.col_attempts }}</th>
                        <th class="px-3.5 py-2 text-end">{{ copy.col_actions }}</th>
                    </tr>
                </thead>
                <tbody>
                    <template v-for="row in parked" :key="row.id">
                        <tr
                            class="border-t"
                            :style="{ borderColor: 'var(--ds-border-soft)' }"
                            :data-testid="`dead-letter-row-${row.id}`"
                        >
                            <td class="whitespace-nowrap px-3.5 py-2.5">
                                {{ localTime(row.parked_at) }}
                            </td>
                            <td class="px-3.5 py-2.5">
                                <span class="font-mono text-[11.5px]"
                                    >{{ row.channel }}/{{ row.channel_ref_id }}</span
                                >
                            </td>
                            <td class="px-3.5 py-2.5">
                                <span class="font-mono text-[11.5px] font-semibold">{{
                                    row.error_class
                                }}</span>
                                <p
                                    class="mt-0.5 max-w-prose text-[11.5px]"
                                    :style="{ color: 'var(--ds-text-secondary)' }"
                                >
                                    {{ row.error_message }}
                                </p>
                                <button
                                    type="button"
                                    class="mt-1 text-[11px] font-medium underline"
                                    :style="{ color: 'var(--ds-accent)' }"
                                    :data-testid="`dead-letter-payload-toggle-${row.id}`"
                                    :aria-expanded="openPayload === row.id"
                                    @click="togglePayload(row.id)"
                                >
                                    {{
                                        openPayload === row.id
                                            ? copy.payload_hide
                                            : copy.payload_show
                                    }}
                                </button>
                            </td>
                            <td class="px-3.5 py-2.5 text-end tabular-nums">{{ row.attempts }}</td>
                            <td class="px-3.5 py-2.5 text-end">
                                <button
                                    v-if="mayRedrive && confirming !== row.id"
                                    type="button"
                                    class="min-h-9 rounded-md px-3.5 text-[12px] font-semibold"
                                    :style="{
                                        background: 'var(--ds-accent)',
                                        color: 'var(--ds-accent-contrast)',
                                    }"
                                    :data-testid="`dead-letter-redrive-${row.id}`"
                                    @click="confirming = row.id"
                                >
                                    {{ copy.redrive }}
                                </button>
                            </td>
                        </tr>
                        <!-- Inline confirmation (the B6 confirm pattern: explicit
                             yes/cancel, danger-bordered, never a browser dialog) -->
                        <tr v-if="confirming === row.id" class="border-t-0">
                            <td colspan="5" class="px-3.5 pb-3">
                                <div
                                    class="rounded-lg border p-3"
                                    :style="{ borderColor: 'var(--ds-danger-border)' }"
                                    :data-testid="`dead-letter-confirm-${row.id}`"
                                >
                                    <p class="text-[12px]">{{ copy.redrive_confirm }}</p>
                                    <div class="mt-2 flex items-center gap-2">
                                        <button
                                            type="button"
                                            class="min-h-9 rounded-md px-3.5 text-[12px] font-semibold"
                                            :style="{
                                                background: 'var(--ds-accent)',
                                                color: 'var(--ds-accent-contrast)',
                                            }"
                                            :data-testid="`dead-letter-confirm-yes-${row.id}`"
                                            @click="redrive(row)"
                                        >
                                            {{ copy.redrive_yes }}
                                        </button>
                                        <button
                                            type="button"
                                            class="min-h-9 rounded-md border px-3.5 text-[12px] font-medium"
                                            :style="{
                                                borderColor: 'var(--ds-border-strong)',
                                                color: 'var(--ds-text-secondary)',
                                            }"
                                            :data-testid="`dead-letter-confirm-cancel-${row.id}`"
                                            @click="confirming = null"
                                        >
                                            {{ copy.redrive_cancel }}
                                        </button>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="conflictRow === row.id" class="border-t-0">
                            <td colspan="5" class="px-3.5 pb-3">
                                <p
                                    class="rounded-md border px-3 py-2 text-[11.5px]"
                                    :style="{
                                        borderColor: 'var(--ds-border-strong)',
                                        color: 'var(--ds-text-secondary)',
                                    }"
                                    :data-testid="`dead-letter-conflict-${row.id}`"
                                >
                                    {{ copy.not_parked }}
                                </p>
                            </td>
                        </tr>
                        <tr v-if="openPayload === row.id" class="border-t-0">
                            <td colspan="5" class="px-3.5 pb-3">
                                <pre
                                    class="overflow-x-auto rounded-lg border p-3 font-mono text-[11px] leading-relaxed"
                                    :style="{
                                        background: 'var(--ds-inset-bg)',
                                        borderColor: 'var(--ds-border-soft)',
                                    }"
                                    :data-testid="`dead-letter-payload-${row.id}`"
                                    >{{ JSON.stringify(row.payload, null, 2) }}</pre>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <button
            v-if="next_cursor"
            type="button"
            class="mt-3 min-h-9 rounded-md border px-3.5 text-[12px] font-medium"
            :style="{ borderColor: 'var(--ds-border-strong)', color: 'var(--ds-text-secondary)' }"
            data-testid="dead-letters-load-more"
            @click="loadMore"
        >
            {{ copy.load_more }}
        </button>
    </BackofficeShell>
</template>
