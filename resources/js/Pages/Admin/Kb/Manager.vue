<script setup lang="ts">
// Doc 10 §7 (S10 step 12, Option A → [B9]): the manager three-pane — categories |
// articles (status + translation badges per variant) | detail. Functional-first from
// DS tokens; INTERNAL articles were already filtered server-side for non-holders
// (FR-10 absence). Every action navigates to the editor; creation rides the proven door.
import { computed, ref } from 'vue';
import { kbCall } from './kbApi';

type Variant = {
    id: string;
    language: string;
    status: string;
    translation_state: string;
    translation_source: string;
    title: string;
    index_status: string;
};
type Article = {
    id: string;
    category_id: string;
    audience: string;
    source_language: string;
    variants: Variant[];
};
type Category = {
    id: string;
    parent_id: string | null;
    name: Record<string, string>;
    slug: string;
    ai_usable: boolean;
};
const props = defineProps<{
    t: Record<string, string>;
    categories: Category[];
    articles: Article[];
    can: { create: boolean; manage_categories: boolean; view_internal: boolean };
}>();

const selectedCategory = ref<string | null>(props.categories[0]?.id ?? null);
const selectedArticle = ref<string | null>(null);
const message = ref('');

const categoryArticles = computed(() =>
    props.articles.filter((a) => a.category_id === selectedCategory.value),
);
const article = computed(() => props.articles.find((a) => a.id === selectedArticle.value) ?? null);

function name(category: Category): string {
    return category.name.en ?? Object.values(category.name)[0] ?? category.slug;
}
function badge(variant: Variant): string {
    const status = props.t['status_' + variant.status] ?? variant.status;
    const state = props.t['ts_' + variant.translation_state] ?? variant.translation_state;
    return `${variant.language} · ${status} · ${state}`;
}
async function createArticle(): Promise<void> {
    if (!selectedCategory.value) return;
    try {
        const created = await kbCall<{ variant_id: string }>('/admin/kb/articles', {
            category_id: selectedCategory.value,
            audience: 'public',
            source_language: 'en',
            title: 'Untitled',
        });
        window.location.href = '/admin/kb/variants/' + created.variant_id + '/edit';
    } catch (e) {
        message.value = (e as Error).message;
    }
}
</script>

<template>
    <main
        class="mx-auto flex max-w-6xl flex-col gap-3 p-4 text-start text-sm md:flex-row"
        :style="{ color: 'var(--ds-text-primary)', background: 'var(--ds-page-bg)' }"
    >
        <h1 class="sr-only">{{ t.title }}</h1>
        <p
            v-if="message"
            role="status"
            data-testid="kb-manager-message"
            class="rounded border px-2 py-1 text-xs"
            :style="{ borderColor: 'var(--ds-border-strong)' }"
        >
            {{ message }}
        </p>

        <section class="md:w-56" :aria-label="t.categories" data-testid="kb-pane-categories">
            <h2
                class="mb-2 text-xs font-semibold uppercase"
                :style="{ color: 'var(--ds-text-muted)' }"
            >
                {{ t.categories }}
            </h2>
            <ul class="space-y-1">
                <li v-for="c in categories" :key="c.id">
                    <button
                        type="button"
                        class="w-full rounded border px-2 py-1 text-start text-xs"
                        :data-testid="'kb-category-' + c.slug"
                        :aria-pressed="selectedCategory === c.id"
                        :style="{
                            borderColor:
                                selectedCategory === c.id
                                    ? 'var(--ds-accent)'
                                    : 'var(--ds-border-strong)',
                            background: 'var(--ds-panel-bg)',
                        }"
                        @click="
                            selectedCategory = c.id;
                            selectedArticle = null;
                        "
                    >
                        {{ name(c) }}
                    </button>
                </li>
            </ul>
        </section>

        <section class="flex-1" :aria-label="t.articles" data-testid="kb-pane-articles">
            <div class="mb-2 flex items-center justify-between">
                <h2
                    class="text-xs font-semibold uppercase"
                    :style="{ color: 'var(--ds-text-muted)' }"
                >
                    {{ t.articles }}
                </h2>
                <button
                    v-if="can.create"
                    type="button"
                    data-testid="kb-new-article"
                    class="rounded border px-2 py-1 text-xs"
                    :style="{ borderColor: 'var(--ds-accent)', color: 'var(--ds-accent)' }"
                    @click="createArticle"
                >
                    {{ t.new_article }}
                </button>
            </div>
            <p v-if="!selectedCategory" class="text-xs" :style="{ color: 'var(--ds-text-muted)' }">
                {{ t.no_category }}
            </p>
            <p
                v-else-if="categoryArticles.length === 0"
                data-testid="kb-empty-category"
                class="text-xs"
                :style="{ color: 'var(--ds-text-muted)' }"
            >
                {{ t.empty_category }}
            </p>
            <ul v-else class="space-y-1">
                <li v-for="a in categoryArticles" :key="a.id">
                    <button
                        type="button"
                        class="w-full rounded border p-2 text-start"
                        :data-testid="'kb-article-' + a.id"
                        :aria-pressed="selectedArticle === a.id"
                        :style="{
                            borderColor:
                                selectedArticle === a.id
                                    ? 'var(--ds-accent)'
                                    : 'var(--ds-border-strong)',
                            background: 'var(--ds-panel-bg)',
                        }"
                        @click="selectedArticle = a.id"
                    >
                        <span class="block text-sm">
                            {{ a.variants[0]?.title ?? a.id }}
                            <span
                                v-if="a.audience === 'internal'"
                                class="ms-1 rounded border px-1 text-[10px] uppercase"
                                :style="{
                                    borderColor: 'var(--ds-note-border)',
                                    color: 'var(--ds-note-fg)',
                                }"
                                >{{ t.audience_internal }}</span
                            >
                        </span>
                        <span class="mt-1 flex flex-wrap gap-1">
                            <span
                                v-for="v in a.variants"
                                :key="v.id"
                                class="rounded border px-1 text-[10px]"
                                :data-testid="'kb-badge-' + v.id"
                                :style="{
                                    borderColor: 'var(--ds-border-strong)',
                                    color: 'var(--ds-text-muted)',
                                }"
                            >
                                {{ badge(v)
                                }}<template v-if="v.translation_source === 'ai'">
                                    · {{ t.source_ai }}</template
                                >
                            </span>
                        </span>
                    </button>
                </li>
            </ul>
        </section>

        <section class="md:w-72" :aria-label="t.detail" data-testid="kb-pane-detail">
            <h2
                class="mb-2 text-xs font-semibold uppercase"
                :style="{ color: 'var(--ds-text-muted)' }"
            >
                {{ t.detail }}
            </h2>
            <p v-if="!article" class="text-xs" :style="{ color: 'var(--ds-text-muted)' }">
                {{ t.no_article }}
            </p>
            <div
                v-else
                class="space-y-2 rounded border p-2"
                :style="{
                    borderColor: 'var(--ds-border-strong)',
                    background: 'var(--ds-panel-bg)',
                }"
            >
                <p class="text-xs">
                    {{ article.audience === 'internal' ? t.audience_internal : t.audience_public }}
                    · {{ article.source_language }}
                </p>
                <ul class="space-y-1">
                    <li
                        v-for="v in article.variants"
                        :key="v.id"
                        class="flex items-center justify-between gap-2 text-xs"
                    >
                        <span>{{ badge(v) }}</span>
                        <span class="flex gap-2">
                            <a
                                class="underline"
                                :data-testid="'kb-edit-' + v.id"
                                :href="'/admin/kb/variants/' + v.id + '/edit'"
                                >{{ t.edit }}</a
                            >
                            <a
                                class="underline"
                                :href="'/admin/kb/variants/' + v.id + '/history-page'"
                                >{{ t.history }}</a
                            >
                        </span>
                    </li>
                </ul>
            </div>
        </section>
    </main>
</template>
