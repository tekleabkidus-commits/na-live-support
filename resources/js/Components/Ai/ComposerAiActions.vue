<script setup lang="ts">
// Doc 09 FR-15 — the composer's AI actions (fills Doc 07 FR-19's AI slot):
// Draft · Improve · Translate · Summarize (→ note draft). Each is a queued
// request; the card is polled (§8 10 s → degraded). The result is INSERTED
// into the composer via the parent's hook — never sent (BR-01, X1).
// SESSION-F1 [B8]: brought to the frozen B8-S02-composer-actions-v1 —
// four labelled violet verbs (no icon-only), one verb at a time with the
// running one saying so, the AI-OFF labelled degrade (the cluster is gone and
// one line says what still works — read from the kill-switch banner door),
// the Improve card ("meaning kept", whole-text accept, original recoverable),
// the Translate PAIR (original ↔ translation, "digits verified ✓"), the
// validator rejection naming the caught digits with no override, mixed-input
// detection in the label, the summary card that lands in NOTE mode, 44 px at 375.
import { computed, onMounted, ref, watch } from 'vue';
import { awaitCard, copilotApi, type CopilotCard } from './copilotApi';

const props = defineProps<{
    conversationId: string | null;
    body: string;
    mode: 'reply' | 'note';
    canInsert: boolean;
    customerLanguage: string | null;
    t: Record<string, string>;
    insert: (text: string, kind: string, artifactId: string, mode: 'reply' | 'note') => void;
}>();

type Card = CopilotCard & { digits?: { missing: string[]; extra: string[] } };
const ACTIONS = ['draft', 'improve', 'translate', 'summarize'] as const;
type ActionKey = (typeof ACTIONS)[number];
const busy = ref<ActionKey | null>(null);
const card = ref<Card | null>(null);
const original = ref(''); // the text the verb worked on — the pair's first line, recoverable until Insert
const mixedInput = ref(false);
const aiOff = ref(false);
const announcement = ref('');

function fill(template: string | undefined, vars: Record<string, string>): string {
    let out = template ?? '';
    for (const [k, v] of Object.entries(vars)) out = out.replaceAll(':' + k, v);
    return out;
}
const noticeText = (key: string): string =>
    props.t['notice.' + key.replace('ai.copilot.notice.', '')] ?? key;
const langName = (code: string | null | undefined): string =>
    code ? (props.t['lang_' + code] ?? code.toUpperCase()) : '';
const tagName = (code: string | null | undefined): string =>
    code ? (props.t['tag_' + code] ?? code.toUpperCase()) : '';
const isTranslate = computed(() => card.value?.kind === 'translate');
const isImprove = computed(() => card.value?.kind === 'improve_text');
const isSummary = computed(() => card.value?.kind === 'note_draft');
const cardLabel = computed(() => {
    const c = card.value;
    if (!c) return '';
    if (isImprove.value) return props.t.card_improved ?? '';
    if (isTranslate.value) {
        return mixedInput.value
            ? fill(props.t.card_translate_mixed, { to: langName(c.target_language) })
            : fill(props.t.card_translate, {
                  from: langName(c.source_language),
                  to: langName(c.target_language),
              });
    }
    if (isSummary.value) return props.t.card_summary ?? '';
    return props.t['kind_' + (c.kind ?? '')] ?? c.kind ?? '';
});
const digitsCaught = computed(() => {
    const d = card.value?.digits;
    if (!d) return '';
    const from = d.missing[0] ?? '';
    const to = d.extra[0] ?? '';
    return from && to
        ? fill(props.t.reject_digits, { from, to })
        : from
          ? fill(props.t.reject_missing, { digit: from })
          : '';
});
const isDigitRejection = computed(
    () =>
        card.value?.state === 'refused' &&
        (card.value.notices ?? []).includes('ai.copilot.notice.digit_mismatch'),
);

// B01 AI-off: the kill-switch state the workspace banner reads (AC-08's door, every staff member) — the
// cluster is GONE, not greyed, and one line says what still works (R-04's human fallback).
async function readKillSwitch(): Promise<void> {
    try {
        const r = await fetch('/admin/ai/banner', { headers: { Accept: 'application/json' } });
        if (!r.ok) return;
        const json = (await r.json()) as { off?: { surface: string }[] };
        aiOff.value = (json.off ?? []).some((row) => row.surface === 'copilot');
    } catch {
        /* unknown → the cluster stays; an outage surfaces as a degraded card */
    }
}
onMounted(() => void readKillSwitch());
watch(
    () => props.conversationId,
    () => {
        card.value = null;
        void readKillSwitch();
    },
);

async function run(action: ActionKey): Promise<void> {
    if (!props.conversationId || busy.value) return;
    if ((action === 'improve' || action === 'translate') && props.body.trim() === '') {
        announcement.value = props.t.needs_text ?? '';
        return;
    }
    busy.value = action;
    original.value = props.body;
    mixedInput.value = /[ሀ-፿]/.test(props.body) && /[A-Za-z]{2,}/.test(props.body);
    card.value = { state: 'pending' };
    try {
        const { request_id } =
            action === 'draft'
                ? await copilotApi.draft(props.conversationId)
                : await copilotApi.action(props.conversationId, action, {
                      text: props.body,
                      ...(action === 'translate' && props.customerLanguage
                          ? { target_language: props.customerLanguage }
                          : {}),
                  });
        card.value = (await awaitCard(props.conversationId, request_id)) as Card;
        announcement.value =
            card.value.state === 'artifact' && card.value.text
                ? card.value.text
                : (props.t['state_' + card.value.state] ?? '');
    } catch {
        card.value = { state: 'degraded', notices: ['ai.copilot.notice.degraded'] };
    } finally {
        busy.value = null;
    }
}

async function onInsert(): Promise<void> {
    const c = card.value;
    if (!c?.artifact_id || !c.text || !props.canInsert) return;
    try {
        const inserted = await copilotApi.insert(c.artifact_id);
        props.insert(
            inserted.text,
            inserted.kind,
            inserted.artifact_id,
            inserted.kind === 'note_draft' ? 'note' : 'reply',
        );
        card.value = null;
    } catch {
        announcement.value = props.t.insert_failed ?? '';
    }
}

async function onDiscard(): Promise<void> {
    const c = card.value;
    if (c?.artifact_id) {
        try {
            await copilotApi.discard(c.artifact_id);
        } catch {
            /* retried by the next action */
        }
    }
    card.value = null;
}
</script>

<template>
    <div class="cx text-start" data-testid="ai-actions">
        <p class="sr-only" role="status" aria-live="polite">{{ announcement }}</p>

        <!-- B01 AI-off: off is SAID, not implied — the cluster is gone and one line explains what remains -->
        <p
            v-if="aiOff"
            class="cx-banner cx-banner-deg w-full"
            data-testid="ai-actions-off"
            role="status"
        >
            {{ t.ai_off_line }}
        </p>

        <div v-else class="cx-cluster">
            <button
                v-for="a in ACTIONS"
                :key="a"
                type="button"
                :data-testid="'ai-action-' + a"
                class="cx-aibtn"
                :disabled="busy !== null || !conversationId"
                :aria-busy="busy === a"
                @click="run(a)"
            >
                <template v-if="busy === a">◌ {{ t['busy_' + a] }}</template>
                <template v-else>✦ {{ t['action_' + a] }}</template>
            </button>
        </div>

        <section
            v-if="card"
            data-testid="ai-action-card"
            role="status"
            aria-live="polite"
            :aria-label="cardLabel || t.card_region"
            class="cx-cardwrap w-full"
        >
            <!-- pending -->
            <div v-if="card.state === 'pending'" class="cx-aicard">
                <div class="cx-ailabel">
                    <span>✦ {{ t['busy_' + (busy ?? 'draft')] }}</span>
                </div>
                <p class="cx-aitext cx-caret">{{ t.state_pending }}</p>
            </div>

            <!-- the artifact: improve / translate pair / summary / draft -->
            <template v-else-if="card.state === 'artifact'">
                <div class="cx-aicard">
                    <div class="cx-ailabel">
                        <span>✦ {{ cardLabel }}</span>
                        <span v-if="isTranslate || isImprove" data-testid="ai-digits-verified">{{
                            t.digits_verified
                        }}</span>
                    </div>
                    <div v-if="isTranslate" class="cx-pair" data-testid="ai-translate-pair">
                        <span class="cx-tag">{{
                            mixedInput ? t.tag_in : tagName(card.source_language)
                        }}</span>
                        <span class="cx-orig">{{ original }}</span>
                        <span class="cx-tag">{{ tagName(card.target_language) }}</span>
                        <span data-testid="ai-action-text" class="whitespace-pre-wrap">{{
                            card.text
                        }}</span>
                    </div>
                    <p v-else data-testid="ai-action-text" class="cx-aitext whitespace-pre-wrap">
                        {{ card.text }}
                    </p>
                </div>
                <div class="cx-acts">
                    <button
                        v-if="canInsert"
                        type="button"
                        data-testid="ai-action-insert"
                        class="cx-btn cx-btn-pri"
                        @click="onInsert"
                    >
                        {{
                            isImprove
                                ? t.insert_replaces
                                : isTranslate
                                  ? fill(t.insert_translation, {
                                        to: langName(card.target_language),
                                    })
                                  : isSummary
                                    ? t.insert_as_note
                                    : t.insert
                        }}
                    </button>
                    <button
                        type="button"
                        data-testid="ai-action-discard"
                        class="cx-btn"
                        @click="onDiscard"
                    >
                        {{ t.discard }}
                    </button>
                </div>
                <p class="cx-hint">
                    {{
                        isImprove
                            ? t.hint_improve
                            : isTranslate
                              ? t.hint_translate
                              : isSummary
                                ? t.hint_summary
                                : t.hint_draft
                    }}
                </p>
            </template>

            <!-- B05: the validator rejection — the digits it caught, as text; no override -->
            <template v-else-if="isDigitRejection">
                <div class="cx-banner cx-banner-rej" data-testid="ai-action-banner">
                    {{
                        digitsCaught
                            ? fill(t.reject_title, { digits: digitsCaught })
                            : t.reject_title_plain
                    }}
                    <small>{{ t.reject_sub }}</small>
                </div>
                <div class="cx-acts">
                    <button
                        type="button"
                        class="cx-btn"
                        :disabled="busy !== null"
                        data-testid="ai-action-regenerate"
                        @click="run('translate')"
                    >
                        {{ t.regenerate }}
                    </button>
                    <button
                        type="button"
                        data-testid="ai-action-discard"
                        class="cx-btn"
                        @click="onDiscard"
                    >
                        {{ t.discard }}
                    </button>
                </div>
            </template>

            <!-- degraded / busy / refused-for-other-reasons -->
            <template v-else>
                <div class="cx-banner cx-banner-deg" data-testid="ai-action-banner">
                    <strong>{{ t['state_' + card.state] }}</strong>
                    <small v-for="n in card.notices ?? []" :key="n">{{ noticeText(n) }}</small>
                </div>
                <div class="cx-acts">
                    <button
                        type="button"
                        class="cx-btn"
                        data-testid="ai-action-dismiss"
                        @click="card = null"
                    >
                        {{ t.dismiss }}
                    </button>
                </div>
            </template>
        </section>
    </div>
</template>

<style scoped>
/* B8-S02 anatomy — tokens only; the frame's measures verbatim. */
.cx {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 6px;
}
.cx-cluster {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
}
.cx-aibtn {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 11px;
    font-weight: 600;
    color: var(--ds-ai-fg);
    background: var(--ds-ai-bg);
    border: 1px solid var(--ds-ai-border);
    border-radius: 6px;
    padding: 5px 10px;
}
.cx-aibtn[disabled] {
    opacity: 0.55;
}
.cx-cardwrap {
    margin-top: 4px;
}
.cx-aicard {
    background: var(--ds-ai-bg);
    border: 1px solid var(--ds-ai-border);
    border-inline-start: 3px solid var(--ds-ai-edge);
    border-radius: 8px;
    padding: 10px 11px;
}
.cx-ailabel {
    font-size: 9.5px;
    font-weight: 700;
    color: var(--ds-ai-fg);
    letter-spacing: 0.05em;
    text-transform: uppercase;
    margin-bottom: 5px;
    display: flex;
    justify-content: space-between;
    gap: 8px;
    flex-wrap: wrap;
}
.cx-aitext {
    font-size: 12.5px;
    line-height: 1.55;
    color: var(--ds-text-primary);
    margin: 0;
}
.cx-caret::after {
    content: '▍';
    color: var(--ds-ai-fg);
    font-size: 12px;
    animation: none;
}
.cx-pair {
    display: grid;
    grid-template-columns: max-content 1fr;
    gap: 4px 8px;
    font-size: 12.5px;
    line-height: 1.5;
    color: var(--ds-text-primary);
}
.cx-tag {
    font-size: 10px;
    font-weight: 700;
    color: var(--ds-ai-fg);
    padding-top: 2px;
}
.cx-orig {
    color: var(--ds-text-muted);
}
.cx-acts {
    display: flex;
    gap: 8px;
    margin-top: 10px;
    flex-wrap: wrap;
}
.cx-btn {
    font-size: 11px;
    font-weight: 600;
    border-radius: 6px;
    padding: 6px 12px;
    border: 1px solid var(--ds-border-strong);
    background: var(--ds-panel-bg);
    color: var(--ds-text-primary);
}
.cx-btn-pri {
    background: var(--ds-accent);
    color: var(--ds-accent-contrast);
    border-color: var(--ds-accent);
}
.cx-btn[disabled] {
    opacity: 0.55;
}
.cx-hint {
    font-size: 10.5px;
    color: var(--ds-text-muted);
    margin: 7px 0 0;
}
.cx-banner {
    border-radius: 8px;
    padding: 9px 11px;
    font-size: 11.5px;
    line-height: 1.5;
    font-weight: 600;
}
.cx-banner small {
    display: block;
    font-weight: 400;
    margin-top: 4px;
    opacity: 0.92;
}
.cx-banner-deg {
    background: var(--ds-inset-bg);
    border: 1px solid var(--ds-border-strong);
    color: var(--ds-text-secondary);
}
.cx-banner-rej {
    background: var(--ds-danger-bg);
    border: 1px solid var(--ds-danger-surface-border);
    color: var(--ds-danger-text);
}
/* §13 at 375: every verb and card action clears 44 px; the cluster wraps, nothing hides */
@media (max-width: 639px) {
    .cx-aibtn {
        padding: 12px 14px;
        min-height: 44px;
    }
    .cx-btn {
        padding: 12px 16px;
        min-height: 44px;
    }
}
</style>
