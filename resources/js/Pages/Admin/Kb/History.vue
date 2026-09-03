<script setup lang="ts">
// Doc 10 FR-08/US-02 — version history. SESSION-F1 [B9] rebuilt it to the frozen B9-S03 §C:
// a two-pane page in the Backoffice chrome — the version list (number, author, note, badges:
// current · sanitizer override kept · cited-by-AI · reviewed-by · ✦ AI-translated · seeded;
// View / Compare / Restore per row, Restore ABSENT on the current version) beside the diff
// pane with the §9 block anatomy (green "+" added, red "−" removed as a collapsed stub, "±"
// changed as a before/after pair with the differing words marked, dashed unchanged) and the
// §13 prev/next changed-block landmarks; the restore CONFIRMATION (a NEW draft, history
// untouched); the archive dialog with its consequences in nouns and the archived banner.
// The doors: versions history/show/diff/restore, workflow archive/unarchive. Tokens only.
import { computed, onMounted, ref } from 'vue';
import BackofficeShell from '@/Components/Backoffice/BackofficeShell.vue';
import { kbCall } from './kbApi';

type Version = {
    id: string;
    version: number;
    author_id: string;
    author_name: string | null;
    note: string | null;
    content_hash: string;
    published_at: string;
    override_kept: boolean;
    flag_count: number;
    reviewers: string[];
    citations: number;
    seeded: boolean;
    ai_translated: boolean;
};
type Block = {
    type?: string;
    text?: string;
    items?: string[];
    rows?: string[][];
    alt?: string;
    media_key?: string | null;
    anchor?: string;
};
type Entry = {
    kind: 'added' | 'removed' | 'changed' | 'unchanged';
    key: string;
    old: Block | null;
    new: Block | null;
};
type Diff = {
    from: number;
    to: number;
    summary: { added: number; removed: number; changed: number; unchanged: number };
    entries: Entry[];
};
const props = defineProps<{
    t: Record<string, string>;
    variant: {
        id: string;
        article_id: string;
        language: string;
        title: string;
        status: string;
        current_version_id: string | null;
        translation_source: string;
    };
    article: {
        audience: string;
        source_language: string;
        seeded: boolean;
        category_id: string;
        category_name: string | null;
    } | null;
    versions: Version[];
}>();

function fill(template: string | undefined, vars: Record<string, string | number>): string {
    let out = template ?? '';
    for (const [k, v] of Object.entries(vars)) out = out.split(':' + k).join(String(v));
    return out;
}
const hhmm = (iso: string): string => iso.replace('T', ' ').slice(0, 16);
const statusWord = (s: string): string => props.t['status_' + s] ?? s;
const typeWord = (b: Block | null): string =>
    (b?.type === 'also_asked'
        ? props.t.also_asked
        : props.t['block_' + (b?.type ?? 'paragraph')]) ??
    b?.type ??
    '';
const blockText = (b: Block | null): string => {
    if (!b) return '';
    if (typeof b.text === 'string') return b.text;
    if (b.items) return b.items.join(' · ');
    if (b.rows) return b.rows.map((r) => r.join(' | ')).join(' / ');
    if (b.type === 'image') return (b.media_key ?? '') + (b.alt ? ' · ' + b.alt : '');
    if (b.anchor) return '#' + b.anchor;
    return '';
};
const currentVersion = computed(
    () => props.versions.find((v) => v.id === props.variant.current_version_id) ?? null,
);
const latest = computed(() => props.versions[0] ?? null);
const isArchived = computed(() => props.variant.status === 'archived');
const isSource = computed(() => props.article?.source_language === props.variant.language);

// ── compare: older → newer through the diff door ──
const fromId = ref<string>(props.versions[1]?.id ?? '');
const toId = ref<string>(props.versions[0]?.id ?? '');
const diff = ref<Diff | null>(null);
const diffFailed = ref(false);
const loading = ref(false);
const selected = ref<string | null>(props.versions[0]?.id ?? null);
async function loadDiff(): Promise<void> {
    if (!fromId.value || !toId.value || fromId.value === toId.value) {
        diff.value = null;
        return;
    }
    loading.value = true;
    diffFailed.value = false;
    try {
        diff.value = await kbCall<Diff>(
            '/admin/kb/versions/' + fromId.value + '/diff?to=' + toId.value,
            undefined,
            'GET',
        );
        focusIndex.value = 0;
    } catch {
        diffFailed.value = true;
    } finally {
        loading.value = false;
    }
}
function compareWithCurrent(v: Version): void {
    selected.value = v.id;
    const cur = currentVersion.value ?? latest.value;
    if (!cur || cur.id === v.id) return;
    fromId.value = v.id;
    toId.value = cur.id;
    void loadDiff();
}
const changedEntries = computed(() =>
    (diff.value?.entries ?? []).map((e, i) => ({ e, i })).filter((x) => x.e.kind !== 'unchanged'),
);
const focusIndex = ref(0);
function goChange(dir: -1 | 1): void {
    const n = changedEntries.value.length;
    if (!n) return;
    focusIndex.value = (focusIndex.value + dir + n) % n;
    const target = changedEntries.value[focusIndex.value];
    if (target) document.getElementById('kb-diff-' + target.i)?.scrollIntoView({ block: 'center' });
}
const isFocused = (i: number): boolean => changedEntries.value[focusIndex.value]?.i === i;
const changeNumber = (i: number): number => changedEntries.value.findIndex((x) => x.i === i) + 1;
const expanded = ref<Set<string>>(new Set());
function toggleStub(key: string): void {
    const next = new Set(expanded.value);
    if (next.has(key)) next.delete(key);
    else next.add(key);
    expanded.value = next;
}
// the differing words, marked: a word-level LCS over the two texts (presentation only)
function wordDiff(
    a: string,
    b: string,
): { before: { w: string; del: boolean }[]; after: { w: string; ins: boolean }[] } {
    const A = a.split(/(\s+)/);
    const B = b.split(/(\s+)/);
    const n = A.length;
    const m = B.length;
    const L: number[][] = Array.from({ length: n + 1 }, () => new Array<number>(m + 1).fill(0));
    for (let i = n - 1; i >= 0; i--)
        for (let j = m - 1; j >= 0; j--)
            L[i]![j] =
                A[i] === B[j] ? L[i + 1]![j + 1]! + 1 : Math.max(L[i + 1]![j]!, L[i]![j + 1]!);
    const before: { w: string; del: boolean }[] = [];
    const after: { w: string; ins: boolean }[] = [];
    let i = 0;
    let j = 0;
    while (i < n && j < m) {
        if (A[i] === B[j]) {
            before.push({ w: A[i]!, del: false });
            after.push({ w: B[j]!, ins: false });
            i++;
            j++;
        } else if (L[i + 1]![j]! >= L[i]![j + 1]!) before.push({ w: A[i++]!, del: true });
        else after.push({ w: B[j++]!, ins: true });
    }
    while (i < n) before.push({ w: A[i++]!, del: true });
    while (j < m) after.push({ w: B[j++]!, ins: true });
    return { before, after };
}

// ── view a version (the show door) ──
const viewing = ref<{ version: number; title: string; blocks: Block[] } | null>(null);
async function view(v: Version): Promise<void> {
    selected.value = v.id;
    try {
        const res = await kbCall<{ version: number; title: string; content: { blocks?: Block[] } }>(
            '/admin/kb/versions/' + v.id,
            undefined,
            'GET',
        );
        viewing.value = {
            version: res.version,
            title: res.title,
            blocks: res.content.blocks ?? [],
        };
    } catch (e) {
        message.value = (e as Error).message;
    }
}

// ── restore (FR-08): a NEW draft, explained before it happens ──
const message = ref('');
const restoreFor = ref<Version | null>(null);
const restoreMissing = ref<string[]>([]);
async function openRestore(v: Version): Promise<void> {
    restoreFor.value = v;
    restoreMissing.value = [];
    try {
        const res = await kbCall<{ content: { blocks?: Block[] } }>(
            '/admin/kb/versions/' + v.id,
            undefined,
            'GET',
        );
        restoreMissing.value = (res.content.blocks ?? [])
            .filter((b) => b.type === 'image' && !b.media_key)
            .map((b) => b.alt ?? props.t.image_word ?? '');
    } catch {
        restoreMissing.value = [];
    }
}
async function restore(): Promise<void> {
    if (!restoreFor.value) return;
    try {
        await kbCall('/admin/kb/versions/' + restoreFor.value.id + '/restore', {});
        restoreFor.value = null;
        message.value = props.t.restored ?? '';
    } catch (e) {
        message.value = (e as Error).message;
    }
}

// ── archive / un-archive (FR-09) ──
const archiveOpen = ref(false);
async function archive(): Promise<void> {
    try {
        await kbCall('/admin/kb/variants/' + props.variant.id + '/archive', {});
        window.location.reload();
    } catch (e) {
        message.value = (e as Error).message;
        archiveOpen.value = false;
    }
}
async function unarchive(): Promise<void> {
    try {
        await kbCall('/admin/kb/variants/' + props.variant.id + '/unarchive', {});
        window.location.reload();
    } catch (e) {
        message.value = (e as Error).message;
    }
}
const siblingsCount = computed(() => 1); // this page is one variant's history; the archive door acts on this variant
const isMobile = ref(false);
onMounted(() => {
    const mq = window.matchMedia('(max-width: 639px)');
    isMobile.value = mq.matches;
    mq.addEventListener('change', (e) => (isMobile.value = e.matches));
    void loadDiff();
});
const totalCitations = computed(() => props.versions.reduce((n, v) => n + v.citations, 0));
const emptyCompareText = computed(
    () => (props.versions.length > 1 ? props.t.pick_two : props.t.only_one_version) ?? '',
);
</script>

<template>
    <BackofficeShell
        :t="t"
        active="kb"
        :title="variant.title"
        :crumb="[
            { label: t.nav_kb ?? '', href: '/admin/kb' },
            { label: article?.category_name ?? '' },
            { label: t.crumb_editing ?? '' },
        ]"
        :read-note="isMobile ? t.readnote_history : undefined"
    >
        <template #sub>
            <span class="bo-pill" :class="variant.status" data-testid="kb-history-status"
                >{{ statusWord(variant.status)
                }}<template v-if="currentVersion"> · v{{ currentVersion.version }}</template></span
            >
            <span class="bo-stamp"
                >{{ t.variant_word }} <span class="bo-mono">{{ variant.language }}</span
                ><template v-if="isSource"> · {{ t.source_language_word }}</template> ·
                {{ fill(t.n_versions, { n: versions.length })
                }}<template v-if="isArchived && latest">
                    · {{ fill(t.last_published_v, { v: latest.version }) }}</template
                ></span
            >
        </template>
        <template #actions>
            <a
                class="bo-btn"
                :href="'/admin/kb/variants/' + variant.id + '/edit'"
                data-testid="kb-open-editor"
                >{{ isArchived ? t.open_readonly : t.open_editor }}</a
            >
            <a class="bo-btn" href="/admin/kb">{{ t.back_to_article }}</a>
            <button
                v-if="!isArchived && !isMobile"
                type="button"
                class="bo-btn"
                data-testid="kb-archive-open"
                @click="archiveOpen = true"
            >
                {{ t.archive_ellipsis }}
            </button>
        </template>
        <template #banner>
            <p v-if="message" role="status" class="bo-banner" data-testid="kb-history-message">
                {{ message }}
            </p>
            <p v-if="isArchived" class="bo-banner" data-testid="kb-archived-banner">
                ▣
                {{
                    fill(t.archived_banner, {
                        time: latest ? hhmm(latest.published_at) : '',
                        slugs: '/' + variant.language + '/' + variant.title,
                        citations: totalCitations,
                    })
                }}
                <span class="acts">
                    <button
                        v-if="!isMobile"
                        type="button"
                        class="bo-btn sm"
                        data-testid="kb-unarchive"
                        @click="unarchive"
                    >
                        {{ t.unarchive_to_draft }}
                    </button>
                    <a
                        v-if="latest"
                        class="bo-btn sm"
                        :href="'/admin/kb/cite/' + variant.article_id + '/' + latest.id"
                        data-testid="kb-open-tombstone"
                        >{{ t.open_tombstone }}</a
                    >
                </span>
            </p>
        </template>

        <div class="kb-hist">
            <section class="bo-pane" :aria-label="t.versions_title" data-testid="kb-versions-pane">
                <div class="pt">
                    {{ t.versions_word }}
                    <span class="n">{{ fill(t.versions_meta, { n: versions.length }) }}</span>
                </div>
                <ol class="kb-vlist2" data-testid="kb-versions">
                    <li
                        v-for="v in versions"
                        :key="v.id"
                        class="kb-vrow2"
                        :class="{ on: selected === v.id }"
                        :data-testid="'kb-version-' + v.version"
                    >
                        <span class="vn">v{{ v.version }}</span>
                        <div>
                            <div>
                                {{ v.author_name ?? '—' }}
                                <span class="who">{{ hhmm(v.published_at) }}</span
                                ><span class="sr-only"> · {{ t.version }} {{ v.version }}</span>
                            </div>
                            <div v-if="v.note" class="vnote">{{ v.note }}</div>
                            <div class="bd">
                                <span v-if="v.id === variant.current_version_id" class="bo-chip cur"
                                    >{{ t.current_word }} · {{ statusWord(variant.status) }}</span
                                >
                                <span
                                    v-else-if="isArchived && v.id === latest?.id"
                                    class="bo-chip"
                                    >{{ t.last_published }}</span
                                >
                                <span v-if="v.override_kept" class="bo-chip warn"
                                    >⚠ {{ fill(t.override_kept_chip, { n: v.flag_count }) }}</span
                                >
                                <span v-if="v.citations" class="bo-chip">{{
                                    fill(t.cited_by_ai, { n: v.citations })
                                }}</span>
                                <span v-for="r in v.reviewers" :key="r" class="bo-chip">{{
                                    fill(t.reviewed_by, { name: r })
                                }}</span>
                                <span v-if="v.ai_translated" class="bo-chip ai"
                                    >✦ {{ t.ai_translated_chip }}</span
                                >
                                <span v-if="v.seeded" class="bo-chip seed">{{
                                    t.seeded_chip
                                }}</span>
                            </div>
                            <div class="acts">
                                <button
                                    type="button"
                                    class="bo-link"
                                    :data-testid="'kb-view-' + v.version"
                                    @click="view(v)"
                                >
                                    {{ t.view_word }}
                                </button>
                                <button
                                    v-if="versions.length > 1"
                                    type="button"
                                    class="bo-link"
                                    :data-testid="'kb-diff-' + v.version"
                                    @click="compareWithCurrent(v)"
                                >
                                    {{ t.compare_with_current }}
                                </button>
                                <button
                                    v-if="v.id !== variant.current_version_id && !isMobile"
                                    type="button"
                                    class="bo-link"
                                    :data-testid="'kb-restore-' + v.version"
                                    @click="openRestore(v)"
                                >
                                    {{ t.restore }}
                                </button>
                            </div>
                        </div>
                    </li>
                </ol>
                <p class="bo-hint kb-vnote-foot">{{ t.versions_law }}</p>
            </section>

            <section class="bo-pane" :aria-label="t.changes_word" data-testid="kb-diff-pane">
                <div class="kb-dhead">
                    <span>{{ t.compare_word }}</span>
                    <select
                        v-model="fromId"
                        class="bo-sel"
                        :aria-label="t.compare_from"
                        data-testid="kb-diff-from"
                        @change="loadDiff"
                    >
                        <option v-for="v in versions" :key="v.id" :value="v.id">
                            v{{ v.version }} · {{ hhmm(v.published_at) }}
                        </option>
                    </select>
                    <span aria-hidden="true">→</span>
                    <select
                        v-model="toId"
                        class="bo-sel on"
                        :aria-label="t.compare_to"
                        data-testid="kb-diff-to"
                        @change="loadDiff"
                    >
                        <option v-for="v in versions" :key="v.id" :value="v.id">
                            v{{ v.version }} · {{ hhmm(v.published_at)
                            }}<template v-if="v.id === variant.current_version_id">
                                ({{ t.current_lower }})</template
                            >
                        </option>
                    </select>
                    <span v-if="diff" class="sum" data-testid="kb-diff-summary">
                        <span class="d-cur"
                            >+ {{ fill(t.n_added, { n: diff.summary.added }) }}</span
                        >
                        <span class="d-cur"
                            >− {{ fill(t.n_removed, { n: diff.summary.removed }) }}</span
                        >
                        <span class="d-out"
                            >± {{ fill(t.n_changed, { n: diff.summary.changed }) }}</span
                        >
                        <span class="d-mis">{{
                            fill(t.n_unchanged, { n: diff.summary.unchanged })
                        }}</span>
                    </span>
                </div>
                <nav
                    v-if="diff && changedEntries.length"
                    class="kb-dnav"
                    :aria-label="t.changed_blocks"
                    data-testid="kb-diff-nav"
                >
                    <button type="button" class="bo-btn sm" @click="goChange(-1)">
                        ‹ {{ t.prev_change }}
                    </button>
                    <b
                        >{{
                            fill(t.change_n_of, { n: focusIndex + 1, total: changedEntries.length })
                        }}
                        ·
                        {{
                            typeWord(
                                changedEntries[focusIndex]?.e.new ??
                                    changedEntries[focusIndex]?.e.old ??
                                    null,
                            )
                        }}</b
                    >
                    <button type="button" class="bo-btn sm" @click="goChange(1)">
                        {{ t.next_change }} ›
                    </button>
                    <span class="bo-hint">{{ t.landmarks_note }}</span>
                </nav>
                <div v-if="loading" class="kb-dl" aria-busy="true">
                    <span class="bo-sk w80"></span><span class="bo-sk w60"></span>
                </div>
                <p v-else-if="diffFailed" class="bo-banner" role="alert">{{ t.diff_failed }}</p>
                <p v-else-if="!diff" class="bo-empty">
                    <b>{{ emptyCompareText }}</b>
                </p>
                <div v-else class="kb-dl" data-testid="kb-diff-view">
                    <section
                        v-for="(e, i) in diff.entries"
                        :id="'kb-diff-' + i"
                        :key="e.key"
                        class="kb-db"
                        :class="[
                            e.kind === 'added'
                                ? 'add'
                                : e.kind === 'removed'
                                  ? 'rem'
                                  : e.kind === 'changed'
                                    ? 'chg'
                                    : 'same',
                            { focus: isFocused(i) },
                        ]"
                        :aria-label="typeWord(e.new ?? e.old) + ' · ' + t['kind_' + e.kind]"
                        :data-testid="'kb-diff-entry-' + e.kind"
                    >
                        <div class="lab">
                            <span class="g" aria-hidden="true">{{
                                e.kind === 'added'
                                    ? '+'
                                    : e.kind === 'removed'
                                      ? '−'
                                      : e.kind === 'changed'
                                        ? '±'
                                        : '='
                            }}</span>
                            <span>{{ typeWord(e.new ?? e.old) }} · {{ t['kind_' + e.kind] }}</span>
                            <span class="n">
                                <template v-if="e.kind === 'unchanged'">{{
                                    blockText(e.old).slice(0, 60)
                                }}</template>
                                <template v-else
                                    >{{
                                        fill(t.change_n_of, {
                                            n: changeNumber(i),
                                            total: changedEntries.length,
                                        })
                                    }}<template v-if="e.kind === 'removed' && !expanded.has(e.key)">
                                        · {{ t.collapsed_word }}</template
                                    ></template
                                >
                            </span>
                        </div>
                        <div v-if="e.kind === 'changed'" class="pair">
                            <div>
                                <div class="h">v{{ diff.from }} · {{ t.before_word }}</div>
                                <span
                                    v-for="(w, wi) in wordDiff(blockText(e.old), blockText(e.new))
                                        .before"
                                    :key="wi"
                                    ><del v-if="w.del">{{ w.w }}</del
                                    ><template v-else>{{ w.w }}</template></span
                                >
                            </div>
                            <div>
                                <div class="h">v{{ diff.to }} · {{ t.after_word }}</div>
                                <span
                                    v-for="(w, wi) in wordDiff(blockText(e.old), blockText(e.new))
                                        .after"
                                    :key="wi"
                                    ><ins v-if="w.ins">{{ w.w }}</ins
                                    ><template v-else>{{ w.w }}</template></span
                                >
                            </div>
                        </div>
                        <div v-else-if="e.kind === 'added'" class="txt">
                            <div :class="{ 'kb-callout': e.new?.type === 'callout' }">
                                {{ blockText(e.new) }}
                            </div>
                        </div>
                        <div v-else-if="e.kind === 'removed'" class="stub">
                            <span
                                >“{{
                                    expanded.has(e.key)
                                        ? blockText(e.old)
                                        : blockText(e.old).slice(0, 80) +
                                          (blockText(e.old).length > 80 ? '…' : '')
                                }}”</span
                            >
                            <button
                                type="button"
                                class="bo-link"
                                :data-testid="'kb-stub-' + e.key"
                                @click="toggleStub(e.key)"
                            >
                                {{ expanded.has(e.key) ? t.collapse_word : t.expand_word }}
                            </button>
                        </div>
                    </section>
                    <p class="bo-hint">{{ t.diff_legend }}</p>
                </div>
            </section>
        </div>

        <!-- view a version -->
        <div v-if="viewing" class="bo-scrim" @click.self="viewing = null">
            <div
                class="bo-dlg"
                role="dialog"
                aria-modal="true"
                :aria-label="fill(t.view_version_title, { v: viewing.version })"
                data-testid="kb-version-view"
            >
                <h3 class="dt">
                    {{ fill(t.view_version_title, { v: viewing.version }) }} — {{ viewing.title }}
                </h3>
                <div class="db">
                    <div v-for="(b, i) in viewing.blocks" :key="i" class="kb-snapblock">
                        <div class="lab">{{ typeWord(b) }}</div>
                        <div>{{ blockText(b) }}</div>
                    </div>
                    <p class="bo-hint">{{ t.snapshot_immutable }}</p>
                </div>
                <div class="df">
                    <button type="button" class="bo-btn" @click="viewing = null">
                        {{ t.close }}
                    </button>
                </div>
            </div>
        </div>

        <!-- restore confirmation (C02) -->
        <div v-if="restoreFor" class="bo-scrim" @click.self="restoreFor = null">
            <div
                class="bo-dlg"
                role="dialog"
                aria-modal="true"
                :aria-label="fill(t.restore_title, { v: restoreFor.version })"
                data-testid="kb-restore-dialog"
            >
                <h3 class="dt">{{ fill(t.restore_title, { v: restoreFor.version }) }}</h3>
                <div class="db">
                    <dl class="bo-row2">
                        <dt>{{ t.from_word }}</dt>
                        <dd>
                            v{{ restoreFor.version }} · {{ hhmm(restoreFor.published_at) }} ·
                            {{ restoreFor.author_name ?? '—'
                            }}<template v-if="restoreFor.note"> — “{{ restoreFor.note }}”</template>
                        </dd>
                        <dt>{{ t.creates_word }}</dt>
                        <dd>
                            {{
                                fill(t.restore_creates, {
                                    code: variant.language,
                                    v: restoreFor.version,
                                })
                            }}
                        </dd>
                        <dt>{{ t.keeps_word }}</dt>
                        <dd>
                            {{
                                currentVersion
                                    ? fill(t.restore_keeps, { v: currentVersion.version })
                                    : t.restore_keeps_none
                            }}
                        </dd>
                        <dt>{{ t.history_word }}</dt>
                        <dd>
                            {{
                                fill(t.restore_history, {
                                    v: restoreFor.version,
                                    next: (latest?.version ?? 0) + 1,
                                })
                            }}
                        </dd>
                    </dl>
                    <p
                        v-if="restoreMissing.length"
                        class="bo-warnbox"
                        data-testid="kb-restore-missing-media"
                    >
                        {{ fill(t.restore_missing_media, { n: restoreMissing.length }) }}
                    </p>
                    <p class="bo-warnbox">{{ t.restore_replaces_copy }}</p>
                </div>
                <div class="df">
                    <button type="button" class="bo-btn" @click="restoreFor = null">
                        {{ t.cancel }}
                    </button>
                    <button
                        type="button"
                        class="bo-btn p"
                        data-testid="kb-restore-confirm"
                        @click="restore"
                    >
                        {{ fill(t.restore_verb, { v: restoreFor.version }) }}
                    </button>
                </div>
            </div>
        </div>

        <!-- archive (C04) — the consequence in nouns -->
        <div v-if="archiveOpen" class="bo-scrim" @click.self="archiveOpen = false">
            <div
                class="bo-dlg"
                role="dialog"
                aria-modal="true"
                :aria-label="t.archive_title"
                data-testid="kb-archive-dialog"
            >
                <h3 class="dt">{{ t.archive_title }}</h3>
                <div class="db">
                    <p>{{ fill(t.archive_intro, { title: variant.title }) }}</p>
                    <dl class="bo-row2">
                        <dt>{{ t.leaves_word }}</dt>
                        <dd>{{ t.archive_leaves }}</dd>
                        <dt>{{ t.keeps_word }}</dt>
                        <dd>{{ fill(t.archive_keeps, { n: versions.length }) }}</dd>
                        <dt>{{ t.undo_word }}</dt>
                        <dd>{{ t.archive_undo }}</dd>
                        <dt>{{ t.audited_word }}</dt>
                        <dd>{{ t.archive_audited }}</dd>
                    </dl>
                    <p class="bo-hint">{{ t.archive_telegram_note }}</p>
                </div>
                <div class="df">
                    <button type="button" class="bo-btn" @click="archiveOpen = false">
                        {{ t.cancel }}
                    </button>
                    <button
                        type="button"
                        class="bo-btn danger"
                        data-testid="kb-archive-confirm"
                        @click="archive"
                    >
                        {{ fill(t.archive_verb, { n: siblingsCount }) }}
                    </button>
                </div>
            </div>
        </div>

        <section
            v-if="isArchived"
            class="bo-card kb-unarchive-card"
            data-testid="kb-unarchive-card"
        >
            <div class="ct">{{ t.unarchive_word }}</div>
            <div class="cb">
                <p>
                    {{ fill(t.unarchive_body, { v: latest?.version ?? 0 }) }}
                    <span class="bo-pill draft">{{ statusWord('draft') }}</span>
                </p>
                <p>{{ t.unarchive_tombstones }}</p>
                <dl class="bo-kv2">
                    <dt>{{ t.absent_here }}</dt>
                    <dd>{{ t.delete_absent }}</dd>
                </dl>
            </div>
        </section>
    </BackofficeShell>
</template>

<style>
.kb-hist {
    display: grid;
    grid-template-columns: 380px minmax(0, 1fr);
    gap: 16px;
    padding: 12px 18px 24px;
    flex: 1;
    align-items: start;
}
.kb-vlist2 {
    list-style: none;
    margin: 0;
    padding: 0;
}
.kb-vrow2 {
    display: grid;
    grid-template-columns: 44px minmax(0, 1fr);
    gap: 10px;
    padding: 10px;
    border-block-end: 1px solid var(--ds-border-soft);
    min-height: 64px;
    align-items: start;
    font-size: 12.5px;
}
.kb-vrow2.on {
    background: var(--ds-inset-bg);
}
.kb-vrow2 .vn {
    font-family: ui-monospace, monospace;
    font-size: 12px;
    font-weight: 600;
    padding-top: 2px;
}
.kb-vrow2 .who {
    font-size: 11px;
    color: var(--ds-text-muted);
    margin-inline-start: 6px;
}
.kb-vrow2 .vnote {
    font-size: 12.5px;
    margin-top: 3px;
    line-height: 1.4;
}
.kb-vrow2 .bd {
    display: flex;
    gap: 4px;
    flex-wrap: wrap;
    margin-top: 6px;
}
.kb-vrow2 .acts {
    display: flex;
    gap: 8px;
    margin-top: 6px;
    flex-wrap: wrap;
}
.kb-vnote-foot {
    padding: 10px 12px;
    margin: 0;
}
.kb-dhead {
    display: flex;
    gap: 10px;
    align-items: center;
    padding: 10px 12px;
    border-block-end: 1px solid var(--ds-border-soft);
    flex-wrap: wrap;
    font-size: 12px;
}
.kb-dhead .sum {
    display: flex;
    gap: 10px;
    margin-inline-start: auto;
    font-variant-numeric: tabular-nums;
    flex-wrap: wrap;
}
.kb-dnav {
    display: flex;
    gap: 8px;
    align-items: center;
    padding: 8px 12px;
    border-block-end: 1px solid var(--ds-border-soft);
    font-size: 11.5px;
    color: var(--ds-text-secondary);
    flex-wrap: wrap;
}
.kb-dl {
    padding: 10px 12px;
    display: grid;
    gap: 8px;
}
.kb-db {
    border: 1px solid var(--ds-border);
    border-radius: 8px;
    overflow: hidden;
}
.kb-db .lab {
    display: flex;
    gap: 8px;
    align-items: center;
    padding: 6px 10px;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.07em;
    text-transform: uppercase;
    color: var(--ds-text-label);
    border-block-end: 1px solid var(--ds-border-soft);
    flex-wrap: wrap;
}
.kb-db .lab .g {
    font-family: ui-monospace, monospace;
    font-size: 12px;
    font-weight: 700;
    width: 16px;
    text-align: center;
    letter-spacing: 0;
}
.kb-db .lab .n {
    margin-inline-start: auto;
    text-transform: none;
    letter-spacing: 0;
    font-weight: 500;
    color: var(--ds-text-muted);
    overflow-wrap: anywhere;
}
.kb-db .txt {
    padding: 10px 12px;
    font-size: 13px;
    line-height: 1.6;
}
.kb-db.add {
    border-color: var(--ds-success-text);
    background: var(--ds-success-bg);
}
.kb-db.add .g,
.kb-db.add .lab {
    color: var(--ds-success-text);
    border-block-end-color: transparent;
}
.kb-db.rem {
    border-color: var(--ds-danger-border);
    background: var(--ds-danger-bg);
}
.kb-db.rem .g,
.kb-db.rem .lab {
    color: var(--ds-danger-text);
    border-block-end-color: transparent;
}
.kb-db.chg .g,
.kb-db.chg .lab {
    color: var(--ds-warn-text);
}
.kb-db.same {
    border-style: dashed;
    background: var(--ds-panel-soft);
}
.kb-db.same .lab {
    border-block-end: 0;
    color: var(--ds-text-muted);
}
.kb-db.focus {
    outline: 2px solid var(--ds-focus-ring);
    outline-offset: 2px;
}
.kb-db .pair {
    display: grid;
    grid-template-columns: 1fr 1fr;
}
.kb-db .pair > div {
    padding: 10px 12px;
    font-size: 13px;
    line-height: 1.6;
    overflow-wrap: anywhere;
}
.kb-db .pair > div + div {
    border-inline-start: 1px solid var(--ds-border);
}
.kb-db .pair .h {
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.07em;
    text-transform: uppercase;
    color: var(--ds-text-label);
    margin-bottom: 4px;
}
.kb-db del {
    background: var(--ds-danger-bg);
    color: var(--ds-danger-text);
    text-decoration: line-through;
}
.kb-db ins {
    background: var(--ds-success-bg);
    color: var(--ds-success-text);
    text-decoration: none;
    font-weight: 600;
}
.kb-db .stub {
    display: flex;
    gap: 10px;
    align-items: center;
    padding: 8px 12px;
    font-size: 12px;
    color: var(--ds-text-secondary);
    flex-wrap: wrap;
}
.kb-db .stub .bo-link {
    margin-inline-start: auto;
}
.kb-callout {
    border-inline-start: 3px solid var(--ds-warn-border);
    background: var(--ds-warn-bg);
    padding: 8px 10px;
    border-radius: 0 6px 6px 0;
}
.kb-snapblock {
    border: 1px dashed var(--ds-border-strong);
    border-radius: 8px;
    padding: 8px 10px;
    font-size: 12.5px;
}
.kb-snapblock .lab {
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.07em;
    text-transform: uppercase;
    color: var(--ds-text-label);
    margin-bottom: 4px;
}
.kb-unarchive-card {
    margin: 0 18px 24px;
}
@media (max-width: 639px) {
    .kb-hist {
        grid-template-columns: 1fr;
        padding: 10px 12px;
    }
    .kb-db .pair {
        grid-template-columns: 1fr;
    }
    .kb-db .pair > div + div {
        border-inline-start: 0;
        border-block-start: 1px solid var(--ds-border);
    }
    .kb-vrow2 {
        min-height: 44px;
    }
}
</style>
