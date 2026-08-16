<script setup lang="ts">
/**
 * B4-S02 — the full profile page (S08 step 11): the SAME panel content in a
 * wider frame (the left column IS the CustomerPanel fill — one brain, no
 * fork), the right column carrying what the slot could never hold: the
 * action surfaces. Frames:
 *   B4-S03 C01/C02 link (+C02-v2's out-of-scope refusal), C03/C04 unlink —
 *          both lists (what moves / what stays), the UNLINK token, the
 *          button repeating the count.
 *   B4-S04 merge — per-field radios grouped with BOTH values read in
 *          context (§13), the checklist as lists WITH counts, the MERGE
 *          token rendered BESIDE the input (both layouts), EC-12's refusal
 *          with the exact tickets. Reason fields on merge/unlink are the
 *          FILED divergence: the frames draw none, SEC-02's document wins.
 *   B4-S05 E04–E07 RG set/clear/history (Reason-Required drawn pattern —
 *          also D04–D06's class-2 fill source), F01–F05 block/unblock.
 *   [D17]  the Audit tab renders its seam for audit.view holders.
 */
import { onMounted, ref } from 'vue';
import CustomerPanel from '../../Components/Customers/CustomerPanel.vue';

const props = defineProps<{
    t: Record<string, string>;
    customerId: string;
    can: Record<string, boolean>;
}>();

function xsrf(): string {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    return match?.[1] !== undefined ? decodeURIComponent(match[1]) : '';
}
async function post(
    path: string,
    body: Record<string, unknown>,
): Promise<{ status: number; body: Record<string, unknown> }> {
    const response = await fetch(path, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-XSRF-TOKEN': xsrf(),
        },
        body: JSON.stringify(body),
    });
    return {
        status: response.status,
        body: (await response.json().catch(() => ({}))) as Record<string, unknown>,
    };
}
const fill = (template: string | undefined, vars: Record<string, string>): string => {
    let out = template ?? '';
    for (const [key, value] of Object.entries(vars)) out = out.replaceAll(':' + key, value);
    return out;
};

// ── Merge (B4-S04) ─────────────────────────────────────────────
const mergeOpen = ref(false);
const mergeQuery = ref('');
const mergeHits = ref<{ id: string; display_name: string | null }[]>([]);
const mergeTarget = ref<{ id: string; display_name: string | null } | null>(null);
const mergePreview = ref<{
    field_diff: Record<string, { survivor: unknown; merged: unknown }>;
    consequences: {
        conversations: number;
        tickets: number;
        notes: number;
        identities: number;
        linked_work_flagged: number;
    };
} | null>(null);
const mergeChoices = ref<Record<string, 'survivor' | 'merged'>>({});
const mergeReason = ref('');
const mergeToken = ref('');
const mergeError = ref('');
const mergeBlockedTickets = ref<string[]>([]);

async function searchMergeTargets(): Promise<void> {
    const response = await fetch(
        `/api/staff/customers/search?q=${encodeURIComponent(mergeQuery.value)}`,
        {
            headers: { Accept: 'application/json' },
        },
    );
    if (!response.ok) return;
    const data = (await response.json()) as { hits: { id: string; display_name: string | null }[] };
    mergeHits.value = data.hits.filter((hit) => hit.id !== props.customerId);
}
async function pickMergeTarget(hit: { id: string; display_name: string | null }): Promise<void> {
    mergeTarget.value = hit;
    mergeError.value = '';
    mergeBlockedTickets.value = [];
    const { status, body } = await post(`/api/staff/customers/${props.customerId}/merge-preview`, {
        merged_id: hit.id,
    });
    if (status === 409) {
        // EC-12: refused AT CONFIRM with the exact tickets listed.
        mergeError.value = String(body.detail ?? '');
        mergeBlockedTickets.value = (body.blocking_tickets as string[] | undefined) ?? [];
        mergePreview.value = null;
        return;
    }
    if (status !== 200) {
        mergeError.value = String(body.detail ?? status);
        return;
    }
    mergePreview.value = body as never;
    mergeChoices.value = {};
    for (const field of Object.keys((body as { field_diff: Record<string, unknown> }).field_diff)) {
        mergeChoices.value[field] = 'survivor';
    }
}
async function executeMerge(): Promise<void> {
    if (!mergeTarget.value) return;
    const { status, body } = await post(`/api/staff/customers/${props.customerId}/merge`, {
        merged_id: mergeTarget.value.id,
        choices: mergeChoices.value,
        reason: mergeReason.value,
        confirm: mergeToken.value,
    });
    if (status === 201) {
        window.location.reload(); // this page IS the survivor
        return;
    }
    mergeError.value = String(body.detail ?? status);
    mergeBlockedTickets.value = (body.blocking_tickets as string[] | undefined) ?? [];
}

// ── Unlink (B4-S03 C03/C04) ────────────────────────────────────
const identities = ref<
    { id: string; type: string | null; value: string | null; username: string | null }[]
>([]);
const unlinkTarget = ref<{ id: string; label: string } | null>(null);
const unlinkPreview = ref<{
    conversations: number;
    tickets: number;
    open_ticket_ids: string[];
} | null>(null);
const unlinkReason = ref('');
const unlinkToken = ref('');
const unlinkError = ref('');

onMounted(async () => {
    const response = await fetch(`/api/staff/customers/${props.customerId}/panel`, {
        headers: { Accept: 'application/json' },
    });
    if (!response.ok) return;
    const data = (await response.json()) as { identities: typeof identities.value };
    identities.value = data.identities;
});
async function openUnlink(identity: {
    id: string;
    value: string | null;
    username: string | null;
}): Promise<void> {
    unlinkTarget.value = { id: identity.id, label: identity.username ?? identity.value ?? '' };
    unlinkError.value = '';
    const response = await fetch(
        `/api/staff/customers/${props.customerId}/identities/${identity.id}/unlink-preview`,
        {
            headers: { Accept: 'application/json' },
        },
    );
    if (!response.ok) {
        unlinkError.value = String(response.status);
        return;
    }
    unlinkPreview.value = (await response.json()) as never;
}
async function executeUnlink(): Promise<void> {
    if (!unlinkTarget.value) return;
    const { status, body } = await post(
        `/api/staff/customers/${props.customerId}/identities/${unlinkTarget.value.id}/unlink`,
        { reason: unlinkReason.value, confirm: unlinkToken.value },
    );
    if (status === 200) {
        window.location.reload();
        return;
    }
    unlinkError.value = String(body.detail ?? status);
}

// ── Link (B4-S03 C01/C02 + C02-v2) ─────────────────────────────
const linkType = ref<'phone' | 'email'>('phone');
const linkValue = ref('');
const linkResult = ref('');
const linkOffer = ref<{ existing_customer_id: string } | null>(null);
async function linkIdentity(): Promise<void> {
    linkResult.value = '';
    linkOffer.value = null;
    const { status, body } = await post(`/api/staff/customers/${props.customerId}/identities`, {
        type: linkType.value,
        value: linkValue.value,
    });
    if (status === 409) {
        // FR-09's block + the merge-instead offer (C02; C02-v2: the id may
        // sit outside your scope — you learn it exists and nothing more).
        linkOffer.value = { existing_customer_id: String(body.existing_customer_id ?? '') };
        linkResult.value = String(body.detail ?? '');
        return;
    }
    linkResult.value = status === 201 || status === 200 ? 'linked' : String(body.detail ?? status);
    if (status === 201 || status === 200) window.location.reload();
}

// ── RG (B4-S05 E04–E07) ────────────────────────────────────────
const rg = ref<{
    current: { rg_flag: boolean } | null;
    history: { action: string; reason: string | null; created_at: string }[];
} | null>(null);
const rgReason = ref('');
const rgError = ref('');
async function loadRg(): Promise<void> {
    if (!props.can.rg_view) return;
    const response = await fetch(`/api/staff/customers/${props.customerId}/rg-flag`, {
        headers: { Accept: 'application/json' },
    });
    if (response.ok) rg.value = (await response.json()) as never;
}
onMounted(loadRg);
async function rgAct(action: 'set' | 'clear'): Promise<void> {
    rgError.value = '';
    const path = action === 'set' ? 'rg-flag' : 'rg-flag/clear';
    const { status, body } = await post(`/api/staff/customers/${props.customerId}/${path}`, {
        reason: rgReason.value,
    });
    if (status === 200 || status === 201) {
        rgReason.value = '';
        await loadRg();
        return;
    }
    rgError.value = String(body.detail ?? status);
}

// ── Block (B4-S05 F01–F05; D04–D06's Reason-Required fill) ─────
const blockReason = ref('');
const blockError = ref('');
async function blockAct(action: 'block' | 'unblock'): Promise<void> {
    blockError.value = '';
    const { status, body } = await post(`/api/staff/customers/${props.customerId}/${action}`, {
        reason: blockReason.value,
    });
    if (status === 200) {
        window.location.reload();
        return;
    }
    blockError.value = String(body.detail ?? status);
}

const tab = ref<'profile' | 'audit'>('profile');
</script>

<template>
    <main
        class="mx-auto max-w-5xl p-6 text-start"
        :style="{ color: 'var(--ds-text-primary)' }"
        data-testid="profile-page"
    >
        <div
            class="flex gap-3 border-b text-sm"
            :style="{ borderColor: 'var(--ds-border)' }"
            role="tablist"
        >
            <button
                type="button"
                role="tab"
                :aria-selected="tab === 'profile'"
                data-testid="profile-tab-main"
                class="pb-1"
                :style="{
                    color: tab === 'profile' ? 'var(--ds-text-primary)' : 'var(--ds-text-muted)',
                }"
                @click="tab = 'profile'"
            >
                {{ t.actions }}
            </button>
            <button
                v-if="can.audit_view"
                type="button"
                role="tab"
                :aria-selected="tab === 'audit'"
                data-testid="profile-tab-audit"
                class="pb-1"
                :style="{
                    color: tab === 'audit' ? 'var(--ds-text-primary)' : 'var(--ds-text-muted)',
                }"
                @click="tab = 'audit'"
            >
                {{ t.audit_tab }}
            </button>
        </div>

        <p
            v-if="tab === 'audit'"
            class="mt-3 text-sm"
            :style="{ color: 'var(--ds-text-muted)' }"
            data-testid="audit-seam"
        >
            {{ t.audit_seam }}
        </p>

        <div v-else class="mt-4 grid gap-6 lg:grid-cols-2">
            <!-- LEFT: the panel's own fill — one brain, wider frame (B4-S02). -->
            <section>
                <CustomerPanel :customer-id="customerId" :t="t" />
            </section>

            <!-- RIGHT: the action surfaces. -->
            <section class="space-y-5">
                <h2 class="text-sm font-semibold">{{ t.actions }}</h2>

                <!-- Link identity (C01/C02). -->
                <div v-if="can.identity_link" data-testid="link-identity">
                    <h3 class="text-xs font-semibold" :style="{ color: 'var(--ds-text-label)' }">
                        {{ t.link_identity }}
                    </h3>
                    <div class="mt-1 flex gap-1.5">
                        <select
                            v-model="linkType"
                            data-testid="link-type"
                            class="rounded border px-1.5 py-1 text-xs"
                            :style="{
                                borderColor: 'var(--ds-border-strong)',
                                background: 'var(--ds-panel-bg)',
                                color: 'var(--ds-text-primary)',
                            }"
                        >
                            <option value="phone">{{ t.type_phone }}</option>
                            <option value="email">{{ t.type_email }}</option>
                        </select>
                        <input
                            v-model="linkValue"
                            data-testid="link-value"
                            class="flex-1 rounded border px-2 py-1 text-xs"
                            :style="{
                                borderColor: 'var(--ds-border-strong)',
                                background: 'var(--ds-panel-bg)',
                                color: 'var(--ds-text-primary)',
                            }"
                        />
                        <button
                            type="button"
                            data-testid="link-submit"
                            class="rounded border px-2 py-1 text-xs"
                            :style="{ borderColor: 'var(--ds-border-strong)' }"
                            @click="linkIdentity"
                        >
                            {{ t.link_identity }}
                        </button>
                    </div>
                    <div
                        v-if="linkOffer"
                        data-testid="link-merge-offer"
                        class="mt-1 text-xs"
                        :style="{ color: 'var(--ds-text-muted)' }"
                    >
                        {{ linkResult }}
                        <a
                            :href="`/customers/${linkOffer.existing_customer_id}`"
                            class="underline"
                            :style="{ color: 'var(--ds-accent)' }"
                            >{{ linkOffer.existing_customer_id.slice(-8) }}</a
                        >
                    </div>
                    <p
                        v-else-if="linkResult"
                        class="mt-1 text-xs"
                        :style="{ color: 'var(--ds-text-muted)' }"
                    >
                        {{ linkResult }}
                    </p>
                </div>

                <!-- Unlink (C03/C04): both lists, the token, the counting button. -->
                <div v-if="can.identity_unlink" data-testid="unlink-identity">
                    <h3 class="text-xs font-semibold" :style="{ color: 'var(--ds-text-label)' }">
                        {{ t.unlink_title }}
                    </h3>
                    <ul class="mt-1 space-y-1">
                        <li
                            v-for="identity in identities"
                            :key="identity.id"
                            class="flex items-center gap-2 text-xs"
                        >
                            <span>{{ identity.username ?? identity.value }}</span>
                            <button
                                type="button"
                                :data-testid="'unlink-open-' + identity.id"
                                class="underline"
                                :style="{ color: 'var(--ds-accent)' }"
                                @click="openUnlink(identity)"
                            >
                                {{ t.unlink }}
                            </button>
                        </li>
                    </ul>
                    <div
                        v-if="unlinkTarget && unlinkPreview"
                        data-testid="unlink-dialog"
                        class="mt-2 rounded border p-2 text-xs"
                        :style="{ borderColor: 'var(--ds-border-strong)' }"
                    >
                        <p class="font-semibold">{{ t.unlink_title }}: {{ unlinkTarget.label }}</p>
                        <!-- C03: what moves — counts from the ONE move set. -->
                        <ul class="mt-1" data-testid="unlink-moves">
                            <li>{{ unlinkPreview.conversations }} conversations</li>
                            <li>{{ unlinkPreview.tickets }} tickets</li>
                        </ul>
                        <label class="mt-1 block" :style="{ color: 'var(--ds-text-label)' }">
                            {{ t.reason_label }}
                            <input
                                v-model="unlinkReason"
                                data-testid="unlink-reason"
                                class="mt-0.5 w-full rounded border px-2 py-1"
                                :style="{
                                    borderColor: 'var(--ds-border-strong)',
                                    background: 'var(--ds-panel-bg)',
                                    color: 'var(--ds-text-primary)',
                                }"
                            />
                        </label>
                        <!-- The token, RENDERED BESIDE the input (the B1-S04 phrase pattern). -->
                        <div class="mt-1 flex items-center gap-2">
                            <code
                                data-testid="unlink-token"
                                class="rounded border px-1.5 py-0.5"
                                :style="{ borderColor: 'var(--ds-border-strong)' }"
                                >UNLINK</code
                            >
                            <input
                                v-model="unlinkToken"
                                data-testid="unlink-confirm"
                                class="flex-1 rounded border px-2 py-1"
                                :style="{
                                    borderColor: 'var(--ds-border-strong)',
                                    background: 'var(--ds-panel-bg)',
                                    color: 'var(--ds-text-primary)',
                                }"
                            />
                        </div>
                        <button
                            type="button"
                            data-testid="unlink-execute"
                            class="mt-2 rounded border px-2 py-1"
                            :style="{ borderColor: 'var(--ds-border-strong)' }"
                            :disabled="unlinkToken !== 'UNLINK' || unlinkReason.trim().length < 5"
                            @click="executeUnlink"
                        >
                            {{ t.unlink }} · {{ unlinkPreview.tickets }}
                        </button>
                        <p v-if="unlinkError" :style="{ color: 'var(--ds-danger-strong)' }">
                            {{ unlinkError }}
                        </p>
                    </div>
                </div>

                <!-- Merge (B4-S04). -->
                <div v-if="can.merge" data-testid="merge-section">
                    <h3 class="text-xs font-semibold" :style="{ color: 'var(--ds-text-label)' }">
                        {{ t.merge_title }}
                    </h3>
                    <button
                        v-if="!mergeOpen"
                        type="button"
                        data-testid="merge-open"
                        class="mt-1 rounded border px-2 py-1 text-xs"
                        :style="{ borderColor: 'var(--ds-border-strong)' }"
                        @click="mergeOpen = true"
                    >
                        {{ t.merge_open }}
                    </button>
                    <div
                        v-else
                        class="mt-2 rounded border p-2 text-xs"
                        :style="{ borderColor: 'var(--ds-border-strong)' }"
                        data-testid="merge-dialog"
                    >
                        <label class="block" :style="{ color: 'var(--ds-text-label)' }">
                            {{ t.merge_pick }}
                            <div class="mt-0.5 flex gap-1.5">
                                <input
                                    v-model="mergeQuery"
                                    data-testid="merge-search"
                                    class="flex-1 rounded border px-2 py-1"
                                    :style="{
                                        borderColor: 'var(--ds-border-strong)',
                                        background: 'var(--ds-panel-bg)',
                                        color: 'var(--ds-text-primary)',
                                    }"
                                    @keydown.enter.prevent="searchMergeTargets"
                                />
                                <button
                                    type="button"
                                    data-testid="merge-search-go"
                                    class="rounded border px-2 py-1"
                                    :style="{ borderColor: 'var(--ds-border-strong)' }"
                                    @click="searchMergeTargets"
                                >
                                    ⌕
                                </button>
                            </div>
                        </label>
                        <ul v-if="mergeHits.length > 0 && !mergeTarget" class="mt-1 space-y-0.5">
                            <li v-for="hit in mergeHits" :key="hit.id">
                                <button
                                    type="button"
                                    :data-testid="'merge-pick-' + hit.id"
                                    class="underline"
                                    :style="{ color: 'var(--ds-accent)' }"
                                    @click="pickMergeTarget(hit)"
                                >
                                    {{ hit.display_name ?? hit.id.slice(-8) }}
                                </button>
                            </li>
                        </ul>

                        <!-- EC-12's refusal: the exact tickets, and nothing happened. -->
                        <div
                            v-if="mergeBlockedTickets.length > 0"
                            data-testid="merge-blocked"
                            role="alert"
                            class="mt-2"
                            :style="{ color: 'var(--ds-danger-strong)' }"
                        >
                            <p>{{ t.merge_blocked }}</p>
                            <ul>
                                <li v-for="id in mergeBlockedTickets" :key="id">{{ id }}</li>
                            </ul>
                        </div>

                        <template v-if="mergePreview && mergeTarget">
                            <!-- Per-field radios, grouped, BOTH values in context (§13). -->
                            <fieldset
                                v-for="(pair, field) in mergePreview.field_diff"
                                :key="field"
                                class="mt-2"
                                :data-testid="'merge-field-' + field"
                            >
                                <legend class="font-semibold">
                                    {{ t[String(field)] ?? field }}
                                </legend>
                                <label class="block">
                                    <input
                                        v-model="mergeChoices[field]"
                                        type="radio"
                                        value="survivor"
                                        :name="'field-' + field"
                                    />
                                    {{
                                        fill(t.merge_keep, {
                                            label: String(t[String(field)] ?? field),
                                            survivor: String(pair.survivor ?? '—'),
                                            merged: String(pair.merged ?? '—'),
                                        }).split(', or')[0]
                                    }}
                                </label>
                                <label class="block">
                                    <input
                                        v-model="mergeChoices[field]"
                                        type="radio"
                                        value="merged"
                                        :name="'field-' + field"
                                    />
                                    or "{{ String(pair.merged ?? '—') }}" from merged
                                </label>
                            </fieldset>

                            <!-- The checklist: lists WITH counts (§13). -->
                            <ul
                                class="mt-2"
                                data-testid="merge-checklist"
                                :aria-label="t.merge_checklist"
                            >
                                <li>
                                    {{
                                        fill(t.merge_conversations, {
                                            count: String(mergePreview.consequences.conversations),
                                        })
                                    }}
                                </li>
                                <li>
                                    {{
                                        fill(t.merge_tickets, {
                                            count: String(mergePreview.consequences.tickets),
                                        })
                                    }}
                                </li>
                                <li>
                                    {{
                                        fill(t.merge_notes, {
                                            count: String(mergePreview.consequences.notes),
                                        })
                                    }}
                                </li>
                                <li>
                                    {{
                                        fill(t.merge_identities, {
                                            count: String(mergePreview.consequences.identities),
                                        })
                                    }}
                                </li>
                                <li v-if="mergePreview.consequences.linked_work_flagged > 0">
                                    {{
                                        fill(t.merge_linked_work, {
                                            count: String(
                                                mergePreview.consequences.linked_work_flagged,
                                            ),
                                        })
                                    }}
                                </li>
                            </ul>

                            <!-- SEC-02: the reason (the filed frames-draw-none divergence). -->
                            <label class="mt-2 block" :style="{ color: 'var(--ds-text-label)' }">
                                {{ t.reason_label }}
                                <input
                                    v-model="mergeReason"
                                    data-testid="merge-reason"
                                    class="mt-0.5 w-full rounded border px-2 py-1"
                                    :style="{
                                        borderColor: 'var(--ds-border-strong)',
                                        background: 'var(--ds-panel-bg)',
                                        color: 'var(--ds-text-primary)',
                                    }"
                                />
                            </label>
                            <!-- B4-S04: the MERGE token BESIDE the input, both layouts. -->
                            <div class="mt-1 flex items-center gap-2">
                                <code
                                    data-testid="merge-token"
                                    class="rounded border px-1.5 py-0.5"
                                    :style="{ borderColor: 'var(--ds-border-strong)' }"
                                    >MERGE</code
                                >
                                <input
                                    v-model="mergeToken"
                                    data-testid="merge-confirm"
                                    class="flex-1 rounded border px-2 py-1"
                                    :style="{
                                        borderColor: 'var(--ds-border-strong)',
                                        background: 'var(--ds-panel-bg)',
                                        color: 'var(--ds-text-primary)',
                                    }"
                                    :aria-label="t.merge_token_hint"
                                />
                            </div>
                            <button
                                type="button"
                                data-testid="merge-execute"
                                class="mt-2 rounded border px-2 py-1"
                                :style="{ borderColor: 'var(--ds-border-strong)' }"
                                :disabled="mergeToken !== 'MERGE' || mergeReason.trim().length < 5"
                                @click="executeMerge"
                            >
                                {{ t.merge_title }}
                            </button>
                        </template>
                        <p
                            v-if="mergeError && mergeBlockedTickets.length === 0"
                            :style="{ color: 'var(--ds-danger-strong)' }"
                        >
                            {{ mergeError }}
                        </p>
                    </div>
                </div>

                <!-- RG (E04–E07): reason-required both ways; history whole. -->
                <div v-if="can.rg_view" data-testid="rg-section">
                    <h3 class="text-xs font-semibold" :style="{ color: 'var(--ds-text-label)' }">
                        {{ t.rg_title }}
                    </h3>
                    <template v-if="can.rg_set">
                        <label
                            class="mt-1 block text-xs"
                            :style="{ color: 'var(--ds-text-label)' }"
                        >
                            {{ t.reason_label }}
                            <input
                                v-model="rgReason"
                                data-testid="rg-reason"
                                class="mt-0.5 w-full rounded border px-2 py-1 text-xs"
                                :style="{
                                    borderColor: 'var(--ds-border-strong)',
                                    background: 'var(--ds-panel-bg)',
                                    color: 'var(--ds-text-primary)',
                                }"
                            />
                        </label>
                        <div class="mt-1 flex gap-1.5">
                            <button
                                type="button"
                                data-testid="rg-set"
                                class="rounded border px-2 py-1 text-xs"
                                :style="{ borderColor: 'var(--ds-border-strong)' }"
                                :disabled="rgReason.trim().length < 5"
                                @click="rgAct('set')"
                            >
                                {{ t.rg_set }}
                            </button>
                            <button
                                type="button"
                                data-testid="rg-clear"
                                class="rounded border px-2 py-1 text-xs"
                                :style="{ borderColor: 'var(--ds-border-strong)' }"
                                :disabled="rgReason.trim().length < 5"
                                @click="rgAct('clear')"
                            >
                                {{ t.rg_clear }}
                            </button>
                        </div>
                        <p
                            v-if="rgError"
                            class="text-xs"
                            :style="{ color: 'var(--ds-danger-strong)' }"
                        >
                            {{ rgError }}
                        </p>
                    </template>
                    <!-- E07: the history, never deleted. -->
                    <div
                        v-if="rg && rg.history.length > 0"
                        data-testid="rg-history"
                        class="mt-2 text-xs"
                    >
                        <p class="font-semibold" :style="{ color: 'var(--ds-text-label)' }">
                            {{ t.rg_history }}
                        </p>
                        <ul>
                            <li v-for="(row, index) in rg.history" :key="index">
                                {{ row.action }} · {{ row.reason }} ·
                                {{ row.created_at.slice(0, 10) }}
                            </li>
                        </ul>
                    </div>
                </div>

                <!-- Blocking (F01–F05; the D04–D06 Reason-Required fill). -->
                <div v-if="can.block" data-testid="block-section">
                    <h3 class="text-xs font-semibold" :style="{ color: 'var(--ds-text-label)' }">
                        {{ t.block_title }}
                    </h3>
                    <label class="mt-1 block text-xs" :style="{ color: 'var(--ds-text-label)' }">
                        {{ t.reason_label }}
                        <input
                            v-model="blockReason"
                            data-testid="block-reason"
                            class="mt-0.5 w-full rounded border px-2 py-1 text-xs"
                            :style="{
                                borderColor: 'var(--ds-border-strong)',
                                background: 'var(--ds-panel-bg)',
                                color: 'var(--ds-text-primary)',
                            }"
                        />
                    </label>
                    <div class="mt-1 flex gap-1.5">
                        <button
                            type="button"
                            data-testid="block-execute"
                            class="rounded border px-2 py-1 text-xs"
                            :style="{
                                borderColor: 'var(--ds-danger-strong)',
                                color: 'var(--ds-danger-strong)',
                            }"
                            :disabled="blockReason.trim().length < 5"
                            @click="blockAct('block')"
                        >
                            {{ t.block }}
                        </button>
                        <button
                            type="button"
                            data-testid="unblock-execute"
                            class="rounded border px-2 py-1 text-xs"
                            :style="{ borderColor: 'var(--ds-border-strong)' }"
                            :disabled="blockReason.trim().length < 5"
                            @click="blockAct('unblock')"
                        >
                            {{ t.unblock }}
                        </button>
                    </div>
                    <p
                        v-if="blockError"
                        class="text-xs"
                        :style="{ color: 'var(--ds-danger-strong)' }"
                    >
                        {{ blockError }}
                    </p>
                </div>
            </section>
        </div>
    </main>
</template>
