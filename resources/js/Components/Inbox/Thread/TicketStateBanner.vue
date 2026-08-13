<script setup lang="ts">
// B2-S04's state banners (S07 step 14), drawn whole from the frozen v2:
//  C8  resolved — ✓ resolved-by + the auto-close countdown + Reopen now
//  C9  closed — the reopen window boundary (server-computed), the reopen
//      control WITH the written reason the closed→open edge demands, the
//      composer replaced (the banner IS the composer's replacement)
//  C13 reopened — ↺ count + the earlier-ticket link when one exists
//  C10 spam — marked-by + the 7-day unmark countdown + "Not spam" (reason
//      ≥10), terminal shape (no button) once the window passes
//  C11 escalated — ▲ raised-by/reason/target, "Read the full reason"; the
//      agent KEEPS the ticket and keeps replying (no composer change)
//  C12 channel unreachable — ⚠ + SLA paused + notes-still-work + Rescue
// Every boundary here is a SERVER-computed timestamp (C-11); this component
// renders countdowns against it and never derives windows itself.
import { computed, onBeforeUnmount, ref } from 'vue';

type BannerTicket = {
    id: string;
    status: string;
    assignee_name?: string | null;
    auto_close_at?: string | null;
    resolved_at?: string | null;
    closed_at?: string | null;
    reopen_window_until?: string | null;
    reopened_count?: number;
    previous_ticket_number?: number | string | null;
    spam_marked_at?: string | null;
    spam_marked_by_name?: string | null;
    spam_unmark_until?: string | null;
    escalation?: {
        path: string | null;
        reason: string | null;
        at: string | null;
        by_name: string | null;
        target_name: string | null;
    } | null;
    channel_unreachable?: boolean;
};

const props = defineProps<{
    ticket: BannerTicket;
    t: Record<string, string>;
}>();

const emit = defineEmits<{
    (e: 'reopen-now'): void;
    (e: 'reopen-with-reason', reason: string): void;
    (e: 'unspam', reason: string): void;
    (e: 'open-rescue'): void;
}>();

const label = (key: string, replacements: Record<string, string> = {}): string => {
    let value = props.t[key] ?? '';
    for (const [token, replacement] of Object.entries(replacements)) {
        value = value.replaceAll(':' + token, replacement);
    }
    return value;
};

// One ticking clock for every countdown in the banner.
const now = ref(Date.now());
const timer = setInterval(() => (now.value = Date.now()), 30_000);
onBeforeUnmount(() => clearInterval(timer));

function remaining(until: string | null | undefined): string | null {
    if (!until) return null;
    const ms = new Date(until).getTime() - now.value;
    if (ms <= 0) return null;
    const hours = Math.floor(ms / 3_600_000);
    if (hours >= 48)
        return label('remaining_days', {
            days: String(Math.floor(hours / 24)),
            hours: String(hours % 24),
        });
    if (hours >= 1) return label('remaining_hours', { hours: String(hours) });
    return label('remaining_minutes', { minutes: String(Math.max(1, Math.floor(ms / 60_000))) });
}

const autoCloseIn = computed(() => remaining(props.ticket.auto_close_at));
const reopenWindowIn = computed(() => remaining(props.ticket.reopen_window_until));
const unmarkIn = computed(() => remaining(props.ticket.spam_unmark_until));

const reasonOpen = ref<'reopen' | 'unspam' | null>(null);
const reason = ref('');
const escalationExpanded = ref(false);

function submitReason(): void {
    if (reason.value.trim() === '') return;
    if (reasonOpen.value === 'reopen') emit('reopen-with-reason', reason.value.trim());
    if (reasonOpen.value === 'unspam') emit('unspam', reason.value.trim());
    reasonOpen.value = null;
    reason.value = '';
}

function timeShort(iso: string | null | undefined): string {
    if (!iso) return '';
    return new Intl.DateTimeFormat(undefined, {
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date(iso));
}
</script>

<template>
    <div
        data-testid="ticket-state-banner"
        class="border-t px-4 py-2.5 text-xs"
        :style="{ borderColor: 'var(--ds-border)', background: 'var(--ds-inset-bg)' }"
    >
        <!-- C13 — the reopened marker rides ABOVE the status shape. -->
        <p
            v-if="(ticket.reopened_count ?? 0) > 0 && ticket.status === 'open'"
            data-testid="reopened-marker"
            class="mb-1.5"
        >
            <span aria-hidden="true">↺</span>
            {{ label('reopened_times', { count: String(ticket.reopened_count) }) }}
            <span
                v-if="ticket.previous_ticket_number != null"
                :style="{ color: 'var(--ds-text-muted)' }"
            >
                · {{ label('reopened_earlier', { number: String(ticket.previous_ticket_number) }) }}
            </span>
        </p>

        <!-- C8 — resolved, auto-close running. -->
        <template v-if="ticket.status === 'resolved'">
            <p
                class="font-semibold"
                :style="{ color: 'var(--ds-ok-strong, var(--ds-text-primary))' }"
            >
                <span aria-hidden="true">✓</span>
                {{
                    ticket.assignee_name
                        ? label('resolved_by', { name: ticket.assignee_name })
                        : label('resolved_title')
                }}
            </p>
            <p
                v-if="autoCloseIn"
                data-testid="auto-close-countdown"
                class="mt-0.5"
                :style="{ color: 'var(--ds-text-muted)' }"
            >
                {{ label('auto_close_in', { remaining: autoCloseIn }) }}
            </p>
            <button
                type="button"
                data-testid="reopen-now"
                class="mt-1.5 min-h-[32px] rounded-md border px-3 font-medium"
                :style="{ borderColor: 'var(--ds-border-strong)' }"
                @click="emit('reopen-now')"
            >
                {{ t.reopen_now }}
            </button>
        </template>

        <!-- C9 — closed; the banner replaces the composer. -->
        <template v-else-if="ticket.status === 'closed'">
            <p class="font-semibold">
                <span aria-hidden="true">⊝</span>
                {{ label('closed_at_time', { time: timeShort(ticket.closed_at) }) }}
            </p>
            <p
                v-if="reopenWindowIn"
                data-testid="reopen-window-open"
                class="mt-0.5"
                :style="{ color: 'var(--ds-text-muted)' }"
            >
                <span aria-hidden="true">⏱</span>
                {{ label('reopen_window_in', { remaining: reopenWindowIn }) }}
            </p>
            <p
                v-else
                data-testid="reopen-window-lapsed"
                class="mt-0.5"
                :style="{ color: 'var(--ds-text-muted)' }"
            >
                {{ t.reopen_window_passed }}
            </p>
            <div v-if="reasonOpen !== 'reopen'" class="mt-1.5">
                <button
                    v-if="reopenWindowIn"
                    type="button"
                    data-testid="reopen-closed"
                    class="min-h-[32px] rounded-md border px-3 font-medium"
                    :style="{ borderColor: 'var(--ds-border-strong)' }"
                    @click="reasonOpen = 'reopen'"
                >
                    {{ t.reopen_with_reason }}
                </button>
            </div>
        </template>

        <!-- C10 — spam: unmark window running, or terminal. -->
        <template v-else-if="ticket.status === 'spam'">
            <template v-if="unmarkIn">
                <p class="font-semibold">
                    <span aria-hidden="true">⊘</span>
                    {{
                        label('spam_marked_by', {
                            name: ticket.spam_marked_by_name ?? '',
                            time: timeShort(ticket.spam_marked_at),
                        })
                    }}
                </p>
                <p class="mt-0.5" :style="{ color: 'var(--ds-text-muted)' }">
                    {{ label('spam_unmark_in', { remaining: unmarkIn }) }}
                </p>
                <button
                    v-if="reasonOpen !== 'unspam'"
                    type="button"
                    data-testid="not-spam"
                    class="mt-1.5 min-h-[32px] rounded-md border px-3 font-medium"
                    :style="{ borderColor: 'var(--ds-border-strong)' }"
                    @click="reasonOpen = 'unspam'"
                >
                    {{ t.not_spam }}
                </button>
            </template>
            <template v-else>
                <p data-testid="spam-terminal" class="font-semibold">
                    <span aria-hidden="true">⊘</span> {{ t.spam_final }}
                </p>
                <p class="mt-0.5" :style="{ color: 'var(--ds-text-muted)' }">
                    {{ t.spam_final_detail }}
                </p>
            </template>
        </template>

        <!-- C11 — escalated: reviewer added, conversation kept. -->
        <template v-else-if="ticket.status === 'escalated' && ticket.escalation">
            <p
                class="font-semibold"
                :style="{ color: 'var(--ds-warn-strong, var(--ds-text-primary))' }"
            >
                <span aria-hidden="true">▲</span> {{ t.escalated_title }}
            </p>
            <p class="mt-0.5" :style="{ color: 'var(--ds-text-muted)' }">
                {{ label('escalated_by', { name: ticket.escalation.by_name ?? '' }) }}
                <span v-if="ticket.escalation.target_name">
                    · {{ label('escalated_target', { name: ticket.escalation.target_name }) }}</span
                >
            </p>
            <p v-if="escalationExpanded" data-testid="escalation-reason" class="mt-0.5">
                {{ ticket.escalation.reason }}
            </p>
            <button
                v-else
                type="button"
                data-testid="read-escalation-reason"
                class="mt-0.5 underline"
                :style="{ color: 'var(--ds-accent)' }"
                @click="escalationExpanded = true"
            >
                {{ t.read_full_reason }}
            </button>
            <p class="mt-0.5" :style="{ color: 'var(--ds-text-faint)' }">{{ t.escalated_keeps }}</p>
        </template>

        <!-- C12 — channel unreachable (any live status). -->
        <template v-if="ticket.channel_unreachable && !['spam', 'closed'].includes(ticket.status)">
            <p
                data-testid="unreachable-banner"
                class="mt-1 font-semibold"
                :style="{ color: 'var(--ds-warn-strong, var(--ds-text-primary))' }"
            >
                <span aria-hidden="true">⚠</span> {{ t.unreachable_title }}
                <span
                    class="ms-1 rounded border px-1 text-[10px]"
                    :style="{ borderColor: 'var(--ds-border-strong)' }"
                    >⏸ {{ t.sla_paused }}</span
                >
            </p>
            <p class="mt-0.5" :style="{ color: 'var(--ds-text-muted)' }">
                {{ t.unreachable_detail }}
            </p>
            <button
                type="button"
                data-testid="open-rescue"
                class="mt-0.5 underline"
                :style="{ color: 'var(--ds-accent)' }"
                @click="emit('open-rescue')"
            >
                {{ t.open_rescue }}
            </button>
        </template>

        <!-- The shared written-reason field (C9 reopen / C10 not-spam). -->
        <div v-if="reasonOpen !== null" class="mt-1.5 flex items-center gap-2">
            <input
                v-model="reason"
                type="text"
                maxlength="500"
                data-testid="banner-reason"
                :placeholder="t.reason_placeholder"
                class="w-64 rounded-md border px-2 py-1"
                :style="{
                    borderColor: 'var(--ds-border-strong)',
                    background: 'var(--ds-panel-bg)',
                }"
                @keydown.enter="submitReason"
            />
            <button
                type="button"
                data-testid="banner-reason-submit"
                class="min-h-[32px] rounded-md px-3 font-semibold"
                :style="{ background: 'var(--ds-text-ink)', color: 'var(--ds-page-bg)' }"
                @click="submitReason"
            >
                {{ reasonOpen === 'reopen' ? t.reopen_verb : t.not_spam }}
            </button>
        </div>
    </div>
</template>
