<script setup lang="ts">
/**
 * Doc 08 FR-21 — the Customer slot fill (S08 step 10, escalation #3 part 2's
 * slot pass-through; the component itself is Doc 08's own file). v1 renders
 * the T-07 contract honestly: header + identities immediately, sections as
 * independent fetches, the fixed section order (header → identities →
 * betting → history/rollups → recent tickets → notes/files tabs). The
 * 12-frame fidelity pass (inline edit, popovers, announce cycle — AC-08,
 * AC-B4-03) continues on this file within step 10.
 *
 * RG discipline rides the API: rg keys PRESENT ⟺ this viewer is permitted
 * (absence, never redaction) — the component renders what arrives and never
 * infers. The blocked banner is red (§8); the RG banner is slate (§9) —
 * "the two states look different on purpose" (B4-S05).
 */
import { ref, watch } from 'vue';

type Identity = {
    id: string;
    type: string | null;
    value: string | null;
    source: string | null;
    first_seen_at: string | null;
    display: string | null;
    username: string | null;
    username_history: { old: string; changed_at: string | null }[];
};

type Header = Record<string, unknown> & {
    id?: string;
    display_name?: string | null;
    blocked?: boolean;
    blocked_reason?: string | null;
    rg_flag?: boolean;
    tickets_count?: number;
    open_tickets?: number;
    satisfaction_avg?: string | number | null;
    satisfaction_count?: number;
    last_contact_at?: string | null;
    merged_into_id?: string | null;
};

const props = defineProps<{
    customerId: string | null;
    t: Record<string, string>;
}>();

const loading = ref(false);
const failed = ref(false);
const header = ref<Header | null>(null);
const identities = ref<Identity[]>([]);
const tickets = ref<{ id: string; number: string; subject: string | null; status: string }[]>([]);

async function load(): Promise<void> {
    if (props.customerId === null) {
        header.value = null;
        identities.value = [];
        tickets.value = [];
        return;
    }
    loading.value = true;
    failed.value = false;
    try {
        const panel = await fetch(`/api/staff/customers/${props.customerId}/panel`, {
            headers: { Accept: 'application/json' },
        });
        if (!panel.ok) throw new Error(String(panel.status));
        const data = (await panel.json()) as { header: Header; identities: Identity[] };
        header.value = data.header;
        identities.value = data.identities;

        // T-07: sections fetch independently — recent tickets is v1's eager one.
        const recent = await fetch(`/api/staff/customers/${props.customerId}/panel/tickets`, {
            headers: { Accept: 'application/json' },
        });
        if (recent.ok) {
            tickets.value = ((await recent.json()) as { tickets: typeof tickets.value }).tickets;
        }
    } catch {
        failed.value = true;
    } finally {
        loading.value = false;
    }
}

watch(() => props.customerId, load, { immediate: true });

function displayName(): string {
    const name = header.value?.display_name;
    if (typeof name === 'string' && name !== '') return name;
    const first = identities.value[0];
    if (first?.display) return first.display;
    if (first?.username) return first.username;
    return (props.t.no_name ?? '').replace(':short', String(header.value?.id ?? '').slice(-4));
}
</script>

<template>
    <div data-testid="customer-panel-fill">
        <p v-if="customerId === null" class="text-sm" :style="{ color: 'var(--ds-text-muted)' }">
            {{ t.empty }}
        </p>
        <p v-else-if="loading" class="text-sm" :style="{ color: 'var(--ds-text-muted)' }">
            {{ t.loading }}
        </p>
        <div v-else-if="failed" class="text-sm" :style="{ color: 'var(--ds-text-muted)' }">
            {{ t.error }}
            <button
                type="button"
                class="ms-1 underline"
                :style="{ color: 'var(--ds-accent)' }"
                @click="load"
            >
                {{ t.retry }}
            </button>
        </div>
        <div v-else-if="header" class="space-y-3">
            <!-- Header: name + the two banners ("different on purpose"). -->
            <section :aria-label="t.identities">
                <h3 class="text-sm font-semibold" :style="{ color: 'var(--ds-text-primary)' }">
                    {{ displayName() }}
                </h3>
                <div
                    v-if="header.blocked"
                    data-testid="customer-blocked-banner"
                    class="mt-2 rounded-md border px-3 py-2 text-xs"
                    :style="{
                        background: 'var(--ds-danger-bg)',
                        borderColor: 'var(--ds-danger-strong)',
                        color: 'var(--ds-danger-strong)',
                    }"
                >
                    {{ (t.blocked_banner ?? '').replace(':reason', String(header.blocked_reason ?? '')) }}
                </div>
                <div
                    v-if="header.rg_flag === true"
                    data-testid="customer-rg-banner"
                    role="note"
                    class="mt-2 rounded-md border px-3 py-2 text-xs"
                    :style="{
                        background: 'var(--ds-status-closed-bg)',
                        borderColor: 'var(--ds-border-strong)',
                        color: 'var(--ds-status-closed-fg)',
                    }"
                >
                    {{ t.rg_banner }}
                </div>
            </section>

            <!-- Identities with provenance, ALWAYS (FR-05). -->
            <section :aria-label="t.identities">
                <h4 class="text-xs font-semibold" :style="{ color: 'var(--ds-text-label)' }">
                    {{ t.identities }}
                </h4>
                <ul class="mt-1 space-y-1">
                    <li
                        v-for="identity in identities"
                        :key="identity.id"
                        class="text-xs"
                        :style="{ color: 'var(--ds-text-primary)', unicodeBidi: 'isolate' }"
                    >
                        <span>{{ identity.username ?? identity.value }}</span>
                        <span class="ms-1" :style="{ color: 'var(--ds-text-muted)' }">
                            {{
                                identity.source === 'staff'
                                    ? t.by_staff
                                    : (t.from_channel ?? '').replace(':channel', String(identity.type ?? ''))
                            }}
                        </span>
                    </li>
                    <li
                        v-if="identities.length === 0"
                        class="text-xs"
                        :style="{ color: 'var(--ds-text-muted)' }"
                    >
                        {{ t.unreachable }}
                    </li>
                </ul>
            </section>

            <!-- History = the rollups card (B4-S05's own decode). -->
            <section :aria-label="t.history">
                <h4 class="text-xs font-semibold" :style="{ color: 'var(--ds-text-label)' }">
                    {{ t.history }}
                </h4>
                <p class="mt-1 text-xs" :style="{ color: 'var(--ds-text-primary)' }">
                    {{
                        (t.tickets_line ?? '')
                            .replace(':count', String(header.tickets_count ?? 0))
                            .replace(':open', String(header.open_tickets ?? 0))
                    }}
                </p>
            </section>

            <!-- Recent tickets (5, link-through lands with the profile page). -->
            <section :aria-label="t.recent_tickets">
                <h4 class="text-xs font-semibold" :style="{ color: 'var(--ds-text-label)' }">
                    {{ t.recent_tickets }}
                </h4>
                <ul class="mt-1 space-y-1">
                    <li
                        v-for="ticket in tickets"
                        :key="ticket.id"
                        class="text-xs"
                        :style="{ color: 'var(--ds-text-primary)' }"
                    >
                        #{{ ticket.number }} · {{ ticket.subject ?? '—' }}
                    </li>
                </ul>
            </section>
        </div>
    </div>
</template>
