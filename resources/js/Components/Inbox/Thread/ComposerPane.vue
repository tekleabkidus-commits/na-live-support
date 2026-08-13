<script setup lang="ts">
// The composer (S07 step 11) — one component, every state (FR-20: "the
// composer is the single renderer for all of these").
//
// FR-17 mode safety: Reply/Note is a HARD visual flip — note-yellow
// background + dashed border + the banner label + a DIFFERENT send label
// ("Send reply" vs "Add note"). The differentiation is NOT themeable below
// a contrast floor (BR-02): the note surface uses the fixed --ds-note-*
// tokens, and AC-05's heuristic spec asserts the computed contrast delta.
// Mode is sticky per ticket per user (sessionStorage). THE SEND PATHS ARE
// SEPARATE ROUTES — a note can never reach the reply door from here, and
// the server refuses it structurally anyway (sabotage #14's proof).
//
// FR-20 states (Doc 05 §8, the FR-23/AC-04/US-02 closing rows): normal /
// closing countdown strip / expired options card (SMS pre-filtered by
// phone presence) / connection_paused (red card naming the account +
// health link) / blocked_by_user guidance / conversation-blocked.
//
// FR-21: the split button (Send / Send & Resolve / Send & Pending) — the
// reply STAYS SENT on a refused transition; the rollback strip renders the
// refusal with B12-A01 AC-05's ONE-TAP RETRY.
//
// FR-18: live caps per channel capability + the rich-lite downgrade hint.
// FR-19: "/" opens the palette (macros are Doc 12's — the seam renders its
// empty truth until then) + the AI-ACTIONS SLOT (Doc 09's; violet accent
// law, disabled cluster naming its owner).
// FR-22: draft autosave (LWW), draft chip, >20-char discard/keep guard.
// FR-16: the collision strip ("X is replying…") and the send-race
// confirm — driven by the same entry points the realtime feed will call
// (window.__inboxComposer, the scripted-stream discipline); sabotage #8
// suppresses the strip and the dual-surface proof goes red.
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { queueSend } from '../../../lib/offline';
import { mintIdempotencyKey } from '../../../lib/sendQueue';
import { serverNow } from '../../../lib/serverClock';
import { useInboxConnection } from '../../../stores/inbox/connection';

const props = defineProps<{
    conversationId: string;
    channel: string;
    customerName: string;
    ticketStatus: string;
    conversationState: string; // active | blocked | …
    windowState: 'open' | 'closing' | 'expired' | 'none';
    windowExpiresAt: string | null;
    connectionState: string; // active | paused | revoked (Doc 05 seam)
    connectionAccount: string;
    customerHasPhone: boolean;
    blockedByUser: boolean;
    channelUnreachable?: boolean;
    voiceFlag: boolean;
    t: Record<string, string>;
}>();

const emit = defineEmits<{ (e: 'sent', messageId: string): void }>();

// ── FR-17: sticky mode per ticket per user ─────────────────────────────────
const modeKey = 'composer-mode-' + props.conversationId;
const mode = ref<'reply' | 'note'>(
    (sessionStorage.getItem(modeKey) as 'reply' | 'note') ?? 'reply',
);
watch(mode, (value) => sessionStorage.setItem(modeKey, value));

const body = ref('');
const sending = ref(false);
// T-03's outbound half, minted CLIENT-side (step 13): one key per send
// SERIES — a retry after failure or the reopen-confirm resend reuses the
// same key, so the server's findByIdempotencyKey makes "no duplicate on
// retry" (B12-A01 AC-05) a physics fact rather than a hope.
const currentSendKey = ref<string | null>(null);
const connection = useInboxConnection();
const paletteOpen = ref(false);
const splitOpen = ref(false);
const reopenPrompt = ref(false);
const pendingThen = ref<string | null>(null);
const transitionFailure = ref<{ to: string; detail: string } | null>(null);
const typingBy = ref<string | null>(null); // FR-16 collision strip
const raceBy = ref<string | null>(null); // FR-16 send race
const raceConfirmOpen = ref(false);

// ── FR-20: the state resolver — one computed, every Doc 05 §8 state ───────
const composerState = computed((): string => {
    if (props.conversationState === 'blocked') return 'conversation_blocked';
    if (props.blockedByUser) return 'blocked_by_user';
    // C12 (step 14): the channel is unreachable at the TICKET level — the
    // reply path is off; notes to colleagues still work (the banner above
    // the composer says so; BR-07 pauses timers server-side).
    if (props.channelUnreachable && mode.value !== 'note') return 'unreachable';
    if (props.connectionState === 'paused' || props.connectionState === 'revoked')
        return 'connection_paused';
    if (mode.value === 'note') return 'normal'; // notes never touch the channel
    if (props.windowState === 'expired') return 'expired';
    if (props.windowState === 'closing') return 'closing';
    return 'normal';
});
const canType = computed(
    () =>
        mode.value === 'note' ||
        ![
            'expired',
            'connection_paused',
            'blocked_by_user',
            'conversation_blocked',
            'unreachable',
        ].includes(composerState.value),
);

// ── FR-18: live caps + downgrade hint ──────────────────────────────────────
const MAX_TEXT: Record<string, number> = { telegram: 4096, fakechan: 4096 };
const remaining = computed(() => (MAX_TEXT[props.channel] ?? 4096) - body.value.length);

// ── FR-22: draft autosave (debounced LWW through the server door) ─────────
let draftTimer: ReturnType<typeof setTimeout> | null = null;
function xsrf(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return match?.[1] ? decodeURIComponent(match[1]) : '';
}
let applyingSync = false;
watch(body, () => {
    if (mode.value !== 'reply' || applyingSync) return;
    if (draftTimer) clearTimeout(draftTimer);
    draftTimer = setTimeout(() => void saveDraft(), 800);
});
async function saveDraft(): Promise<void> {
    try {
        await fetch(`/inbox/thread/${props.conversationId}/draft`, {
            method: 'PATCH',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': xsrf(),
            },
            body: JSON.stringify({ body: body.value || null }),
        });
        // EC-03: the OTHER TAB hears about the write (last-writer wins —
        // this write IS the latest by construction).
        draftSync?.postMessage({ conversationId: props.conversationId, body: body.value });
    } catch {
        /* offline: FR-29's machinery owns queued mutations (step 13) */
    }
}

// ── EC-03 (step 13): dual-tab drafts sync LAST-WRITER with the Doc 04
// EC-06 toast ("Draft updated on another device"). STRUCTURAL guarantee
// alongside: this sync NEVER touches typingBy — a user's own other tab can
// not trigger the collision strip (typing indicators never self-trigger).
const draftSyncedToast = ref(false);
let draftSync: BroadcastChannel | null = null;
onMounted(() => {
    if (typeof BroadcastChannel === 'undefined') return;
    draftSync = new BroadcastChannel('inbox-draft-sync');
    draftSync.onmessage = (event: MessageEvent) => {
        const data = event.data as { conversationId: string; body: string };
        if (data.conversationId !== props.conversationId || data.body === body.value) {
            return;
        }
        // The incoming write is the LATER writer — it wins (LWW). The guard
        // stops the apply from re-broadcasting (no ping-pong).
        applyingSync = true;
        body.value = data.body;
        setTimeout(() => (applyingSync = false), 0);
        draftSyncedToast.value = true;
        setTimeout(() => (draftSyncedToast.value = false), 3_000);
    };
});
onBeforeUnmount(() => draftSync?.close());

// FR-22: the unsent-draft guard — only past 20 chars.
function onPageHide(event: BeforeUnloadEvent): void {
    if (mode.value === 'reply' && body.value.length > 20) {
        event.preventDefault();
    }
}
onMounted(() => window.addEventListener('beforeunload', onPageHide));
onBeforeUnmount(() => window.removeEventListener('beforeunload', onPageHide));

// ── FR-29 (step 13): the offline mirror + queue path ───────────────────────
// The live-text mirror is FS-05's preserve half: recovery snapshots it BEFORE
// the store reset and hands it back through preservedText after the remount
// (the guard sabotage #17 disables). Composer text above all.
watch(body, (value) => {
    connection.composerLiveText[props.conversationId] = value;
});
watch(
    () => connection.preservedText[props.conversationId],
    (preserved) => {
        if (typeof preserved === 'string' && preserved !== '' && body.value === '') {
            body.value = preserved;
            delete connection.preservedText[props.conversationId];
        }
    },
    { immediate: true },
);
// FR-22's restore half: hydrate the persisted draft on thread open — the
// DraftStore contract ("the row simply persists") only SHOWS if someone
// reads it back. Live text always outranks the stored row: FS-05's
// preservedText and anything already typed win (body must still be empty
// when the fetch lands). applyingSync keeps the watcher from re-saving
// what the server just handed us.
onMounted(async () => {
    if (mode.value !== 'reply') return;
    try {
        const response = await fetch(`/inbox/thread/${props.conversationId}/draft`, {
            headers: { Accept: 'application/json' },
        });
        if (!response.ok) return;
        const draft = (await response.json()) as { body: string | null };
        if (typeof draft.body === 'string' && draft.body !== '' && body.value === '') {
            applyingSync = true;
            body.value = draft.body;
            setTimeout(() => (applyingSync = false), 0);
        }
    } catch {
        /* offline: nothing to restore from the server */
    }
});

// Reconnect: the draft the server missed while offline is re-persisted, and
// the queued chips for this conversation come from the store mirror.
watch(
    () => connection.state,
    (state, previous) => {
        if (
            state === 'online' &&
            previous !== 'online' &&
            mode.value === 'reply' &&
            body.value !== ''
        ) {
            void saveDraft();
        }
    },
);
const queuedHere = computed(() => connection.queuedFor(props.conversationId));

// ── send paths — SEPARATE, per mode (FR-17's structural arm) ──────────────
async function send(then: string | null = null, confirmReopen = false): Promise<void> {
    if (body.value.trim() === '' || sending.value) return;

    // FR-29: offline/reconnecting → the send is QUEUED, visibly, with its
    // idempotency key minted at queue time; the composer clears the way a
    // real send would (the chip carries the text now — BR-01, no lying).
    if (connection.readOnly) {
        await queueSend({
            conversationId: props.conversationId,
            mode: mode.value,
            body: body.value,
            then,
            queuedAt: serverNow(),
        });
        body.value = '';
        currentSendKey.value = null;
        return;
    }

    // FR-16: a colleague replied since typing began → non-blocking confirm.
    if (
        raceBy.value !== null &&
        !raceConfirmOpen.value &&
        mode.value === 'reply' &&
        !confirmReopen
    ) {
        pendingThen.value = then;
        raceConfirmOpen.value = true;
        return;
    }
    raceConfirmOpen.value = false;

    sending.value = true;
    transitionFailure.value = null;
    try {
        if (mode.value === 'note') {
            const response = await fetch(`/inbox/thread/${props.conversationId}/note`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': xsrf(),
                },
                body: JSON.stringify({ body: body.value }),
            });
            if (response.status === 201) {
                emit('sent', ((await response.json()) as { message_id: string }).message_id);
                body.value = '';
            }
            return;
        }

        // One key per send SERIES (reused by the reopen-confirm resend and
        // any retry) — the server's dedupe makes retries single-shot.
        currentSendKey.value ??= mintIdempotencyKey();
        const response = await fetch(`/inbox/thread/${props.conversationId}/reply`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': xsrf(),
            },
            body: JSON.stringify({
                body: body.value,
                idempotency_key: currentSendKey.value,
                ...(then ? { then } : {}),
                ...(confirmReopen ? { confirm_reopen: true } : {}),
            }),
        });
        if (response.status === 409) {
            pendingThen.value = then;
            reopenPrompt.value = true; // Doc 06 EC-08's dialog
            return;
        }
        if (response.status === 201) {
            const payload = (await response.json()) as {
                message_id: string;
                transition: { ok: boolean; to: string; detail?: string } | null;
            };
            emit('sent', payload.message_id);
            body.value = '';
            currentSendKey.value = null;
            reopenPrompt.value = false;
            raceBy.value = null;
            if (payload.transition && !payload.transition.ok) {
                // FR-21: reply SENT, transition refused — rollback strip + retry.
                transitionFailure.value = {
                    to: payload.transition.to,
                    detail: payload.transition.detail ?? '',
                };
            }
        }
    } finally {
        sending.value = false;
    }
}

// B12-A01 AC-05: the one-tap retry.
async function retryTransition(): Promise<void> {
    if (!transitionFailure.value) return;
    const to = transitionFailure.value.to === 'resolved' ? 'resolve' : transitionFailure.value.to;
    const response = await fetch(`/inbox/thread/${props.conversationId}/transition`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-XSRF-TOKEN': xsrf(),
        },
        body: JSON.stringify({ to }),
    });
    if (response.ok) transitionFailure.value = null;
}

function label(key: string, replacements: Record<string, string> = {}): string {
    let value = props.t[key] ?? '';
    for (const [token, replacement] of Object.entries(replacements))
        value = value.replace(':' + token, replacement);
    return value;
}

// ── FR-31 (step 13): the shortcut map's composer commands ──────────────────
// R / N / ⌘Enter / Shift+E arrive as window CustomEvents from the one
// dispatcher — the composer keeps its internals private and answers the
// four commands the map names.
const inputEl = ref<HTMLTextAreaElement | null>(null);
function onComposerCommand(event: Event): void {
    const command = (event as CustomEvent<{ command: string }>).detail?.command;
    switch (command) {
        case 'focus-reply':
            mode.value = 'reply';
            inputEl.value?.focus();
            return;
        case 'note-mode':
            mode.value = 'note';
            inputEl.value?.focus();
            return;
        case 'send':
            void send(null);
            return;
        case 'send-resolve':
            void send('resolve');
            return;
        default:
            return;
    }
}
onMounted(() => window.addEventListener('inbox:composer-command', onComposerCommand));
onBeforeUnmount(() => window.removeEventListener('inbox:composer-command', onComposerCommand));

// The scripted-stream hook (FR-16): the SAME entry points the realtime
// typing/message feed will call.
onMounted(() => {
    (window as unknown as Record<string, unknown>).__inboxComposer = {
        typing: (name: string | null) => (typingBy.value = name),
        colleagueReplied: (name: string) => (raceBy.value = name),
        state: () => ({
            mode: mode.value,
            state: composerState.value,
            transitionFailure: transitionFailure.value,
        }),
    };
});
</script>

<template>
    <div
        data-testid="composer"
        :data-mode="mode"
        :data-state="composerState"
        class="border-t"
        :style="{ borderColor: 'var(--ds-border)' }"
    >
        <!-- FR-29: the offline-queue strip + the queued chips (B1-S02's drawn
             states). role=status: the flush outcome is announced, not tinted. -->
        <p
            v-if="queuedHere.length > 0"
            data-testid="offline-send-strip"
            role="status"
            class="px-3 py-1 text-xs"
            :style="{ background: 'var(--ds-inset-bg)', color: 'var(--ds-text-ink)' }"
        >
            {{ label('offline_queued_strip', { count: String(queuedHere.length) }) }}
        </p>
        <div v-if="queuedHere.length > 0" class="flex flex-wrap gap-1.5 px-3 py-1">
            <span
                v-for="queued in queuedHere"
                :key="queued.id"
                data-testid="queued-send-chip"
                class="rounded-full px-2 py-0.5 text-[10.5px]"
                :style="{ background: 'var(--ds-inset-bg)', color: 'var(--ds-text-ink)' }"
            >
                ◷ {{ t.queued_chip }}
            </span>
        </div>

        <!-- EC-03: the other-device draft toast (Doc 04 EC-06's copy). -->
        <p
            v-if="draftSyncedToast"
            data-testid="draft-synced-toast"
            role="status"
            class="px-3 py-1 text-xs"
            :style="{ background: 'var(--ds-text-ink)', color: 'var(--ds-page-bg)' }"
        >
            {{ t.draft_synced }}
        </p>

        <!-- FR-16: the collision strip (role=status; their draft NEVER shows). -->
        <p
            v-if="typingBy"
            data-testid="collision-strip"
            role="status"
            class="px-3 py-1 text-xs font-semibold"
            :style="{ background: 'var(--ds-inset-bg)', color: 'var(--ds-text-ink)' }"
        >
            ✎ {{ label('collision_typing', { name: typingBy }) }}
        </p>

        <!-- FR-20: closing countdown strip (amber, inline over composer). -->
        <p
            v-if="composerState === 'closing'"
            data-testid="closing-strip"
            class="px-3 py-1 text-xs font-semibold"
            :style="{ background: 'var(--ds-window-soon-bg)', color: 'var(--ds-window-soon-fg)' }"
        >
            ⏱ {{ label('closing_strip', { time: windowExpiresAt ?? '' }) }}
        </p>

        <!-- FR-21: the rollback strip — reply SENT, transition refused. -->
        <p
            v-if="transitionFailure"
            data-testid="transition-rollback"
            role="status"
            class="flex items-center gap-2 px-3 py-1 text-xs"
            :style="{ background: 'var(--ds-warn-bg)', color: 'var(--ds-warn-text)' }"
        >
            {{ label('transition_failed', { detail: transitionFailure.detail }) }}
            <button
                type="button"
                data-testid="transition-retry"
                class="underline"
                @click="retryTransition"
            >
                {{ t.transition_retry }}
            </button>
        </p>

        <!-- FR-20: the terminal cards — the composer is their single renderer. -->
        <div
            v-if="composerState === 'expired' && mode === 'reply'"
            data-testid="expired-card"
            class="p-3"
        >
            <p class="text-sm font-semibold" :style="{ color: 'var(--ds-window-expired-fg)' }">
                {{ t.expired_title }}
            </p>
            <p class="mt-1 text-xs" :style="{ color: 'var(--ds-text-secondary)' }">
                {{ t.expired_hint }}
            </p>
            <button
                v-if="customerHasPhone"
                type="button"
                data-testid="expired-sms"
                class="mt-2 rounded-md border px-2 py-1 text-xs"
                :style="{ borderColor: 'var(--ds-border-strong)', color: 'var(--ds-text-primary)' }"
            >
                {{ t.expired_sms }}
            </button>
            <p
                v-else
                data-testid="expired-no-phone"
                class="mt-2 text-xs"
                :style="{ color: 'var(--ds-text-muted)' }"
            >
                {{ t.expired_no_phone }}
            </p>
        </div>
        <div
            v-else-if="composerState === 'connection_paused' && mode === 'reply'"
            data-testid="paused-card"
            class="m-3 rounded-md border p-2 text-xs"
            :style="{
                background: 'var(--ds-danger-bg)',
                borderColor: 'var(--ds-danger-surface-border)',
                color: 'var(--ds-danger-text)',
            }"
        >
            {{ label('paused_card', { account: connectionAccount }) }}
            <a
                href="/admin/telegram/health"
                class="ms-1 underline"
                data-testid="paused-health-link"
                >{{ t.paused_link }}</a
            >
        </div>
        <p
            v-else-if="composerState === 'blocked_by_user' && mode === 'reply'"
            data-testid="blocked-user-card"
            class="m-3 rounded-md border p-2 text-xs"
            :style="{
                background: 'var(--ds-inset-bg)',
                borderColor: 'var(--ds-border)',
                color: 'var(--ds-text-ink)',
            }"
        >
            {{ t.blocked_user_card }}
        </p>
        <p
            v-else-if="composerState === 'conversation_blocked'"
            data-testid="conversation-blocked-card"
            class="m-3 rounded-md border p-2 text-xs"
            :style="{
                background: 'var(--ds-danger-bg)',
                borderColor: 'var(--ds-danger-surface-border)',
                color: 'var(--ds-danger-text)',
            }"
        >
            {{ t.blocked_conversation_card }}
        </p>

        <!-- The input surface (FR-17's hard differentiation lives HERE). -->
        <div
            v-if="canType"
            data-testid="composer-surface"
            class="m-3 rounded-lg p-2"
            :style="
                mode === 'note'
                    ? {
                          background: 'var(--ds-note-bg)',
                          border: '2px dashed var(--ds-border-strong)',
                      }
                    : { background: 'var(--ds-panel-bg)', border: '1px solid var(--ds-border)' }
            "
        >
            <div class="mb-1 flex items-center gap-2">
                <div
                    role="tablist"
                    :aria-label="t.mode_reply + ' / ' + t.mode_note"
                    class="flex gap-1"
                >
                    <button
                        type="button"
                        role="tab"
                        data-testid="mode-reply"
                        :aria-selected="mode === 'reply'"
                        class="rounded px-2 py-0.5 text-xs font-semibold"
                        :style="
                            mode === 'reply'
                                ? {
                                      background: 'var(--ds-accent)',
                                      color: 'var(--ds-accent-contrast)',
                                  }
                                : { color: 'var(--ds-text-muted)' }
                        "
                        @click="mode = 'reply'"
                    >
                        {{ t.mode_reply }}
                    </button>
                    <button
                        type="button"
                        role="tab"
                        data-testid="mode-note"
                        :aria-selected="mode === 'note'"
                        class="rounded px-2 py-0.5 text-xs font-semibold"
                        :style="
                            mode === 'note'
                                ? { background: 'var(--ds-note-fg)', color: 'var(--ds-note-bg)' }
                                : { color: 'var(--ds-text-muted)' }
                        "
                        @click="mode = 'note'"
                    >
                        {{ t.mode_note }}
                    </button>
                </div>
                <p
                    v-if="mode === 'note'"
                    data-testid="note-mode-banner"
                    role="status"
                    class="text-[10px] font-bold uppercase"
                    :style="{ color: 'var(--ds-note-fg)' }"
                >
                    🔒 {{ t.note_banner }}
                </p>
                <span
                    v-if="body.length > 0 && mode === 'reply'"
                    data-testid="draft-chip"
                    class="ms-auto rounded px-1.5 text-[10px]"
                    :style="{ background: 'var(--ds-inset-bg)', color: 'var(--ds-text-label)' }"
                >
                    ✏️ {{ t.draft_chip }}
                </span>
            </div>

            <textarea
                ref="inputEl"
                v-model="body"
                data-testid="composer-input"
                rows="3"
                class="w-full resize-y bg-transparent text-sm outline-none"
                :style="{ color: mode === 'note' ? 'var(--ds-note-fg)' : 'var(--ds-text-primary)' }"
                :placeholder="
                    mode === 'note'
                        ? t.placeholder_note
                        : label('placeholder_reply', { name: customerName })
                "
                :aria-label="
                    mode === 'note'
                        ? t.placeholder_note
                        : label('placeholder_reply', { name: customerName })
                "
                @keydown="
                    (e: KeyboardEvent) => {
                        if (e.key === '/' && body.length === 0) paletteOpen = true;
                    }
                "
            ></textarea>

            <div class="flex items-center gap-2">
                <!-- FR-19: palette trigger + the Doc 09 AI slot (violet law). -->
                <button
                    type="button"
                    data-testid="palette-trigger"
                    class="text-xs underline"
                    :style="{ color: 'var(--ds-text-muted)' }"
                    @click="paletteOpen = !paletteOpen"
                >
                    {{ t.palette_hint }}
                </button>
                <button
                    v-if="voiceFlag"
                    type="button"
                    data-testid="voice-record"
                    class="rounded border px-1.5 py-0.5 text-[10px]"
                    :style="{
                        borderColor: 'var(--ds-border-strong)',
                        color: 'var(--ds-text-secondary)',
                    }"
                    :aria-label="t.voice_label"
                >
                    🎤
                </button>
                <span
                    data-testid="ai-slot"
                    class="rounded border px-1.5 py-0.5 text-[10px]"
                    :style="{
                        borderColor: 'var(--ds-ai-fg)',
                        color: 'var(--ds-ai-fg)',
                        background: 'var(--ds-ai-bg)',
                    }"
                    :title="t.ai_slot_hint"
                    >✦ {{ t.ai_slot_title }}</span
                >
                <!-- FR-18: live caps + the downgrade hint. -->
                <span
                    data-testid="caps-hint"
                    class="ms-auto text-[10px] tabular-nums"
                    :style="{ color: 'var(--ds-text-faint)' }"
                >
                    {{ label('caps_hint', { remaining: String(remaining), channel: channel }) }}
                </span>

                <!-- FR-21: the split button. -->
                <div class="relative">
                    <button
                        type="button"
                        data-testid="composer-send"
                        class="rounded-s-md px-3 py-1 text-xs font-semibold"
                        :style="
                            mode === 'note'
                                ? { background: 'var(--ds-note-fg)', color: 'var(--ds-note-bg)' }
                                : {
                                      background: 'var(--ds-accent)',
                                      color: 'var(--ds-accent-contrast)',
                                  }
                        "
                        :disabled="sending"
                        @click="send()"
                    >
                        {{ mode === 'note' ? t.add_note : t.send_reply }}
                    </button>
                    <button
                        v-if="mode === 'reply'"
                        type="button"
                        data-testid="split-toggle"
                        class="rounded-e-md border-s px-1 py-1 text-xs"
                        :style="{
                            background: 'var(--ds-accent)',
                            color: 'var(--ds-accent-contrast)',
                            borderColor: 'var(--ds-accent-contrast)',
                        }"
                        :aria-label="t.split_menu"
                        :aria-expanded="splitOpen"
                        @click="splitOpen = !splitOpen"
                    >
                        ▾
                    </button>
                    <div
                        v-if="splitOpen"
                        data-testid="split-menu"
                        role="menu"
                        class="absolute bottom-full end-0 z-30 mb-1 w-52 rounded-md border p-1"
                        :style="{
                            background: 'var(--ds-panel-bg)',
                            borderColor: 'var(--ds-border)',
                        }"
                    >
                        <button
                            type="button"
                            role="menuitem"
                            data-testid="send-resolve"
                            class="block w-full rounded px-2 py-1 text-start text-xs"
                            :style="{ color: 'var(--ds-text-primary)' }"
                            @click="
                                splitOpen = false;
                                send('resolve');
                            "
                        >
                            {{ t.send_and_resolve }}
                        </button>
                        <button
                            type="button"
                            role="menuitem"
                            data-testid="send-pending"
                            class="block w-full rounded px-2 py-1 text-start text-xs"
                            :style="{ color: 'var(--ds-text-primary)' }"
                            @click="
                                splitOpen = false;
                                send('pending_customer');
                            "
                        >
                            {{ t.send_and_pending }}
                        </button>
                    </div>
                </div>
            </div>

            <p
                v-if="mode === 'reply' && channel === 'telegram'"
                data-testid="downgrade-hint"
                class="mt-1 text-[10px]"
                :style="{ color: 'var(--ds-text-faint)' }"
            >
                {{ t.caps_downgrade }}
            </p>

            <!-- FR-19: the palette (macros = Doc 12's seam, honest empty). -->
            <div
                v-if="paletteOpen"
                data-testid="macro-palette"
                class="mt-2 rounded-md border p-2"
                :style="{ background: 'var(--ds-panel-soft)', borderColor: 'var(--ds-border)' }"
            >
                <p
                    class="text-[10px] font-semibold uppercase"
                    :style="{ color: 'var(--ds-text-label)' }"
                >
                    {{ t.palette_title }}
                </p>
                <p class="mt-1 text-xs" :style="{ color: 'var(--ds-text-muted)' }">
                    {{ t.palette_empty }}
                </p>
            </div>
        </div>

        <!-- Doc 06 EC-08: the reopen prompt dialog. -->
        <div
            v-if="reopenPrompt"
            data-testid="reopen-dialog"
            role="alertdialog"
            aria-modal="true"
            :aria-label="t.reopen_title"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-6"
        >
            <div class="w-80 rounded-lg p-4" :style="{ background: 'var(--ds-panel-bg)' }">
                <p class="text-sm font-semibold" :style="{ color: 'var(--ds-text-primary)' }">
                    {{ t.reopen_title }}
                </p>
                <p class="mt-1 text-xs" :style="{ color: 'var(--ds-text-secondary)' }">
                    {{ t.reopen_hint }}
                </p>
                <div class="mt-3 flex justify-end gap-2">
                    <button
                        type="button"
                        data-testid="reopen-cancel"
                        class="rounded border px-2 py-1 text-xs"
                        :style="{
                            borderColor: 'var(--ds-border)',
                            color: 'var(--ds-text-secondary)',
                        }"
                        @click="reopenPrompt = false"
                    >
                        {{ t.reopen_cancel }}
                    </button>
                    <button
                        type="button"
                        data-testid="reopen-confirm"
                        class="rounded px-2 py-1 text-xs font-semibold"
                        :style="{
                            background: 'var(--ds-accent)',
                            color: 'var(--ds-accent-contrast)',
                        }"
                        @click="send(pendingThen, true)"
                    >
                        {{ t.reopen_confirm }}
                    </button>
                </div>
            </div>
        </div>

        <!-- FR-16: the send-race non-blocking confirm. -->
        <div
            v-if="raceConfirmOpen"
            data-testid="race-dialog"
            role="alertdialog"
            aria-modal="true"
            :aria-label="label('send_race_title', { name: raceBy ?? '' })"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-6"
        >
            <div class="w-80 rounded-lg p-4" :style="{ background: 'var(--ds-panel-bg)' }">
                <p class="text-sm font-semibold" :style="{ color: 'var(--ds-text-primary)' }">
                    {{ label('send_race_title', { name: raceBy ?? '' }) }}
                </p>
                <div class="mt-3 flex justify-end gap-2">
                    <button
                        type="button"
                        data-testid="race-cancel"
                        class="rounded border px-2 py-1 text-xs"
                        :style="{
                            borderColor: 'var(--ds-border)',
                            color: 'var(--ds-text-secondary)',
                        }"
                        @click="raceConfirmOpen = false"
                    >
                        {{ t.send_race_cancel }}
                    </button>
                    <button
                        type="button"
                        data-testid="race-send"
                        class="rounded px-2 py-1 text-xs font-semibold"
                        :style="{
                            background: 'var(--ds-accent)',
                            color: 'var(--ds-accent-contrast)',
                        }"
                        @click="
                            raceBy = null;
                            raceConfirmOpen = false;
                            send(pendingThen);
                        "
                    >
                        {{ t.send_race_send }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
