<script setup lang="ts">
// Doc 10 §8 (S10 step 12, Option A → [B9]): the editor — language tabs (tablist), the
// FIXED block set (paragraph text + image alt; R-04: no free-form CMS), autosave through
// the step-3 door, sanitizer flags PRE-publish with BR-06's override-reason path, the
// publish dialog surfacing the door's own refusals (missing alt / missing media), the
// AI drawer over the step-9 doors (drafts only — BR-02 said in the UI), the INTERNAL
// banner (--ds-note-*), the review thread, and the §7 stats header (kb.feedback.view).
import { onMounted, ref } from 'vue';
import { KbCallError, kbCall } from './kbApi';

type Block = {
    id: string;
    type: string;
    text?: string;
    alt?: string;
    media_key?: string;
    ai?: boolean;
};
type Flag = { block: number; sentence: string; pattern: string };
type Comment = {
    id: string;
    body: string;
    author_id: string;
    block_anchor: string | null;
    resolved_at: string | null;
    created_at: string;
};
const props = defineProps<{
    t: Record<string, string>;
    variant: {
        id: string;
        article_id: string;
        language: string;
        status: string;
        translation_state: string;
        translation_source: string;
        title: string;
        summary: string | null;
        working_copy: { blocks?: Block[] };
        index_status: string;
    };
    article: { id: string; audience: string };
    siblings: { id: string; language: string; status: string; translation_state: string }[];
    flags: Flag[];
    comments: Comment[];
    can: { publish: boolean; stats: boolean };
}>();

const blocks = ref<Block[]>(props.variant.working_copy.blocks ?? []);
const title = ref(props.variant.title);
const summary = ref(props.variant.summary ?? '');
const message = ref('');
const savedAt = ref('');
const publishOpen = ref(false);
const overrideReason = ref('');
const refusal = ref<{ missing_alt?: string[]; missing_media?: string[] }>({});
type Stats = {
    views: Record<string, number>;
    citations: number;
    inserts: number;
    feedback: { helpful_pct: number | null };
    deflection_candidates: number;
};
const stats = ref<Stats | null>(null);
const viewsTotal = (s: Stats): number => Object.values(s.views ?? {}).reduce((a, b) => a + b, 0);
const commentBody = ref('');
const publishedSiblings = props.siblings.filter(
    (s) => s.id !== props.variant.id && s.status === 'published',
);
const outdatedLanguages = publishedSiblings.map((s) => s.language).join(', ');

function fail(e: unknown): void {
    message.value = (e as Error).message;
}
async function save(): Promise<void> {
    try {
        await kbCall(
            '/admin/kb/variants/' + props.variant.id + '/working-copy',
            { content: { blocks: blocks.value } },
            'PUT',
        );
        await kbCall(
            '/admin/kb/variants/' + props.variant.id + '/meta',
            { title: title.value, summary: summary.value },
            'PATCH',
        );
        savedAt.value = new Date().toISOString().slice(11, 19);
        message.value = '';
    } catch (e) {
        fail(e);
    }
}
function addParagraph(): void {
    blocks.value.push({ id: 'b' + Date.now(), type: 'paragraph', text: '' });
}
async function transition(door: string): Promise<void> {
    try {
        await kbCall('/admin/kb/variants/' + props.variant.id + '/' + door, {});
        window.location.reload();
    } catch (e) {
        fail(e);
    }
}
async function publish(): Promise<void> {
    refusal.value = {};
    try {
        await kbCall('/admin/kb/variants/' + props.variant.id + '/publish', {
            ...(overrideReason.value
                ? { sanitizer_override: { reason: overrideReason.value } }
                : {}),
        });
        window.location.reload();
    } catch (e) {
        if (e instanceof KbCallError) {
            refusal.value = {
                missing_alt: e.problem.missing_alt,
                missing_media: e.problem.missing_media,
            };
        }
        fail(e);
    }
}
async function aiTool(path: string, body: Record<string, unknown> = {}): Promise<void> {
    try {
        await kbCall(path, body);
        message.value = props.t.ai_note ?? '';
    } catch (e) {
        fail(e);
    }
}
async function resolveComment(commentId: string): Promise<void> {
    try {
        await kbCall('/admin/kb/comments/' + commentId + '/resolve', {});
        window.location.reload();
    } catch (e) {
        fail(e);
    }
}
async function sendComment(): Promise<void> {
    try {
        await kbCall('/admin/kb/variants/' + props.variant.id + '/comments', {
            body: commentBody.value,
        });
        window.location.reload();
    } catch (e) {
        fail(e);
    }
}
onMounted(async () => {
    if (!props.can.stats) return;
    try {
        stats.value = await kbCall<Stats>(
            '/admin/kb/articles/' + props.variant.article_id + '/stats',
            undefined,
            'GET',
        );
    } catch {
        stats.value = null; // the header is an extra; its failure never blocks editing
    }
});
</script>

<template>
    <main
        class="mx-auto max-w-4xl space-y-3 p-4 text-start text-sm"
        :style="{ color: 'var(--ds-text-primary)', background: 'var(--ds-page-bg)' }"
    >
        <h1 class="text-lg font-semibold">✎ {{ t.editor_title }} — {{ title }}</h1>

        <p
            v-if="article.audience === 'internal'"
            data-testid="kb-internal-banner"
            class="rounded border px-2 py-1 text-xs font-semibold"
            :style="{
                borderColor: 'var(--ds-note-border)',
                background: 'var(--ds-note-bg)',
                color: 'var(--ds-note-fg)',
            }"
        >
            {{ t.internal_banner }}
        </p>
        <p
            v-if="message"
            role="status"
            data-testid="kb-editor-message"
            class="rounded border px-2 py-1 text-xs"
            :style="{ borderColor: 'var(--ds-border-strong)' }"
        >
            {{ message }}
        </p>

        <div
            v-if="stats"
            data-testid="kb-stats-header"
            class="flex flex-wrap gap-3 rounded border p-2 text-xs"
            :style="{ borderColor: 'var(--ds-border-strong)', background: 'var(--ds-panel-bg)' }"
        >
            <span>{{ t.stats_views }}: {{ viewsTotal(stats) }}</span>
            <span>{{ t.stats_citations }}: {{ stats.citations }}</span>
            <span>{{ t.stats_inserts }}: {{ stats.inserts }}</span>
            <span>{{ t.stats_helpful }}: {{ stats.feedback?.helpful_pct ?? '—' }}%</span>
            <span
                >{{ t.stats_deflection }}: {{ stats.deflection_candidates }}
                <em :style="{ color: 'var(--ds-text-muted)' }"
                    >({{ t.stats_formula_note }})</em
                ></span
            >
        </div>

        <div role="tablist" :aria-label="t.title" class="flex gap-1">
            <a
                v-for="s in siblings"
                :key="s.id"
                role="tab"
                :aria-selected="s.id === variant.id"
                :data-testid="'kb-tab-' + s.language"
                :href="'/admin/kb/variants/' + s.id + '/edit'"
                class="rounded-t border px-3 py-1 text-xs"
                :style="{
                    borderColor:
                        s.id === variant.id ? 'var(--ds-accent)' : 'var(--ds-border-strong)',
                    background: s.id === variant.id ? 'var(--ds-panel-bg)' : 'transparent',
                }"
            >
                {{ s.language }} · {{ t['status_' + s.status] ?? s.status }}
            </a>
        </div>

        <label class="block text-xs"
            >{{ t.article_title }}
            <input
                v-model="title"
                data-testid="kb-title"
                class="mt-1 w-full rounded border px-2 py-1"
                :style="{
                    borderColor: 'var(--ds-border-strong)',
                    background: 'var(--ds-panel-bg)',
                }"
            />
        </label>
        <label class="block text-xs"
            >{{ t.summary }}
            <input
                v-model="summary"
                data-testid="kb-summary"
                class="mt-1 w-full rounded border px-2 py-1"
                :style="{
                    borderColor: 'var(--ds-border-strong)',
                    background: 'var(--ds-panel-bg)',
                }"
            />
        </label>

        <section class="space-y-2" data-testid="kb-blocks">
            <div
                v-for="(b, i) in blocks"
                :key="b.id"
                class="rounded border p-2"
                :style="{
                    borderColor: 'var(--ds-border-strong)',
                    background: 'var(--ds-panel-bg)',
                }"
            >
                <p class="mb-1 text-[10px] uppercase" :style="{ color: 'var(--ds-text-muted)' }">
                    {{ b.type === 'image' ? t.block_image : t.block_paragraph }} #{{ i }}
                    <span v-if="b.ai" :style="{ color: 'var(--ds-ai-fg)' }"
                        >· {{ t.source_ai }}</span
                    >
                </p>
                <textarea
                    v-if="b.type === 'paragraph'"
                    v-model="b.text"
                    :data-testid="'kb-block-' + b.id"
                    rows="3"
                    class="w-full rounded border px-2 py-1 text-sm"
                    :style="{
                        borderColor: 'var(--ds-border-strong)',
                        background: 'var(--ds-page-bg)',
                    }"
                ></textarea>
                <input
                    v-else-if="b.type === 'image'"
                    v-model="b.alt"
                    :data-testid="'kb-alt-' + b.id"
                    :placeholder="t.block_image"
                    class="w-full rounded border px-2 py-1 text-sm"
                    :style="{
                        borderColor: 'var(--ds-border-strong)',
                        background: 'var(--ds-page-bg)',
                    }"
                />
            </div>
            <div class="flex gap-2">
                <button
                    type="button"
                    data-testid="kb-add-paragraph"
                    class="rounded border px-2 py-1 text-xs"
                    :style="{ borderColor: 'var(--ds-border-strong)' }"
                    @click="addParagraph"
                >
                    {{ t.add_paragraph }}
                </button>
                <button
                    type="button"
                    data-testid="kb-save"
                    class="rounded border px-3 py-1 text-xs"
                    :style="{ borderColor: 'var(--ds-accent)', color: 'var(--ds-accent)' }"
                    @click="save"
                >
                    {{ t.save }}<span v-if="savedAt"> · {{ t.saved }} {{ savedAt }}</span>
                </button>
            </div>
        </section>

        <section
            v-if="flags.length"
            data-testid="kb-sanitizer-flags"
            class="rounded border p-2 text-xs"
            :style="{ borderColor: 'var(--ds-warning-border, var(--ds-border-strong))' }"
        >
            <p class="font-semibold">{{ t.sanitizer_warnings }}</p>
            <ul class="mt-1 list-disc ps-4">
                <li v-for="(f, i) in flags" :key="i">
                    #{{ f.block }} “{{ f.sentence }}” — {{ f.pattern }}
                </li>
            </ul>
        </section>

        <section class="flex flex-wrap gap-2 text-xs">
            <button
                v-if="variant.status === 'draft'"
                type="button"
                data-testid="kb-submit"
                class="rounded border px-3 py-1"
                :style="{ borderColor: 'var(--ds-border-strong)' }"
                @click="transition('submit')"
            >
                {{ t.submit_review }}
            </button>
            <button
                v-if="can.publish"
                type="button"
                data-testid="kb-publish-open"
                class="rounded border px-3 py-1"
                :style="{ borderColor: 'var(--ds-accent)', color: 'var(--ds-accent)' }"
                @click="publishOpen = true"
            >
                {{ t.publish }}
            </button>
            <button
                v-if="can.publish && variant.status === 'published'"
                type="button"
                data-testid="kb-unpublish"
                class="rounded border px-3 py-1"
                :style="{ borderColor: 'var(--ds-border-strong)' }"
                @click="transition('unpublish')"
            >
                {{ t.unpublish }}
            </button>
            <button
                v-if="can.publish"
                type="button"
                data-testid="kb-archive"
                class="rounded border px-3 py-1"
                :style="{ borderColor: 'var(--ds-border-strong)' }"
                @click="transition('archive')"
            >
                {{ t.archive }}
            </button>
        </section>

        <div
            v-if="publishOpen"
            role="dialog"
            :aria-label="t.publish_confirm"
            data-testid="kb-publish-dialog"
            class="space-y-2 rounded border p-3 text-xs"
            :style="{ borderColor: 'var(--ds-accent)', background: 'var(--ds-panel-bg)' }"
        >
            <p class="font-semibold">{{ t.publish_confirm }}</p>
            <p>
                {{ t.publish_audience }}:
                {{ article.audience === 'internal' ? t.audience_internal : t.audience_public }}
            </p>
            <p v-if="publishedSiblings.length">
                {{ t.publish_outdated }} {{ outdatedLanguages }}
            </p>
            <div v-if="refusal.missing_alt?.length" data-testid="kb-missing-alt">
                <p class="font-semibold">{{ t.missing_alt }}</p>
                <ul class="list-disc ps-4">
                    <li v-for="id in refusal.missing_alt" :key="id">{{ id }}</li>
                </ul>
            </div>
            <div v-if="refusal.missing_media?.length" data-testid="kb-missing-media">
                <p class="font-semibold">{{ t.missing_media }}</p>
                <ul class="list-disc ps-4">
                    <li v-for="id in refusal.missing_media" :key="id">{{ id }}</li>
                </ul>
            </div>
            <label v-if="flags.length" class="block"
                >{{ t.override_reason }}
                <input
                    v-model="overrideReason"
                    data-testid="kb-override-reason"
                    class="mt-1 w-full rounded border px-2 py-1"
                    :style="{
                        borderColor: 'var(--ds-border-strong)',
                        background: 'var(--ds-page-bg)',
                    }"
                />
            </label>
            <button
                type="button"
                data-testid="kb-publish-confirm"
                class="rounded border px-3 py-1"
                :style="{ borderColor: 'var(--ds-accent)', color: 'var(--ds-accent)' }"
                @click="publish"
            >
                {{ t.publish }}
            </button>
        </div>

        <details
            data-testid="kb-ai-drawer"
            class="rounded border p-2 text-xs"
            :style="{ borderColor: 'var(--ds-ai-fg)' }"
        >
            <summary class="cursor-pointer" :style="{ color: 'var(--ds-ai-fg)' }">
                ✦ {{ t.ai_drawer }}
            </summary>
            <p class="my-1" :style="{ color: 'var(--ds-text-muted)' }">{{ t.ai_note }}</p>
            <div class="flex flex-wrap gap-2">
                <button
                    type="button"
                    data-testid="kb-ai-simplify"
                    class="rounded border px-2 py-1"
                    :style="{ borderColor: 'var(--ds-ai-fg)', color: 'var(--ds-ai-fg)' }"
                    @click="aiTool('/admin/kb/variants/' + variant.id + '/ai/simplify')"
                >
                    {{ t.ai_simplify }}
                </button>
                <button
                    type="button"
                    data-testid="kb-ai-faq"
                    class="rounded border px-2 py-1"
                    :style="{ borderColor: 'var(--ds-ai-fg)', color: 'var(--ds-ai-fg)' }"
                    @click="aiTool('/admin/kb/variants/' + variant.id + '/ai/faq')"
                >
                    {{ t.ai_faq }}
                </button>
                <button
                    type="button"
                    data-testid="kb-ai-translate-all"
                    class="rounded border px-2 py-1"
                    :style="{ borderColor: 'var(--ds-ai-fg)', color: 'var(--ds-ai-fg)' }"
                    @click="aiTool('/admin/kb/articles/' + variant.article_id + '/translate-all')"
                >
                    {{ t.ai_translate_all }}
                </button>
            </div>
        </details>

        <section
            data-testid="kb-review-thread"
            class="space-y-2 rounded border p-2 text-xs"
            :style="{ borderColor: 'var(--ds-border-strong)' }"
        >
            <h2 class="font-semibold">{{ t.review_thread }}</h2>
            <ul class="space-y-1">
                <li
                    v-for="c in comments"
                    :key="c.id"
                    :style="{
                        color: c.resolved_at ? 'var(--ds-text-muted)' : 'var(--ds-text-primary)',
                    }"
                >
                    {{ c.body }}
                    <button
                        v-if="!c.resolved_at"
                        type="button"
                        class="ms-1 underline"
                        @click="resolveComment(c.id)"
                    >
                        {{ t.resolve }}
                    </button>
                </li>
            </ul>
            <div class="flex gap-2">
                <input
                    v-model="commentBody"
                    :placeholder="t.comment_placeholder"
                    data-testid="kb-comment-input"
                    class="flex-1 rounded border px-2 py-1"
                    :style="{
                        borderColor: 'var(--ds-border-strong)',
                        background: 'var(--ds-panel-bg)',
                    }"
                />
                <button
                    type="button"
                    data-testid="kb-comment-send"
                    class="rounded border px-2 py-1"
                    :style="{ borderColor: 'var(--ds-border-strong)' }"
                    @click="sendComment"
                >
                    {{ t.comment_send }}
                </button>
            </div>
        </section>
    </main>
</template>
