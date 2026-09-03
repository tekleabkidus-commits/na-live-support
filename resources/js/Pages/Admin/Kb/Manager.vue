<script setup lang="ts">
// Doc 10 §7/§8 — the KB manager. SESSION-F1 [B9] rebuilt it to the frozen B9-S01 (the
// three panes in the Backoffice chrome: the category TREE with counts, grips and ↑↓;
// article rows with the §8 columns — status pill · language dots · usage; filters + search;
// the three empties; the fresh-tenant checklist; 375 read posture) and B9-S04 (the
// categories MODE of the same page: the tree beside the FR-01 editor, the EC-03
// reassign-first delete, the EC-02 AI-usable-off consequence, the depth-3 wall).
// Every mutation rides an existing door (categories store/update/impact/reassign/destroy,
// articles store/move); INTERNAL rows are absent server-side (FR-10). Tokens only.
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import BackofficeShell from '@/Components/Backoffice/BackofficeShell.vue';
import { kbCall } from './kbApi';

type Variant = {
    id: string;
    language: string;
    status: string;
    translation_state: string;
    translation_source: string;
    title: string;
    slug: string;
    summary: string;
    index_status: string;
    updated_at: string;
    published_at: string | null;
};
type Article = {
    id: string;
    category_id: string;
    audience: string;
    source_language: string;
    seeded: boolean;
    created_at: string;
    author_name: string | null;
    variants: Variant[];
    views: number;
    citations: number;
};
type Category = {
    id: string;
    parent_id: string | null;
    depth: number;
    name: Record<string, string>;
    slug: string;
    position: number;
    icon: string | null;
    ai_usable: boolean;
    visibility_default: string;
    count: number;
    published_count: number;
    chatbot_count: number;
    description: Record<string, string>;
};
const props = defineProps<{
    t: Record<string, string>;
    categories: Category[];
    articles: Article[];
    languages: string[];
    can: { create: boolean; manage_categories: boolean; view_internal: boolean; edit: boolean };
}>();

const ICONS = ['⬇', '⬆', '📱', '🏦', '👤', '🎁', '🛡', '🔒', '💬', '📄', '⚙', '❓'];
const MAX_DEPTH = 3;

function fill(template: string | undefined, vars: Record<string, string | number>): string {
    let out = template ?? '';
    for (const [k, v] of Object.entries(vars)) out = out.split(':' + k).join(String(v));
    return out;
}
function name(c: Category | undefined): string {
    if (!c) return '';
    return c.name.en ?? Object.values(c.name)[0] ?? c.slug;
}
function langName(code: string): string {
    return props.t['lang_' + code] ?? code;
}

// ── mode: articles (S01) or categories (S04) — one page, one nav row (D04) ──
const mode = ref<'articles' | 'categories'>(
    window.location.hash === '#categories' ? 'categories' : 'articles',
);
function setMode(m: 'articles' | 'categories'): void {
    mode.value = m;
    history.replaceState(null, '', m === 'categories' ? '#categories' : window.location.pathname);
}

// ── tree ──
const ordered = computed(() => {
    const byParent = new Map<string | null, Category[]>();
    for (const c of props.categories) {
        const list = byParent.get(c.parent_id) ?? [];
        list.push(c);
        byParent.set(c.parent_id, list);
    }
    const out: Category[] = [];
    const walk = (parent: string | null): void => {
        for (const c of (byParent.get(parent) ?? []).sort(
            (a, b) => a.position - b.position || a.slug.localeCompare(b.slug),
        )) {
            out.push(c);
            walk(c.id);
        }
    };
    walk(null);
    return out;
});
const siblingsOf = (c: Category): Category[] =>
    ordered.value.filter((x) => x.parent_id === c.parent_id);
const pathOf = (c: Category): string => {
    const parts: string[] = [];
    let cur: Category | undefined = c;
    while (cur) {
        parts.unshift(name(cur));
        cur = props.categories.find((x) => x.id === cur?.parent_id);
    }
    return parts.join(' › ');
};
const selectedCategory = ref<string | null>(ordered.value[0]?.id ?? null);
const category = computed(
    () => props.categories.find((c) => c.id === selectedCategory.value) ?? null,
);
const selectedArticle = ref<string | null>(null);
const article = computed(() => props.articles.find((a) => a.id === selectedArticle.value) ?? null);

// ── article facts ──
function articleStatus(a: Article): string {
    if (a.variants.some((v) => v.status === 'published')) return 'published';
    if (a.variants.some((v) => v.status === 'in_review')) return 'in_review';
    if (a.variants.length && a.variants.every((v) => v.status === 'archived')) return 'archived';
    return 'draft';
}
function sourceVariant(a: Article): Variant | undefined {
    return a.variants.find((v) => v.language === a.source_language) ?? a.variants[0];
}
function title(a: Article): string {
    return sourceVariant(a)?.title ?? a.id;
}
function isAiDraft(a: Article): boolean {
    return a.variants.some((v) => v.translation_source === 'ai');
}
function lastUpdated(a: Article): string {
    return a.variants.reduce((m, v) => (v.updated_at > m ? v.updated_at : m), a.created_at);
}
function rel(iso: string): string {
    // Postgres text stamps read "2026-09-03 10:16:12.123456+00" — normalise to ISO for Date.parse
    const then = new Date(
        iso
            .replace(' ', 'T')
            .replace(/(\.\d{3})\d+/, '$1')
            .replace(/([+-]\d{2})$/, '$1:00'),
    ).getTime();
    if (Number.isNaN(then)) return '';
    const mins = Math.max(0, Math.round((Date.now() - then) / 60000));
    if (mins < 60) return fill(props.t.rel_min, { n: mins });
    const hours = Math.round(mins / 60);
    if (hours < 24) return fill(props.t.rel_hours, { n: hours });
    const days = Math.round(hours / 24);
    if (days === 1) return props.t.rel_yesterday ?? '';
    return fill(props.t.rel_days, { n: days });
}
function meta(a: Article): string {
    if (a.seeded && articleStatus(a) === 'draft') return props.t.meta_seeded ?? '';
    const verb = articleStatus(a) === 'archived' ? props.t.meta_archived : props.t.meta_updated;
    return `${a.author_name ?? props.t.author_unknown} · ${verb} ${rel(lastUpdated(a))}`;
}
function usage(a: Article): string {
    if (a.audience === 'internal') return props.t.usage_internal ?? '';
    const st = articleStatus(a);
    if (st === 'archived') return props.t.usage_tombstone ?? '';
    if (a.seeded && st === 'draft') return props.t.usage_seeded ?? '';
    if (st !== 'published') return '—';
    return fill(props.t.usage_line, {
        views: a.views.toLocaleString(),
        citations: a.citations.toLocaleString(),
    });
}
function dot(
    a: Article,
    lang: string,
): { cls: string; glyph: string; state: string; variant: Variant | undefined } {
    const v = a.variants.find((x) => x.language === lang);
    if (!v) return { cls: 'd-mis', glyph: '○', state: 'missing', variant: undefined };
    if (v.translation_state === 'outdated')
        return { cls: 'd-out', glyph: '◐', state: 'outdated', variant: v };
    if (v.translation_state === 'missing')
        return { cls: 'd-mis', glyph: '○', state: 'missing', variant: v };
    return { cls: 'd-cur', glyph: '●', state: 'current', variant: v };
}
const statusWord = (s: string): string => props.t['status_' + s] ?? s;
const stateWord = (s: string): string => props.t['ts_' + s] ?? s;

// ── filters + search (FR-17): client-side over the loaded rows, the server search door for body matches ──
const query = ref('');
const fLang = ref('');
const fVis = ref('');
const fStatus = ref('');
const searching = ref(false);
const serverHits = ref<Set<string> | null>(null);
let searchTimer: ReturnType<typeof setTimeout> | null = null;
watch(query, (q) => {
    if (searchTimer) clearTimeout(searchTimer);
    if (q.trim().length < 2) {
        serverHits.value = null;
        searching.value = false;
        return;
    }
    searching.value = true;
    searchTimer = setTimeout(async () => {
        try {
            const res = await kbCall<{ unavailable: boolean; hits: { article_id?: string }[] }>(
                '/admin/kb/search?q=' + encodeURIComponent(q.trim()),
                undefined,
                'GET',
            );
            // FR-17's staff search (Meilisearch): body/tag hits merge with the local title/summary match;
            // when the index is unavailable the door says so and the local match stands alone.
            serverHits.value = new Set(
                (res.hits ?? []).map((r) => r.article_id ?? '').filter(Boolean),
            );
        } catch {
            serverHits.value = new Set();
        } finally {
            searching.value = false;
        }
    }, 300);
});
const filtersOn = computed(() => fLang.value !== '' || fVis.value !== '' || fStatus.value !== '');
function clearFilters(): void {
    fLang.value = '';
    fVis.value = '';
    fStatus.value = '';
    query.value = '';
}
const categoryArticles = computed(() =>
    props.articles.filter((a) => a.category_id === selectedCategory.value),
);
const listed = computed(() => {
    const q = query.value.trim().toLowerCase();
    return categoryArticles.value.filter((a) => {
        if (
            fLang.value &&
            !a.variants.some((v) => v.language === fLang.value && v.translation_state !== 'missing')
        )
            return false;
        if (fVis.value && a.audience !== fVis.value) return false;
        if (fStatus.value && articleStatus(a) !== fStatus.value) return false;
        if (q) {
            const local = a.variants.some((v) =>
                (v.title + ' ' + v.summary).toLowerCase().includes(q),
            );
            if (!local && !(serverHits.value?.has(a.id) ?? false)) return false;
        }
        return true;
    });
});
const anyPublished = computed(() =>
    props.articles.some((a) => a.variants.some((v) => v.status === 'published')),
);
const freshTenant = computed(() => !anyPublished.value && props.articles.some((a) => a.seeded));
const seededInCategory = computed(() => categoryArticles.value.filter((a) => a.seeded));
const anyAiDraft = computed(() => props.articles.some(isAiDraft));
const listHeader = computed(() => {
    const cat = name(category.value ?? undefined);
    if (!category.value) return '';
    if (query.value.trim() && listed.value.length === 0) return props.t.results_zero ?? '';
    if (filtersOn.value || query.value.trim())
        return fill(props.t.results_match, {
            cat,
            n: listed.value.length,
            total: categoryArticles.value.length,
        });
    if (freshTenant.value && seededInCategory.value.length)
        return fill(props.t.results_seeded, { cat, n: seededInCategory.value.length });
    return `${cat} · ${categoryArticles.value.length}`;
});

// ── creation + move (existing doors) ──
const message = ref('');
async function createArticle(titleText = ''): Promise<void> {
    if (!selectedCategory.value) return;
    try {
        const created = await kbCall<{ variant_id: string }>('/admin/kb/articles', {
            category_id: selectedCategory.value,
            audience: category.value?.visibility_default === 'internal' ? 'internal' : 'public',
            source_language: props.languages[0] ?? 'en',
            title: titleText || (props.t.untitled ?? 'Untitled'),
        });
        window.location.href = '/admin/kb/variants/' + created.variant_id + '/edit';
    } catch (e) {
        message.value = (e as Error).message;
    }
}
const moveOpen = ref(false);
const movePick = ref('');
const movable = computed(() =>
    props.articles.filter((a) => a.category_id !== selectedCategory.value),
);
async function moveHere(): Promise<void> {
    if (!movePick.value || !selectedCategory.value) return;
    try {
        await kbCall('/admin/kb/articles/' + movePick.value + '/move', {
            category_id: selectedCategory.value,
        });
        window.location.reload();
    } catch (e) {
        message.value = (e as Error).message;
    }
}

// ── categories mode: editor state (S04) ──
type Draft = {
    name: Record<string, string>;
    slug: string;
    description: Record<string, string>;
    icon: string;
    position: number;
    ai_usable: boolean;
    visibility_default: string;
};
const draft = ref<Draft | null>(null);
function loadDraft(c: Category | null): void {
    draft.value = c
        ? {
              name: { ...c.name },
              slug: c.slug,
              description: { ...c.description },
              icon: c.icon ?? '',
              position: c.position,
              ai_usable: c.ai_usable,
              visibility_default: c.visibility_default,
          }
        : null;
}
watch(category, (c) => loadDraft(c), { immediate: true });
const saving = ref(false);
const catMessage = ref('');
const liveLine = ref('');
async function saveCategory(): Promise<void> {
    if (!category.value || !draft.value) return;
    saving.value = true;
    catMessage.value = '';
    try {
        const body: Record<string, unknown> = {
            name: draft.value.name,
            slug: draft.value.slug,
            description: draft.value.description,
            icon: draft.value.icon || null,
            position: draft.value.position,
            visibility_default: draft.value.visibility_default,
        };
        await kbCall('/admin/kb/categories/' + category.value.id, body, 'PATCH');
        window.location.reload();
    } catch (e) {
        catMessage.value = (e as Error).message;
    } finally {
        saving.value = false;
    }
}
// AI-usable: ON is a plain save; OFF asks the EC-02 question with the numbers first.
const aiOffOpen = ref(false);
async function applyAiUsable(on: boolean): Promise<void> {
    if (!category.value) return;
    try {
        await kbCall('/admin/kb/categories/' + category.value.id, { ai_usable: on }, 'PATCH');
        window.location.reload();
    } catch (e) {
        catMessage.value = (e as Error).message;
        aiOffOpen.value = false;
    }
}
function toggleAi(): void {
    if (!draft.value) return;
    if (draft.value.ai_usable) aiOffOpen.value = true;
    else void applyAiUsable(true);
}
const subcategoryCount = (c: Category): number =>
    props.categories.filter((x) => x.parent_id === c.id).length;
const internalCount = (c: Category): number =>
    props.articles.filter((a) => a.category_id === c.id && a.audience === 'internal').length;

// reorder among siblings (↑ ↓) — positions renumbered 0..n through the update door (audited per row)
async function move(c: Category, dir: -1 | 1): Promise<void> {
    const sib = siblingsOf(c);
    const i = sib.findIndex((x) => x.id === c.id);
    const j = i + dir;
    if (j < 0 || j >= sib.length) return;
    const next = [...sib];
    [next[i], next[j]] = [next[j]!, next[i]!];
    liveLine.value = fill(props.t.live_moved, { name: name(c), n: j + 1, total: sib.length });
    try {
        for (let k = 0; k < next.length; k++) {
            const row = next[k]!;
            if (row.position !== k)
                await kbCall('/admin/kb/categories/' + row.id, { position: k }, 'PATCH');
        }
        window.location.reload();
    } catch (e) {
        catMessage.value = (e as Error).message;
    }
}
function onTreeKey(e: KeyboardEvent, c: Category): void {
    if (!props.can.manage_categories || mode.value !== 'categories') return;
    if (e.key === 'ArrowUp' && e.altKey) {
        e.preventDefault();
        void move(c, -1);
    } else if (e.key === 'ArrowDown' && e.altKey) {
        e.preventDefault();
        void move(c, 1);
    }
}

// new category (store door)
const newOpen = ref(false);
const newDraft = ref<{
    name: Record<string, string>;
    slug: string;
    parent_id: string | null;
    ai_usable: boolean;
    visibility_default: string;
    icon: string;
}>({
    name: {},
    slug: '',
    parent_id: null,
    ai_usable: false,
    visibility_default: 'public',
    icon: '',
});
const parentOptions = computed(() => ordered.value.filter((c) => c.depth < MAX_DEPTH));
function openNew(parent: Category | null): void {
    newDraft.value = {
        name: {},
        slug: '',
        parent_id: parent?.id ?? null,
        ai_usable: parent?.ai_usable ?? false,
        visibility_default: parent?.visibility_default ?? 'public',
        icon: '',
    };
    newOpen.value = true;
}
async function createCategory(): Promise<void> {
    try {
        await kbCall('/admin/kb/categories', {
            name: newDraft.value.name,
            slug: newDraft.value.slug,
            parent_id: newDraft.value.parent_id,
            ai_usable: newDraft.value.ai_usable,
            visibility_default: newDraft.value.visibility_default,
            icon: newDraft.value.icon || null,
        });
        window.location.reload();
    } catch (e) {
        catMessage.value = (e as Error).message;
    }
}

// delete needs reassign first (EC-03) — the impact door drives the sentence
const deleteOpen = ref(false);
const reassignTo = ref('');
const impact = ref<number | null>(null);
const reassignTargets = computed(() =>
    ordered.value.filter((c) => c.id !== selectedCategory.value),
);
watch(reassignTo, async (to) => {
    impact.value = null;
    if (!to || !category.value) return;
    try {
        const res = await kbCall<{ retrieval_impact: number }>(
            '/admin/kb/categories/' + category.value.id + '/impact?to=' + to,
            undefined,
            'GET',
        );
        impact.value = res.retrieval_impact;
    } catch {
        impact.value = null;
    }
});
const zeroImpactTarget = computed(
    () => reassignTargets.value.find((c) => c.ai_usable && c.id !== reassignTo.value) ?? null,
);
async function reassign(): Promise<void> {
    if (!category.value || !reassignTo.value) return;
    try {
        await kbCall('/admin/kb/categories/' + category.value.id + '/reassign', {
            to: reassignTo.value,
        });
        window.location.reload();
    } catch (e) {
        catMessage.value = (e as Error).message;
    }
}
async function destroyCategory(): Promise<void> {
    if (!category.value) return;
    try {
        await kbCall('/admin/kb/categories/' + category.value.id, undefined, 'DELETE');
        selectedCategory.value = null;
        window.location.reload();
    } catch (e) {
        catMessage.value = (e as Error).message;
    }
}
const moveArticlesOpen = ref(false);
function openDelete(): void {
    reassignTo.value = '';
    impact.value = null;
    deleteOpen.value = true;
}

const isMobile = ref(false);
onMounted(() => {
    const mq = window.matchMedia('(max-width: 639px)');
    isMobile.value = mq.matches;
    mq.addEventListener('change', (e) => (isMobile.value = e.matches));
});
function pick(c: Category): void {
    selectedCategory.value = c.id;
    selectedArticle.value = null;
    if (isMobile.value)
        void nextTick(() =>
            document.getElementById('kb-articles-pane')?.scrollIntoView({ block: 'start' }),
        );
}
function slugLine(a: Article): string {
    return a.variants.map((v) => '/' + v.language + '/' + v.slug).join(' · ');
}
const parentName = computed(() =>
    name(props.categories.find((x) => x.id === category.value?.parent_id)),
);
const reassignTargetName = computed(() =>
    name(props.categories.find((c) => c.id === reassignTo.value)),
);
</script>

<template>
    <BackofficeShell
        :t="t"
        active="kb"
        :title="mode === 'categories' ? (t.categories_title ?? '') : (t.title ?? '')"
        :crumb="
            mode === 'categories'
                ? [{ label: t.nav_kb ?? '', href: '/admin/kb' }, { label: t.crumb_editing ?? '' }]
                : undefined
        "
        :read-note="
            isMobile
                ? mode === 'categories'
                    ? t.readnote_categories
                    : t.readnote_manager
                : undefined
        "
    >
        <template #sub>
            <template v-if="mode === 'articles'">
                <span data-testid="kb-sub">{{
                    fill(t.sub_line, {
                        articles: articles.length,
                        categories: categories.length,
                        langs: languages.length,
                        codes: languages.join(' · '),
                    })
                }}</span>
            </template>
            <template v-else>
                <span class="bo-chip" data-testid="kb-cat-chip">{{
                    fill(t.cat_chip, { categories: categories.length, articles: articles.length })
                }}</span>
                <span class="bo-stamp">{{ t.cat_stamp }}</span>
            </template>
        </template>
        <template #actions>
            <template v-if="mode === 'articles'">
                <button
                    v-if="can.manage_categories"
                    type="button"
                    class="bo-btn"
                    data-testid="kb-open-categories"
                    @click="setMode('categories')"
                >
                    {{ t.manage_categories }}
                </button>
                <button
                    v-if="can.create"
                    type="button"
                    class="bo-btn p"
                    data-testid="kb-new-article"
                    @click="createArticle()"
                >
                    {{ t.new_article }}
                </button>
            </template>
            <button
                v-else
                type="button"
                class="bo-btn"
                data-testid="kb-back-to-articles"
                @click="setMode('articles')"
            >
                {{ t.back_to_articles }}
            </button>
        </template>

        <p v-if="message" role="status" data-testid="kb-manager-message" class="bo-note">
            {{ message }}
        </p>
        <p v-if="mode === 'articles' && freshTenant" class="bo-note" data-testid="kb-fresh-note">
            {{ t.fresh_note }}
        </p>

        <!-- 375: jump tabs for the three panes (the frame's tabs; the panes stay stacked and visible) -->
        <nav v-if="isMobile" class="bo-tabs" :aria-label="t.panes_label">
            <a href="#kb-categories-pane" class="on">{{
                mode === 'categories' ? t.categories : t.articles
            }}</a>
            <a href="#kb-articles-pane">{{
                mode === 'categories' ? t.category_one : t.categories
            }}</a>
            <a v-if="mode === 'articles'" href="#kb-detail-pane">{{ t.detail }}</a>
        </nav>

        <!-- ═══ S01 · the manager ═══ -->
        <div v-if="mode === 'articles'" class="kb-panes">
            <section
                id="kb-categories-pane"
                class="bo-pane"
                :aria-label="t.categories"
                data-testid="kb-pane-categories"
            >
                <div class="pt">
                    {{ t.categories }}
                    <span v-if="can.manage_categories" class="n">{{ t.tree_hint }}</span>
                </div>
                <ul class="kb-tree" role="tree" :aria-label="t.categories">
                    <li
                        v-for="c in ordered"
                        :key="c.id"
                        role="treeitem"
                        :aria-level="c.depth"
                        :aria-selected="selectedCategory === c.id"
                        class="kb-ti"
                        :class="['l' + c.depth, { on: selectedCategory === c.id }]"
                        :title="c.ai_usable ? t.tip_ai_usable : t.tip_not_ai_usable"
                    >
                        <span v-if="can.manage_categories" class="grip" aria-hidden="true">⠿</span>
                        <button
                            type="button"
                            class="kb-tname"
                            :data-testid="'kb-category-' + c.slug"
                            :aria-pressed="selectedCategory === c.id"
                            @click="pick(c)"
                        >
                            <span v-if="c.icon" class="ico" aria-hidden="true">{{ c.icon }}</span
                            >{{ name(c) }}
                            <span
                                v-if="c.visibility_default === 'internal'"
                                class="bo-tag"
                                :title="t.tip_internal_category"
                                >{{ t.audience_internal }}</span
                            >
                        </button>
                        <span
                            v-if="can.manage_categories && selectedCategory === c.id"
                            class="mv"
                            :aria-label="t.reorder"
                        >
                            <button
                                type="button"
                                :aria-label="t.move_up"
                                :data-testid="'kb-cat-up-' + c.slug"
                                @click="move(c, -1)"
                            >
                                ↑
                            </button>
                            <button
                                type="button"
                                :aria-label="t.move_down"
                                :data-testid="'kb-cat-down-' + c.slug"
                                @click="move(c, 1)"
                            >
                                ↓
                            </button>
                        </span>
                        <span class="cnt" :data-testid="'kb-cat-count-' + c.slug">{{
                            c.count
                        }}</span>
                    </li>
                </ul>
            </section>

            <section
                id="kb-articles-pane"
                class="bo-pane"
                :aria-label="t.articles"
                data-testid="kb-pane-articles"
            >
                <div class="pt">
                    {{ t.articles }}
                    <span class="n" data-testid="kb-list-header">{{ listHeader }}</span>
                </div>
                <div class="kb-filters">
                    <label class="bo-search2"
                        ><span aria-hidden="true">⌕</span
                        ><span class="sr-only">{{ t.search_placeholder }}</span
                        ><input
                            v-model="query"
                            type="search"
                            :placeholder="t.search_placeholder"
                            data-testid="kb-search"
                    /></label>
                    <select
                        v-model="fLang"
                        class="bo-sel"
                        :class="{ on: fLang }"
                        :aria-label="t.filter_language"
                        data-testid="kb-filter-language"
                    >
                        <option value="">{{ t.filter_language }}: {{ t.any }}</option>
                        <option v-for="l in languages" :key="l" :value="l">
                            {{ t.filter_language }}: {{ l }}
                        </option>
                    </select>
                    <select
                        v-model="fVis"
                        class="bo-sel"
                        :class="{ on: fVis }"
                        :aria-label="t.filter_visibility"
                        data-testid="kb-filter-visibility"
                    >
                        <option value="">{{ t.filter_visibility }}: {{ t.any }}</option>
                        <option value="public">
                            {{ t.filter_visibility }}: {{ t.audience_public }}
                        </option>
                        <option v-if="can.view_internal" value="internal">
                            {{ t.filter_visibility }}: {{ t.audience_internal }}
                        </option>
                    </select>
                    <select
                        v-model="fStatus"
                        class="bo-sel"
                        :class="{ on: fStatus }"
                        :aria-label="t.filter_status"
                        data-testid="kb-filter-status"
                    >
                        <option value="">{{ t.filter_status }}: {{ t.any }}</option>
                        <option
                            v-for="s in ['draft', 'in_review', 'published', 'archived']"
                            :key="s"
                            :value="s"
                        >
                            {{ t.filter_status }}: {{ statusWord(s) }}
                        </option>
                    </select>
                    <button
                        v-if="filtersOn || query"
                        type="button"
                        class="bo-link"
                        data-testid="kb-filter-clear"
                        @click="clearFilters"
                    >
                        {{ t.clear }}
                    </button>
                </div>

                <div v-if="!category" class="bo-empty">
                    <b>{{ t.no_category }}</b>
                </div>
                <div
                    v-else-if="searching"
                    class="kb-rows"
                    :aria-label="t.loading_articles"
                    data-testid="kb-list-skeleton"
                >
                    <div v-for="i in 4" :key="i" class="kb-row">
                        <div>
                            <span class="bo-sk w80"></span
                            ><span class="bo-sk w40" style="margin-top: 6px"></span>
                        </div>
                        <span class="bo-sk h20"></span><span class="bo-sk w60"></span
                        ><span class="bo-sk w60"></span>
                    </div>
                </div>
                <div
                    v-else-if="categoryArticles.length === 0"
                    class="bo-empty"
                    data-testid="kb-empty-category"
                >
                    <b>{{ fill(t.empty_title, { cat: name(category) }) }}</b>
                    <span>{{ t.empty_body }}</span>
                    <span class="kb-empty-acts">
                        <button
                            v-if="can.create"
                            type="button"
                            class="bo-btn p"
                            @click="createArticle()"
                        >
                            {{ t.new_article }}
                        </button>
                        <button
                            v-if="can.edit && movable.length"
                            type="button"
                            class="bo-btn"
                            data-testid="kb-move-here-open"
                            @click="moveOpen = !moveOpen"
                        >
                            {{ t.move_here }}
                        </button>
                    </span>
                    <span v-if="moveOpen" class="kb-move-row">
                        <select
                            v-model="movePick"
                            class="bo-sel"
                            :aria-label="t.move_here"
                            data-testid="kb-move-pick"
                        >
                            <option value="">{{ t.pick_article }}</option>
                            <option v-for="a in movable" :key="a.id" :value="a.id">
                                {{ title(a) }}
                            </option>
                        </select>
                        <button
                            type="button"
                            class="bo-btn sm p"
                            :disabled="!movePick"
                            data-testid="kb-move-confirm"
                            @click="moveHere"
                        >
                            {{ t.move_verb }}
                        </button>
                    </span>
                </div>
                <div v-else-if="listed.length === 0" class="bo-empty" data-testid="kb-no-results">
                    <b
                        >{{ t.no_results }} <span class="q">{{ query }}</span></b
                    >
                    <span>{{ t.no_results_body }}</span>
                    <button
                        v-if="can.create && query.trim()"
                        type="button"
                        class="bo-btn p"
                        data-testid="kb-create-from-query"
                        @click="createArticle(query.trim())"
                    >
                        {{ t.create_from_query }}
                    </button>
                </div>
                <div v-else class="kb-rows" role="table" :aria-label="t.articles">
                    <ul
                        v-if="freshTenant && seededInCategory.length"
                        class="bo-check"
                        data-testid="kb-fresh-checklist"
                    >
                        <li>
                            <span class="bo-box done" aria-hidden="true">✓</span
                            >{{
                                fill(t.fresh_step_seeded, { lang: langName(languages[0] ?? 'en') })
                            }}
                        </li>
                        <li>
                            <span class="bo-box" aria-hidden="true"></span>{{ t.fresh_step_review }}
                        </li>
                        <li>
                            <span class="bo-box" aria-hidden="true"></span
                            >{{ t.fresh_step_publish }}
                        </li>
                        <li>
                            <span class="bo-box" :class="{ done: anyAiDraft }" aria-hidden="true">{{
                                anyAiDraft ? '✓' : ''
                            }}</span
                            >{{ t.fresh_step_translate }}
                        </li>
                    </ul>
                    <div
                        v-for="a in listed"
                        :key="a.id"
                        role="row"
                        class="kb-row"
                        :class="{ on: selectedArticle === a.id }"
                        :data-testid="'kb-article-' + a.id"
                        tabindex="0"
                        @click="selectedArticle = a.id"
                        @keydown.enter.prevent="selectedArticle = a.id"
                    >
                        <div role="cell">
                            <div class="ttl">
                                {{ title(a) }}
                                <span
                                    v-if="a.audience === 'internal'"
                                    class="bo-tag"
                                    :title="t.internal_banner"
                                    >{{ t.audience_internal }}</span
                                >
                                <span v-if="isAiDraft(a)" class="bo-tag ai"
                                    >✦ {{ t.source_ai }}</span
                                >
                            </div>
                            <div class="meta">{{ meta(a) }}</div>
                        </div>
                        <div role="cell">
                            <span class="bo-pill" :class="articleStatus(a)">{{
                                statusWord(articleStatus(a))
                            }}</span>
                        </div>
                        <div role="cell">
                            <span class="bo-dots">
                                <span
                                    v-for="l in languages"
                                    :key="l"
                                    :class="dot(a, l).cls"
                                    :title="l + ': ' + stateWord(dot(a, l).state)"
                                    :data-testid="
                                        dot(a, l).variant
                                            ? 'kb-badge-' + dot(a, l).variant!.id
                                            : undefined
                                    "
                                    ><i aria-hidden="true">{{ dot(a, l).glyph }}</i
                                    >{{ l
                                    }}<span class="sr-only">
                                        ·
                                        {{
                                            dot(a, l).variant
                                                ? statusWord(dot(a, l).variant!.status)
                                                : t.ts_missing
                                        }}
                                        · {{ stateWord(dot(a, l).state) }}</span
                                    ></span
                                >
                            </span>
                        </div>
                        <div role="cell" class="right">{{ usage(a) }}</div>
                    </div>
                </div>
            </section>

            <section
                id="kb-detail-pane"
                class="bo-pane"
                :aria-label="t.detail"
                data-testid="kb-pane-detail"
            >
                <div class="pt">{{ t.detail }}</div>
                <div v-if="!article" class="bo-empty">
                    <b>{{ freshTenant ? t.pick_seeded : t.no_article }}</b>
                </div>
                <div v-else class="kb-detail">
                    <h3>{{ title(article) }}</h3>
                    <p class="kb-detail-tags">
                        <span class="bo-pill" :class="articleStatus(article)">{{
                            statusWord(articleStatus(article))
                        }}</span>
                        <span
                            v-for="v in article.variants.filter(
                                (x) => x.translation_source === 'ai',
                            )"
                            :key="v.id"
                            class="bo-tag ai"
                            >✦ {{ t.source_ai }} ·
                            {{ fill(t.variant_of, { lang: v.language }) }}</span
                        >
                    </p>
                    <dl class="bo-kv2">
                        <dt>{{ t.audience }}</dt>
                        <dd>
                            <template v-if="article.audience === 'internal'"
                                >{{ t.audience_internal }} · {{ t.copilot_only }}</template
                            >
                            <template v-else
                                >{{ t.audience_public }} ·
                                <template v-if="category?.ai_usable">{{
                                    fill(t.ai_inherited, { cat: name(category ?? undefined) })
                                }}</template>
                                <template v-else>{{
                                    fill(t.ai_not_inherited, { cat: name(category ?? undefined) })
                                }}</template></template
                            >
                        </dd>
                        <dt>{{ t.source }}</dt>
                        <dd>
                            {{
                                fill(t.source_first, {
                                    lang: langName(article.source_language),
                                    code: article.source_language,
                                })
                            }}
                        </dd>
                        <dt>{{ t.category_one }}</dt>
                        <dd>{{ name(category ?? undefined) }}</dd>
                        <dt>{{ t.slug }}</dt>
                        <dd class="bo-mono">
                            {{ slugLine(article) }}
                        </dd>
                    </dl>
                    <div class="kb-vlist">
                        <div v-for="v in article.variants" :key="v.id" class="kb-vrow">
                            <span class="lang">{{ v.language }}</span>
                            <span
                                :class="dot(article, v.language).cls"
                                :title="stateWord(v.translation_state)"
                                ><i aria-hidden="true">{{ dot(article, v.language).glyph }}</i>
                                {{ stateWord(v.translation_state) }}</span
                            >
                            <span class="bo-pill" :class="v.status">{{
                                statusWord(v.status)
                            }}</span>
                            <span class="sr-only"
                                >{{ v.language }} · {{ statusWord(v.status) }} ·
                                {{ stateWord(v.translation_state) }}</span
                            >
                            <span class="acts">
                                <a
                                    v-if="can.edit"
                                    class="bo-link"
                                    :data-testid="'kb-edit-' + v.id"
                                    :href="'/admin/kb/variants/' + v.id + '/edit'"
                                    >{{ t.edit }}</a
                                >
                                <a
                                    v-if="can.edit"
                                    class="bo-link"
                                    :href="'/admin/kb/variants/' + v.id + '/history-page'"
                                    >{{ t.history }}</a
                                >
                            </span>
                        </div>
                    </div>
                    <p
                        v-if="article.variants.some((v) => v.translation_state === 'outdated')"
                        class="bo-hint"
                    >
                        {{ t.outdated_note }}
                    </p>
                </div>
            </section>
        </div>
        <div v-if="mode === 'articles'" class="kb-legend">
            <span><span class="d-cur">●</span> {{ t.ts_current }}</span>
            <span><span class="d-out">◐</span> {{ t.legend_outdated }}</span>
            <span><span class="d-mis">○</span> {{ t.ts_missing }}</span>
            <span
                ><span class="bo-tag">{{ t.audience_internal }}</span> {{ t.legend_internal }}</span
            >
            <span
                ><span class="bo-tag ai">✦ {{ t.source_ai }}</span> {{ t.legend_ai }}</span
            >
        </div>

        <!-- ═══ S04 · categories mode ═══ -->
        <div v-else class="kb-cat">
            <section
                id="kb-categories-pane"
                class="bo-pane"
                :aria-label="t.categories"
                data-testid="kb-pane-categories"
            >
                <div class="pt">
                    {{ t.categories }}
                    <span class="n">{{
                        fill(t.tree_stats, { n: categories.length, articles: articles.length })
                    }}</span>
                </div>
                <div class="kb-tree-acts">
                    <button
                        type="button"
                        class="bo-btn sm"
                        data-testid="kb-new-category"
                        @click="openNew(null)"
                    >
                        {{ t.new_category }}
                    </button>
                </div>
                <ul class="kb-tree" role="tree" :aria-label="t.categories">
                    <li
                        v-for="c in ordered"
                        :key="c.id"
                        role="treeitem"
                        :aria-level="c.depth"
                        :aria-selected="selectedCategory === c.id"
                        class="kb-ti"
                        :class="['l' + c.depth, { on: selectedCategory === c.id }]"
                        :title="c.ai_usable ? t.tip_ai_usable : t.tip_not_ai_usable"
                        @keydown="onTreeKey($event, c)"
                    >
                        <span class="grip" aria-hidden="true">⋮⋮</span>
                        <button
                            type="button"
                            class="kb-tname"
                            :data-testid="'kb-category-' + c.slug"
                            :aria-pressed="selectedCategory === c.id"
                            @click="pick(c)"
                        >
                            <span class="ico" aria-hidden="true">{{ c.icon ?? '' }}</span
                            >{{ name(c) }}
                            <span
                                v-if="c.visibility_default === 'internal'"
                                class="bo-tag"
                                :title="t.tip_internal_category"
                                >{{ t.audience_internal }}</span
                            >
                        </button>
                        <span class="cnt">{{ c.count }}</span>
                        <span v-if="selectedCategory === c.id" class="mv" :aria-label="t.reorder">
                            <button
                                type="button"
                                :aria-label="t.move_up"
                                :data-testid="'kb-cat-up-' + c.slug"
                                @click="move(c, -1)"
                            >
                                ↑
                            </button>
                            <button
                                type="button"
                                :aria-label="t.move_down"
                                :data-testid="'kb-cat-down-' + c.slug"
                                @click="move(c, 1)"
                            >
                                ↓
                            </button>
                        </span>
                    </li>
                </ul>
                <p v-if="liveLine" class="kb-live" role="status" data-testid="kb-tree-live">
                    {{ liveLine }}
                </p>
                <p class="bo-hint kb-tree-hint">{{ t.tree_help }}</p>
            </section>

            <section
                id="kb-articles-pane"
                class="bo-pane"
                :aria-label="t.category_one"
                data-testid="kb-pane-category"
            >
                <div class="pt">
                    {{ t.category_one }}
                    <span v-if="category" class="n" data-testid="kb-cat-depth"
                        >{{ fill(t.depth_of, { d: category.depth, max: MAX_DEPTH }) }} ·
                        {{ pathOf(category) }}</span
                    >
                </div>
                <div v-if="!category || !draft" class="bo-empty">
                    <b>{{ t.no_category }}</b>
                </div>
                <form v-else class="kb-form" @submit.prevent="saveCategory">
                    <p
                        v-if="catMessage"
                        role="alert"
                        class="bo-refuse"
                        data-testid="kb-cat-message"
                    >
                        {{ catMessage }}
                    </p>
                    <div class="kb-facts" data-testid="kb-cat-facts">
                        <div class="kb-fact">
                            <div class="k">{{ t.fact_articles }}</div>
                            <div class="v">{{ category.count }}</div>
                            <div class="s">{{ t.fact_in_category }}</div>
                        </div>
                        <div class="kb-fact">
                            <div class="k">{{ t.fact_published }}</div>
                            <div class="v">{{ category.published_count }}</div>
                            <div class="s">
                                {{
                                    fill(t.fact_draft_review, {
                                        n: category.count - category.published_count,
                                    })
                                }}
                            </div>
                        </div>
                        <div class="kb-fact">
                            <div class="k">{{ t.fact_chatbot }}</div>
                            <div class="v">{{ category.chatbot_count }}</div>
                            <div class="s">
                                {{ category.ai_usable ? t.fact_chatbot_sub : t.fact_not_ai }}
                            </div>
                        </div>
                    </div>
                    <div class="kb-g2">
                        <div v-for="l in languages" :key="l" class="bo-fld">
                            <label :for="'kb-cat-name-' + l"
                                >{{ t.field_name }} · {{ langName(l) }}
                                <span
                                    :class="draft.name[l] ? 'd-cur' : 'd-mis'"
                                    :title="
                                        l + ': ' + (draft.name[l] ? t.ts_current : t.ts_missing)
                                    "
                                    ><i aria-hidden="true">{{ draft.name[l] ? '●' : '○' }}</i></span
                                ></label
                            >
                            <input
                                :id="'kb-cat-name-' + l"
                                v-model="draft.name[l]"
                                class="bo-in"
                                :data-testid="'kb-cat-name-' + l"
                                :disabled="!can.manage_categories"
                            />
                        </div>
                    </div>
                    <div class="kb-g2">
                        <div class="bo-fld">
                            <label for="kb-cat-slug">{{ t.field_slug }}</label>
                            <input
                                id="kb-cat-slug"
                                v-model="draft.slug"
                                class="bo-in bo-mono"
                                data-testid="kb-cat-slug"
                                :disabled="!can.manage_categories"
                            />
                            <p class="bo-hint">{{ t.slug_hint }}</p>
                        </div>
                        <div class="bo-fld">
                            <span class="lab">{{ t.field_parent }}</span>
                            <p class="bo-in" data-testid="kb-cat-parent">
                                {{ category.parent_id ? parentName : t.top_level }}
                            </p>
                            <p class="bo-hint">{{ t.parent_hint }}</p>
                        </div>
                    </div>
                    <div class="kb-g2">
                        <div v-for="l in languages" :key="'d' + l" class="bo-fld">
                            <label :for="'kb-cat-desc-' + l"
                                >{{ t.field_description }} · {{ langName(l) }}</label
                            >
                            <textarea
                                :id="'kb-cat-desc-' + l"
                                v-model="draft.description[l]"
                                class="bo-in ta"
                                rows="2"
                                :data-testid="'kb-cat-desc-' + l"
                                :disabled="!can.manage_categories"
                            ></textarea>
                        </div>
                    </div>
                    <div class="kb-g2">
                        <div class="bo-fld">
                            <span id="kb-icon-label" class="lab">{{ t.field_icon }}</span>
                            <div
                                class="kb-icons"
                                role="radiogroup"
                                aria-labelledby="kb-icon-label"
                                data-testid="kb-cat-icons"
                            >
                                <button
                                    v-for="ic in ICONS"
                                    :key="ic"
                                    type="button"
                                    role="radio"
                                    :aria-checked="draft.icon === ic"
                                    :aria-label="ic"
                                    :class="{ on: draft.icon === ic }"
                                    :disabled="!can.manage_categories"
                                    @click="draft.icon = draft.icon === ic ? '' : ic"
                                >
                                    {{ ic }}
                                </button>
                            </div>
                        </div>
                        <div class="bo-fld">
                            <label for="kb-cat-position">{{ t.field_position }}</label>
                            <input
                                id="kb-cat-position"
                                v-model.number="draft.position"
                                type="number"
                                min="0"
                                class="bo-in"
                                data-testid="kb-cat-position"
                                :disabled="!can.manage_categories"
                            />
                            <p class="bo-hint">{{ t.position_hint }}</p>
                        </div>
                    </div>
                    <div class="kb-tog">
                        <button
                            type="button"
                            role="switch"
                            :aria-checked="draft.ai_usable"
                            class="kb-sw"
                            :class="{ on: draft.ai_usable }"
                            data-testid="kb-cat-ai-switch"
                            :disabled="!can.manage_categories"
                            @click="toggleAi"
                        >
                            <span class="sr-only">{{ t.field_ai_usable }}</span>
                        </button>
                        <div>
                            <b
                                >{{ t.field_ai_usable }} —
                                {{ draft.ai_usable ? t.on_word : t.off_word }}</b
                            >
                            <small>{{
                                draft.ai_usable ? t.ai_on_sentence : t.ai_off_sentence
                            }}</small>
                        </div>
                    </div>
                    <div class="bo-fld">
                        <span id="kb-vis-label" class="lab">{{ t.field_visibility }}</span>
                        <div
                            class="kb-seg"
                            role="radiogroup"
                            aria-labelledby="kb-vis-label"
                            data-testid="kb-cat-visibility"
                        >
                            <button
                                v-for="v in ['public', 'internal']"
                                :key="v"
                                type="button"
                                role="radio"
                                :aria-checked="draft.visibility_default === v"
                                :class="{ on: draft.visibility_default === v }"
                                :disabled="!can.manage_categories"
                                @click="draft.visibility_default = v"
                            >
                                {{ t['audience_' + v] }}
                            </button>
                        </div>
                        <p class="bo-hint">{{ t.visibility_hint }}</p>
                    </div>
                    <div v-if="can.manage_categories" class="kb-form-acts">
                        <button
                            type="submit"
                            class="bo-btn p"
                            :disabled="saving"
                            data-testid="kb-cat-save"
                        >
                            {{ t.save }}
                        </button>
                        <button
                            v-if="category.depth < MAX_DEPTH"
                            type="button"
                            class="bo-btn"
                            data-testid="kb-cat-add-sub"
                            @click="openNew(category)"
                        >
                            {{ t.add_subcategory }}
                        </button>
                        <button
                            type="button"
                            class="bo-btn"
                            data-testid="kb-cat-move-articles"
                            @click="moveArticlesOpen = true"
                        >
                            {{ t.move_articles }}
                        </button>
                        <button
                            type="button"
                            class="bo-btn"
                            data-testid="kb-cat-delete"
                            @click="openDelete"
                        >
                            {{ t.delete_ellipsis }}
                        </button>
                    </div>
                </form>
            </section>

            <aside class="bo-rail" data-testid="kb-cat-rail">
                <div
                    v-if="category && category.depth >= MAX_DEPTH"
                    class="bo-card"
                    data-testid="kb-depth-wall"
                >
                    <div class="ct">{{ t.wall_title }}</div>
                    <div class="cb">
                        <p class="bo-absent">{{ t.wall_body }}</p>
                        <p class="bo-hint">{{ t.wall_physics }}</p>
                    </div>
                </div>
                <div
                    v-if="
                        category &&
                        !category.ai_usable &&
                        category.visibility_default === 'internal'
                    "
                    class="bo-card"
                    data-testid="kb-internal-split"
                >
                    <div class="ct">{{ t.split_title }}</div>
                    <div class="cb">
                        <dl class="bo-kv2">
                            <dt>{{ t.split_two_k }}</dt>
                            <dd>{{ t.split_two_v }}</dd>
                            <dt>{{ t.split_this_k }}</dt>
                            <dd>{{ t.split_this_v }}</dd>
                            <dt>{{ t.split_public_k }}</dt>
                            <dd>{{ t.split_public_v }}</dd>
                        </dl>
                    </div>
                </div>
                <div v-else class="bo-card" data-testid="kb-inherit-card">
                    <div class="ct">{{ t.inherit_title }}</div>
                    <div class="cb">
                        <dl class="bo-kv2">
                            <dt>{{ t.field_ai_usable }}</dt>
                            <dd>{{ t.inherit_ai }}</dd>
                            <dt>{{ t.inherit_public_k }}</dt>
                            <dd>
                                {{
                                    category?.ai_usable
                                        ? fill(t.inherit_public_v, {
                                              n: category?.chatbot_count ?? 0,
                                          })
                                        : t.inherit_public_off
                                }}
                            </dd>
                            <dt>{{ t.inherit_internal_k }}</dt>
                            <dd>
                                {{
                                    fill(t.inherit_internal_v, {
                                        n: category ? internalCount(category) : 0,
                                    })
                                }}
                            </dd>
                        </dl>
                        <span class="bo-tip"
                            ><span class="bo-link">{{ t.hover_demo }}</span
                            ><span class="tt">{{
                                fill(t.ai_inherited_short, { cat: name(category ?? undefined) })
                            }}</span></span
                        >
                    </div>
                </div>
                <div class="bo-card">
                    <div class="ct">{{ t.where_title }}</div>
                    <div class="cb">{{ t.where_body }}</div>
                </div>
            </aside>
        </div>

        <!-- EC-03: delete needs reassign first -->
        <div v-if="deleteOpen && category" class="bo-scrim" @click.self="deleteOpen = false">
            <div
                class="bo-dlg"
                role="dialog"
                aria-modal="true"
                :aria-label="fill(t.delete_title, { name: name(category) })"
                data-testid="kb-delete-dialog"
            >
                <h3 class="dt">{{ fill(t.delete_title, { name: name(category) }) }}</h3>
                <div class="db">
                    <template v-if="category.count > 0">
                        <p class="bo-refuse" data-testid="kb-delete-refuse">
                            <b>{{ fill(t.delete_refuse, { n: category.count }) }}</b>
                            {{ t.delete_refuse_body }}
                        </p>
                        <dl class="bo-row2">
                            <dt>{{ fill(t.move_n_to, { n: category.count }) }}</dt>
                            <dd>
                                <select
                                    v-model="reassignTo"
                                    class="bo-sel"
                                    :aria-label="t.move_n_to"
                                    data-testid="kb-reassign-target"
                                >
                                    <option value="">{{ t.pick_category }}</option>
                                    <option v-for="c in reassignTargets" :key="c.id" :value="c.id">
                                        {{ pathOf(c) }} ·
                                        {{ c.ai_usable ? t.ai_usable_word : t.not_ai_usable_word }}
                                    </option>
                                </select>
                            </dd>
                        </dl>
                        <p
                            v-if="reassignTo && impact !== null"
                            class="bo-warnbox"
                            data-testid="kb-reassign-impact"
                        >
                            {{
                                fill(impact > 0 ? t.impact_leave : t.impact_none, {
                                    n: impact,
                                    target: reassignTargetName,
                                })
                            }}
                        </p>
                        <dl class="bo-row2">
                            <dt>{{ t.alternative }}</dt>
                            <dd v-if="zeroImpactTarget">
                                {{ fill(t.alternative_body, { target: name(zeroImpactTarget) }) }}
                            </dd>
                            <dd v-else>{{ t.alternative_none }}</dd>
                            <dt>{{ t.then_word }}</dt>
                            <dd>{{ t.then_body }}</dd>
                        </dl>
                        <p class="bo-hint">{{ t.delete_only_empty }}</p>
                    </template>
                    <template v-else>
                        <p>{{ t.delete_empty_body }}</p>
                    </template>
                </div>
                <div class="df">
                    <button type="button" class="bo-btn" @click="deleteOpen = false">
                        {{ t.cancel }}
                    </button>
                    <button
                        v-if="category.count > 0"
                        type="button"
                        class="bo-btn p"
                        :disabled="!reassignTo"
                        data-testid="kb-reassign-confirm"
                        @click="reassign"
                    >
                        {{ fill(t.reassign_verb, { n: category.count }) }}
                    </button>
                    <button
                        v-else
                        type="button"
                        class="bo-btn danger"
                        data-testid="kb-delete-confirm"
                        @click="destroyCategory"
                    >
                        {{ t.delete_verb }}
                    </button>
                </div>
            </div>
        </div>

        <!-- "Move articles…" — the same reassign door, from the editor's own button -->
        <div
            v-if="moveArticlesOpen && category"
            class="bo-scrim"
            @click.self="moveArticlesOpen = false"
        >
            <div
                class="bo-dlg"
                role="dialog"
                aria-modal="true"
                :aria-label="t.move_articles"
                data-testid="kb-move-articles-dialog"
            >
                <h3 class="dt">
                    {{ fill(t.move_articles_title, { name: name(category), n: category.count }) }}
                </h3>
                <div class="db">
                    <select
                        v-model="reassignTo"
                        class="bo-sel"
                        :aria-label="t.move_n_to"
                        data-testid="kb-move-articles-target"
                    >
                        <option value="">{{ t.pick_category }}</option>
                        <option v-for="c in reassignTargets" :key="c.id" :value="c.id">
                            {{ pathOf(c) }}
                        </option>
                    </select>
                    <p v-if="reassignTo && impact !== null" class="bo-warnbox">
                        {{
                            fill(impact > 0 ? t.impact_leave : t.impact_none, {
                                n: impact,
                                target: reassignTargetName,
                            })
                        }}
                    </p>
                </div>
                <div class="df">
                    <button type="button" class="bo-btn" @click="moveArticlesOpen = false">
                        {{ t.cancel }}
                    </button>
                    <button
                        type="button"
                        class="bo-btn p"
                        :disabled="!reassignTo || category.count === 0"
                        @click="reassign"
                    >
                        {{ fill(t.reassign_verb, { n: category.count }) }}
                    </button>
                </div>
            </div>
        </div>

        <!-- EC-02: AI-usable off — the consequence in nouns -->
        <div v-if="aiOffOpen && category" class="bo-scrim" @click.self="aiOffOpen = false">
            <div
                class="bo-dlg"
                role="dialog"
                aria-modal="true"
                :aria-label="fill(t.aioff_title, { name: name(category) })"
                data-testid="kb-aioff-dialog"
            >
                <h3 class="dt">{{ fill(t.aioff_title, { name: name(category) }) }}</h3>
                <div class="db">
                    <dl class="bo-row2">
                        <dt>{{ t.category_one }}</dt>
                        <dd>
                            {{
                                fill(t.aioff_category, {
                                    name: name(category),
                                    n: subcategoryCount(category),
                                })
                            }}
                        </dd>
                        <dt>{{ t.now_word }}</dt>
                        <dd>{{ fill(t.aioff_now, { n: category.chatbot_count }) }}</dd>
                        <dt>{{ t.unchanged_word }}</dt>
                        <dd>{{ t.aioff_unchanged }}</dd>
                        <dt>{{ t.audience_internal }}</dt>
                        <dd>{{ fill(t.aioff_internal, { n: internalCount(category) }) }}</dd>
                        <dt>{{ t.audited_word }}</dt>
                        <dd>{{ t.aioff_audited }}</dd>
                    </dl>
                </div>
                <div class="df">
                    <button type="button" class="bo-btn" @click="aiOffOpen = false">
                        {{ t.keep_on }}
                    </button>
                    <button
                        type="button"
                        class="bo-btn danger"
                        data-testid="kb-aioff-confirm"
                        @click="applyAiUsable(false)"
                    >
                        {{ fill(t.aioff_verb, { n: category.chatbot_count }) }}
                    </button>
                </div>
            </div>
        </div>

        <!-- new category (store door) -->
        <div v-if="newOpen" class="bo-scrim" @click.self="newOpen = false">
            <form
                class="bo-dlg"
                role="dialog"
                aria-modal="true"
                :aria-label="t.new_category"
                data-testid="kb-new-category-dialog"
                @submit.prevent="createCategory"
            >
                <h3 class="dt">{{ t.new_category }}</h3>
                <div class="db">
                    <div v-for="l in languages" :key="'n' + l" class="bo-fld">
                        <label :for="'kb-newcat-name-' + l"
                            >{{ t.field_name }} · {{ langName(l) }}</label
                        >
                        <input
                            :id="'kb-newcat-name-' + l"
                            v-model="newDraft.name[l]"
                            class="bo-in"
                            :data-testid="'kb-newcat-name-' + l"
                        />
                    </div>
                    <div class="bo-fld">
                        <label for="kb-newcat-slug">{{ t.field_slug }}</label>
                        <input
                            id="kb-newcat-slug"
                            v-model="newDraft.slug"
                            class="bo-in bo-mono"
                            data-testid="kb-newcat-slug"
                            pattern="[a-z0-9-]+"
                            required
                        />
                    </div>
                    <div class="bo-fld">
                        <label for="kb-newcat-parent">{{ t.field_parent }}</label>
                        <select
                            id="kb-newcat-parent"
                            v-model="newDraft.parent_id"
                            class="bo-in"
                            data-testid="kb-newcat-parent"
                        >
                            <option :value="null">{{ t.top_level }}</option>
                            <option v-for="c in parentOptions" :key="c.id" :value="c.id">
                                {{ pathOf(c) }}
                            </option>
                        </select>
                        <p class="bo-hint">{{ t.parent_hint }}</p>
                    </div>
                    <label class="kb-checkline"
                        ><input
                            v-model="newDraft.ai_usable"
                            type="checkbox"
                            data-testid="kb-newcat-ai"
                        />
                        {{ t.field_ai_usable }}</label
                    >
                </div>
                <div class="df">
                    <button type="button" class="bo-btn" @click="newOpen = false">
                        {{ t.cancel }}
                    </button>
                    <button type="submit" class="bo-btn p" data-testid="kb-newcat-create">
                        {{ t.create_verb }}
                    </button>
                </div>
            </form>
        </div>
    </BackofficeShell>
</template>

<style>
.kb-panes {
    display: grid;
    grid-template-columns: 240px minmax(0, 1fr) 320px;
    gap: 16px;
    padding: 14px 18px 8px;
    flex: 1;
}
.kb-cat {
    display: grid;
    grid-template-columns: 320px minmax(0, 1fr) 300px;
    gap: 16px;
    padding: 12px 18px 24px;
    flex: 1;
    align-items: start;
}
.kb-tree {
    list-style: none;
    margin: 0;
    padding: 6px;
}
.kb-ti {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 4px 8px;
    border-radius: 6px;
    font-size: 12.5px;
    min-height: 36px;
    position: relative;
}
.kb-ti.on {
    background: var(--ds-inset-bg);
    font-weight: 600;
}
.kb-ti.l2 {
    padding-inline-start: 26px;
}
.kb-ti.l3 {
    padding-inline-start: 44px;
}
.kb-tname {
    flex: 1;
    min-width: 0;
    text-align: start;
    background: none;
    border: 0;
    padding: 4px 0;
    font: inherit;
    color: var(--ds-text-primary);
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
    min-height: 28px;
}
.kb-ti .ico {
    width: 18px;
    text-align: center;
    color: var(--ds-text-muted);
    font-size: 12px;
}
.kb-ti .grip {
    color: var(--ds-text-muted);
    font-size: 11px;
    cursor: grab;
}
.kb-ti .cnt {
    margin-inline-start: auto;
    font-size: 11px;
    color: var(--ds-text-muted);
    font-variant-numeric: tabular-nums;
}
.kb-ti .mv {
    display: inline-flex;
    gap: 2px;
}
.kb-ti .mv button {
    width: 24px;
    height: 24px;
    border: 1px solid var(--ds-border-strong);
    border-radius: 4px;
    background: var(--ds-panel-bg);
    font-size: 10px;
    color: var(--ds-text-primary);
    cursor: pointer;
}
.kb-tree-acts {
    display: flex;
    gap: 6px;
    padding: 8px 12px 0;
}
.kb-tree-hint {
    padding: 0 12px 10px;
    margin: 0;
}
.kb-live {
    font-size: 11.5px;
    color: var(--ds-text-secondary);
    padding: 8px 12px;
    border-block-start: 1px dashed var(--ds-border);
    margin: 6px 0 0;
}
.kb-filters {
    display: flex;
    gap: 6px;
    padding: 10px 12px;
    border-block-end: 1px solid var(--ds-border-soft);
    flex-wrap: wrap;
    align-items: center;
}
.kb-rows {
    padding: 4px 6px;
    display: grid;
    gap: 0;
}
.kb-row {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 92px 96px 96px;
    gap: 10px;
    align-items: center;
    padding: 10px 8px;
    border-radius: 6px;
    min-height: 64px;
    border-block-end: 1px solid var(--ds-border-soft);
    cursor: pointer;
}
.kb-row.on {
    background: var(--ds-inset-bg);
}
.kb-row:hover {
    background: var(--ds-row-hover);
}
.kb-row:focus-visible {
    outline: 2px solid var(--ds-focus-ring);
    outline-offset: -2px;
}
.kb-row .ttl {
    font-size: 13px;
    font-weight: 600;
    line-height: 1.35;
    overflow-wrap: anywhere;
    display: inline;
}
.kb-row .ttl .bo-tag {
    margin-inline-start: 4px;
    vertical-align: middle;
}
.kb-row .meta {
    font-size: 11px;
    color: var(--ds-text-muted);
    margin-top: 3px;
}
.kb-row .right {
    font-size: 11px;
    color: var(--ds-text-muted);
    text-align: end;
    font-variant-numeric: tabular-nums;
}
.kb-empty-acts,
.kb-move-row {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    justify-content: center;
    align-items: center;
}
.kb-detail {
    padding: 12px;
}
.kb-detail h3 {
    font-size: 14.5px;
    margin: 0 0 6px;
    line-height: 1.35;
    overflow-wrap: anywhere;
}
.kb-detail-tags {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
    margin: 0 0 10px;
}
.kb-detail .bo-kv2 {
    grid-template-columns: 96px 1fr;
    margin: 10px 0;
}
.kb-vlist {
    border-block-start: 1px solid var(--ds-border-soft);
    margin-top: 8px;
}
.kb-vrow {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 9px 0;
    border-block-end: 1px solid var(--ds-border-soft);
    font-size: 12px;
    min-height: 44px;
    flex-wrap: wrap;
}
.kb-vrow .lang {
    font-family: ui-monospace, monospace;
    font-size: 11px;
    width: 24px;
    color: var(--ds-text-muted);
}
.kb-vrow .acts {
    margin-inline-start: auto;
    display: flex;
    gap: 6px;
}
.kb-legend {
    display: flex;
    gap: 18px;
    flex-wrap: wrap;
    font-size: 11.5px;
    color: var(--ds-text-secondary);
    padding: 8px 18px 24px;
}
.kb-form {
    padding: 14px 16px;
    display: grid;
    gap: 12px;
}
.kb-g2 {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
}
.kb-facts {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 8px;
}
.kb-fact {
    border: 1px solid var(--ds-border);
    border-radius: 8px;
    padding: 8px 10px;
}
.kb-fact .k {
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.07em;
    color: var(--ds-text-label);
}
.kb-fact .v {
    font-size: 15px;
    font-weight: 600;
    margin-top: 2px;
    font-variant-numeric: tabular-nums;
}
.kb-fact .s {
    font-size: 11px;
    color: var(--ds-text-muted);
}
.kb-tog {
    display: flex;
    gap: 10px;
    align-items: flex-start;
    padding: 10px 12px;
    border: 1px solid var(--ds-border);
    border-radius: 8px;
}
.kb-tog b {
    font-size: 12.5px;
}
.kb-tog small {
    display: block;
    font-size: 11.5px;
    color: var(--ds-text-secondary);
    margin-top: 2px;
    line-height: 1.45;
}
.kb-sw {
    width: 44px;
    height: 24px;
    border-radius: 999px;
    background: var(--ds-border-strong);
    position: relative;
    flex: none;
    margin-top: 2px;
    border: 0;
    cursor: pointer;
    padding: 0;
}
.kb-sw::after {
    content: '';
    position: absolute;
    top: 3px;
    inset-inline-start: 3px;
    width: 18px;
    height: 18px;
    border-radius: 50%;
    background: var(--ds-panel-bg);
}
.kb-sw.on {
    background: var(--ds-success-text);
}
.kb-sw.on::after {
    inset-inline-start: 23px;
}
.kb-icons {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
}
.kb-icons button {
    width: 36px;
    height: 36px;
    border: 1px solid var(--ds-border);
    border-radius: 6px;
    display: grid;
    place-items: center;
    font-size: 15px;
    background: var(--ds-panel-bg);
    cursor: pointer;
}
.kb-icons button.on {
    border-color: var(--ds-accent);
    background: var(--ds-inset-bg);
    box-shadow: inset 0 0 0 1px var(--ds-accent);
}
.kb-seg {
    display: inline-flex;
    border: 1px solid var(--ds-border);
    border-radius: 6px;
    overflow: hidden;
}
.kb-seg button {
    padding: 7px 12px;
    font-size: 11.5px;
    font-weight: 600;
    min-height: 32px;
    display: inline-flex;
    align-items: center;
    background: var(--ds-panel-bg);
    border: 0;
    color: var(--ds-text-primary);
    cursor: pointer;
    font-family: inherit;
}
.kb-seg button.on {
    background: var(--ds-accent);
    color: var(--ds-accent-contrast);
}
.kb-form-acts {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}
.kb-checkline {
    display: flex;
    gap: 8px;
    align-items: center;
    font-size: 12px;
}
@media (max-width: 639px) {
    .kb-panes,
    .kb-cat {
        grid-template-columns: 1fr;
        padding: 10px 12px;
    }
    .kb-row {
        grid-template-columns: minmax(0, 1fr) auto;
    }
    .kb-row .right {
        display: none;
    }
    .kb-row > div:nth-child(3) {
        grid-column: 1 / -1;
    }
    .kb-g2,
    .kb-facts {
        grid-template-columns: 1fr;
    }
    .kb-ti {
        min-height: 44px;
    }
    .kb-form-acts {
        display: none;
    }
}
</style>
