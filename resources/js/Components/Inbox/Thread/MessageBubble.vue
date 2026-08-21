<script setup lang="ts">
// FR-14 — one message, the full Doc 04 §8/§9 contract: in/out bubbles,
// note style (structurally distinct — N-04's visual arm), tombstones
// (presence, never content), media (photo opens the B12-S01 lightbox),
// delivery states with DOC 05 AC-07's LAW: the UI NEVER renders a
// delivered state for Telegram — a 'delivered' payload draws the SENT
// glyph on a telegram thread (sabotage #7 gives delivered its own glyph
// and the DOM assertion goes red). B12-S02: the edited marker with
// history-by-capability. EC-10: 40-line clamp with "show more".
// US-03 (Doc 05 closing row): owner-phone attribution renders MARKED.
import { computed, ref } from 'vue';

type Media = { url?: string; kind?: string; alt_text?: string | null; scan_status?: string };

const props = defineProps<{
    message: Record<string, unknown>;
    channel: string;
    t: Record<string, string>;
}>();

const emit = defineEmits<{
    (e: 'open-media', media: Media): void;
    (e: 'open-history', id: string): void;
    (e: 'jump-to', id: string): void;
}>();

const str = (key: string): string => {
    const value = props.message[key];
    return typeof value === 'string' ? value : '';
};

const isOut = computed(() => str('direction') === 'out');
const isNote = computed(() => props.message.is_internal === true);
const isDeleted = computed(() => str('deleted_at') !== '');

const meta = computed(() => (props.message.channel_meta ?? {}) as Record<string, unknown>);

// S09 step 12 (the COUNTERSIGNED Doc 07 slot-fill packet) — Doc 09 FR-19c /
// AC-05: an AI-authored message carries the AI accent AND a text label (never
// colour alone); FR-15: a translated agent message carries the original on
// hover/title — ABSENT when channel_meta has no ai_translation.
const isAi = computed(() => str('author_type') === 'ai');
const showOriginal = ref(false);
const aiTranslation = computed(() => {
    const raw = meta.value.ai_translation;
    if (typeof raw !== 'object' || raw === null) return null;
    const record = raw as Record<string, unknown>;
    return typeof record.original_text === 'string'
        ? {
              original: record.original_text,
              source: typeof record.source_language === 'string' ? record.source_language : '',
          }
        : null;
});
const ownerPhone = computed(() => meta.value.reported_agent_type === 'owner_phone');

// ── AC-07 (Doc 05, the closing proof): the truthful-receipts glyph map.
// 'delivered' COLLAPSES INTO 'sent' for telegram — there is no delivered
// glyph in this DOM, across ALL message states. Sabotage #7's target.
const deliveryGlyph = computed((): { glyph: string; state: string; label: string } | null => {
    const state = str('delivery_state');
    if (!isOut.value || state === '') {
        return null;
    }
    const truthful = props.channel === 'telegram' && state === 'delivered' ? 'sent' : state;
    const map: Record<string, { glyph: string; label: string }> = {
        queued: { glyph: '◷', label: props.t.state_queued ?? '' },
        sending: { glyph: '…', label: props.t.state_sending ?? '' },
        sent: { glyph: '✓', label: props.t.state_sent ?? '' },
        delivered: { glyph: '✓✓', label: props.t.state_sent ?? '' },
        failed_retryable: { glyph: '⚠', label: props.t.state_failed ?? '' },
        failed_final: { glyph: '⚠', label: props.t.state_failed ?? '' },
        canceled: { glyph: '⊘', label: props.t.state_canceled ?? '' },
    };
    const entry = map[truthful] ?? null;

    return entry === null ? null : { glyph: entry.glyph, state: truthful, label: entry.label };
});

// EC-10: clamp at 40 lines; "show more" expands.
const expanded = ref(false);
const lines = computed(() => str('body').split('\n'));
const clamped = computed(() => lines.value.length > 40 && !expanded.value);
const visibleBody = computed(() =>
    clamped.value ? lines.value.slice(0, 40).join('\n') : str('body'),
);

const mediaItems = computed(() =>
    Array.isArray(props.message.media) ? (props.message.media as Media[]) : [],
);

const time = computed(() => {
    const stamp = str('created_at');
    return stamp
        ? new Date(stamp).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
        : '';
});
</script>

<template>
    <div
        :data-message="message.id"
        :data-kind="str('kind')"
        class="flex"
        :class="isOut ? 'justify-end' : 'justify-start'"
    >
        <div
            class="max-w-[75%] rounded-lg px-3 py-2"
            :style="
                isNote
                    ? {
                          background: 'var(--ds-note-bg)',
                          color: 'var(--ds-note-fg)',
                          border: '1px dashed var(--ds-border-strong)',
                      }
                    : isAi
                      ? {
                            background: 'var(--ds-ai-bg)',
                            color: 'var(--ds-ai-fg)',
                            border: '1px solid var(--ds-ai-fg)',
                        }
                      : isOut
                        ? { background: 'var(--ds-accent)', color: 'var(--ds-accent-contrast)' }
                        : { background: 'var(--ds-panel-soft)', color: 'var(--ds-text-primary)' }
            "
        >
            <!-- Note banner (N-04's visual arm). -->
            <p
                v-if="isNote"
                class="mb-1 text-[10px] font-bold uppercase tracking-wide"
                data-role="note-banner"
            >
                🔒 {{ t.internal_note }}
            </p>
            <!-- Doc 09 FR-19c: the AI marker — accent + TEXT, on every AI-authored message. -->
            <p
                v-if="isAi"
                class="mb-1 text-[10px] font-bold uppercase tracking-wide"
                data-role="ai-marker"
            >
                ✦ {{ t.ai_author }}
            </p>
            <!-- Doc 09 FR-15: the translation marker — the agent's original on hover/title; absent without ai_translation. -->
            <div
                v-if="aiTranslation"
                class="mb-1 text-[10px]"
                data-role="ai-translation"
                :title="(t.translated_from ?? '').replace(':original', aiTranslation.original)"
                :style="{ color: 'var(--ds-ai-fg)' }"
            >
                ✦ {{ t.translated }}
                <button type="button" class="ms-1 underline" @click="showOriginal = !showOriginal">
                    {{ t.translated_show_original }}
                </button>
                <span
                    v-if="showOriginal"
                    data-role="ai-translation-original"
                    class="block"
                    :style="{ color: 'var(--ds-text-primary)' }"
                    >{{ aiTranslation.original }}</span
                >
            </div>

            <!-- US-03: owner-phone attribution, marked. -->
            <p
                v-if="ownerPhone"
                data-role="owner-phone-chip"
                class="mb-1 text-[10px] font-semibold"
            >
                📱 {{ t.owner_phone
                }}<template v-if="meta.owner_phone_display">
                    · {{ meta.owner_phone_display }}</template
                >
            </p>

            <!-- Reply reference (Doc 04 EC-08: click jumps, fetching context). -->
            <button
                v-if="str('reply_to_message_id')"
                type="button"
                data-role="reply-ref"
                class="mb-1 block w-full truncate rounded border-s-2 px-1.5 py-0.5 text-start text-[10px]"
                :style="{ borderColor: 'var(--ds-border-strong)' }"
                @click="emit('jump-to', str('reply_to_message_id'))"
            >
                {{ t.reply_ref }}
            </button>

            <!-- Tombstone: presence, never content. -->
            <p v-if="isDeleted" data-role="tombstone" class="text-xs italic">
                {{ t.deleted }}
            </p>

            <template v-else>
                <!-- Media -->
                <div v-for="(item, index) in mediaItems" :key="index" class="mb-1">
                    <button
                        v-if="item.kind === 'photo' && item.scan_status === 'clean'"
                        type="button"
                        data-role="media-photo"
                        class="rounded border px-2 py-1 text-xs underline"
                        :style="{ borderColor: 'var(--ds-border-soft)' }"
                        :aria-label="t.open_photo"
                        @click="emit('open-media', item)"
                    >
                        📷 {{ t.photo }}
                    </button>
                    <button
                        v-else-if="item.scan_status === 'infected'"
                        type="button"
                        data-role="media-infected"
                        class="rounded border px-2 py-1 text-xs"
                        :style="{ borderColor: 'var(--ds-danger-border)' }"
                        @click="emit('open-media', item)"
                    >
                        ⛔ {{ t.media_infected }}
                    </button>
                    <span
                        v-else-if="item.scan_status === 'pending'"
                        data-role="media-pending"
                        class="text-xs"
                    >
                        {{ t.media_pending }}
                    </span>
                </div>

                <!-- Body with EC-10's clamp. -->
                <p
                    v-if="visibleBody"
                    data-role="body"
                    class="whitespace-pre-wrap break-words text-sm"
                >
                    {{ visibleBody }}
                </p>
                <button
                    v-if="lines.length > 40"
                    type="button"
                    data-role="clamp-toggle"
                    class="mt-1 text-xs underline"
                    @click="expanded = !expanded"
                >
                    {{ expanded ? t.show_less : t.show_more }}
                </button>
            </template>

            <!-- Meta line: time, edited marker (B12-S02), delivery glyph. -->
            <p class="mt-1 flex items-center gap-1.5 text-[10px]">
                <span>{{ time }}</span>
                <button
                    v-if="str('edited_at') && !isDeleted"
                    type="button"
                    data-role="edited-marker"
                    class="underline"
                    @click="emit('open-history', String(message.id))"
                >
                    {{ t.edited }}
                </button>
                <span
                    v-if="deliveryGlyph"
                    :data-delivery-glyph="deliveryGlyph.state"
                    role="img"
                    :aria-label="deliveryGlyph.label"
                    >{{ deliveryGlyph.glyph }} {{ deliveryGlyph.label }}</span
                >
            </p>
        </div>
    </div>
</template>
