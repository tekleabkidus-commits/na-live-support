<script setup lang="ts">
// B2-S03 (S07 step 14) — the R4 Ticket tab's property panel, filling the
// slot step 8 left honest. This unit is B9: the status control with the
// TWO-LEVEL pending-reason selector, posting {to, pending_reason} to the
// countersigned transition door (ruled core edit #6) — never beside it.
// The reason values ARE Doc 06 FR-01's enum; the second level exists only
// under "awaiting internal", so the common case stays one tap (the
// frame's own words). Refusals render INLINE in the panel (the B2-S06
// posture applied to this surface): the C-04 stable detail, in place,
// never a toast that outlives the context.
import { computed, ref, watch } from 'vue';
import TicketTimeline from './TicketTimeline.vue';

type PanelTicket = {
    id: string;
    number: number | string;
    status: string;
    priority?: string;
    pending_reason?: string | null;
    assignee_id?: string | null;
    assignee_name?: string | null;
    tags?: string[];
    subject?: string | null;
    subject_source?: string | null;
    subject_was_ai?: boolean;
    reopened_count?: number;
    channel_unreachable?: boolean;
};

const props = defineProps<{
    ticket: PanelTicket | null;
    conversationId: string | null;
    can: Record<string, boolean>;
    t: Record<string, string>;
    xsrf: () => string;
    // D3 (SS-04): the per-viewer legal-target map — matrix ∩ capability,
    // computed server-side from the matrix's own table. Absence is the
    // render rule; the refused strip stays the race surface.
    transitions: Record<string, string[]>;
}>();

const emit = defineEmits<{
    // The workspace owns the stores; a landed change refetches view+thread.
    (e: 'changed'): void;
}>();

// ── B9 state ────────────────────────────────────────────────────────────
// The selector opens when the agent picks "Pending"; level 1 defaults to
// the drawn default (awaiting customer); level 2 renders only under
// awaiting internal.
const pendingOpen = ref(false);
const level1 = ref<'customer' | 'internal'>('customer');
const level2 = ref<'department' | 'named_user' | 'external'>('department');
const busy = ref(false);
const timelineTick = ref(0);

// ── B13 subject state ───────────────────────────────────────────────────
const editingSubject = ref(false);
const subjectDraft = ref('');
const subjectLabel = computed(() => {
    const source = props.ticket?.subject_source ?? null;
    if (source === 'ai') return props.t.written_by_copilot ?? '';
    if (source === 'staff') {
        return props.ticket?.subject_was_ai
            ? (props.t.copilot_edited_by_staff ?? '')
            : (props.t.edited_by_staff ?? '');
    }
    return '';
});
function startSubjectEdit(): void {
    subjectDraft.value = props.ticket?.subject ?? '';
    editingSubject.value = true;
}
async function saveSubject(): Promise<void> {
    if (busy.value || props.ticket === null) return;
    busy.value = true;
    refusal.value = '';
    try {
        const response = await fetch(`/inbox/t/${props.ticket.id}/subject`, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': props.xsrf(),
            },
            body: JSON.stringify({ subject: subjectDraft.value }),
        });
        if (response.ok) {
            editingSubject.value = false;
            timelineTick.value += 1; // B12 gains a Subject-set row
            emit('changed');
            return;
        }
        const body = (await response.json().catch(() => null)) as { detail?: string } | null;
        refusal.value = body?.detail ?? (props.t.refused ?? '');
    } finally {
        busy.value = false;
    }
}
// INLINE refusal (B2-S06 posture): the stable detail renders in place.
const refusal = ref('');

watch(
    () => props.ticket?.id,
    () => {
        pendingOpen.value = false;
        refusal.value = '';
    },
);

const status = computed(() => props.ticket?.status ?? '');
// D3: what THIS viewer may do from HERE — the drawn control renders only
// these; escalate/spam ride their own operation surfaces (B1-S04).
const legalTargets = computed(() => props.transitions[status.value] ?? []);
const showPending = computed(
    () =>
        legalTargets.value.includes('pending_customer') ||
        legalTargets.value.includes('pending_internal'),
);
const showOpen = computed(() => legalTargets.value.includes('open'));
// From closed/resolved the open target IS the reopen act — the drawn label.
const openLabel = computed(() =>
    status.value === 'closed' || status.value === 'resolved'
        ? (props.t.reopen ?? props.t.status_open ?? '')
        : (props.t.status_open ?? ''),
);
const showResolved = computed(() => legalTargets.value.includes('resolved'));
const isPending = computed(
    () => status.value === 'pending_customer' || status.value === 'pending_internal',
);

const pendingReasonLabel = computed(() => {
    const reason = props.ticket?.pending_reason ?? null;
    if (reason === null) return '';
    return props.t['reason_' + reason.replace(':', '_')] ?? reason;
});

function composedReason(): string {
    return level1.value === 'customer' ? 'awaiting_customer' : 'awaiting_internal:' + level2.value;
}

async function post(to: string, pendingReason: string | null): Promise<void> {
    if (busy.value || props.conversationId === null) return;
    busy.value = true;
    refusal.value = '';
    try {
        const response = await fetch(`/inbox/thread/${props.conversationId}/transition`, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': props.xsrf(),
            },
            body: JSON.stringify(
                pendingReason === null ? { to } : { to, pending_reason: pendingReason },
            ),
        });
        if (response.ok) {
            pendingOpen.value = false;
            timelineTick.value += 1; // the relay will have a new row for B12
            emit('changed');
            return;
        }
        const body = (await response.json().catch(() => null)) as { detail?: string } | null;
        refusal.value = body?.detail ?? props.t.refused ?? '';
    } finally {
        busy.value = false;
    }
}

function setPending(): void {
    const to = level1.value === 'customer' ? 'pending_customer' : 'pending_internal';
    void post(to, composedReason());
}

const initials = computed(() => {
    const name = props.ticket?.assignee_name ?? '';
    return name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0] ?? '')
        .join('');
});
</script>

<template>
    <div v-if="ticket === null" data-testid="ticket-panel-empty">
        <p class="text-sm" :style="{ color: 'var(--ds-text-muted)' }">{{ t.empty }}</p>
    </div>
    <div v-else data-testid="ticket-panel" class="flex flex-col gap-3">
        <!-- Header: the drawn "Ticket #4812" row. -->
        <div class="flex items-baseline gap-2">
            <span class="text-xs font-semibold" :style="{ color: 'var(--ds-text-muted)' }">{{
                t.title
            }}</span>
            <span class="text-sm font-semibold" data-testid="ticket-panel-number"
                >#{{ ticket.number }}</span
            >
        </div>

        <!-- B13: the subject field — the source label is a FACT about the
             field, not decoration (the frame's words); it survives edits as
             "Copilot draft, edited by staff". -->
        <div data-testid="panel-subject">
            <p class="mb-1 text-xs" :style="{ color: 'var(--ds-text-muted)' }">
                {{ t.subject_label }}
            </p>
            <template v-if="!editingSubject">
                <p class="text-xs" data-testid="panel-subject-text">
                    <span v-if="ticket.subject">{{ ticket.subject }}</span>
                    <span v-else :style="{ color: 'var(--ds-text-muted)' }">{{
                        t.subject_none
                    }}</span>
                </p>
                <div class="mt-0.5 flex items-center gap-2">
                    <span
                        v-if="subjectLabel !== ''"
                        class="text-[11px]"
                        data-testid="panel-subject-source"
                        :style="{ color: 'var(--ds-ai-fg)' }"
                        >◆ {{ subjectLabel }}</span
                    >
                    <button
                        v-if="can.tickets_edit"
                        type="button"
                        data-testid="panel-subject-edit"
                        class="text-[11px] underline"
                        :style="{ color: 'var(--ds-text-secondary)' }"
                        @click="startSubjectEdit"
                    >
                        {{ t.edit }}
                    </button>
                </div>
            </template>
            <template v-else>
                <input
                    v-model="subjectDraft"
                    type="text"
                    maxlength="120"
                    data-testid="panel-subject-input"
                    :aria-label="t.subject_label"
                    class="w-full rounded-md border px-2 py-1 text-xs"
                    :style="{ borderColor: 'var(--ds-border)' }"
                />
                <div class="mt-1 flex items-center gap-2">
                    <button
                        type="button"
                        data-testid="panel-subject-save"
                        class="rounded-md px-2.5 py-1 text-xs font-semibold"
                        :style="{ background: 'var(--ds-text-ink)', color: 'var(--ds-page-bg)' }"
                        :disabled="busy"
                        @click="void saveSubject()"
                    >
                        {{ t.save }}
                    </button>
                    <button
                        type="button"
                        data-testid="panel-subject-cancel"
                        class="rounded-md border px-2.5 py-1 text-xs"
                        :style="{ borderColor: 'var(--ds-border)' }"
                        @click="editingSubject = false"
                    >
                        {{ t.cancel }}
                    </button>
                    <span
                        class="ms-auto text-[11px]"
                        data-testid="panel-subject-counter"
                        :style="{ color: 'var(--ds-text-muted)' }"
                        >{{ subjectDraft.length }} / 120</span
                    >
                </div>
            </template>
        </div>

        <!-- Status control (B9: Pending / Open / Resolved). -->
        <div v-if="can.tickets_status_change" role="group" :aria-label="t.status_label">
            <p class="mb-1 text-xs" :style="{ color: 'var(--ds-text-muted)' }">
                {{ t.status_label }}
            </p>
            <div class="flex gap-1">
                <button
                    v-if="showPending"
                    type="button"
                    data-testid="panel-status-pending"
                    class="rounded-md border px-2 py-1 text-xs"
                    :aria-pressed="isPending || pendingOpen"
                    :style="{
                        borderColor:
                            isPending || pendingOpen ? 'var(--ds-accent)' : 'var(--ds-border)',
                        fontWeight: isPending || pendingOpen ? 600 : 400,
                    }"
                    @click="pendingOpen = !pendingOpen"
                >
                    {{ t.status_pending }}<span v-if="isPending" aria-hidden="true"> ✓</span>
                </button>
                <button
                    v-if="showOpen"
                    type="button"
                    data-testid="panel-status-open"
                    class="rounded-md border px-2 py-1 text-xs"
                    :aria-pressed="status === 'open'"
                    :style="{
                        borderColor: status === 'open' ? 'var(--ds-accent)' : 'var(--ds-border)',
                        fontWeight: status === 'open' ? 600 : 400,
                    }"
                    @click="void post('open', null)"
                >
                    {{ openLabel }}
                </button>
                <button
                    v-if="showResolved"
                    type="button"
                    data-testid="panel-status-resolved"
                    class="rounded-md border px-2 py-1 text-xs"
                    :aria-pressed="status === 'resolved'"
                    :style="{
                        borderColor:
                            status === 'resolved' ? 'var(--ds-accent)' : 'var(--ds-border)',
                        fontWeight: status === 'resolved' ? 600 : 400,
                    }"
                    @click="void post('resolve', null)"
                >
                    {{ t.status_resolved }}
                </button>
            </div>
        </div>

        <!-- The current reason, rendered when pending (panel truth). -->
        <p
            v-if="isPending && pendingReasonLabel !== ''"
            data-testid="panel-pending-reason"
            class="text-xs"
            :style="{ color: 'var(--ds-text-secondary)' }"
        >
            {{ (t.reason_current ?? '').replace(':reason', pendingReasonLabel) }}
        </p>

        <!-- B9: the two-level "Pending because" selector. -->
        <div
            v-if="pendingOpen"
            data-testid="pending-selector"
            class="rounded-md border p-2"
            :style="{ borderColor: 'var(--ds-border)' }"
        >
            <p class="mb-1 text-xs font-semibold">{{ t.pending_because }}</p>
            <div role="radiogroup" :aria-label="t.pending_because" class="flex flex-col gap-1">
                <label class="flex items-center gap-2 text-xs">
                    <input
                        v-model="level1"
                        type="radio"
                        value="customer"
                        data-testid="pending-awaiting-customer"
                    />
                    {{ t.awaiting_customer }}
                </label>
                <label class="flex items-center gap-2 text-xs">
                    <input
                        v-model="level1"
                        type="radio"
                        value="internal"
                        data-testid="pending-awaiting-internal"
                    />
                    {{ t.awaiting_internal }}
                </label>
            </div>

            <!-- The second level exists ONLY under awaiting internal. -->
            <div
                v-if="level1 === 'internal'"
                data-testid="pending-waiting-on"
                class="mt-2 border-s ps-2"
                :style="{ borderColor: 'var(--ds-border-soft)' }"
            >
                <p class="mb-1 text-xs font-semibold">{{ t.waiting_on }}</p>
                <div role="radiogroup" :aria-label="t.waiting_on" class="flex flex-col gap-1">
                    <label class="flex items-center gap-2 text-xs">
                        <input
                            v-model="level2"
                            type="radio"
                            value="department"
                            data-testid="waiting-department"
                        />
                        {{ t.waiting_department }}
                    </label>
                    <label class="flex items-center gap-2 text-xs">
                        <input
                            v-model="level2"
                            type="radio"
                            value="named_user"
                            data-testid="waiting-named-user"
                        />
                        {{ t.waiting_named_user }}
                    </label>
                    <label class="flex items-center gap-2 text-xs">
                        <input
                            v-model="level2"
                            type="radio"
                            value="external"
                            data-testid="waiting-external"
                        />
                        {{ t.waiting_external }}
                    </label>
                </div>
                <p class="mt-1 text-[11px]" :style="{ color: 'var(--ds-text-muted)' }">
                    {{ t.internal_hint }}
                </p>
            </div>

            <!-- The drawn SLA-clock explainer. -->
            <p class="mt-2 text-[11px]" :style="{ color: 'var(--ds-text-muted)' }">
                {{ t.sla_hint }}
            </p>

            <div class="mt-2 flex gap-2">
                <button
                    type="button"
                    data-testid="pending-set"
                    class="rounded-md px-2.5 py-1 text-xs font-semibold"
                    :style="{ background: 'var(--ds-text-ink)', color: 'var(--ds-page-bg)' }"
                    :disabled="busy"
                    @click="setPending"
                >
                    {{ t.set_pending }}
                </button>
                <button
                    type="button"
                    data-testid="pending-cancel"
                    class="rounded-md border px-2.5 py-1 text-xs"
                    :style="{ borderColor: 'var(--ds-border)' }"
                    @click="pendingOpen = false"
                >
                    {{ t.cancel }}
                </button>
            </div>
        </div>

        <!-- INLINE refusal (B2-S06 posture): stable detail, in place. -->
        <p
            v-if="refusal !== ''"
            data-testid="panel-refusal"
            role="alert"
            class="rounded-md border px-2 py-1 text-xs"
            :style="{
                borderColor: 'var(--ds-danger-strong)',
                color: 'var(--ds-danger-strong)',
            }"
        >
            {{ refusal }}
        </p>

        <!-- Assignee row (display; the edit affordance is the palettes'). -->
        <div>
            <p class="mb-1 text-xs" :style="{ color: 'var(--ds-text-muted)' }">
                {{ t.assignee_label }}
            </p>
            <div
                v-if="ticket.assignee_name"
                class="flex items-center gap-2"
                data-testid="panel-assignee"
            >
                <span
                    aria-hidden="true"
                    class="inline-flex h-6 w-6 items-center justify-center rounded-full text-[10px] font-semibold"
                    :style="{ background: 'var(--ds-row-hover)' }"
                    >{{ initials }}</span
                >
                <span class="text-xs">{{ ticket.assignee_name }}</span>
            </div>
            <p v-else class="text-xs" :style="{ color: 'var(--ds-text-muted)' }">
                {{ t.unassigned }}
            </p>
        </div>

        <!-- B12: the timeline, newest first, over the living relay. -->
        <TicketTimeline :ticket-id="ticket.id" :t="t" :refresh-tick="timelineTick" />
    </div>
</template>
