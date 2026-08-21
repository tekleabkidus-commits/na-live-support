<script setup lang="ts">
// Doc 09 FR-15 — the composer's AI actions (fills Doc 07 FR-19's AI slot):
// Draft reply · Improve · Translate · Summarize (→ note draft). Each is a
// queued request; the card is polled (§8 10 s → degraded). The result is
// INSERTED into the composer via the parent's hook — never sent (BR-01).
import { ref } from 'vue';
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

const busy = ref<string | null>(null);
const card = ref<CopilotCard | null>(null);
const announcement = ref('');

const noticeText = (key: string): string =>
    props.t['notice.' + key.replace('ai.copilot.notice.', '')] ?? key;

async function run(action: 'draft' | 'improve' | 'translate' | 'summarize'): Promise<void> {
    if (!props.conversationId || busy.value) return;
    if ((action === 'improve' || action === 'translate') && props.body.trim() === '') {
        announcement.value = props.t.needs_text ?? '';
        return;
    }
    busy.value = action;
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
        card.value = await awaitCard(props.conversationId, request_id);
        announcement.value = props.t['state_' + card.value.state] ?? '';
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
    <div class="flex flex-wrap items-center gap-1" data-testid="ai-actions">
        <p class="sr-only" role="status" aria-live="polite">{{ announcement }}</p>
        <span class="text-[10px]" :style="{ color: 'var(--ds-ai-fg)' }">✦</span>
        <button
            v-for="a in ['draft', 'improve', 'translate', 'summarize'] as const"
            :key="a"
            type="button"
            :data-testid="'ai-action-' + a"
            class="rounded border px-1.5 py-0.5 text-[10px]"
            :style="{
                borderColor: 'var(--ds-ai-fg)',
                color: 'var(--ds-ai-fg)',
                background: 'var(--ds-ai-bg)',
            }"
            :disabled="busy !== null || !conversationId"
            :aria-busy="busy === a"
            @click="run(a)"
        >
            {{ t['action_' + a] }}
        </button>

        <div
            v-if="card"
            data-testid="ai-action-card"
            role="status"
            aria-live="polite"
            class="mt-1 w-full rounded border p-2 text-xs"
            :style="{
                borderColor: 'var(--ds-ai-fg)',
                background: 'var(--ds-ai-bg)',
                color: 'var(--ds-text-primary)',
            }"
        >
            <p v-if="card.state === 'pending'" :style="{ color: 'var(--ds-text-muted)' }">
                {{ t.state_pending }}
            </p>
            <template v-else-if="card.state === 'artifact'">
                <p class="text-[10px] font-semibold" :style="{ color: 'var(--ds-ai-fg)' }">
                    ✦ {{ t['kind_' + (card.kind ?? '')] ?? card.kind }}
                </p>
                <p data-testid="ai-action-text" class="whitespace-pre-wrap">{{ card.text }}</p>
                <div class="mt-1 flex gap-2">
                    <button
                        type="button"
                        data-testid="ai-action-insert"
                        class="rounded px-2 py-0.5 text-[10px] font-semibold"
                        :style="{ background: 'var(--ds-ai-fg)', color: 'var(--ds-panel-bg)' }"
                        :disabled="!canInsert"
                        @click="onInsert"
                    >
                        {{ t.insert }}
                    </button>
                    <button
                        type="button"
                        data-testid="ai-action-discard"
                        class="rounded border px-2 py-0.5 text-[10px]"
                        :style="{
                            borderColor: 'var(--ds-border-strong)',
                            color: 'var(--ds-text-muted)',
                        }"
                        @click="onDiscard"
                    >
                        {{ t.discard }}
                    </button>
                </div>
            </template>
            <div
                v-else
                data-testid="ai-action-banner"
                class="rounded border-s-4 px-2 py-1"
                :style="{ borderColor: 'var(--ds-status-pending-fg)' }"
            >
                <strong>{{ t['state_' + card.state] }}</strong>
                <span v-for="n in card.notices ?? []" :key="n"> — {{ noticeText(n) }}</span>
                <button type="button" class="ms-2 underline" @click="card = null">
                    {{ t.dismiss }}
                </button>
            </div>
        </div>
    </div>
</template>
