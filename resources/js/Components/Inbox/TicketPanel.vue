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
    department_id?: string | null;
    sla_state?: string | null;
    sla_due_at?: string | null;
    window_expires_at?: string | null;
    merged_into_number?: number | string | null;
    origin_ticket_number?: number | string | null;
    final_survivor_number?: number | string | null;
    final_survivor_conversation_id?: string | null;
};

const props = defineProps<{
    ticket: PanelTicket | null;
    conversationId: string | null;
    can: Record<string, boolean>;
    t: Record<string, string>;
    xsrf: () => string;
    loading?: boolean;
    loadTick?: number;
    // D3 (SS-04): the per-viewer legal-target map — matrix ∩ capability,
    // computed server-side from the matrix's own table. Absence is the
    // render rule; the refused strip stays the race surface.
    transitions: Record<string, string[]>;
}>();

const emit = defineEmits<{
    // The workspace owns the stores; a landed change refetches view+thread.
    (e: 'changed'): void;
    // D7: the lost race offers the next unassigned ticket instead.
    (e: 'claim-next'): void;
    // D12: the merged banner opens the FINAL survivor.
    (e: 'open-survivor', conversationId: string): void;
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
// D2: the optimistic marker — the clicked target shows 'saving…' until the
// server answers; a refusal SNAPS BACK to truth (the refetch re-renders the
// real value; the strip explains; the control never sits lying).
const pendingTarget = ref<string | null>(null);
const refusalPutBack = ref(false);
// D5: the inline claim — an unassigned ticket offers "Assign to me" right
// on the row (the drawn empty says what fills it and offers the act).
const claiming = ref(false);
// D7/D8/D9/D10 race+system surfaces
const claimLost = ref(false);
const noopStrip = ref(false);
const newestEvent = ref('');
async function assignToMe(): Promise<void> {
    if (claiming.value || props.ticket === null) return;
    claiming.value = true;
    refusal.value = '';
    try {
        const response = await fetch(`/inbox/t/${props.ticket.id}/claim`, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': props.xsrf(),
            },
        });
        if (response.ok) {
            timelineTick.value += 1;
            emit('changed');
            return;
        }
        const body = (await response.json().catch(() => null)) as { detail?: string } | null;
        refusal.value = body?.detail ?? props.t.refused ?? '';
        // D7: no dialog — the avatar swaps in place via the refetch, the
        // words explain, and the next move is offered.
        claimLost.value = response.status === 409;
        emit('changed');
    } finally {
        claiming.value = false;
    }
}

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
        refusal.value = body?.detail ?? props.t.refused ?? '';
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
        claimLost.value = false;
        noopStrip.value = false;
        newestEvent.value = '';
    },
);

const status = computed(() => props.ticket?.status ?? '');
// D3: what THIS viewer may do from HERE — the drawn control renders only
// these; escalate/spam ride their own operation surfaces (B1-S04).
const legalTargets = computed(() => props.transitions[status.value] ?? []);
// The drawn control shows the CURRENT state ✓-marked ALONGSIDE the legal
// targets ("Closed ✓ | Reopen") — clicking the current one is EC-07's
// documented noop, which D8 says quietly. Absent stays absent.
const showPending = computed(
    () =>
        isPending.value ||
        legalTargets.value.includes('pending_customer') ||
        legalTargets.value.includes('pending_internal'),
);
const showOpen = computed(() => status.value === 'open' || legalTargets.value.includes('open'));
// From closed/resolved the open target IS the reopen act — the drawn label.
const openLabel = computed(() =>
    status.value === 'closed' || status.value === 'resolved'
        ? (props.t.reopen ?? props.t.status_open ?? '')
        : (props.t.status_open ?? ''),
);
const showResolved = computed(
    () => status.value === 'resolved' || legalTargets.value.includes('resolved'),
);
function statusLabel(value: string): string {
    if (value === 'pending_customer' || value === 'pending_internal') {
        return props.t.status_pending ?? value;
    }
    return props.t['status_' + value] ?? value;
}
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
    refusalPutBack.value = false;
    pendingTarget.value = to;
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
            const okBody = (await response.json().catch(() => null)) as {
                noop?: boolean;
            } | null;
            // D8: two agents agreeing is not a conflict — say it quietly.
            noopStrip.value = okBody?.noop === true;
            pendingOpen.value = false;
            timelineTick.value += 1; // the relay will have a new row for B12
            emit('changed');
            return;
        }
        const body = (await response.json().catch(() => null)) as { detail?: string } | null;
        refusal.value = body?.detail ?? props.t.refused ?? '';
        // D2's snap-back: a refusal refetches TRUTH too — after a race the
        // real value may have moved, and the control re-renders it (with
        // D3's legal set) rather than sitting in the refused position.
        refusalPutBack.value = true;
        emit('changed');
    } finally {
        pendingTarget.value = null;
        busy.value = false;
    }
}

function setPending(): void {
    const to = level1.value === 'customer' ? 'pending_customer' : 'pending_internal';
    void post(to, composedReason());
}

// ── The property rows (priority, tags, assignee picker) ────────────────
const PRIORITIES = ['urgent', 'high', 'normal', 'low'] as const;
async function doorPost(path: string, body: Record<string, unknown> | null): Promise<boolean> {
    refusal.value = '';
    const response = await fetch(path, {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-XSRF-TOKEN': props.xsrf(),
        },
        body: body === null ? undefined : JSON.stringify(body),
    });
    if (response.ok) {
        timelineTick.value += 1;
        emit('changed');
        return true;
    }
    const parsed = (await response.json().catch(() => null)) as { detail?: string } | null;
    // B2-S06: the refusal renders INLINE, in place — never a toast.
    refusal.value = parsed?.detail ?? props.t.refused ?? '';
    emit('changed');
    return false;
}
function setPriority(level: string): void {
    if (props.ticket === null) return;
    void doorPost(`/inbox/t/${props.ticket.id}/priority`, { priority: level });
}
const newTag = ref('');
function addTag(): void {
    const tag = newTag.value.trim();
    if (tag === '' || props.ticket === null) return;
    newTag.value = '';
    void doorPost(`/inbox/t/${props.ticket.id}/tags/add`, { tags: [tag] });
}
function removeTag(tag: string): void {
    if (props.ticket === null) return;
    void doorPost(`/inbox/t/${props.ticket.id}/tags/remove`, { tags: [tag] });
}
// B2-S06: the assignee inline picker over the directory door; a disabled or
// out-of-scope target's 422 renders in place.
const assignPickerOpen = ref(false);
const assignees = ref<{ id: string; name: string }[]>([]);
async function openAssignPicker(): Promise<void> {
    assignPickerOpen.value = !assignPickerOpen.value;
    if (!assignPickerOpen.value) return;
    const response = await fetch('/inbox/assignees?q=', {
        headers: { Accept: 'application/json' },
    });
    if (!response.ok) return;
    const body = (await response.json()) as { assignees: { id: string; name: string }[] };
    assignees.value = body.assignees;
}
function pickAssignee(id: string): void {
    if (props.ticket === null) return;
    assignPickerOpen.value = false;
    void doorPost(`/inbox/t/${props.ticket.id}/assign`, { assignee_id: id });
}
// Followers (FR-18): state read + the self toggle through the doors.
const followers = ref<{ id: string; name: string }[]>([]);
const following = ref(false);
async function loadFollowers(): Promise<void> {
    if (props.ticket === null) return;
    const response = await fetch(`/inbox/t/${props.ticket.id}/followers`, {
        headers: { Accept: 'application/json' },
    });
    if (!response.ok) return;
    const body = (await response.json()) as {
        followers: { id: string; name: string }[];
        following: boolean;
    };
    followers.value = body.followers;
    following.value = body.following;
}
watch(
    () => [props.ticket?.id, props.loadTick] as const,
    () => void loadFollowers(),
    { immediate: true },
);
async function toggleFollow(): Promise<void> {
    if (props.ticket === null) return;
    const path = following.value ? 'unfollow' : 'follow';
    const ok = await doorPost(`/inbox/t/${props.ticket.id}/${path}`, null);
    if (ok) void loadFollowers();
}
function nameInitials(name: string): string {
    return name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0] ?? '')
        .join('');
}

function timeShort(iso: string | null | undefined): string {
    if (!iso) return '';
    return new Intl.DateTimeFormat(undefined, { hour: '2-digit', minute: '2-digit' }).format(
        new Date(iso),
    );
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
    <!-- D1: the loading skeleton — a shape, never a stale ticket. -->
    <div v-if="loading && ticket === null" data-testid="ticket-panel-skeleton" aria-hidden="true">
        <p class="mb-2 text-xs" :style="{ color: 'var(--ds-text-muted)' }">{{ t.loading }}</p>
        <div
            v-for="i in 3"
            :key="i"
            class="mb-2 h-3 rounded"
            :style="{ background: 'var(--ds-row-hover)', width: `${90 - i * 18}%` }"
        ></div>
    </div>
    <div v-else-if="ticket === null" data-testid="ticket-panel-empty">
        <p class="text-sm" :style="{ color: 'var(--ds-text-muted)' }">{{ t.empty }}</p>
    </div>
    <div v-else data-testid="ticket-panel" class="flex flex-col gap-3">
        <!-- D12: a merged ticket lives on in its FINAL survivor — the
             banner skips the middle of the chain. -->
        <div
            v-if="ticket.final_survivor_number"
            data-testid="merged-banner"
            role="status"
            class="rounded-md border px-2 py-1.5 text-xs"
            :style="{ borderColor: 'var(--ds-border)', background: 'var(--ds-inset-bg)' }"
        >
            <p class="font-semibold">
                {{
                    (t.merged_banner ?? '').replace(':number', String(ticket.final_survivor_number))
                }}
            </p>
            <button
                v-if="ticket.final_survivor_conversation_id"
                type="button"
                data-testid="merged-open-survivor"
                class="mt-0.5 underline"
                :style="{ color: 'var(--ds-text-secondary)' }"
                @click="emit('open-survivor', ticket.final_survivor_conversation_id)"
            >
                {{
                    (t.open_survivor ?? '').replace(':number', String(ticket.final_survivor_number))
                }}
            </button>
        </div>

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
                    <span
                        v-else
                        class="border-b border-dashed"
                        :style="{ color: 'var(--ds-text-muted)', borderColor: 'var(--ds-border)' }"
                        >{{ t.subject_none }}</span
                    >
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
                    {{ openLabel }}<span v-if="status === 'open'" aria-hidden="true"> ✓</span>
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
                    {{ t.status_resolved
                    }}<span v-if="status === 'resolved'" aria-hidden="true"> ✓</span>
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

        <!-- D8: the quiet agreeing strip — never an error. -->
        <p
            v-if="noopStrip"
            data-testid="panel-noop-strip"
            role="status"
            class="rounded-md border px-2 py-1 text-xs"
            :style="{ borderColor: 'var(--ds-border)', color: 'var(--ds-text-secondary)' }"
        >
            {{ t.noop_strip }}
        </p>

        <!-- D9/D10: system events explain themselves in place when they are
             the newest thing that happened to this ticket. -->
        <p
            v-if="newestEvent === 'ticket.assignee_deactivated' && !ticket.assignee_name"
            data-testid="panel-handed-back"
            role="status"
            class="rounded-md border px-2 py-1 text-xs"
            :style="{ borderColor: 'var(--ds-border)', color: 'var(--ds-text-secondary)' }"
        >
            {{ t.handed_back }}
        </p>
        <p
            v-else-if="newestEvent === 'ticket.escalation_rerouted'"
            data-testid="panel-rerouted"
            role="status"
            class="rounded-md border px-2 py-1 text-xs"
            :style="{ borderColor: 'var(--ds-border)', color: 'var(--ds-text-secondary)' }"
        >
            {{ t.rerouted }}
        </p>

        <!-- D2: the optimistic 'saving…' marker on the in-flight target. -->
        <p
            v-if="pendingTarget !== null"
            data-testid="panel-status-saving"
            class="text-[11px]"
            :style="{ color: 'var(--ds-text-muted)' }"
        >
            {{ t.saving }}
        </p>

        <!-- INLINE refusal (B2-S06 posture): stable detail, in place. D2's
             put-back heading names the value the control snapped back to —
             reactive, so a raced truth renders once the refetch lands. -->
        <div
            v-if="refusal !== ''"
            data-testid="panel-refusal"
            role="alert"
            class="rounded-md border px-2 py-1 text-xs"
            :style="{
                borderColor: 'var(--ds-danger-strong)',
                color: 'var(--ds-danger-strong)',
            }"
        >
            <p v-if="refusalPutBack" class="font-semibold" data-testid="panel-putback">
                {{ (t.put_back ?? '').replace(':status', statusLabel(status)) }}
            </p>
            <p>{{ refusal }}</p>
        </div>

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
                <button
                    v-if="can.tickets_assign"
                    type="button"
                    data-testid="panel-assign-change"
                    class="text-[11px] underline"
                    :style="{ color: 'var(--ds-text-secondary)' }"
                    @click="void openAssignPicker()"
                >
                    {{ t.change }}
                </button>
            </div>
            <!-- B2-S06: the inline directory picker — refusals render in the
                 panel's own refusal strip, never a toast. -->
            <div
                v-if="assignPickerOpen"
                data-testid="panel-assign-picker"
                class="mt-1 rounded-md border p-1"
                :style="{ borderColor: 'var(--ds-border)' }"
            >
                <button
                    v-for="candidate in assignees"
                    :key="candidate.id"
                    type="button"
                    class="block w-full px-1.5 py-0.5 text-start text-[11px]"
                    :data-assign-candidate="candidate.name"
                    @click="pickAssignee(candidate.id)"
                >
                    {{ candidate.name }}
                </button>
            </div>
            <div v-else class="flex items-center gap-2">
                <p
                    class="border-b border-dashed text-xs"
                    :style="{ color: 'var(--ds-text-muted)', borderColor: 'var(--ds-border)' }"
                >
                    {{ t.unassigned }}
                </p>
                <button
                    v-if="can.tickets_assign_self"
                    type="button"
                    data-testid="panel-assign-me"
                    class="text-[11px] underline"
                    :style="{ color: 'var(--ds-text-secondary)' }"
                    :disabled="claiming"
                    @click="void assignToMe()"
                >
                    {{ t.assign_to_me }}
                </button>
            </div>
        </div>

        <!-- Priority (the drawn 'Priority High ▾') — the step-13 door. -->
        <div v-if="can.tickets_priority_change">
            <p class="mb-1 text-xs" :style="{ color: 'var(--ds-text-muted)' }">
                {{ t.priority_label }}
            </p>
            <select
                data-testid="panel-priority"
                class="rounded-md border px-2 py-1 text-xs"
                :style="{ borderColor: 'var(--ds-border)' }"
                :value="ticket.priority ?? 'normal'"
                :aria-label="t.priority_label"
                @change="setPriority(($event.target as HTMLSelectElement).value)"
            >
                <option v-for="level in PRIORITIES" :key="level" :value="level">
                    {{ t['priority_' + level] }}
                </option>
            </select>
        </div>

        <!-- SLA + reply-window chips, from the projection's own columns. -->
        <div class="flex flex-wrap gap-2">
            <span
                v-if="ticket.sla_state === 'breached' || ticket.sla_state === 'warned'"
                data-testid="panel-sla-chip"
                class="rounded px-1.5 py-0.5 text-[11px] font-semibold"
                :style="{
                    background: 'var(--ds-danger-bg, var(--ds-inset-bg))',
                    color: 'var(--ds-danger-strong)',
                }"
                >▲ {{ ticket.sla_state === 'breached' ? t.sla_breached : t.sla_warned }}</span
            >
            <span
                v-if="ticket.window_expires_at"
                data-testid="panel-window-chip"
                class="rounded px-1.5 py-0.5 text-[11px]"
                :style="{ background: 'var(--ds-inset-bg)', color: 'var(--ds-text-secondary)' }"
                >⏱
                {{
                    (t.window_until ?? '').replace(':time', timeShort(ticket.window_expires_at))
                }}</span
            >
        </div>

        <!-- Department / queue: Doc 11's seam, stated honestly. -->
        <div>
            <p class="mb-1 text-xs" :style="{ color: 'var(--ds-text-muted)' }">
                {{ t.department_label }}
            </p>
            <p
                class="text-xs"
                data-testid="panel-department"
                :style="{ color: 'var(--ds-text-muted)' }"
            >
                {{ t.department_seam }}
            </p>
        </div>

        <!-- Tags: chips with ✕ and an add field — the step-13 split doors. -->
        <div v-if="can.tickets_tags_manage" data-testid="panel-tags">
            <p class="mb-1 text-xs" :style="{ color: 'var(--ds-text-muted)' }">
                {{ t.tags_label }}
            </p>
            <div class="flex flex-wrap items-center gap-1">
                <span
                    v-for="tag in ticket.tags ?? []"
                    :key="tag"
                    class="inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-[11px]"
                    :style="{ background: 'var(--ds-row-hover)' }"
                    :data-panel-tag="tag"
                >
                    {{ tag }}
                    <button
                        type="button"
                        :aria-label="(t.remove_tag ?? '').replace(':tag', tag)"
                        data-role="panel-tag-remove"
                        @click="removeTag(tag)"
                    >
                        ✕
                    </button>
                </span>
                <span
                    v-if="(ticket.tags ?? []).length === 0"
                    class="border-b border-dashed text-[11px]"
                    :style="{ color: 'var(--ds-text-muted)', borderColor: 'var(--ds-border)' }"
                    >{{ t.tags_none }}</span
                >
            </div>
            <div class="mt-1 flex gap-1">
                <input
                    v-model="newTag"
                    type="text"
                    data-testid="panel-tag-input"
                    :aria-label="t.add_tag"
                    :placeholder="t.add_tag"
                    class="w-28 rounded-md border px-1.5 py-0.5 text-[11px]"
                    :style="{ borderColor: 'var(--ds-border)' }"
                    @keydown.enter.prevent="addTag"
                />
                <button
                    type="button"
                    data-testid="panel-tag-add"
                    class="text-[11px] underline"
                    :style="{ color: 'var(--ds-text-secondary)' }"
                    @click="addTag"
                >
                    {{ t.add_tag }}
                </button>
            </div>
        </div>

        <!-- Followers (FR-18): avatars + the self toggle. -->
        <div v-if="can.tickets_follow" data-testid="panel-followers">
            <p class="mb-1 text-xs" :style="{ color: 'var(--ds-text-muted)' }">
                {{ t.followers_label }}
            </p>
            <div class="flex items-center gap-1">
                <span
                    v-for="follower in followers"
                    :key="follower.id"
                    :title="follower.name"
                    :data-follower="follower.name"
                    class="inline-flex h-5 w-5 items-center justify-center rounded-full text-[9px] font-semibold"
                    :style="{ background: 'var(--ds-row-hover)' }"
                    >{{ nameInitials(follower.name) }}</span
                >
                <button
                    type="button"
                    data-testid="panel-follow-toggle"
                    class="ms-1 text-[11px] underline"
                    :style="{ color: 'var(--ds-text-secondary)' }"
                    @click="void toggleFollow()"
                >
                    {{ following ? t.following : t.follow }}
                </button>
            </div>
        </div>

        <!-- Linked tickets (merge/split family), numbers as facts. -->
        <div
            v-if="ticket.merged_into_number || ticket.origin_ticket_number"
            data-testid="panel-linked"
        >
            <p class="mb-1 text-xs" :style="{ color: 'var(--ds-text-muted)' }">
                {{ t.linked_label }}
            </p>
            <p v-if="ticket.merged_into_number" class="text-xs">
                {{ (t.merged_into ?? '').replace(':number', String(ticket.merged_into_number)) }}
            </p>
            <p v-if="ticket.origin_ticket_number" class="text-xs">
                {{ (t.split_from ?? '').replace(':number', String(ticket.origin_ticket_number)) }}
            </p>
        </div>

        <!-- D7: the lost race's next move, offered in place. -->
        <button
            v-if="claimLost"
            type="button"
            data-testid="panel-claim-next"
            class="self-start text-xs underline"
            :style="{ color: 'var(--ds-text-secondary)' }"
            @click="
                claimLost = false;
                emit('claim-next');
            "
        >
            {{ t.claim_next }}
        </button>

        <!-- B12: the timeline, newest first, over the living relay. -->
        <TicketTimeline
            :ticket-id="ticket.id"
            :t="t"
            :refresh-tick="timelineTick + (loadTick ?? 0)"
            @newest="(name) => (newestEvent = name)"
        />
    </div>
</template>
