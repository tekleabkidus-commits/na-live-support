<script setup lang="ts">
// Doc 09 FR-14 — the Copilot tab (fills Doc 07's ContextPanel #copilot slot).
// Option A (R-D09-2): functional, themed, i18n-keyed, design-system tokens
// only; pixel fidelity attaches at the Batch 8 freeze [B8].
// §13: every section is a labelled region; the streamed/polled card is
// role=status (announced politely); confidence is TEXT (never color-only);
// Insert/Regenerate/Discard are buttons; after Insert, focus returns to the
// composer (the parent's `focusComposer` callback). §8: 10 s → degraded card.
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { awaitCard, copilotApi, type CopilotCard, type CopilotPanel } from './copilotApi';

const props = defineProps<{
    conversationId: string | null;
    canInsert: boolean;
    t: Record<string, string>;
    // The composer hook: (text, kind, artifactId) → the parent puts text in the composer.
    insert: (text: string, kind: string, artifactId: string) => void;
}>();

const panel = ref<CopilotPanel | null>(null);
const card = ref<CopilotCard | null>(null);
const busy = ref(false);
const failed = ref(false);
const announcement = ref('');
let refreshTimer: number | null = null;

function fill(template: string | undefined, vars: Record<string, string>): string {
    let out = template ?? '';
    for (const [k, v] of Object.entries(vars)) out = out.replaceAll(':' + k, v);
    return out;
}

const confidenceLabel = computed(() => {
    const c = card.value?.confidence;
    if (typeof c !== 'number') return '';
    const band = c >= 0.8 ? 'high' : c >= 0.5 ? 'medium' : 'low';
    return fill(props.t['confidence_' + band], { pct: String(Math.round(c * 100)) });
});

async function loadPanel(): Promise<void> {
    if (!props.conversationId) return;
    try {
        panel.value = await copilotApi.panel(props.conversationId);
        failed.value = false;
        // FR-14: the summary refreshes on new messages (the server debounces 10 s); re-read while refreshing.
        if (panel.value.summary_state === 'refreshing' && refreshTimer === null) {
            refreshTimer = window.setTimeout(() => {
                refreshTimer = null;
                void loadPanel();
            }, 3000);
        }
    } catch {
        failed.value = true;
    }
}

async function run(kind: 'draft'): Promise<void> {
    if (!props.conversationId || busy.value) return;
    busy.value = true;
    card.value = { state: 'pending' };
    try {
        const { request_id } = await copilotApi.draft(props.conversationId);
        card.value = await awaitCard(props.conversationId, request_id);
        announcement.value = props.t['state_' + card.value.state] ?? '';
    } catch {
        card.value = { state: 'degraded', notices: ['ai.copilot.notice.degraded'] };
    } finally {
        busy.value = false;
    }
    void kind;
}

async function onInsert(): Promise<void> {
    const c = card.value;
    if (!c?.artifact_id || !c.text || !props.canInsert) return;
    try {
        const inserted = await copilotApi.insert(c.artifact_id);
        props.insert(inserted.text, inserted.kind, inserted.artifact_id);
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
            /* the row is the agent's own; a failed discard is retried by the next action */
        }
    }
    card.value = null;
}

function insertKbLink(ref_: string): void {
    // FR-14 "insert-as-link": the article ref as a citation line (Doc 10 renders the link target).
    props.insert('📄 ' + ref_, 'kb_link', '');
}

const noticeText = (key: string): string =>
    props.t['notice.' + key.replace('ai.copilot.notice.', '')] ?? key;

watch(
    () => props.conversationId,
    () => {
        card.value = null;
        panel.value = null;
        void loadPanel();
    },
    { immediate: true },
);

onBeforeUnmount(() => {
    if (refreshTimer !== null) window.clearTimeout(refreshTimer);
});

defineExpose({ loadPanel });
</script>

<template>
    <div class="space-y-3 text-start text-sm" data-testid="copilot-tab">
        <p class="sr-only" role="status" aria-live="polite">{{ announcement }}</p>

        <p v-if="!conversationId" class="text-xs" :style="{ color: 'var(--ds-text-muted)' }">
            {{ t.empty }}
        </p>
        <p v-else-if="failed" class="text-xs" :style="{ color: 'var(--ds-text-muted)' }">
            {{ t.panel_failed }}
        </p>

        <template v-else-if="panel">
            <!-- Chips: intent / sentiment / urgency + confidence as TEXT. -->
            <section :aria-label="t.chips" data-testid="copilot-chips" class="flex flex-wrap gap-1">
                <span
                    v-for="chip in [
                        ['intent', panel.chips?.intent ?? '—'],
                        ['sentiment', panel.chips?.sentiment ?? '—'],
                        ['urgency', panel.chips?.urgency ?? '—'],
                    ]"
                    :key="String(chip[0])"
                    class="rounded border px-1.5 py-0.5 text-[10.5px]"
                    :style="{
                        borderColor: 'var(--ds-ai-fg)',
                        color: 'var(--ds-ai-fg)',
                        background: 'var(--ds-ai-bg)',
                    }"
                    >✦ {{ t['chip_' + String(chip[0])] }}: {{ chip[1] }}</span
                >
            </section>

            <!-- Summary (refresh state as text). -->
            <section
                :aria-label="t.summary"
                data-testid="copilot-summary"
                class="rounded border p-2"
                :style="{ borderColor: 'var(--ds-border-strong)' }"
            >
                <h3 class="text-xs font-semibold" :style="{ color: 'var(--ds-ai-fg)' }">
                    ✦ {{ t.summary }}
                </h3>
                <p
                    v-if="panel.summary"
                    class="mt-1 text-xs"
                    :style="{ color: 'var(--ds-text-primary)' }"
                >
                    {{ panel.summary }}
                </p>
                <p class="mt-1 text-[10.5px]" :style="{ color: 'var(--ds-text-muted)' }">
                    {{ t['summary_' + panel.summary_state] }}
                </p>
            </section>

            <!-- Guardrail notices: amber inline strips (§8). -->
            <ul v-if="panel.notices.length" data-testid="copilot-notices" class="space-y-1">
                <li
                    v-for="n in panel.notices"
                    :key="n"
                    class="rounded border-s-4 px-2 py-1 text-xs"
                    :style="{
                        borderColor: 'var(--ds-status-pending-fg)',
                        background: 'var(--ds-status-pending-bg)',
                        color: 'var(--ds-text-primary)',
                    }"
                >
                    {{ noticeText(n) }}
                </li>
            </ul>

            <!-- Suggested reply card: [Insert] [Regenerate] [Discard]; low-confidence BANNER instead of a bad draft. -->
            <section
                :aria-label="t.suggested_reply"
                data-testid="copilot-card"
                class="rounded border p-2"
                :style="{ borderColor: 'var(--ds-ai-fg)', background: 'var(--ds-ai-bg)' }"
            >
                <h3 class="text-xs font-semibold" :style="{ color: 'var(--ds-ai-fg)' }">
                    ✦ {{ t.suggested_reply }}
                </h3>
                <div role="status" aria-live="polite" class="mt-1">
                    <p v-if="!card" class="text-xs" :style="{ color: 'var(--ds-text-muted)' }">
                        {{ t.card_idle }}
                    </p>
                    <p
                        v-else-if="card.state === 'pending'"
                        class="text-xs"
                        :style="{ color: 'var(--ds-text-muted)' }"
                        data-testid="card-pending"
                    >
                        {{ t.state_pending }}
                    </p>
                    <template v-else-if="card.state === 'artifact'">
                        <p
                            data-testid="card-text"
                            class="whitespace-pre-wrap text-xs"
                            :style="{ color: 'var(--ds-text-primary)' }"
                        >
                            {{ card.text }}
                        </p>
                        <p
                            v-if="confidenceLabel"
                            data-testid="card-confidence"
                            class="mt-1 text-[10.5px]"
                            :style="{ color: 'var(--ds-text-muted)' }"
                        >
                            {{ confidenceLabel }}
                        </p>
                        <ul
                            v-if="card.citations?.length"
                            class="mt-1 text-[10.5px]"
                            :style="{ color: 'var(--ds-text-muted)' }"
                        >
                            <li v-for="c in card.citations" :key="c">📄 {{ c }}</li>
                        </ul>
                    </template>
                    <div
                        v-else
                        data-testid="card-banner"
                        class="rounded border-s-4 px-2 py-1 text-xs"
                        :style="{
                            borderColor: 'var(--ds-status-pending-fg)',
                            background: 'var(--ds-panel-bg)',
                            color: 'var(--ds-text-primary)',
                        }"
                    >
                        <strong>{{ t['state_' + card.state] }}</strong>
                        <span v-for="n in card.notices ?? []" :key="n"> — {{ noticeText(n) }}</span>
                    </div>
                    <ul
                        v-if="card?.notices?.length && card.state === 'artifact'"
                        class="mt-1 space-y-1"
                    >
                        <li
                            v-for="n in card.notices"
                            :key="n"
                            class="rounded border-s-4 px-2 py-1 text-[10.5px]"
                            :style="{
                                borderColor: 'var(--ds-status-pending-fg)',
                                background: 'var(--ds-status-pending-bg)',
                            }"
                        >
                            {{ noticeText(n) }}
                        </li>
                    </ul>
                </div>
                <div class="mt-2 flex flex-wrap gap-2">
                    <button
                        v-if="card?.state === 'artifact'"
                        type="button"
                        data-testid="copilot-insert"
                        class="rounded px-2 py-1 text-xs font-semibold"
                        :style="{ background: 'var(--ds-ai-fg)', color: 'var(--ds-panel-bg)' }"
                        :disabled="!canInsert"
                        :title="canInsert ? '' : t.insert_forbidden"
                        @click="onInsert"
                    >
                        {{ t.insert }}
                    </button>
                    <button
                        type="button"
                        data-testid="copilot-regenerate"
                        class="rounded border px-2 py-1 text-xs"
                        :style="{ borderColor: 'var(--ds-ai-fg)', color: 'var(--ds-ai-fg)' }"
                        :disabled="busy || card?.state === 'locked'"
                        @click="run('draft')"
                    >
                        {{ card ? t.regenerate : t.suggest }}
                        <span v-if="card?.regenerations" class="tabular-nums">
                            ({{ card.regenerations.used }}/{{ card.regenerations.limit }})</span
                        >
                    </button>
                    <button
                        v-if="card && card.state !== 'pending'"
                        type="button"
                        data-testid="copilot-discard"
                        class="rounded border px-2 py-1 text-xs"
                        :style="{
                            borderColor: 'var(--ds-border-strong)',
                            color: 'var(--ds-text-muted)',
                        }"
                        @click="onDiscard"
                    >
                        {{ t.discard }}
                    </button>
                </div>
            </section>

            <!-- KB top 3 with insert-as-link. -->
            <section :aria-label="t.kb" data-testid="copilot-kb">
                <h3 class="text-xs font-semibold" :style="{ color: 'var(--ds-text-label)' }">
                    {{ t.kb }}
                </h3>
                <ul v-if="(card?.kb ?? panel.kb).length" class="mt-1 space-y-1">
                    <li
                        v-for="item in card?.kb ?? panel.kb"
                        :key="item.article_ref"
                        class="flex items-start gap-2 text-xs"
                    >
                        <span class="min-w-0 flex-1" :style="{ color: 'var(--ds-text-secondary)' }"
                            ><span class="font-mono text-[10px]">{{ item.article_ref }}</span> —
                            {{ item.excerpt }}</span
                        >
                        <button
                            type="button"
                            class="shrink-0 underline"
                            :style="{ color: 'var(--ds-ai-fg)' }"
                            @click="insertKbLink(item.article_ref)"
                        >
                            {{ t.insert_link }}
                        </button>
                    </li>
                </ul>
                <p v-else class="mt-1 text-[10.5px]" :style="{ color: 'var(--ds-text-muted)' }">
                    {{ t.kb_empty }}
                </p>
            </section>

            <!-- Similar resolved tickets (scope-checked server-side). -->
            <section :aria-label="t.similar" data-testid="copilot-similar">
                <h3 class="text-xs font-semibold" :style="{ color: 'var(--ds-text-label)' }">
                    {{ t.similar }}
                </h3>
                <ul v-if="panel.similar_tickets.length" class="mt-1 space-y-1">
                    <li
                        v-for="s in panel.similar_tickets"
                        :key="s.ticket_id"
                        class="text-xs"
                        :style="{ color: 'var(--ds-text-secondary)' }"
                    >
                        <span class="font-semibold tabular-nums">#{{ s.number ?? '—' }}</span>
                        {{ s.subject ?? '' }} —
                        <span :style="{ color: 'var(--ds-text-muted)' }">{{ s.summary }}</span>
                    </li>
                </ul>
                <p v-else class="mt-1 text-[10.5px]" :style="{ color: 'var(--ds-text-muted)' }">
                    {{ t.similar_empty }}
                </p>
            </section>

            <!-- Macro suggestions: Doc 12's seam, honest empty. -->
            <section :aria-label="t.macros" data-testid="copilot-macros">
                <h3 class="text-xs font-semibold" :style="{ color: 'var(--ds-text-label)' }">
                    {{ t.macros }}
                </h3>
                <p class="mt-1 text-[10.5px]" :style="{ color: 'var(--ds-text-muted)' }">
                    {{ t.macros_seam }}
                </p>
            </section>
        </template>
        <p v-else class="text-xs" :style="{ color: 'var(--ds-text-muted)' }">{{ t.loading }}</p>
    </div>
</template>
