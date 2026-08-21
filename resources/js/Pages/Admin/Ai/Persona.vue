<script setup lang="ts">
// Doc 09 FR-18 Persona (S09 step 12, Option A [B8]): name, greeting per
// language, tone sliders; PREVIEW against 5 canned conversations BEFORE
// publish (the publish button unlocks only after a preview of THIS draft).
// The disclosure is shown read-only beside each greeting (FR-19a: wording
// in guardrails, presence gated at publish — a greeting without it is
// refused by the door and the refusal is shown verbatim).
import { computed, ref } from 'vue';
import { adminCall } from './adminApi';

type Preview = {
    state: string;
    results?: {
        dialog: string;
        language: string;
        action: string;
        text: string | null;
        reason: string | null;
    }[];
};
const props = defineProps<{
    t: Record<string, string>;
    published: {
        id: string;
        version: number;
        payload: Record<string, unknown>;
        published_at: string | null;
    } | null;
    history: { id: string; key: string; version: number; status: string; created_at: string }[];
    disclosure: Record<string, string>;
    languages: string[];
}>();

const payload = props.published?.payload ?? {};
const name = ref(String(payload.name ?? ''));
const greeting = ref<Record<string, string>>(
    Object.fromEntries(
        props.languages.map((l) => [
            l,
            String((payload.greeting as Record<string, string> | undefined)?.[l] ?? ''),
        ]),
    ),
);
const tone = ref({
    formality: Number((payload.tone as { formality?: number } | undefined)?.formality ?? 50),
    detail: Number((payload.tone as { detail?: number } | undefined)?.detail ?? 50),
});
const draftId = ref<string | null>(null);
const previewedDraft = ref<string | null>(null);
const preview = ref<Preview | null>(null);
const message = ref('');
const busy = ref(false);

const canPublish = computed(
    () =>
        draftId.value !== null &&
        previewedDraft.value === draftId.value &&
        preview.value?.state === 'ready',
);

async function saveDraft(): Promise<void> {
    busy.value = true;
    message.value = '';
    try {
        const r = await adminCall<{ version_id: string }>('/admin/ai/persona/draft', {
            name: name.value,
            greeting: greeting.value,
            tone: tone.value,
        });
        draftId.value = r.version_id;
        preview.value = null;
        message.value = props.t.draft_saved ?? '';
    } catch (e) {
        message.value = (e as Error).message;
    } finally {
        busy.value = false;
    }
}

async function runPreview(): Promise<void> {
    if (!draftId.value) return;
    busy.value = true;
    try {
        const r = await adminCall<{ preview_id: string }>(
            `/admin/ai/persona/${draftId.value}/preview`,
            {},
        );
        preview.value = { state: 'pending' };
        const id = draftId.value;
        for (let i = 0; i < 40; i++) {
            await new Promise((resolve) => setTimeout(resolve, 1500));
            const p = await adminCall<Preview>(
                `/admin/ai/persona/preview/${r.preview_id}`,
                undefined,
                'GET',
            );
            if (p.state === 'ready') {
                preview.value = p;
                previewedDraft.value = id;
                break;
            }
        }
        if (preview.value?.state !== 'ready') message.value = props.t.preview_timeout ?? '';
    } catch (e) {
        message.value = (e as Error).message;
    } finally {
        busy.value = false;
    }
}

async function publish(): Promise<void> {
    if (!canPublish.value || !draftId.value) return;
    busy.value = true;
    try {
        await adminCall(`/admin/ai/versions/${draftId.value}/publish`, {});
        message.value = props.t.published ?? '';
        window.location.reload();
    } catch (e) {
        message.value = (e as Error).message; // e.g. the FR-19a refusal, verbatim
    } finally {
        busy.value = false;
    }
}

async function rollback(id: string): Promise<void> {
    busy.value = true;
    try {
        await adminCall(`/admin/ai/versions/${id}/rollback`, {});
        window.location.reload();
    } catch (e) {
        message.value = (e as Error).message;
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <main
        class="mx-auto max-w-3xl space-y-4 p-4 text-start"
        :style="{ color: 'var(--ds-text-primary)' }"
    >
        <h1 class="text-lg font-semibold">✦ {{ t.persona_title }}</h1>
        <p class="text-xs" :style="{ color: 'var(--ds-text-muted)' }">{{ t.persona_help }}</p>
        <p
            v-if="message"
            role="status"
            data-testid="persona-message"
            class="rounded border px-2 py-1 text-xs"
            :style="{ borderColor: 'var(--ds-border-strong)' }"
        >
            {{ message }}
        </p>

        <label class="block text-xs">
            <span class="font-semibold">{{ t.name }}</span>
            <input
                v-model="name"
                data-testid="persona-name"
                class="mt-1 w-full rounded border px-2 py-1"
                :style="{
                    borderColor: 'var(--ds-border-strong)',
                    background: 'var(--ds-panel-bg)',
                }"
            />
        </label>

        <fieldset
            v-for="lang in languages"
            :key="lang"
            class="rounded border p-2"
            :style="{ borderColor: 'var(--ds-border-strong)' }"
        >
            <legend class="px-1 text-xs font-semibold">{{ t.greeting }} — {{ lang }}</legend>
            <textarea
                v-model="greeting[lang]"
                :data-testid="'persona-greeting-' + lang"
                rows="3"
                class="w-full rounded border px-2 py-1 text-sm"
                :style="{
                    borderColor: 'var(--ds-border-strong)',
                    background: 'var(--ds-panel-bg)',
                }"
            />
            <p class="mt-1 text-[10.5px]" :style="{ color: 'var(--ds-text-muted)' }">
                {{ t.disclosure_required }}: <em>{{ disclosure[lang] }}</em>
            </p>
        </fieldset>

        <div class="grid grid-cols-2 gap-3 text-xs">
            <label
                >{{ t.tone_formal }} ↔ {{ t.tone_friendly
                }}<input
                    v-model.number="tone.formality"
                    type="range"
                    min="0"
                    max="100"
                    class="w-full"
                    data-testid="tone-formality"
            /></label>
            <label
                >{{ t.tone_brief }} ↔ {{ t.tone_detailed
                }}<input
                    v-model.number="tone.detail"
                    type="range"
                    min="0"
                    max="100"
                    class="w-full"
                    data-testid="tone-detail"
            /></label>
        </div>

        <div class="flex flex-wrap gap-2">
            <button
                type="button"
                data-testid="persona-save"
                class="rounded border px-3 py-1 text-xs"
                :style="{ borderColor: 'var(--ds-ai-fg)', color: 'var(--ds-ai-fg)' }"
                :disabled="busy"
                @click="saveDraft"
            >
                {{ t.save_draft }}
            </button>
            <button
                type="button"
                data-testid="persona-preview"
                class="rounded border px-3 py-1 text-xs"
                :style="{ borderColor: 'var(--ds-ai-fg)', color: 'var(--ds-ai-fg)' }"
                :disabled="busy || !draftId"
                @click="runPreview"
            >
                {{ t.preview }}
            </button>
            <button
                type="button"
                data-testid="persona-publish"
                class="rounded px-3 py-1 text-xs font-semibold"
                :style="{ background: 'var(--ds-ai-fg)', color: 'var(--ds-panel-bg)' }"
                :disabled="busy || !canPublish"
                :title="canPublish ? '' : t.preview_first"
                @click="publish"
            >
                {{ t.publish }}
            </button>
        </div>

        <section
            v-if="preview"
            :aria-label="t.preview"
            data-testid="persona-preview-results"
            class="rounded border p-2"
            :style="{ borderColor: 'var(--ds-ai-fg)', background: 'var(--ds-ai-bg)' }"
        >
            <p v-if="preview.state !== 'ready'" class="text-xs">{{ t.preview_running }}</p>
            <table v-else class="w-full text-xs">
                <thead>
                    <tr class="text-start">
                        <th class="text-start">{{ t.dialog }}</th>
                        <th class="text-start">{{ t.action }}</th>
                        <th class="text-start">{{ t.reply }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="r in preview.results" :key="r.dialog" class="align-top">
                        <td class="pe-2 font-mono">{{ r.dialog }}</td>
                        <td class="pe-2">
                            {{ r.action }}<span v-if="r.reason"> ({{ r.reason }})</span>
                        </td>
                        <td class="whitespace-pre-wrap">{{ r.text ?? '—' }}</td>
                    </tr>
                </tbody>
            </table>
        </section>

        <section :aria-label="t.history" class="text-xs">
            <h2 class="font-semibold">{{ t.history }}</h2>
            <ul class="mt-1 space-y-1">
                <li v-for="h in history" :key="h.id" class="flex items-center gap-2">
                    <span class="tabular-nums">v{{ h.version }}</span
                    ><span>{{ h.status }}</span
                    ><span :style="{ color: 'var(--ds-text-muted)' }">{{
                        h.created_at.slice(0, 19)
                    }}</span>
                    <button
                        v-if="h.status === 'superseded'"
                        type="button"
                        class="underline"
                        :data-testid="'rollback-' + h.version"
                        @click="rollback(h.id)"
                    >
                        {{ t.rollback }}
                    </button>
                </li>
            </ul>
        </section>
    </main>
</template>
