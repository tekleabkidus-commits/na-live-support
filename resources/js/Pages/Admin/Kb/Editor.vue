<script setup lang="ts">
// Doc 10 §8 — the article editor. SESSION-F1 [B9] rebuilt it to the frozen B9-S02: the
// Backoffice chrome with the breadcrumb; the FR-02 block set behind a real toolbar
// (heading · paragraph · list · table · image · callout · anchor + FR-06's "Also asked");
// language tabs with the ●◐○ dots; the autosave stamp (every 5 s, a working copy, not a
// version); the concurrent-edit lock banner (held / taken over); sanitizer flags INLINE on
// the sentence with the keep/edit popover; the review rail (checklist, permission-shaped
// verbs, block-anchored comments); the publish dialog (audience · AI-usability · languages ·
// sanitizer summary · alt gate · the variants that flip to Outdated) and the door's own
// refusals in place; the publish pipeline strip; the stats header + feedback list; the
// INTERNAL banner repeated above the fold; the AI drawer (drafts only — no publish path);
// 375 READ-ONLY with the notice. Every mutation rides an existing door. Tokens only.
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import BackofficeShell from '@/Components/Backoffice/BackofficeShell.vue';
import { KbCallError, kbCall } from './kbApi';

type Block = {
    id: string;
    type: string;
    text?: string;
    alt?: string;
    media_key?: string | null;
    url?: string;
    items?: string[];
    rows?: string[][];
    ordered?: boolean;
    anchor?: string;
    ai?: boolean;
    upload_failed?: string;
    blocked?: boolean;
    placeholder?: boolean;
};
type Flag = { block: number; sentence: string; pattern: string };
type Comment = {
    id: string;
    body: string;
    author_id: string;
    author_name: string | null;
    block_anchor: string | null;
    resolved_at: string | null;
    created_at: string;
};
type Sibling = { id: string; language: string; status: string; translation_state: string };
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
        slug: string;
        working_copy: { blocks?: Block[] };
        index_status: string;
        working_copy_saved_at: string | null;
        current_version_id: string | null;
    };
    article: {
        id: string;
        audience: string;
        category_id: string;
        source_language: string;
        category_name: string | null;
        category_ai_usable: boolean;
    };
    siblings: Sibling[];
    flags: Flag[];
    comments: Comment[];
    current_version: {
        version: number;
        note: string | null;
        published_at: string;
        author_name: string | null;
        override_kept: boolean;
    } | null;
    languages: string[];
    lock: { holder_id: string | null; holder_name: string | null; mine: boolean };
    can: { publish: boolean; stats: boolean };
}>();

const BLOCK_TYPES = [
    'heading',
    'paragraph',
    'list',
    'table',
    'image',
    'callout',
    'anchor',
] as const;
function fill(template: string | undefined, vars: Record<string, string | number>): string {
    let out = template ?? '';
    for (const [k, v] of Object.entries(vars)) out = out.split(':' + k).join(String(v));
    return out;
}
const langName = (code: string): string => props.t['lang_' + code] ?? code;
const statusWord = (s: string): string => props.t['status_' + s] ?? s;
const stateWord = (s: string): string => props.t['ts_' + s] ?? s;
const typeWord = (type: string, b?: Block): string =>
    (type === 'list' && b?.ordered ? props.t.block_numbered_list : props.t['block_' + type]) ??
    type;
const hhmmss = (iso: string | null): string => (iso ? iso.replace('T', ' ').slice(11, 19) : '');
const hhmm = (iso: string | null): string => (iso ? iso.replace('T', ' ').slice(11, 16) : '');
const isSource = computed(() => props.variant.language === props.article.source_language);

// ── document state ──
const blocks = ref<Block[]>((props.variant.working_copy.blocks ?? []).map((b) => ({ ...b })));
const title = ref(props.variant.title);
const summary = ref(props.variant.summary ?? '');
const slug = ref(props.variant.slug);
const message = ref('');
const savedAt = ref<string | null>(props.variant.working_copy_saved_at);
const dirtyCount = ref(0);
const saving = ref(false);
const focused = ref<string | null>(null);
const readOnly = ref(false);
const isMobile = ref(false);
const editable = computed(() => !readOnly.value && !isMobile.value);

// FR-05: autosave every 5 s while dirty — a working copy, never a version (the stamp says so).
let saveTimer: ReturnType<typeof setInterval> | null = null;
function markDirty(): void {
    dirtyCount.value += 1;
}
watch(blocks, markDirty, { deep: true });
watch([title, summary, slug], markDirty);
async function save(): Promise<void> {
    if (!editable.value || saving.value) return;
    const pending = dirtyCount.value;
    saving.value = true;
    try {
        const res = await kbCall<{ saved_at: string }>(
            '/admin/kb/variants/' + props.variant.id + '/working-copy',
            {
                content: { blocks: blocks.value },
                ...(savedAt.value ? { expected_saved_at: savedAt.value } : {}),
            },
            'PUT',
        );
        savedAt.value = res.saved_at;
        await kbCall(
            '/admin/kb/variants/' + props.variant.id + '/meta',
            { title: title.value, summary: summary.value, slug: slug.value },
            'PATCH',
        );
        dirtyCount.value = Math.max(0, dirtyCount.value - pending);
        message.value = '';
    } catch (e) {
        message.value = (e as Error).message;
    } finally {
        saving.value = false;
    }
}
const differsFromVersion = computed(
    () =>
        !!props.current_version &&
        !!savedAt.value &&
        savedAt.value > props.current_version.published_at,
);

// ── the concurrent-edit lock (FR-05, T-07): acquire → heartbeat → release; a loser reads ──
const lockHolder = ref<{ id: string | null; name: string | null }>({
    id: props.lock.holder_id,
    name: props.lock.holder_name,
});
const takenOverAt = ref<string | null>(null);
let heartbeat: ReturnType<typeof setInterval> | null = null;
async function acquireLock(): Promise<void> {
    try {
        const res = await kbCall<{ mode: string; holder_id: string | null; acquired: boolean }>(
            '/admin/kb/variants/' + props.variant.id + '/lock',
            {},
        );
        if (res.acquired || res.mode === 'optimistic') {
            readOnly.value = false;
            lockHolder.value = { id: null, name: null };
            heartbeat = setInterval(async () => {
                try {
                    await kbCall('/admin/kb/variants/' + props.variant.id + '/lock/heartbeat', {});
                } catch {
                    takenOverAt.value = new Date().toISOString();
                    readOnly.value = true;
                    if (heartbeat) clearInterval(heartbeat);
                }
            }, 30_000);
        }
    } catch (e) {
        if (e instanceof KbCallError && (e.problem as { holder_id?: string }).holder_id) {
            readOnly.value = true;
        }
    }
}
async function takeOver(): Promise<void> {
    try {
        await kbCall('/admin/kb/variants/' + props.variant.id + '/lock/takeover', {});
        window.location.reload();
    } catch (e) {
        message.value = (e as Error).message;
    }
}
onMounted(() => {
    const mq = window.matchMedia('(max-width: 639px)');
    isMobile.value = mq.matches;
    mq.addEventListener('change', (e) => (isMobile.value = e.matches));
    if (props.lock.holder_id && !props.lock.mine) readOnly.value = true;
    else if (!isMobile.value) void acquireLock();
    saveTimer = setInterval(() => {
        if (dirtyCount.value > 0) void save();
    }, 5_000);
    if (props.can.stats) void loadStats();
    void checkAi();
    window.addEventListener('beforeunload', releaseLock);
});
onBeforeUnmount(() => {
    if (saveTimer) clearInterval(saveTimer);
    if (heartbeat) clearInterval(heartbeat);
    releaseLock();
});
function reload(): void {
    window.location.reload();
}
function releaseLock(): void {
    if (readOnly.value || isMobile.value) return;
    void fetch('/admin/kb/variants/' + props.variant.id + '/lock', {
        method: 'DELETE',
        headers: {
            'X-XSRF-TOKEN': decodeURIComponent(
                document.cookie.match(/XSRF-TOKEN=([^;]*)/)?.[1] ?? '',
            ),
        },
        keepalive: true,
    });
}

// ── blocks: the FR-02 set, nothing beyond it (R-04) ──
let seq = 0;
const newId = (): string => 'b' + Date.now().toString(36) + (seq++).toString(36);
function blank(type: string): Block {
    const b: Block = { id: newId(), type };
    if (type === 'list') b.items = [''];
    if (type === 'table')
        b.rows = [
            ['', ''],
            ['', ''],
        ];
    if (type === 'image') b.alt = '';
    if (type === 'anchor') b.anchor = '';
    if (type === 'heading' || type === 'paragraph' || type === 'callout') b.text = '';
    return b;
}
function addBlock(type: string, after: number | null = null): void {
    const b = blank(type);
    const at = after === null ? blocks.value.length : after + 1;
    blocks.value.splice(at, 0, b);
    focused.value = b.id;
}
function moveBlock(i: number, dir: -1 | 1): void {
    const j = i + dir;
    if (j < 0 || j >= blocks.value.length) return;
    const arr = blocks.value;
    [arr[i], arr[j]] = [arr[j]!, arr[i]!];
    announce(
        fill(props.t.block_moved, {
            type: typeWord(arr[j]!.type, arr[j]),
            n: j + 1,
            total: arr.length,
        }),
    );
}
function removeBlock(i: number): void {
    blocks.value.splice(i, 1);
    menuFor.value = null;
}
function duplicateBlock(i: number): void {
    const b = blocks.value[i]!;
    blocks.value.splice(i + 1, 0, { ...JSON.parse(JSON.stringify(b)), id: newId() });
    menuFor.value = null;
}
function clearAi(b: Block): void {
    if (b.ai) b.ai = false; // FR-06: an edited AI block sheds its accent
}
const menuFor = ref<string | null>(null);
const insertOpen = ref(false);
const listText = (b: Block): string => (b.items ?? []).join('\n');
function setList(b: Block, v: string): void {
    b.items = v.split('\n');
    clearAi(b);
}
const tableText = (b: Block): string => (b.rows ?? []).map((r) => r.join(' | ')).join('\n');
function setTable(b: Block, v: string): void {
    b.rows = v.split('\n').map((line) => line.split('|').map((c) => c.trim()));
    clearAi(b);
}
function onBlockKey(e: KeyboardEvent, i: number): void {
    if (!e.altKey) return;
    if (e.key === 'ArrowUp') {
        e.preventDefault();
        moveBlock(i, -1);
    } else if (e.key === 'ArrowDown') {
        e.preventDefault();
        moveBlock(i, 1);
    }
}
const announcement = ref('');
function announce(text: string): void {
    announcement.value = '';
    void Promise.resolve().then(() => (announcement.value = text));
}
// inline marks: Markdown markers around the selection of the focused textarea (the renderer's convention)
function wrapSelection(before: string, after: string): void {
    const el = document.activeElement;
    if (!(el instanceof HTMLTextAreaElement)) return;
    const id = el.dataset.block;
    const b = blocks.value.find((x) => x.id === id);
    if (!b || typeof b.text !== 'string') return;
    const s = el.selectionStart;
    const e = el.selectionEnd;
    b.text = b.text.slice(0, s) + before + b.text.slice(s, e) + after + b.text.slice(e);
}

// ── media (Doc 04 pattern): upload through the door; failure keeps the block, nothing else lost ──
async function upload(b: Block, ev: Event): Promise<void> {
    const input = ev.target as HTMLInputElement;
    const file = input.files?.[0];
    if (!file) return;
    const form = new FormData();
    form.append('image', file);
    b.upload_failed = undefined;
    b.blocked = false;
    try {
        const res = await fetch('/admin/kb/variants/' + props.variant.id + '/media', {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'X-XSRF-TOKEN': decodeURIComponent(
                    document.cookie.match(/XSRF-TOKEN=([^;]*)/)?.[1] ?? '',
                ),
            },
            body: form,
        });
        const json = (await response(res)) as {
            media_key?: string;
            url?: string;
            file?: string;
            detail?: string;
        };
        if (!res.ok) {
            if (/virus|malware|scan/i.test(json.detail ?? '')) b.blocked = true;
            else
                b.upload_failed = fill(props.t.upload_failed, {
                    file: file.name,
                    size: (file.size / 1_048_576).toFixed(1),
                });
            return;
        }
        b.media_key = json.media_key ?? null;
        b.url = json.url;
        b.placeholder = false;
    } catch {
        b.upload_failed = fill(props.t.upload_failed, {
            file: file.name,
            size: (file.size / 1_048_576).toFixed(1),
        });
    } finally {
        input.value = '';
    }
}
const response = async (res: Response): Promise<unknown> => res.json().catch(() => ({}));
const missingAlt = computed(() =>
    blocks.value.filter((b) => b.type === 'image' && !(b.alt ?? '').trim()),
);

// ── sanitizer (FR-13, BR-06): the door's flags, inline on the sentence ──
const openFlag = ref<number | null>(null);
const overrideReason = ref('');
const flagsFor = (i: number): Flag[] => props.flags.filter((f) => f.block === i);
const keptOverride = computed(() => props.current_version?.override_kept ?? false);
function jumpToNextFlag(): void {
    const first = props.flags[0];
    if (!first) return;
    openFlag.value = 0;
    document.getElementById('kb-blk-' + first.block)?.scrollIntoView({ block: 'center' });
}

// ── workflow (FR-07) ──
async function transition(door: string): Promise<void> {
    try {
        await kbCall('/admin/kb/variants/' + props.variant.id + '/' + door, {});
        window.location.reload();
    } catch (e) {
        message.value = (e as Error).message;
    }
}
const publishOpen = ref(false);
const publishNote = ref('');
const refusal = ref<{ missing_alt?: string[]; missing_media?: string[] }>({});
const blocked = computed(
    () => (refusal.value.missing_alt?.length ?? 0) + (refusal.value.missing_media?.length ?? 0) > 0,
);
const publishedSiblings = computed(() =>
    props.siblings.filter((s) => s.id !== props.variant.id && s.status === 'published'),
);
const blockIndexById = (id: string): number => blocks.value.findIndex((b) => b.id === id);
async function publish(): Promise<void> {
    refusal.value = {};
    try {
        await save();
        await kbCall('/admin/kb/variants/' + props.variant.id + '/publish', {
            ...(publishNote.value ? { note: publishNote.value } : {}),
            ...(overrideReason.value
                ? { sanitizer_override: { reason: overrideReason.value } }
                : {}),
        });
        window.location.reload();
    } catch (e) {
        if (e instanceof KbCallError)
            refusal.value = {
                missing_alt: e.problem.missing_alt,
                missing_media: e.problem.missing_media,
            };
        message.value = blocked.value ? '' : (e as Error).message;
    }
}
const moreOpen = ref(false);
const audienceOpen = ref(false);
async function changeAudience(): Promise<void> {
    try {
        await kbCall('/admin/kb/articles/' + props.article.id + '/visibility', {
            audience: props.article.audience === 'internal' ? 'public' : 'internal',
        });
        window.location.reload();
    } catch (e) {
        message.value = (e as Error).message;
        audienceOpen.value = false;
    }
}
const missingLanguages = computed(() =>
    props.languages.filter((l) => !props.siblings.some((s) => s.language === l)),
);
async function addLanguage(language: string): Promise<void> {
    try {
        const res = await kbCall<{ variant_id?: string; id?: string }>(
            '/admin/kb/articles/' + props.article.id + '/variants',
            { language, title: title.value },
        );
        window.location.href = '/admin/kb/variants/' + (res.variant_id ?? res.id ?? '') + '/edit';
    } catch (e) {
        message.value = (e as Error).message;
    }
}

// ── review: the checklist (tenant-configured rows — fixture content, Doc 16 owns the config), comments anchored to blocks ──
const checklist = ref([false, false, false]);
const commentBody = ref('');
const openComments = computed(() => props.comments.filter((c) => !c.resolved_at));
const commentsFor = (id: string): Comment[] =>
    props.comments.filter((c) => c.block_anchor === id && !c.resolved_at);
async function sendComment(): Promise<void> {
    try {
        await kbCall('/admin/kb/variants/' + props.variant.id + '/comments', {
            body: commentBody.value,
            ...(focused.value ? { block_anchor: focused.value } : {}),
        });
        window.location.reload();
    } catch (e) {
        message.value = (e as Error).message;
    }
}
async function resolveComment(id: string): Promise<void> {
    try {
        await kbCall('/admin/kb/comments/' + id + '/resolve', {});
        window.location.reload();
    } catch (e) {
        message.value = (e as Error).message;
    }
}
function jumpTo(anchor: string | null): void {
    if (!anchor) return;
    focused.value = anchor;
    document
        .getElementById('kb-blk-' + blockIndexById(anchor))
        ?.scrollIntoView({ block: 'center' });
}
const anchorLabel = (anchor: string | null): string => {
    if (!anchor) return '';
    const i = blockIndexById(anchor);
    return i < 0
        ? anchor
        : fill(props.t.block_n, { n: i + 1 }) +
              ' · ' +
              typeWord(blocks.value[i]!.type, blocks.value[i]);
};

// ── stats header + feedback list (FR-11/FR-12; kb.feedback.view) — an extra: its failure never blocks editing ──
type Stats = {
    views: Record<string, number>;
    citations: number;
    inserts: number;
    feedback: { up: number; down: number; helpful_pct: number | null; comments: number };
    deflection_candidates: number;
};
const stats = ref<Stats | null>(null);
const statsFailed = ref(false);
const viewsTotal = (s: Stats): number => Object.values(s.views ?? {}).reduce((a, b) => a + b, 0);
type Fb = {
    id: string;
    source: string;
    score: number | null;
    comment: string | null;
    staff_id: string | null;
    actioned_at: string | null;
    created_at: string;
    variant_id: string;
};
const feedback = ref<Fb[]>([]);
const feedbackCursor = ref<string | null>(null);
const fbFilter = ref<'all' | 'down' | 'open'>('all');
async function loadStats(): Promise<void> {
    try {
        stats.value = await kbCall<Stats>(
            '/admin/kb/articles/' + props.variant.article_id + '/stats',
            undefined,
            'GET',
        );
        const list = await kbCall<{ data: Fb[]; next_cursor: string | null }>(
            '/admin/kb/articles/' + props.variant.article_id + '/feedback?limit=20',
            undefined,
            'GET',
        );
        feedback.value = list.data;
        feedbackCursor.value = list.next_cursor;
    } catch {
        statsFailed.value = true;
    }
}
async function moreFeedback(): Promise<void> {
    if (!feedbackCursor.value) return;
    const list = await kbCall<{ data: Fb[]; next_cursor: string | null }>(
        '/admin/kb/articles/' +
            props.variant.article_id +
            '/feedback?limit=20&cursor=' +
            feedbackCursor.value,
        undefined,
        'GET',
    );
    feedback.value.push(...list.data);
    feedbackCursor.value = list.next_cursor;
}
const feedbackListed = computed(() =>
    feedback.value.filter((f) =>
        fbFilter.value === 'down'
            ? f.score === -1
            : fbFilter.value === 'open'
              ? !f.actioned_at
              : true,
    ),
);
async function markActioned(f: Fb): Promise<void> {
    try {
        await kbCall('/admin/kb/feedback/' + f.id + '/action', {});
        f.actioned_at = new Date().toISOString();
    } catch (e) {
        message.value = (e as Error).message;
    }
}
const scoreGlyph = (f: Fb): string =>
    f.staff_id ? '📝' : f.score === 1 ? '👍' : f.score === -1 ? '👎' : '·';

// ── AI drawer (FR-06, BR-02): tools act on THIS variant; results are ai:true blocks the job writes (polled through the recover door); no publish path exists here ──
const drawerOpen = ref(false);
const aiOff = ref(false);
const aiRunning = ref<string | null>(null);
let poll: ReturnType<typeof setInterval> | null = null;
async function checkAi(): Promise<void> {
    try {
        const res = await kbCall<{ off?: { surface: string }[] }>(
            '/admin/ai/banner',
            undefined,
            'GET',
        );
        aiOff.value = (res.off ?? []).some((o) => o.surface === 'copilot');
    } catch {
        aiOff.value = false;
    }
}
async function aiTool(kind: 'simplify' | 'faq' | 'translate_all'): Promise<void> {
    if (aiOff.value) return;
    await save();
    const url =
        kind === 'translate_all'
            ? '/admin/kb/articles/' + props.variant.article_id + '/translate-all'
            : '/admin/kb/variants/' + props.variant.id + '/ai/' + kind;
    try {
        await kbCall(url, {});
        aiRunning.value = kind;
        if (kind === 'translate_all') return;
        const before = blocks.value.length;
        poll = setInterval(async () => {
            const rec = await kbCall<{ content: { blocks?: Block[] }; saved_at: string | null }>(
                '/admin/kb/variants/' + props.variant.id + '/working-copy',
                undefined,
                'GET',
            );
            const next = rec.content.blocks ?? [];
            if (next.length !== before || next.some((b) => b.ai)) {
                blocks.value = next.map((b) => ({ ...b }));
                savedAt.value = rec.saved_at;
                dirtyCount.value = 0;
                aiRunning.value = null;
                if (poll) clearInterval(poll);
            }
        }, 3_000);
    } catch (e) {
        message.value = (e as Error).message;
        aiRunning.value = null;
    }
}
const aiBlocks = computed(() => blocks.value.filter((b) => b.ai));
function discardAi(b: Block): void {
    const i = blocks.value.findIndex((x) => x.id === b.id);
    if (i >= 0) blocks.value.splice(i, 1);
}
const sourceText = ref('');
async function draftFromSource(): Promise<void> {
    try {
        const res = await kbCall<{ variant_id?: string }>('/admin/kb/drafts/from-source', {
            category_id: props.article.category_id,
            audience: props.article.audience,
            language: props.variant.language,
            title: fill(props.t.draft_from_source_title, { title: title.value }),
            text: sourceText.value,
        });
        if (res.variant_id) window.location.href = '/admin/kb/variants/' + res.variant_id + '/edit';
    } catch (e) {
        message.value = (e as Error).message;
    }
}

// ── transliteration helper for Amharic titles (§14): fidel → Latin, offered, never forced ──
const FIDEL_CONS = [
    'h',
    'l',
    'h',
    'm',
    's',
    'r',
    's',
    'sh',
    'q',
    'q',
    'q',
    'q',
    'b',
    'v',
    't',
    'ch',
    'h',
    'n',
    'ny',
    'a',
    'k',
    'k',
    'k',
    'k',
    'w',
    'a',
    'z',
    'zh',
    'y',
    'd',
    'j',
    'g',
    'g',
    'g',
    'g',
    't',
    'ch',
    'p',
    'ts',
    'ts',
    'f',
    'p',
];
const FIDEL_VOW = ['e', 'u', 'i', 'a', 'ie', '', 'o', 'wa'];
function transliterate(text: string): string {
    let out = '';
    for (const ch of text) {
        const cp = ch.codePointAt(0) ?? 0;
        if (cp >= 0x1200 && cp <= 0x135a) {
            const row = Math.floor((cp - 0x1200) / 8);
            const col = (cp - 0x1200) % 8;
            out += (FIDEL_CONS[row] ?? '') + (FIDEL_VOW[col] ?? '');
        } else if (/[a-z0-9]/i.test(ch)) out += ch.toLowerCase();
        else if (/[\s\-—–_·:/]/.test(ch) || cp === 0x1361 || cp === 0x1362) out += '-';
    }
    return out.replace(/-+/g, '-').replace(/^-|-$/g, '').slice(0, 60);
}
const suggestedSlug = computed(() => (/[ሀ-ፚ]/.test(title.value) ? transliterate(title.value) : ''));

// ── pipeline strip (B10): saved → sanitized → indexing from index_status ──
const pipeline = computed(() => {
    if (props.variant.status !== 'published' || !props.current_version) return null;
    const st = props.variant.index_status;
    return {
        version: props.current_version.version,
        since: hhmmss(props.current_version.published_at),
        kept: props.current_version.override_kept,
        index: st,
    };
});

// long article: the INTERNAL banner repeats above the fold (§13 note region)
const longArticle = computed(() => blocks.value.length > 6);
const commentCount = (id: string): number => commentsFor(id).length;
const publishedSiblingCodes = computed(() =>
    publishedSiblings.value.map((s) => s.language).join(', '),
);
</script>

<template>
    <BackofficeShell
        :t="t"
        active="kb"
        :title="title"
        :crumb="[
            { label: t.nav_kb ?? '', href: '/admin/kb' },
            { label: article.category_name ?? '' },
            { label: t.crumb_editing ?? '' },
        ]"
        :read-note="isMobile ? t.readnote_editor : undefined"
    >
        <template #sub>
            <span class="bo-pill" :class="variant.status" data-testid="kb-status-pill"
                >{{ statusWord(variant.status)
                }}<template v-if="current_version">
                    · v{{ current_version.version }}</template
                ></span
            >
            <span v-if="article.audience === 'internal'" class="bo-tag">{{
                t.audience_internal
            }}</span>
            <span v-if="flags.length" class="bo-chip warn" data-testid="kb-flags-chip"
                >⚠ {{ fill(t.flags_chip, { n: flags.length, kept: keptOverride ? 1 : 0 }) }}</span
            >
            <span v-else-if="differsFromVersion && current_version" class="bo-chip">{{
                fill(t.differs_chip, { v: current_version.version })
            }}</span>
            <span
                v-if="openComments.length && variant.status === 'in_review'"
                class="bo-chip warn"
                >{{ fill(t.changes_chip, { n: openComments.length }) }}</span
            >
            <span class="bo-stamp" data-testid="kb-save-stamp">
                <template v-if="readOnly"
                    >⊘
                    {{
                        fill(t.stamp_locked, { name: lockHolder.name ?? t.someone ?? '' })
                    }}</template
                >
                <template v-else-if="saving || dirtyCount"
                    >◌ {{ fill(t.stamp_saving, { n: dirtyCount }) }}</template
                >
                <template v-else-if="savedAt"
                    >✓ {{ fill(t.stamp_saved, { time: hhmmss(savedAt) }) }}</template
                >
                <template v-else>{{ t.stamp_never }}</template>
            </span>
        </template>
        <template #actions>
            <a
                class="bo-btn"
                :href="'/admin/kb/variants/' + variant.id + '/history-page'"
                data-testid="kb-history-link"
                >{{ t.history }}</a
            >
            <button
                v-if="can.publish && editable && variant.status !== 'archived'"
                type="button"
                class="bo-btn p"
                data-testid="kb-publish-open"
                @click="publishOpen = true"
            >
                {{ variant.status === 'in_review' ? t.approve_publish : t.publish_ellipsis }}
            </button>
            <span v-if="can.publish && editable" class="kb-more">
                <button
                    type="button"
                    class="bo-btn"
                    :aria-label="t.more"
                    :aria-expanded="moreOpen"
                    data-testid="kb-more"
                    @click="moreOpen = !moreOpen"
                >
                    ⋯
                </button>
                <span v-if="moreOpen" class="kb-menu" role="menu">
                    <button
                        v-if="variant.status === 'published'"
                        type="button"
                        role="menuitem"
                        data-testid="kb-unpublish"
                        @click="transition('unpublish')"
                    >
                        {{ t.unpublish }}
                    </button>
                    <button
                        v-if="variant.status !== 'archived'"
                        type="button"
                        role="menuitem"
                        data-testid="kb-archive"
                        @click="transition('archive')"
                    >
                        {{ t.archive }}
                    </button>
                    <button
                        v-else
                        type="button"
                        role="menuitem"
                        data-testid="kb-unarchive"
                        @click="transition('unarchive')"
                    >
                        {{ t.unarchive }}
                    </button>
                    <button
                        type="button"
                        role="menuitem"
                        data-testid="kb-audience-open"
                        @click="
                            audienceOpen = true;
                            moreOpen = false;
                        "
                    >
                        {{ t.change_audience }}
                    </button>
                </span>
            </span>
        </template>
        <template #banner>
            <p
                v-if="article.audience === 'internal'"
                class="bo-banner int"
                role="note"
                :aria-label="t.internal_article"
                data-testid="kb-internal-banner"
            >
                🔒 <b>{{ t.audience_internal }}</b> — {{ t.internal_banner_long }}
            </p>
            <p
                v-if="readOnly && !takenOverAt && !isMobile"
                class="bo-banner"
                data-testid="kb-lock-banner"
            >
                🔒
                {{
                    fill(t.lock_held_long, {
                        name: lockHolder.name ?? t.someone ?? '',
                        lang: langName(variant.language),
                    })
                }}
                <span class="acts"
                    ><button
                        type="button"
                        class="bo-btn sm"
                        data-testid="kb-lock-takeover"
                        @click="takeOver"
                    >
                        {{ t.lock_takeover }}
                    </button></span
                >
            </p>
            <p v-if="takenOverAt" class="bo-banner danger" role="alert" data-testid="kb-taken-over">
                🔒 {{ fill(t.taken_over, { time: hhmm(takenOverAt), saved: hhmmss(savedAt) }) }}
                <span class="acts"
                    ><button type="button" class="bo-btn sm" @click="reload">
                        {{ t.reopen_readonly }}
                    </button></span
                >
            </p>
            <p v-if="flags.length && editable" class="bo-banner" data-testid="kb-sanitizer-banner">
                ⚠ {{ fill(t.prepublish_check, { n: flags.length }) }}
                <button type="button" class="bo-link" @click="jumpToNextFlag">
                    {{ t.next_flag }}
                </button>
            </p>
            <p v-if="message" role="status" data-testid="kb-editor-message" class="bo-banner">
                {{ message }}
            </p>
            <div v-if="pipeline" class="kb-pipe" role="status" data-testid="kb-pipeline">
                <span class="st ok">✓ {{ fill(t.pipe_saved, { v: pipeline.version }) }}</span>
                <span class="st ok"
                    >✓ {{ t.pipe_sanitized
                    }}<template v-if="pipeline.kept"> · {{ t.pipe_kept }}</template></span
                >
                <span v-if="pipeline.index === 'current'" class="st ok"
                    >✓ {{ t.pipe_indexed }}</span
                >
                <span v-else-if="pipeline.index === 'failed'" class="st bad"
                    >! {{ t.pipe_index_pending }}</span
                >
                <span v-else class="st run">◌ {{ t.pipe_indexing }}</span>
                <span class="msg">
                    <span class="bo-chip ok">{{
                        fill(t.pipe_live, { time: pipeline.since })
                    }}</span>
                    <template v-if="pipeline.index === 'failed'"
                        >{{ t.pipe_failed_body }}
                        <button
                            v-if="can.publish"
                            type="button"
                            class="bo-btn sm"
                            data-testid="kb-retry-index"
                            @click="publish"
                        >
                            {{ t.retry_indexing }}
                        </button></template
                    >
                    <template v-else-if="pipeline.index !== 'current'">{{
                        t.pipe_serves_previous
                    }}</template>
                </span>
            </div>
        </template>

        <!-- stats header (FR-11/12) — kb.feedback.view; skeleton first, empty variant when nothing counted -->
        <div v-if="can.stats" class="kb-stats" data-testid="kb-stats-header">
            <template v-if="stats">
                <div class="kb-stat">
                    <div class="k">{{ t.stats_views }} · 30 d</div>
                    <div class="v">
                        {{ viewsTotal(stats) ? viewsTotal(stats).toLocaleString() : '—' }}
                    </div>
                    <div class="s">
                        {{
                            viewsTotal(stats)
                                ? fill(t.views_by_surface, {
                                      widget: stats.views.widget ?? 0,
                                      telegram: stats.views.telegram ?? 0,
                                      copilot: stats.views.copilot ?? 0,
                                  })
                                : t.views_none
                        }}
                    </div>
                </div>
                <div class="kb-stat">
                    <div class="k">{{ t.stats_citations }}</div>
                    <div class="v">{{ stats.citations || '—' }}</div>
                    <div class="s">{{ stats.citations ? t.citations_sub : t.citations_none }}</div>
                </div>
                <div class="kb-stat">
                    <div class="k">{{ t.stats_helpful }}</div>
                    <div class="v">
                        {{
                            stats.feedback.helpful_pct === null
                                ? '—'
                                : stats.feedback.helpful_pct + '%'
                        }}
                    </div>
                    <div class="s">
                        {{
                            stats.feedback.helpful_pct === null
                                ? t.feedback_none
                                : fill(t.helpful_sub, {
                                      up: stats.feedback.up,
                                      down: stats.feedback.down,
                                      comments: stats.feedback.comments,
                                  })
                        }}
                    </div>
                </div>
                <div class="kb-stat">
                    <div class="k">{{ t.stats_deflection }}</div>
                    <div class="v">{{ stats.deflection_candidates || '—' }}</div>
                    <div class="s">{{ t.deflection_sub }}</div>
                </div>
            </template>
            <template v-else-if="!statsFailed">
                <div v-for="i in 4" :key="i" class="kb-stat" aria-hidden="true">
                    <span class="bo-sk w40"></span
                    ><span class="bo-sk w60" style="margin-top: 8px"></span>
                </div>
            </template>
        </div>

        <div class="kb-ed" :class="{ drawer: drawerOpen }">
            <div class="kb-doc">
                <div class="kb-tabs2" role="tablist" :aria-label="t.language_variants">
                    <a
                        v-for="s in siblings"
                        :key="s.id"
                        role="tab"
                        :aria-selected="s.id === variant.id"
                        :class="{ on: s.id === variant.id }"
                        :data-testid="'kb-tab-' + s.language"
                        :href="'/admin/kb/variants/' + s.id + '/edit'"
                    >
                        <span class="bo-mono">{{ s.language }}</span>
                        <span
                            :class="
                                s.translation_state === 'current'
                                    ? 'd-cur'
                                    : s.translation_state === 'outdated'
                                      ? 'd-out'
                                      : 'd-mis'
                            "
                            :title="s.language + ': ' + stateWord(s.translation_state)"
                            ><i aria-hidden="true">{{
                                s.translation_state === 'current'
                                    ? '●'
                                    : s.translation_state === 'outdated'
                                      ? '◐'
                                      : '○'
                            }}</i>
                            {{ stateWord(s.translation_state) }}</span
                        >
                        · {{ statusWord(s.status) }}
                        <span v-if="s.language === article.source_language" class="src">{{
                            t.source_tag
                        }}</span>
                    </a>
                </div>
                <span v-if="editable && missingLanguages.length" class="kb-addlang">
                    <button
                        v-for="l in missingLanguages"
                        :key="l"
                        type="button"
                        class="bo-link"
                        :data-testid="'kb-add-language-' + l"
                        @click="addLanguage(l)"
                    >
                        + {{ fill(t.add_language, { lang: langName(l) }) }}
                    </button>
                </span>

                <div class="kb-fields">
                    <div class="bo-fld">
                        <label for="kb-title"
                            >{{ t.article_title
                            }}<template v-if="variant.language !== 'en'">
                                · {{ t['title_' + variant.language] ?? '' }}</template
                            ></label
                        >
                        <input
                            id="kb-title"
                            v-model="title"
                            class="bo-in big"
                            data-testid="kb-title"
                            :readonly="!editable"
                        />
                    </div>
                    <div class="bo-fld">
                        <label for="kb-slug">{{
                            fill(t.slug_lang, { lang: variant.language })
                        }}</label>
                        <div class="kb-slugrow">
                            <input
                                id="kb-slug"
                                v-model="slug"
                                class="bo-in bo-mono"
                                data-testid="kb-slug"
                                :readonly="!editable"
                            />
                            <span
                                v-if="suggestedSlug && editable"
                                class="kb-chipbtn"
                                data-testid="kb-transliterate"
                            >
                                {{ t.transliterate }} →
                                <span class="bo-mono">{{ suggestedSlug }}</span> ·
                                <button
                                    type="button"
                                    class="bo-link"
                                    data-testid="kb-transliterate-use"
                                    @click="slug = suggestedSlug"
                                >
                                    {{ t.use_word }}
                                </button>
                            </span>
                        </div>
                        <p class="bo-hint">{{ t.slug_editor_hint }}</p>
                    </div>
                    <div class="bo-fld">
                        <label for="kb-summary"
                            >{{ t.summary
                            }}<template v-if="variant.language !== 'en'">
                                · {{ t['summary_' + variant.language] ?? '' }}</template
                            >
                            — {{ t.summary_hint }}</label
                        >
                        <input
                            id="kb-summary"
                            v-model="summary"
                            class="bo-in"
                            data-testid="kb-summary"
                            :readonly="!editable"
                        />
                    </div>
                </div>

                <div
                    v-if="editable"
                    class="kb-toolbar"
                    role="toolbar"
                    :aria-label="t.block_toolbar"
                >
                    <span class="kb-menuwrap">
                        <button
                            type="button"
                            class="tb menu"
                            :aria-expanded="insertOpen"
                            data-testid="kb-insert-menu"
                            @click="insertOpen = !insertOpen"
                        >
                            + {{ t.insert_block }} ▾
                        </button>
                        <span v-if="insertOpen" class="kb-menu" role="menu">
                            <button
                                v-for="ty in BLOCK_TYPES"
                                :key="ty"
                                type="button"
                                role="menuitem"
                                :data-testid="'kb-insert-' + ty"
                                @click="
                                    addBlock(ty);
                                    insertOpen = false;
                                "
                            >
                                {{ typeWord(ty) }}
                            </button>
                        </span>
                    </span>
                    <span class="sep" aria-hidden="true"></span>
                    <button
                        v-for="ty in BLOCK_TYPES"
                        :key="ty"
                        type="button"
                        class="tb"
                        :data-testid="'kb-tb-' + ty"
                        @click="addBlock(ty)"
                    >
                        {{ typeWord(ty) }}
                    </button>
                    <span class="sep" aria-hidden="true"></span>
                    <button
                        type="button"
                        class="tb"
                        :aria-label="t.bold"
                        @click="wrapSelection('**', '**')"
                    >
                        <b>B</b>
                    </button>
                    <button
                        type="button"
                        class="tb"
                        :aria-label="t.italic"
                        @click="wrapSelection('_', '_')"
                    >
                        <i>I</i>
                    </button>
                    <button
                        type="button"
                        class="tb"
                        :aria-label="t.link"
                        @click="wrapSelection('[', '](https://)')"
                    >
                        {{ t.link }}
                    </button>
                    <span class="kb-keys"
                        >{{ t.move_block }} <kbd>{{ t.key_alt }}</kbd
                        >+<kbd>↑</kbd>/<kbd>↓</kbd> · {{ t.block_menu }} <kbd>⋯</kbd></span
                    >
                </div>
                <div v-if="!editable && !isMobile" class="kb-toolbar" aria-hidden="true">
                    <span class="bo-sk w40"></span><span class="bo-sk w40"></span>
                </div>

                <div class="kb-blocks" data-testid="kb-blocks">
                    <p class="sr-only" role="status" aria-live="polite">{{ announcement }}</p>
                    <template v-for="(b, i) in blocks" :key="b.id">
                        <p
                            v-if="article.audience === 'internal' && longArticle && i === 6"
                            class="bo-banner int kb-banner-inline"
                            role="note"
                            :aria-label="t.internal_article"
                        >
                            🔒 <b>{{ t.audience_internal }}</b> — {{ t.internal_banner_long }} ·
                            {{ t.repeated_above_fold }}
                        </p>
                        <div
                            :id="'kb-blk-' + i"
                            class="kb-blk"
                            :class="{
                                focus: focused === b.id,
                                ai: b.ai,
                                h: b.type === 'heading',
                                commented: commentCount(b.id),
                            }"
                            :data-testid="'kb-blk-' + b.id"
                            @focusin="focused = b.id"
                            @keydown="onBlockKey($event, i)"
                        >
                            <div class="bh">
                                <span v-if="editable" class="grip" aria-hidden="true">⋮⋮</span>
                                <span
                                    >{{ b.ai && b.type === 'also_asked' ? '✦ ' : ''
                                    }}{{
                                        b.type === 'also_asked' ? t.also_asked : typeWord(b.type, b)
                                    }}</span
                                >
                                <span
                                    v-if="b.ai"
                                    class="kb-aitag"
                                    :data-testid="'kb-ai-tag-' + b.id"
                                    >{{ t.ai_block_tag }}</span
                                >
                                <span v-if="commentCount(b.id)" class="bo-chip warn"
                                    >💬 {{ fill(t.n_comments, { n: commentCount(b.id) }) }}</span
                                >
                                <span v-if="editable" class="mv">
                                    <button
                                        type="button"
                                        :aria-label="t.move_block_up"
                                        :data-testid="'kb-blk-up-' + b.id"
                                        @click="moveBlock(i, -1)"
                                    >
                                        ↑
                                    </button>
                                    <button
                                        type="button"
                                        :aria-label="t.move_block_down"
                                        :data-testid="'kb-blk-down-' + b.id"
                                        @click="moveBlock(i, 1)"
                                    >
                                        ↓
                                    </button>
                                    <span class="kb-menuwrap">
                                        <button
                                            type="button"
                                            :aria-label="t.block_menu"
                                            :aria-expanded="menuFor === b.id"
                                            :data-testid="'kb-blk-menu-' + b.id"
                                            @click="menuFor = menuFor === b.id ? null : b.id"
                                        >
                                            ⋯
                                        </button>
                                        <span v-if="menuFor === b.id" class="kb-menu" role="menu">
                                            <button
                                                type="button"
                                                role="menuitem"
                                                @click="duplicateBlock(i)"
                                            >
                                                {{ t.duplicate_block }}
                                            </button>
                                            <button
                                                type="button"
                                                role="menuitem"
                                                :data-testid="'kb-blk-remove-' + b.id"
                                                @click="removeBlock(i)"
                                            >
                                                {{ t.remove_block }}
                                            </button>
                                        </span>
                                    </span>
                                </span>
                            </div>
                            <div class="bc">
                                <template v-if="b.type === 'heading'">
                                    <input
                                        v-model="b.text"
                                        class="kb-hin"
                                        :aria-label="typeWord(b.type) + ' #' + i"
                                        :data-testid="'kb-block-' + b.id"
                                        :readonly="!editable"
                                        @input="clearAi(b)"
                                    />
                                </template>
                                <template
                                    v-else-if="
                                        b.type === 'paragraph' ||
                                        b.type === 'callout' ||
                                        b.type === 'also_asked' ||
                                        !BLOCK_TYPES.includes(b.type as never)
                                    "
                                >
                                    <div :class="{ 'kb-callout': b.type === 'callout' }">
                                        <textarea
                                            v-if="b.type !== 'also_asked'"
                                            v-model="b.text"
                                            :data-block="b.id"
                                            :aria-label="typeWord(b.type) + ' #' + i"
                                            :data-testid="'kb-block-' + b.id"
                                            rows="3"
                                            class="kb-ta"
                                            :readonly="!editable"
                                            @input="clearAi(b)"
                                        ></textarea>
                                        <textarea
                                            v-else
                                            :value="listText(b)"
                                            :aria-label="t.also_asked"
                                            :data-testid="'kb-block-' + b.id"
                                            rows="3"
                                            class="kb-ta"
                                            :readonly="!editable"
                                            @input="
                                                setList(
                                                    b,
                                                    ($event.target as HTMLTextAreaElement).value,
                                                )
                                            "
                                        ></textarea>
                                    </div>
                                    <div
                                        v-for="(f, fi) in flagsFor(i)"
                                        :key="fi"
                                        class="kb-sanline"
                                    >
                                        <button
                                            type="button"
                                            class="kb-san"
                                            :class="{ kept: keptOverride }"
                                            :aria-expanded="openFlag === flags.indexOf(f)"
                                            :data-testid="'kb-flag-' + i + '-' + fi"
                                            @click="
                                                openFlag =
                                                    openFlag === flags.indexOf(f)
                                                        ? null
                                                        : flags.indexOf(f)
                                            "
                                        >
                                            {{ f.sentence }}
                                        </button>
                                        <span v-if="keptOverride" class="bo-chip warn"
                                            >⚠
                                            {{
                                                fill(t.flag_kept_chip, {
                                                    v: current_version?.version ?? 0,
                                                })
                                            }}</span
                                        >
                                        <div
                                            v-if="openFlag === flags.indexOf(f)"
                                            class="kb-pop"
                                            role="dialog"
                                            :aria-label="t.sanitizer_warning"
                                        >
                                            <h5>⚠ {{ t.flag_title }}</h5>
                                            <p>
                                                {{ t.pattern_word }}
                                                <span class="pat">{{ f.pattern }}</span> —
                                                {{ t.flag_rationale }}
                                            </p>
                                            <div class="acts">
                                                <button
                                                    type="button"
                                                    class="bo-btn sm"
                                                    @click="
                                                        openFlag = null;
                                                        focused = b.id;
                                                    "
                                                >
                                                    {{ t.edit_sentence }}
                                                </button>
                                                <button
                                                    type="button"
                                                    class="bo-btn sm"
                                                    @click="publishOpen = true"
                                                >
                                                    {{ t.keep_needs_reason }}
                                                </button>
                                            </div>
                                            <p class="bo-hint">{{ t.flag_recorded }}</p>
                                        </div>
                                    </div>
                                </template>
                                <template v-else-if="b.type === 'list'">
                                    <label class="kb-listmode"
                                        ><input
                                            v-model="b.ordered"
                                            type="checkbox"
                                            :disabled="!editable"
                                        />
                                        {{ t.numbered }}</label
                                    >
                                    <textarea
                                        :value="listText(b)"
                                        :aria-label="typeWord(b.type, b) + ' #' + i"
                                        :data-testid="'kb-block-' + b.id"
                                        rows="3"
                                        class="kb-ta"
                                        :readonly="!editable"
                                        @input="
                                            setList(b, ($event.target as HTMLTextAreaElement).value)
                                        "
                                    ></textarea>
                                    <p class="bo-hint">{{ t.list_hint }}</p>
                                </template>
                                <template v-else-if="b.type === 'table'">
                                    <textarea
                                        :value="tableText(b)"
                                        :aria-label="typeWord(b.type) + ' #' + i"
                                        :data-testid="'kb-block-' + b.id"
                                        rows="3"
                                        class="kb-ta bo-mono"
                                        :readonly="!editable"
                                        @input="
                                            setTable(
                                                b,
                                                ($event.target as HTMLTextAreaElement).value,
                                            )
                                        "
                                    ></textarea>
                                    <table v-if="b.rows && b.rows.length" class="kb-table">
                                        <tbody>
                                            <tr v-for="(r, ri) in b.rows" :key="ri">
                                                <td v-for="(c, ci) in r" :key="ci">{{ c }}</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                    <p class="bo-hint">{{ t.table_hint }}</p>
                                </template>
                                <template v-else-if="b.type === 'image'">
                                    <div
                                        v-if="b.blocked"
                                        class="kb-upfail"
                                        :data-testid="'kb-upload-blocked-' + b.id"
                                    >
                                        <span
                                            >🛡 <b>{{ t.upload_blocked }}</b> —
                                            {{ t.upload_blocked_body }}</span
                                        >
                                        <span class="acts"
                                            ><button
                                                type="button"
                                                class="bo-btn sm"
                                                @click="removeBlock(i)"
                                            >
                                                {{ t.remove_block }}
                                            </button></span
                                        >
                                    </div>
                                    <div
                                        v-else-if="b.upload_failed"
                                        class="kb-upfail"
                                        :data-testid="'kb-upload-failed-' + b.id"
                                    >
                                        <span
                                            >! <b>{{ b.upload_failed }}</b> —
                                            {{ t.upload_failed_body }}</span
                                        >
                                        <span class="acts"
                                            ><label class="bo-btn sm"
                                                >{{ t.retry
                                                }}<input
                                                    type="file"
                                                    accept="image/*"
                                                    class="sr-only"
                                                    @change="upload(b, $event)" /></label
                                            ><button
                                                type="button"
                                                class="bo-btn sm"
                                                @click="removeBlock(i)"
                                            >
                                                {{ t.remove_block }}
                                            </button></span
                                        >
                                    </div>
                                    <div v-else class="kb-img">
                                        <div class="ph2" :data-testid="'kb-image-' + b.id">
                                            <img v-if="b.url" :src="b.url" :alt="b.alt ?? ''" />
                                            <template v-else-if="b.placeholder">{{
                                                t.image_placeholder
                                            }}</template>
                                            <label v-else-if="editable" class="bo-link"
                                                >{{ t.upload_image
                                                }}<input
                                                    type="file"
                                                    accept="image/*"
                                                    class="sr-only"
                                                    :data-testid="'kb-upload-' + b.id"
                                                    @change="upload(b, $event)"
                                            /></label>
                                            <template v-else>{{
                                                b.media_key ?? t.no_image
                                            }}</template>
                                        </div>
                                        <div class="alt">
                                            <label :for="'kb-alt-' + b.id">{{ t.alt_label }}</label>
                                            <input
                                                :id="'kb-alt-' + b.id"
                                                v-model="b.alt"
                                                class="bo-in"
                                                :class="{
                                                    bad:
                                                        !(b.alt ?? '').trim() &&
                                                        article.audience === 'public',
                                                }"
                                                :aria-label="t.block_image + ' #' + i"
                                                :data-testid="'kb-alt-' + b.id"
                                                :placeholder="t.alt_placeholder"
                                                :readonly="!editable"
                                                @input="clearAi(b)"
                                            />
                                            <p
                                                v-if="
                                                    !(b.alt ?? '').trim() &&
                                                    article.audience === 'public'
                                                "
                                                class="kb-warnline"
                                            >
                                                ⚠ {{ t.alt_missing_line }}
                                            </p>
                                            <p v-else-if="b.media_key" class="bo-hint">
                                                ✓ {{ t.image_stored }}
                                            </p>
                                            <p v-if="b.placeholder" class="kb-warnline">
                                                ⚠ {{ t.media_gone }}
                                            </p>
                                        </div>
                                    </div>
                                </template>
                                <template v-else-if="b.type === 'anchor'">
                                    <span class="kb-anchor"
                                        >#<input
                                            v-model="b.anchor"
                                            class="kb-hin bo-mono"
                                            :aria-label="typeWord(b.type) + ' #' + i"
                                            :data-testid="'kb-block-' + b.id"
                                            :readonly="!editable"
                                        />
                                        — {{ t.anchor_hint }}</span
                                    >
                                </template>
                            </div>
                        </div>
                    </template>
                    <div v-if="editable" class="kb-addrow">
                        <button
                            type="button"
                            class="kb-chipbtn"
                            data-testid="kb-add-paragraph"
                            @click="addBlock('paragraph')"
                        >
                            + {{ t.add_block }}
                        </button>
                        <span>{{ t.block_set_note }}</span>
                    </div>
                    <div v-if="editable" class="kb-addrow">
                        <button type="button" class="bo-btn sm" data-testid="kb-save" @click="save">
                            {{ t.save
                            }}<template v-if="savedAt">
                                · {{ t.saved }} {{ hhmmss(savedAt) }}</template
                            >
                        </button>
                    </div>
                </div>
            </div>

            <!-- rail / drawer -->
            <aside
                v-if="drawerOpen"
                class="kb-drawer"
                :aria-label="t.ai_drawer"
                data-testid="kb-ai-drawer"
            >
                <div class="dh">
                    <b>✦ {{ t.ai_drawer }}</b
                    ><span class="bo-hint">{{ t.drafts_only }}</span
                    ><button
                        type="button"
                        class="x"
                        :aria-label="t.close"
                        @click="drawerOpen = false"
                    >
                        ✕
                    </button>
                </div>
                <div class="tools">
                    <button
                        type="button"
                        class="tool"
                        :class="{ on: aiRunning === 'simplify', off: aiOff }"
                        :disabled="aiOff || !editable"
                        data-testid="kb-ai-simplify"
                        @click="aiTool('simplify')"
                    >
                        <b>✦ {{ t.ai_simplify }}</b
                        ><small>{{ t.ai_simplify_desc }}</small>
                    </button>
                    <button
                        type="button"
                        class="tool"
                        :class="{ on: aiRunning === 'faq', off: aiOff }"
                        :disabled="aiOff || !editable"
                        data-testid="kb-ai-faq"
                        @click="aiTool('faq')"
                    >
                        <b>✦ {{ t.ai_faq }}</b
                        ><small>{{ t.ai_faq_desc }}</small>
                    </button>
                    <button
                        type="button"
                        class="tool"
                        :class="{ on: aiRunning === 'translate_all', off: aiOff }"
                        :disabled="aiOff || !editable"
                        data-testid="kb-ai-translate-all"
                        @click="aiTool('translate_all')"
                    >
                        <b>✦ {{ t.ai_translate_all }}</b
                        ><small>{{ fill(t.ai_translate_desc, { lang: variant.language }) }}</small>
                    </button>
                    <div class="tool dashed" :class="{ off: aiOff }">
                        <b>✦ {{ t.ai_from_source }}</b
                        ><small>{{ t.ai_from_source_desc }}</small>
                        <textarea
                            v-if="!aiOff && editable"
                            v-model="sourceText"
                            class="kb-ta"
                            rows="2"
                            :placeholder="t.paste_source"
                            data-testid="kb-ai-source-text"
                        ></textarea>
                        <button
                            v-if="!aiOff && editable"
                            type="button"
                            class="bo-btn sm"
                            :disabled="!sourceText.trim()"
                            data-testid="kb-ai-from-source"
                            @click="draftFromSource"
                        >
                            {{ t.draft_verb }}
                        </button>
                    </div>
                    <div class="tool dashed" :class="{ off: aiOff }">
                        <b>✦ {{ t.ai_from_conversations }}</b
                        ><small>{{ t.ai_from_conversations_desc }}</small>
                    </div>
                </div>
                <div class="res">
                    <p v-if="aiOff" class="bo-banner ai kb-banner-inline" data-testid="kb-ai-off">
                        ✦ <b>{{ t.ai_off_title }}</b> {{ t.ai_off_body }}
                    </p>
                    <p v-else-if="aiRunning" class="bo-hint">
                        {{ fill(t.ai_running, { tool: t['ai_' + aiRunning] ?? aiRunning }) }}
                    </p>
                    <div
                        v-for="b in aiBlocks"
                        :key="b.id"
                        class="kb-aicard"
                        :data-testid="'kb-ai-result-' + b.id"
                    >
                        <div class="ailabel">
                            ✦ {{ t.ai_assistant }} · {{ t.draft_word }} ·
                            {{ fill(t.block_n, { n: blockIndexById(b.id) + 1 }) }}
                        </div>
                        <div class="aitext">{{ b.text ?? (b.items ?? []).join(' · ') }}</div>
                        <div class="acts">
                            <button type="button" class="bo-btn sm" @click="jumpTo(b.id)">
                                {{ t.jump_to_block }}</button
                            ><button type="button" class="bo-btn sm" @click="discardAi(b)">
                                {{ t.discard }}
                            </button>
                        </div>
                    </div>
                    <p v-if="!aiOff && aiBlocks.length" class="bo-hint">{{ t.ai_inserted_note }}</p>
                </div>
                <div class="foot">{{ aiOff ? t.ai_off_foot : t.drawer_foot }}</div>
            </aside>
            <aside v-else class="bo-rail" data-testid="kb-rail">
                <div class="bo-card">
                    <div class="ct">{{ t.article_card }}</div>
                    <div class="cb">
                        <dl class="bo-kv2">
                            <dt>{{ t.status_word }}</dt>
                            <dd>
                                <span class="bo-pill" :class="variant.status">{{
                                    statusWord(variant.status)
                                }}</span
                                ><template v-if="current_version">
                                    · v{{ current_version.version }}</template
                                >
                            </dd>
                            <dt>{{ t.audience }}</dt>
                            <dd>{{ t['audience_' + article.audience] }} · {{ t.one_audience }}</dd>
                            <dt>{{ t.field_ai_usable }}</dt>
                            <dd>
                                <template v-if="article.audience === 'internal'">{{
                                    t.ai_copilot_only
                                }}</template>
                                <template v-else-if="article.category_ai_usable"
                                    >{{ t.yes_word }} —
                                    <a class="bo-link" href="/admin/kb#categories">{{
                                        fill(t.inherited_from, { cat: article.category_name ?? '' })
                                    }}</a></template
                                >
                                <template v-else
                                    >{{ t.no_word }} —
                                    <a class="bo-link" href="/admin/kb#categories">{{
                                        fill(t.inherited_from, { cat: article.category_name ?? '' })
                                    }}</a></template
                                >
                            </dd>
                            <dt>{{ t.category_one }}</dt>
                            <dd>{{ article.category_name }}</dd>
                            <dt>{{ t.source }}</dt>
                            <dd>
                                {{
                                    fill(isSource ? t.source_first : t.source_translation, {
                                        lang: langName(article.source_language),
                                        code: article.source_language,
                                    })
                                }}
                            </dd>
                            <template
                                v-if="
                                    variant.status === 'published' &&
                                    variant.index_status !== 'current'
                                "
                                ><dt>{{ t.retrieval_word }}</dt>
                                <dd>
                                    <span class="bo-chip warn">{{ t.pipe_index_pending }}</span>
                                    {{ t.pipe_serves_previous }}
                                </dd></template
                            >
                        </dl>
                    </div>
                </div>
                <div v-if="flags.length" class="bo-card" data-testid="kb-sanitizer-flags">
                    <div class="ct">{{ t.sanitizer_summary }}</div>
                    <div class="cb">
                        <p class="bo-hint">{{ t.sanitizer_ruleset }}</p>
                        <dl class="bo-kv2">
                            <dt>{{ t.flagged_word }}</dt>
                            <dd>{{ fill(t.n_sentences, { n: flags.length }) }}</dd>
                            <dt>{{ t.kept_word }}</dt>
                            <dd>
                                {{
                                    keptOverride
                                        ? fill(t.kept_on_v, { v: current_version?.version ?? 0 })
                                        : '0'
                                }}
                            </dd>
                            <dt>{{ t.open_word }}</dt>
                            <dd>
                                {{
                                    flags.map((f) => fill(t.block_n, { n: f.block + 1 })).join(', ')
                                }}
                            </dd>
                        </dl>
                        <ul class="kb-flaglist">
                            <li v-for="(f, i) in flags" :key="i">
                                #{{ f.block + 1 }} “{{ f.sentence }}” — {{ f.pattern }}
                            </li>
                        </ul>
                        <p class="bo-hint">{{ t.popover_keyboard }}</p>
                    </div>
                </div>
                <div class="bo-card" data-testid="kb-review-card">
                    <div class="ct">
                        {{ t.review_word }} <span class="n">{{ t.checklist_note }}</span>
                    </div>
                    <div class="cb">
                        <div class="kb-chk">
                            <label
                                v-for="(row, i) in [
                                    'review_check_accuracy',
                                    'review_check_tone',
                                    'review_check_links',
                                ]"
                                :key="row"
                                ><input
                                    v-model="checklist[i]"
                                    type="checkbox"
                                    :disabled="!editable"
                                />
                                {{ t[row] }}</label
                            >
                        </div>
                        <div v-if="editable" class="kb-verbs">
                            <button
                                v-if="variant.status === 'draft'"
                                type="button"
                                class="bo-btn sm"
                                data-testid="kb-submit"
                                @click="transition('submit')"
                            >
                                {{ t.submit_review }}
                            </button>
                            <button
                                v-if="variant.status === 'in_review' && can.publish"
                                type="button"
                                class="bo-btn sm"
                                data-testid="kb-request-changes"
                                @click="transition('request-changes')"
                            >
                                {{ t.request_changes }}
                            </button>
                            <button
                                v-if="can.publish && variant.status !== 'archived'"
                                type="button"
                                class="bo-btn sm p"
                                @click="publishOpen = true"
                            >
                                {{
                                    variant.status === 'in_review'
                                        ? t.approve_publish
                                        : t.publish_ellipsis
                                }}
                            </button>
                        </div>
                        <p class="bo-hint">
                            {{ can.publish ? t.perm_publish_note : t.perm_no_publish_note }}
                        </p>
                        <div class="kb-comments" data-testid="kb-review-thread">
                            <p class="kb-cmt-h">
                                {{ t.review_thread }} ·
                                {{ fill(t.n_open, { n: openComments.length }) }}
                            </p>
                            <div
                                v-for="c in comments"
                                :key="c.id"
                                class="kb-cmt"
                                :class="{ res: c.resolved_at }"
                            >
                                <p class="who">
                                    <span class="bo-mono">{{ c.author_name ?? '—' }}</span> ·
                                    {{ hhmm(c.created_at) }}
                                    <span v-if="c.block_anchor" class="anch">{{
                                        anchorLabel(c.block_anchor)
                                    }}</span
                                    ><template v-if="c.resolved_at">
                                        · {{ t.resolved_word }}</template
                                    >
                                </p>
                                <p class="body">{{ c.body }}</p>
                                <p v-if="!c.resolved_at" class="acts">
                                    <button
                                        v-if="editable"
                                        type="button"
                                        class="bo-link"
                                        @click="resolveComment(c.id)"
                                    >
                                        {{ t.resolve }}
                                    </button>
                                    <button
                                        v-if="c.block_anchor"
                                        type="button"
                                        class="bo-link"
                                        @click="jumpTo(c.block_anchor)"
                                    >
                                        {{ t.jump_to_block }}
                                    </button>
                                </p>
                            </div>
                            <div v-if="editable" class="kb-cin">
                                <input
                                    v-model="commentBody"
                                    :placeholder="
                                        focused
                                            ? fill(t.comment_anchored, {
                                                  block: anchorLabel(focused),
                                              })
                                            : t.comment_placeholder
                                    "
                                    data-testid="kb-comment-input"
                                    class="bo-in"
                                />
                                <button
                                    type="button"
                                    class="bo-btn sm"
                                    data-testid="kb-comment-send"
                                    :disabled="!commentBody.trim()"
                                    @click="sendComment"
                                >
                                    {{ t.comment_send }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <div
                    v-if="article.audience === 'internal'"
                    class="bo-card"
                    data-testid="kb-audience-card"
                >
                    <div class="ct">{{ t.changing_audience }}</div>
                    <div class="cb">
                        {{ t.changing_audience_body
                        }}<button
                            v-if="can.publish && editable"
                            type="button"
                            class="bo-btn sm"
                            data-testid="kb-audience-open-card"
                            @click="audienceOpen = true"
                        >
                            {{ t.change_audience }}
                        </button>
                    </div>
                </div>
                <div class="bo-card">
                    <div class="ct">✦ {{ t.ai_drawer }}</div>
                    <div class="cb">
                        <button
                            type="button"
                            class="bo-btn sm"
                            data-testid="kb-ai-open"
                            :disabled="!editable"
                            @click="drawerOpen = true"
                        >
                            ✦ {{ t.open_ai_tools }}</button
                        ><span class="bo-hint">{{ t.ai_note }}</span>
                    </div>
                </div>
                <div v-if="can.stats && stats" class="bo-card" data-testid="kb-feedback-card">
                    <div class="ct">
                        {{ t.feedback_word }}
                        <span class="n">{{
                            fill(t.feedback_counts, {
                                comments: stats.feedback.comments,
                                up: stats.feedback.up,
                                down: stats.feedback.down,
                            })
                        }}</span>
                    </div>
                    <div class="cb">
                        <div class="kb-fbfilters">
                            <button
                                type="button"
                                class="bo-sel"
                                :class="{ on: fbFilter === 'all' }"
                                @click="fbFilter = 'all'"
                            >
                                {{ t.all_word }}
                            </button>
                            <button
                                type="button"
                                class="bo-sel"
                                :class="{ on: fbFilter === 'down' }"
                                @click="fbFilter = 'down'"
                            >
                                👎 {{ t.only_word }}
                            </button>
                            <button
                                type="button"
                                class="bo-sel"
                                :class="{ on: fbFilter === 'open' }"
                                @click="fbFilter = 'open'"
                            >
                                {{ t.not_actioned }}
                            </button>
                        </div>
                        <p v-if="!feedbackListed.length" class="bo-hint">{{ t.feedback_none }}</p>
                        <div
                            v-for="f in feedbackListed"
                            :key="f.id"
                            class="kb-fbrow"
                            :data-testid="'kb-fb-' + f.id"
                        >
                            <span class="sc" aria-hidden="true">{{ scoreGlyph(f) }}</span>
                            <div>
                                <span>{{
                                    f.comment ?? (f.score === 1 ? t.score_up : t.score_down)
                                }}</span>
                                <div class="m">{{ f.source }} · {{ hhmm(f.created_at) }}</div>
                            </div>
                            <span class="act"
                                ><span v-if="f.actioned_at" class="bo-chip ok">{{
                                    t.actioned_word
                                }}</span
                                ><button
                                    v-else
                                    type="button"
                                    class="bo-link"
                                    @click="markActioned(f)"
                                >
                                    {{ t.mark_actioned }}
                                </button></span
                            >
                        </div>
                        <p v-if="feedbackCursor" class="kb-pager">
                            <button type="button" class="bo-link" @click="moreFeedback">
                                {{ t.next_page }} ›
                            </button>
                        </p>
                        <p class="bo-hint">{{ t.feedback_law }}</p>
                    </div>
                </div>
            </aside>
        </div>

        <!-- publish dialog (B09) — the door's own refusal in place when blocked -->
        <div v-if="publishOpen" class="bo-scrim" @click.self="publishOpen = false">
            <div
                class="bo-dlg"
                role="dialog"
                aria-modal="true"
                :aria-label="blocked ? t.publish_blocked : t.publish_confirm"
                data-testid="kb-publish-dialog"
            >
                <h3 class="dt">{{ blocked ? t.publish_blocked : t.publish_confirm }}</h3>
                <div class="db">
                    <dl class="bo-row2">
                        <dt>{{ t.variant_word }}</dt>
                        <dd>
                            <span class="bo-mono">{{ variant.language }}</span> ·
                            {{ langName(variant.language) }} ·
                            {{
                                isSource
                                    ? t.the_source_language
                                    : fill(t.translation_of, { code: article.source_language })
                            }}
                        </dd>
                        <dt>{{ t.audience }}</dt>
                        <dd>
                            {{ t['audience_' + article.audience]
                            }}<template v-if="article.audience === 'public'">
                                — {{ t.public_consequence }}</template
                            >
                        </dd>
                        <dt>{{ t.field_ai_usable }}</dt>
                        <dd>
                            <template v-if="article.audience === 'internal'">{{
                                t.ai_copilot_only
                            }}</template
                            ><template v-else
                                >{{ article.category_ai_usable ? t.yes_word : t.no_word }} —
                                <a class="bo-link" href="/admin/kb#categories">{{
                                    fill(t.inherited_from, { cat: article.category_name ?? '' })
                                }}</a>
                                ({{ t.change_on_category }})</template
                            >
                        </dd>
                        <template v-if="!blocked">
                            <dt>{{ t.publishing_word }}</dt>
                            <dd>{{ fill(t.publishing_only, { code: variant.language }) }}</dd>
                            <dt>{{ t.sanitizer_word }}</dt>
                            <dd>
                                {{
                                    flags.length
                                        ? fill(t.sanitizer_flags_line, { n: flags.length })
                                        : t.sanitizer_clean
                                }}
                            </dd>
                            <dt>{{ t.alt_text_word }}</dt>
                            <dd>
                                {{
                                    missingAlt.length
                                        ? '⚠ ' + fill(t.alt_missing_n, { n: missingAlt.length })
                                        : '✓ ' + t.alt_all_present
                                }}
                            </dd>
                        </template>
                    </dl>
                    <template v-if="blocked">
                        <div
                            v-if="refusal.missing_alt?.length"
                            class="bo-refuse"
                            data-testid="kb-missing-alt"
                        >
                            <b>{{ t.missing_alt }}</b>
                            <ul class="kb-list">
                                <li v-for="id in refusal.missing_alt" :key="id">
                                    <button
                                        type="button"
                                        class="bo-link"
                                        @click="
                                            publishOpen = false;
                                            jumpTo(id);
                                        "
                                    >
                                        {{ fill(t.block_n, { n: blockIndexById(id) + 1 }) }} ·
                                        {{ id }}
                                    </button>
                                    — {{ t.no_alt_text }}
                                </li>
                            </ul>
                        </div>
                        <div
                            v-if="refusal.missing_media?.length"
                            class="bo-refuse"
                            data-testid="kb-missing-media"
                        >
                            <b>{{ t.missing_media }}</b>
                            <ul class="kb-list">
                                <li v-for="id in refusal.missing_media" :key="id">
                                    <button
                                        type="button"
                                        class="bo-link"
                                        @click="
                                            publishOpen = false;
                                            jumpTo(id);
                                        "
                                    >
                                        {{ fill(t.block_n, { n: blockIndexById(id) + 1 }) }} ·
                                        {{ id }}
                                    </button>
                                    — {{ t.replace_or_remove }}
                                </li>
                            </ul>
                        </div>
                        <p class="bo-hint">{{ t.refusal_in_place }}</p>
                    </template>
                    <template v-else>
                        <p
                            v-if="isSource && publishedSiblings.length"
                            class="bo-warnbox"
                            data-testid="kb-flips-outdated"
                        >
                            <b>{{ t.flips_to_outdated }}</b>
                            <span class="bo-mono">{{ publishedSiblingCodes }}</span>
                            — {{ t.flips_body }}
                        </p>
                        <label v-if="flags.length" class="bo-fld"
                            >{{ t.override_reason
                            }}<input
                                v-model="overrideReason"
                                class="bo-in"
                                data-testid="kb-override-reason"
                        /></label>
                        <label class="bo-fld"
                            >{{ t.version_note
                            }}<input
                                v-model="publishNote"
                                class="bo-in"
                                data-testid="kb-version-note"
                                :placeholder="t.version_note_placeholder"
                        /></label>
                        <p class="bo-hint">
                            {{ fill(t.publish_does, { v: (current_version?.version ?? 0) + 1 }) }}
                        </p>
                    </template>
                </div>
                <div class="df">
                    <button
                        v-if="blocked"
                        type="button"
                        class="bo-btn"
                        data-testid="kb-publish-back"
                        @click="
                            publishOpen = false;
                            refusal = {};
                        "
                    >
                        {{ t.back_to_editor }}
                    </button>
                    <template v-else>
                        <button type="button" class="bo-btn" @click="publishOpen = false">
                            {{ t.cancel }}
                        </button>
                        <button
                            type="button"
                            class="bo-btn p"
                            data-testid="kb-publish-confirm"
                            @click="publish"
                        >
                            {{ fill(t.publish_lang, { code: variant.language }) }}
                        </button>
                    </template>
                </div>
            </div>
        </div>

        <!-- change audience (SEC-04 high-visibility; EC-11 full pipeline) -->
        <div v-if="audienceOpen" class="bo-scrim" @click.self="audienceOpen = false">
            <div
                class="bo-dlg"
                role="dialog"
                aria-modal="true"
                :aria-label="t.change_audience"
                data-testid="kb-audience-dialog"
            >
                <h3 class="dt">{{ t.change_audience }}</h3>
                <div class="db">
                    <p>
                        {{
                            fill(t.change_audience_body, {
                                from: t['audience_' + article.audience] ?? '',
                                to:
                                    t[
                                        'audience_' +
                                            (article.audience === 'internal'
                                                ? 'public'
                                                : 'internal')
                                    ] ?? '',
                            })
                        }}
                    </p>
                    <p class="bo-warnbox">{{ t.changing_audience_body }}</p>
                </div>
                <div class="df">
                    <button type="button" class="bo-btn" @click="audienceOpen = false">
                        {{ t.cancel }}</button
                    ><button
                        type="button"
                        class="bo-btn danger"
                        data-testid="kb-audience-confirm"
                        @click="changeAudience"
                    >
                        {{ t.change_audience_verb }}
                    </button>
                </div>
            </div>
        </div>
    </BackofficeShell>
</template>

<style>
.kb-stats {
    display: flex;
    gap: 12px;
    padding: 10px 18px 0;
    flex-wrap: wrap;
}
.kb-stat {
    background: var(--ds-panel-bg);
    border: 1px solid var(--ds-border);
    border-radius: 8px;
    padding: 8px 12px;
    min-width: 150px;
    flex: 1;
}
.kb-stat .k {
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.07em;
    color: var(--ds-text-label);
}
.kb-stat .v {
    font-size: 16px;
    font-weight: 600;
    letter-spacing: -0.01em;
    margin-top: 2px;
    font-variant-numeric: tabular-nums;
}
.kb-stat .s {
    font-size: 11px;
    color: var(--ds-text-muted);
}
.kb-ed {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 340px;
    gap: 16px;
    padding: 12px 18px 24px;
    flex: 1;
    align-items: start;
}
.kb-ed.drawer {
    grid-template-columns: minmax(0, 1fr) 380px;
}
.kb-doc {
    background: var(--ds-panel-bg);
    border: 1px solid var(--ds-border);
    border-radius: 8px;
    min-height: 560px;
    min-width: 0;
}
.kb-tabs2 {
    display: flex;
    gap: 2px;
    padding: 6px 8px 0;
    border-block-end: 1px solid var(--ds-border-soft);
    flex-wrap: wrap;
    align-items: center;
}
.kb-tabs2 a {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 9px 12px;
    border: 1px solid transparent;
    border-block-end: 0;
    border-radius: 8px 8px 0 0;
    font-size: 12px;
    color: var(--ds-text-secondary);
    text-decoration: none;
    min-height: 40px;
}
.kb-tabs2 a.on {
    background: var(--ds-panel-bg);
    border-color: var(--ds-border);
    color: var(--ds-text-primary);
    font-weight: 600;
    margin-block-end: -1px;
    position: relative;
}
.kb-tabs2 .src {
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.07em;
    text-transform: uppercase;
    color: var(--ds-text-label);
    border: 1px solid var(--ds-border-strong);
    border-radius: 4px;
    padding: 0 4px;
}
.kb-addlang {
    display: inline-flex;
    gap: 8px;
    padding: 0 8px;
}
.kb-fields {
    padding: 12px 16px 0;
    display: grid;
    gap: 10px;
}
.kb-slugrow {
    display: flex;
    gap: 8px;
    align-items: center;
    flex-wrap: wrap;
}
.kb-slugrow .bo-in {
    flex: 1;
    min-width: 200px;
    width: auto;
}
.kb-chipbtn {
    height: 28px;
    padding: 0 10px;
    border: 1px solid var(--ds-border);
    border-radius: 6px;
    background: var(--ds-inset-bg);
    font-size: 11.5px;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: var(--ds-text-primary);
    cursor: pointer;
    font-family: inherit;
}
.kb-toolbar {
    display: flex;
    gap: 6px;
    align-items: center;
    padding: 12px 16px 8px;
    flex-wrap: wrap;
    position: sticky;
    top: 0;
    background: var(--ds-panel-bg);
    z-index: 3;
}
.kb-toolbar .tb {
    height: 32px;
    padding: 0 10px;
    border: 1px solid var(--ds-border);
    border-radius: 6px;
    background: var(--ds-panel-bg);
    font-size: 11.5px;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: var(--ds-text-primary);
    cursor: pointer;
    font-family: inherit;
}
.kb-toolbar .tb.menu {
    background: var(--ds-inset-bg);
}
.kb-toolbar .sep {
    width: 1px;
    height: 20px;
    background: var(--ds-border);
}
.kb-keys {
    font-size: 11px;
    color: var(--ds-text-muted);
    margin-inline-start: auto;
}
.kb-keys kbd {
    font-family: ui-monospace, monospace;
    font-size: 10px;
    border: 1px solid var(--ds-border-strong);
    border-radius: 4px;
    padding: 0 4px;
}
.kb-menuwrap {
    position: relative;
    display: inline-block;
}
.kb-menu {
    position: absolute;
    top: calc(100% + 4px);
    inset-inline-start: 0;
    background: var(--ds-panel-bg);
    border: 1px solid var(--ds-border-strong);
    border-radius: 8px;
    padding: 4px;
    display: grid;
    min-width: 180px;
    z-index: 5;
}
.kb-menu button {
    text-align: start;
    padding: 8px 10px;
    border: 0;
    background: none;
    font: inherit;
    color: var(--ds-text-primary);
    border-radius: 6px;
    cursor: pointer;
    min-height: 36px;
}
.kb-menu button:hover {
    background: var(--ds-row-hover);
}
.kb-more {
    position: relative;
}
.kb-more .kb-menu {
    inset-inline-start: auto;
    inset-inline-end: 0;
}
.kb-blocks {
    padding: 4px 16px 16px;
    display: grid;
    gap: 8px;
}
.kb-blk {
    border: 1px solid var(--ds-border);
    border-radius: 8px;
    background: var(--ds-panel-bg);
    position: relative;
}
.kb-blk .bh {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 6px 10px;
    border-block-end: 1px solid var(--ds-border-soft);
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.07em;
    text-transform: uppercase;
    color: var(--ds-text-label);
    flex-wrap: wrap;
}
.kb-blk .bh .grip {
    color: var(--ds-text-muted);
    font-size: 12px;
    cursor: grab;
    letter-spacing: 0;
}
.kb-blk .bh .mv {
    margin-inline-start: auto;
    display: inline-flex;
    gap: 4px;
}
.kb-blk .bh .mv button {
    width: 24px;
    height: 24px;
    border: 1px solid var(--ds-border-strong);
    border-radius: 4px;
    background: var(--ds-panel-bg);
    font-size: 11px;
    color: var(--ds-text-primary);
    cursor: pointer;
}
.kb-blk .bc {
    padding: 10px 12px;
    font-size: 13.5px;
    line-height: 1.65;
}
.kb-blk.focus {
    border-color: var(--ds-text-primary);
    box-shadow: 0 0 0 2px var(--ds-focus-ring);
}
.kb-blk.commented {
    border-inline-start: 3px solid var(--ds-warn-border);
}
.kb-blk.ai {
    background: var(--ds-ai-bg);
    border-color: var(--ds-ai-border);
    border-inline-start: 3px solid var(--ds-ai-edge);
}
.kb-blk.ai .bh {
    color: var(--ds-ai-fg);
    border-block-end-color: var(--ds-ai-border);
}
.kb-aitag {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    height: 18px;
    padding: 0 6px;
    border-radius: 4px;
    font-size: 10px;
    font-weight: 600;
    background: var(--ds-ai-bg);
    color: var(--ds-ai-fg);
    text-transform: none;
    letter-spacing: 0;
}
.kb-ta,
.kb-hin {
    width: 100%;
    border: 1px solid transparent;
    border-radius: 6px;
    padding: 4px 6px;
    font: inherit;
    font-size: 13.5px;
    line-height: 1.65;
    background: transparent;
    color: var(--ds-text-primary);
    resize: vertical;
    box-sizing: border-box;
}
.kb-ta:focus,
.kb-hin:focus {
    outline: none;
    border-color: var(--ds-border-strong);
    background: var(--ds-page-bg);
}
.kb-blk.h .kb-hin {
    font-size: 16px;
    font-weight: 600;
    letter-spacing: -0.01em;
}
.kb-callout {
    border-inline-start: 3px solid var(--ds-warn-border);
    background: var(--ds-warn-bg);
    color: var(--ds-text-primary);
    padding: 4px 6px;
    border-radius: 0 6px 6px 0;
}
.kb-table {
    border-collapse: collapse;
    width: 100%;
    font-size: 12.5px;
    margin-top: 6px;
}
.kb-table td {
    border: 1px solid var(--ds-border);
    padding: 6px 8px;
    text-align: start;
}
.kb-table tr:first-child td {
    background: var(--ds-inset-bg);
    font-size: 10.5px;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--ds-text-label);
}
.kb-listmode {
    font-size: 11px;
    color: var(--ds-text-muted);
    display: inline-flex;
    gap: 6px;
    align-items: center;
}
.kb-img {
    display: flex;
    gap: 12px;
    align-items: flex-start;
    flex-wrap: wrap;
}
.kb-img .ph2 {
    width: 160px;
    height: 96px;
    border-radius: 6px;
    background: repeating-linear-gradient(
        45deg,
        var(--ds-inset-bg),
        var(--ds-inset-bg) 6px,
        var(--ds-row-hover) 6px,
        var(--ds-row-hover) 12px
    );
    border: 1px solid var(--ds-border);
    flex: none;
    display: grid;
    place-items: center;
    font-size: 11px;
    color: var(--ds-text-muted);
    overflow: hidden;
    text-align: center;
    padding: 4px;
}
.kb-img .ph2 img {
    max-width: 100%;
    max-height: 100%;
    object-fit: cover;
}
.kb-img .alt {
    flex: 1;
    min-width: 200px;
}
.kb-img .alt label {
    font-size: 11px;
    font-weight: 600;
    color: var(--ds-text-secondary);
    display: block;
    margin-bottom: 4px;
}
.kb-warnline {
    display: flex;
    gap: 6px;
    align-items: flex-start;
    font-size: 11.5px;
    color: var(--ds-warn-text);
    margin: 6px 0 0;
}
.kb-upfail {
    border: 1px solid var(--ds-danger-border);
    background: var(--ds-danger-bg);
    border-radius: 6px;
    padding: 8px 10px;
    font-size: 12px;
    display: flex;
    gap: 10px;
    align-items: center;
    flex-wrap: wrap;
}
.kb-upfail .acts {
    margin-inline-start: auto;
    display: flex;
    gap: 6px;
}
.kb-anchor {
    font-family: ui-monospace, monospace;
    font-size: 12px;
    color: var(--ds-text-secondary);
    display: inline-flex;
    align-items: center;
    gap: 4px;
    flex-wrap: wrap;
}
.kb-anchor .kb-hin {
    width: 200px;
}
.kb-addrow {
    display: flex;
    gap: 8px;
    padding: 4px 0 0;
    align-items: center;
    font-size: 11.5px;
    color: var(--ds-text-muted);
    flex-wrap: wrap;
}
.kb-sanline {
    margin-top: 6px;
    position: relative;
    display: flex;
    gap: 8px;
    align-items: center;
    flex-wrap: wrap;
}
.kb-san {
    text-decoration: underline dotted var(--ds-warn-text);
    text-decoration-thickness: 2px;
    text-underline-offset: 3px;
    background: var(--ds-warn-bg);
    border-radius: 2px;
    border: 0;
    font: inherit;
    font-size: 12.5px;
    color: var(--ds-text-primary);
    cursor: pointer;
    text-align: start;
    padding: 2px 4px;
}
.kb-san.kept {
    background: transparent;
}
.kb-pop {
    flex-basis: 100%;
    width: min(420px, 100%);
    margin-top: 6px;
    background: var(--ds-panel-bg);
    border: 1px solid var(--ds-border-strong);
    border-radius: 8px;
    padding: 12px;
    font-size: 12px;
    line-height: 1.5;
}
.kb-pop h5 {
    margin: 0 0 6px;
    font-size: 12px;
}
.kb-pop .pat {
    font-family: ui-monospace, monospace;
    font-size: 11px;
    background: var(--ds-inset-bg);
    padding: 1px 6px;
    border-radius: 4px;
}
.kb-pop .acts {
    display: flex;
    gap: 6px;
    margin-top: 10px;
    flex-wrap: wrap;
}
.kb-pipe {
    display: flex;
    gap: 0;
    align-items: center;
    margin: 12px 18px 0;
    padding: 8px 12px;
    border: 1px solid var(--ds-border);
    border-radius: 8px;
    background: var(--ds-panel-bg);
    font-size: 12px;
    flex-wrap: wrap;
}
.kb-pipe .st {
    display: inline-flex;
    gap: 6px;
    align-items: center;
    padding: 0 10px;
    min-height: 28px;
}
.kb-pipe .st + .st {
    border-inline-start: 1px solid var(--ds-border-soft);
}
.kb-pipe .ok {
    color: var(--ds-success-text);
}
.kb-pipe .run {
    color: var(--ds-text-secondary);
}
.kb-pipe .bad {
    color: var(--ds-warn-text);
}
.kb-pipe .msg {
    margin-inline-start: auto;
    display: flex;
    gap: 8px;
    align-items: center;
    flex-wrap: wrap;
    color: var(--ds-text-secondary);
}
.kb-chk {
    display: grid;
    gap: 4px;
}
.kb-chk label {
    display: flex;
    gap: 8px;
    align-items: flex-start;
    min-height: 32px;
    font-size: 12px;
}
.kb-verbs {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
}
.kb-comments {
    display: grid;
    gap: 6px;
}
.kb-cmt-h {
    font-size: 11.5px;
    font-weight: 600;
    margin: 4px 0 0;
}
.kb-cmt {
    border: 1px solid var(--ds-border);
    border-radius: 8px;
    padding: 8px 10px;
    font-size: 12px;
    display: grid;
    gap: 4px;
}
.kb-cmt p {
    margin: 0;
}
.kb-cmt .who {
    display: flex;
    gap: 6px;
    align-items: center;
    font-size: 11px;
    color: var(--ds-text-muted);
    flex-wrap: wrap;
}
.kb-cmt .anch {
    font-family: ui-monospace, monospace;
    font-size: 10.5px;
    background: var(--ds-inset-bg);
    padding: 0 5px;
    border-radius: 4px;
    color: var(--ds-text-primary);
}
.kb-cmt .acts {
    display: flex;
    gap: 8px;
}
.kb-cmt.res {
    color: var(--ds-text-muted);
    background: var(--ds-panel-soft);
}
.kb-cin {
    display: flex;
    gap: 6px;
}
.kb-flaglist {
    margin: 0;
    padding-inline-start: 18px;
    font-size: 11.5px;
}
.kb-list {
    margin: 4px 0 0;
    padding-inline-start: 18px;
}
.kb-fbfilters {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
}
.kb-fbrow {
    display: grid;
    grid-template-columns: 28px minmax(0, 1fr) auto;
    gap: 10px;
    align-items: start;
    padding: 8px 0;
    border-block-end: 1px solid var(--ds-border-soft);
    font-size: 12px;
    min-height: 44px;
}
.kb-fbrow .sc {
    font-size: 14px;
}
.kb-fbrow .m {
    font-size: 11px;
    color: var(--ds-text-muted);
    margin-top: 2px;
}
.kb-fbrow .act {
    font-size: 11px;
}
.kb-pager {
    display: flex;
    justify-content: flex-end;
    font-size: 11.5px;
    margin: 0;
}
.kb-drawer {
    background: var(--ds-panel-bg);
    border: 1px solid var(--ds-ai-border);
    border-radius: 8px;
    display: flex;
    flex-direction: column;
    min-height: 560px;
    min-width: 0;
}
.kb-drawer .dh {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 12px;
    border-block-end: 1px solid var(--ds-border-soft);
}
.kb-drawer .dh b {
    color: var(--ds-ai-fg);
    font-size: 12.5px;
}
.kb-drawer .dh .x {
    margin-inline-start: auto;
    width: 28px;
    height: 28px;
    border: 1px solid var(--ds-border);
    border-radius: 6px;
    display: grid;
    place-items: center;
    font-size: 12px;
    background: var(--ds-panel-bg);
    cursor: pointer;
}
.kb-drawer .tools {
    display: grid;
    gap: 6px;
    padding: 10px 12px;
    border-block-end: 1px solid var(--ds-border-soft);
}
.kb-drawer .tool {
    display: grid;
    gap: 4px;
    min-height: 40px;
    padding: 6px 10px;
    border: 1px solid var(--ds-border);
    border-radius: 8px;
    font-size: 12px;
    text-align: start;
    background: var(--ds-panel-bg);
    color: var(--ds-text-primary);
    font-family: inherit;
    cursor: pointer;
}
.kb-drawer .tool small {
    color: var(--ds-text-muted);
    font-size: 11px;
    display: block;
}
.kb-drawer .tool.on {
    border-color: var(--ds-ai-edge);
    background: var(--ds-ai-bg);
}
.kb-drawer .tool.off {
    color: var(--ds-text-muted);
    cursor: default;
}
.kb-drawer .tool.dashed {
    border-style: dashed;
    cursor: default;
}
.kb-drawer .res {
    padding: 10px 12px;
    display: grid;
    gap: 8px;
}
.kb-drawer .foot {
    margin-top: auto;
    padding: 10px 12px;
    border-block-start: 1px solid var(--ds-border-soft);
    font-size: 11px;
    color: var(--ds-text-muted);
}
.kb-aicard {
    background: var(--ds-ai-bg);
    border: 1px solid var(--ds-ai-border);
    border-inline-start: 3px solid var(--ds-ai-edge);
    border-radius: 8px;
    padding: 10px 11px;
}
.kb-aicard .ailabel {
    font-size: 9.5px;
    font-weight: 700;
    color: var(--ds-ai-fg);
    letter-spacing: 0.05em;
    text-transform: uppercase;
    margin-bottom: 5px;
}
.kb-aicard .aitext {
    font-size: 12.5px;
    line-height: 1.55;
    color: var(--ds-text-primary);
}
.kb-aicard .acts {
    display: flex;
    gap: 6px;
    margin-top: 8px;
    flex-wrap: wrap;
}
.kb-banner-inline {
    margin: 0;
}
@media (max-width: 639px) {
    .kb-ed {
        grid-template-columns: 1fr;
        padding: 10px 12px;
    }
    .kb-doc {
        min-height: 0;
    }
    .kb-blocks {
        padding: 4px 12px 12px;
    }
    .kb-blk .bh .mv {
        display: none;
    }
    .kb-tabs2 a {
        min-height: 44px;
    }
    .kb-stats {
        padding: 10px 12px 0;
    }
}
</style>
