<script setup lang="ts">
// Doc 09 FR-18 Guardrails (S09 step 12, Option A [B8]): blocked topics,
// disclosure WORDING (presence is code), RG template text (destructive tier:
// edits go through R-14 review), max_ai_messages, profanity policy, PII
// redaction toggle. Draft → publish through the audited door.
import { ref } from 'vue';
import { adminCall } from './adminApi';

const props = defineProps<{
    t: Record<string, string>;
    published: { id: string; version: number; payload: Record<string, unknown> } | null;
    history: { id: string; version: number; status: string; created_at: string }[];
    defaults: {
        disclosure: Record<string, string>;
        rg_template: Record<string, string>;
        blocked_topics: string[];
    };
}>();

const p = props.published?.payload ?? {};
const blocked = ref<string[]>(
    Array.isArray(p.blocked_topics)
        ? (p.blocked_topics as string[])
        : [...props.defaults.blocked_topics],
);
const newTopic = ref('');
const disclosure = ref<Record<string, string>>({
    en: String((p.disclosure as Record<string, string> | undefined)?.en ?? ''),
    am: String((p.disclosure as Record<string, string> | undefined)?.am ?? ''),
});
const rgTemplate = ref<Record<string, string>>({
    en: String((p.rg_template as Record<string, string> | undefined)?.en ?? ''),
    am: String((p.rg_template as Record<string, string> | undefined)?.am ?? ''),
});
const maxAi = ref(Number(p.max_ai_messages ?? 8));
const profanity = ref(String(p.profanity_policy ?? 'soften'));
const pii = ref(p.pii_redaction !== false);
const message = ref('');
const draftId = ref<string | null>(null);

async function save(): Promise<void> {
    try {
        const r = await adminCall<{ version_id: string }>('/admin/ai/guardrails/draft', {
            blocked_topics: blocked.value,
            disclosure: disclosure.value,
            rg_template: rgTemplate.value,
            max_ai_messages: maxAi.value,
            profanity_policy: profanity.value,
            pii_redaction: pii.value,
        });
        draftId.value = r.version_id;
        message.value = props.t.draft_saved ?? '';
    } catch (e) {
        message.value = (e as Error).message;
    }
}
async function publish(): Promise<void> {
    if (!draftId.value) return;
    try {
        await adminCall(`/admin/ai/versions/${draftId.value}/publish`, {});
        window.location.reload();
    } catch (e) {
        message.value = (e as Error).message;
    }
}
</script>

<template>
    <main
        class="mx-auto max-w-3xl space-y-4 p-4 text-start text-sm"
        :style="{ color: 'var(--ds-text-primary)' }"
    >
        <h1 class="text-lg font-semibold">✦ {{ t.guardrails_title }}</h1>
        <p
            v-if="message"
            role="status"
            data-testid="guardrails-message"
            class="rounded border px-2 py-1 text-xs"
            :style="{ borderColor: 'var(--ds-border-strong)' }"
        >
            {{ message }}
        </p>

        <section :aria-label="t.blocked_topics">
            <h2 class="text-xs font-semibold">{{ t.blocked_topics }}</h2>
            <ul class="mt-1 flex flex-wrap gap-1">
                <li
                    v-for="(topic, i) in blocked"
                    :key="topic"
                    class="rounded border px-1.5 py-0.5 text-xs"
                    :style="{ borderColor: 'var(--ds-border-strong)' }"
                >
                    {{ topic }}
                    <button
                        type="button"
                        class="ms-1"
                        :aria-label="t.remove"
                        @click="blocked.splice(i, 1)"
                    >
                        ×
                    </button>
                </li>
            </ul>
            <div class="mt-1 flex gap-1">
                <input
                    v-model="newTopic"
                    data-testid="blocked-topic-input"
                    class="rounded border px-2 py-1 text-xs"
                    :style="{
                        borderColor: 'var(--ds-border-strong)',
                        background: 'var(--ds-panel-bg)',
                    }"
                />
                <button
                    type="button"
                    class="rounded border px-2 py-1 text-xs"
                    @click="newTopic.trim() && (blocked.push(newTopic.trim()), (newTopic = ''))"
                >
                    {{ t.add }}
                </button>
            </div>
        </section>

        <section
            v-for="lang in ['en', 'am']"
            :key="lang"
            class="rounded border p-2"
            :style="{ borderColor: 'var(--ds-border-strong)' }"
        >
            <h2 class="text-xs font-semibold">{{ lang }}</h2>
            <label class="mt-1 block text-xs"
                >{{ t.disclosure_wording }}
                <input
                    v-model="disclosure[lang]"
                    :placeholder="defaults.disclosure[lang]"
                    class="mt-1 w-full rounded border px-2 py-1"
                    :style="{
                        borderColor: 'var(--ds-border-strong)',
                        background: 'var(--ds-panel-bg)',
                    }"
                />
            </label>
            <label class="mt-2 block text-xs"
                >{{ t.rg_template }}
                <span :style="{ color: 'var(--ds-status-pending-fg)' }"
                    >({{ t.destructive_tier }})</span
                >
                <textarea
                    v-model="rgTemplate[lang]"
                    :placeholder="defaults.rg_template[lang]"
                    rows="3"
                    class="mt-1 w-full rounded border px-2 py-1"
                    :style="{
                        borderColor: 'var(--ds-border-strong)',
                        background: 'var(--ds-panel-bg)',
                    }"
                />
            </label>
        </section>

        <div class="grid grid-cols-3 gap-3 text-xs">
            <label
                >{{ t.max_ai_messages
                }}<input
                    v-model.number="maxAi"
                    type="number"
                    min="1"
                    max="50"
                    class="mt-1 w-full rounded border px-2 py-1"
                    :style="{
                        borderColor: 'var(--ds-border-strong)',
                        background: 'var(--ds-panel-bg)',
                    }"
            /></label>
            <label
                >{{ t.profanity_policy }}
                <select
                    v-model="profanity"
                    class="mt-1 w-full rounded border px-2 py-1"
                    :style="{
                        borderColor: 'var(--ds-border-strong)',
                        background: 'var(--ds-panel-bg)',
                    }"
                >
                    <option value="block">{{ t.profanity_block }}</option>
                    <option value="soften">{{ t.profanity_soften }}</option>
                    <option value="allow">{{ t.profanity_allow }}</option>
                </select>
            </label>
            <label class="flex items-end gap-2"
                ><input v-model="pii" type="checkbox" /> {{ t.pii_redaction }}</label
            >
        </div>

        <div class="flex gap-2">
            <button
                type="button"
                data-testid="guardrails-save"
                class="rounded border px-3 py-1 text-xs"
                :style="{ borderColor: 'var(--ds-ai-fg)', color: 'var(--ds-ai-fg)' }"
                @click="save"
            >
                {{ t.save_draft }}
            </button>
            <button
                type="button"
                data-testid="guardrails-publish"
                class="rounded px-3 py-1 text-xs font-semibold"
                :style="{ background: 'var(--ds-ai-fg)', color: 'var(--ds-panel-bg)' }"
                :disabled="!draftId"
                @click="publish"
            >
                {{ t.publish }}
            </button>
        </div>
    </main>
</template>
