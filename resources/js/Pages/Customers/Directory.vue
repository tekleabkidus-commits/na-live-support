<script setup lang="ts">
/**
 * B4-S06 — the customer directory (S08 step 12; Doc 08 FR-20/EC-08/FS-01).
 * Frames: G01 table + filter bar + bulk bar · G02 375 cards · G03 the
 * five-filter bar when the RG verdict is absent (the control does not
 * exist; the door never queried the column; the count matches) · G04 the
 * historical-username match explaining itself · G05 Amharic search · G06
 * the labeled degrade banner · G09 loading placeholders + the honest empty
 * state. The RG filter renders ONLY off the door's own rg_available answer.
 */
import { computed, onMounted, ref, watch } from 'vue';
import DirectoryCard from '../../Components/Customers/DirectoryCard.vue';

type IdentityGlyph = { type: string; value: string; display: string };
export type DirectoryRow = {
    id: string;
    display_name: string | null;
    short_id: string;
    is_shell: boolean;
    identities: IdentityGlyph[];
    language: string | null;
    country: string | null;
    tags: string[];
    blocked: boolean;
    open_tickets: number;
    tickets_count: number;
    satisfaction_avg: number | null;
    satisfaction_count: number;
    last_contact_at: string | null;
    rg_flag?: boolean;
};
type Matched = {
    on: string;
    value: string;
    used_until: string | null;
    current_username: string | null;
} | null;
type SearchHit = {
    id: string;
    display_name: string | null;
    matched: Matched;
};

const props = defineProps<{
    t: Record<string, string>;
    refOptions: { language: Record<string, string>; country: Record<string, string> };
    can: Record<string, boolean>;
}>();

const fill = (template: string | undefined, vars: Record<string, string>): string => {
    let out = template ?? '';
    for (const [key, value] of Object.entries(vars)) out = out.replaceAll(':' + key, value);
    return out;
};
function xsrf(): string {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    return match?.[1] !== undefined ? decodeURIComponent(match[1]) : '';
}

// ── Table state ────────────────────────────────────────────────
const rows = ref<DirectoryRow[]>([]);
const total = ref(0);
const loading = ref(true);
const rgAvailable = ref(false);
const nextCursor = ref<string | null>(null);
// Previous/Next: the stack of cursors that produced the current page.
const cursorTrail = ref<(string | null)[]>([null]);

const filters = ref<{
    has_open: boolean;
    language: string;
    country: string;
    tag: string;
    blocked: string;
    rg: string;
}>({ has_open: false, language: '', country: '', tag: '', blocked: '', rg: '' });

const anyFilter = computed(
    () =>
        filters.value.has_open ||
        filters.value.language !== '' ||
        filters.value.country !== '' ||
        filters.value.tag !== '' ||
        filters.value.blocked !== '' ||
        filters.value.rg !== '',
);

function listUrl(cursor: string | null): string {
    const params = new URLSearchParams();
    if (filters.value.has_open) params.set('has_open', '1');
    if (filters.value.language !== '') params.set('language', filters.value.language);
    if (filters.value.country !== '') params.set('country', filters.value.country);
    if (filters.value.tag !== '') params.set('tag', filters.value.tag);
    if (filters.value.blocked !== '') params.set('blocked', filters.value.blocked);
    // G03: the rg parameter is only ever SENT when the door offered the
    // filter — for everyone else this branch does not run.
    if (rgAvailable.value && filters.value.rg !== '') params.set('rg', filters.value.rg);
    if (cursor !== null) params.set('cursor', cursor);
    const suffix = params.toString();
    return '/api/staff/customers/directory' + (suffix === '' ? '' : '?' + suffix);
}

async function loadPage(cursor: string | null): Promise<void> {
    loading.value = true;
    try {
        const response = await fetch(listUrl(cursor), { headers: { Accept: 'application/json' } });
        if (!response.ok) throw new Error(String(response.status));
        const data = (await response.json()) as {
            items: DirectoryRow[];
            total: number;
            next_cursor: string | null;
            filters: { rg_available: boolean };
        };
        rows.value = data.items;
        total.value = data.total;
        nextCursor.value = data.next_cursor;
        rgAvailable.value = data.filters.rg_available;
        selection.value = new Set();
    } finally {
        loading.value = false;
    }
}

function nextPage(): void {
    if (nextCursor.value === null) return;
    cursorTrail.value.push(nextCursor.value);
    void loadPage(nextCursor.value);
}
function previousPage(): void {
    if (cursorTrail.value.length < 2) return;
    cursorTrail.value.pop();
    void loadPage(cursorTrail.value[cursorTrail.value.length - 1] ?? null);
}
function reload(): void {
    cursorTrail.value = [null];
    void loadPage(null);
}
watch(filters, reload, { deep: true });
onMounted(reload);

function clearFilters(): void {
    filters.value = { has_open: false, language: '', country: '', tag: '', blocked: '', rg: '' };
}

// ── Search (the step-8 door; FS-01's degrade arrives labeled) ──
const query = ref('');
const searching = ref(false);
const searchHits = ref<SearchHit[] | null>(null);
const degraded = ref(false);
let debounce: ReturnType<typeof setTimeout> | null = null;

watch(query, () => {
    if (debounce !== null) clearTimeout(debounce);
    if (query.value.trim() === '') {
        searchHits.value = null;
        degraded.value = false;
        return;
    }
    debounce = setTimeout(() => void runSearch(), 250);
});

async function runSearch(): Promise<void> {
    const q = query.value.trim();
    if (q === '') return;
    searching.value = true;
    try {
        const response = await fetch('/api/staff/customers/search?q=' + encodeURIComponent(q), {
            headers: { Accept: 'application/json' },
        });
        if (!response.ok) throw new Error(String(response.status));
        const data = (await response.json()) as { degraded: boolean; hits: SearchHit[] };
        if (query.value.trim() !== q) return; // a newer query owns the box
        searchHits.value = data.hits;
        degraded.value = data.degraded;
    } finally {
        searching.value = false;
    }
}
function clearSearch(): void {
    query.value = '';
}

function hitName(hit: SearchHit): string {
    if (hit.display_name !== null && hit.display_name !== '') return hit.display_name;
    return (
        fill(props.t.no_name, { short: hit.id.slice(-4) })
            .split('—')[0]
            ?.trim() ?? hit.id.slice(-4)
    );
}
function matchedLine(hit: SearchHit): string | null {
    const matched = hit.matched;
    if (matched === null) return null;
    if (matched.on === 'username_history') {
        const now = matched.current_username ?? '';
        if (matched.used_until !== null) {
            return fill(props.t.matched_history, {
                old: '@' + matched.value.replace(/^@/, ''),
                date: matched.used_until.slice(0, 10),
                now: '@' + now.replace(/^@/, ''),
            });
        }
        return fill(props.t.matched_history_no_date, {
            old: '@' + matched.value.replace(/^@/, ''),
            now: '@' + now.replace(/^@/, ''),
        });
    }
    if (matched.on === 'name') return props.t.matched_on_name ?? null;
    if (matched.on === 'phone') return props.t.matched_on_phone ?? null;
    if (matched.on === 'email') return props.t.matched_on_email ?? null;
    return null;
}

// ── EC-08: same-name rows disambiguate visibly ────────────────
const nameCollisions = computed(() => {
    const counts = new Map<string, number>();
    for (const row of rows.value) {
        const name = row.display_name ?? '';
        if (name !== '') counts.set(name, (counts.get(name) ?? 0) + 1);
    }
    return counts;
});
function showShortId(row: DirectoryRow): boolean {
    return row.display_name === null || (nameCollisions.value.get(row.display_name ?? '') ?? 0) > 1;
}
function rowName(row: DirectoryRow): string {
    if (row.display_name !== null && row.display_name !== '') return row.display_name;
    return fill(props.t.no_name, { short: row.short_id }).split('—')[0]?.trim() ?? row.short_id;
}

// ── Bulk bar: tags and export ONLY (G01's sentence is the law) ─
const selection = ref<Set<string>>(new Set());
const bulkTag = ref('');
const bulkNotice = ref('');
function toggleRow(id: string): void {
    const next = new Set(selection.value);
    if (next.has(id)) next.delete(id);
    else next.add(id);
    selection.value = next;
}
function toggleAll(): void {
    selection.value =
        selection.value.size === rows.value.length
            ? new Set()
            : new Set(rows.value.map((row) => row.id));
}
async function applyBulkTag(): Promise<void> {
    const tag = bulkTag.value.trim();
    if (tag === '') return;
    for (const row of rows.value) {
        if (!selection.value.has(row.id)) continue;
        if (row.tags.includes(tag)) continue;
        await fetch(`/api/staff/customers/${row.id}/profile`, {
            method: 'PATCH',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': xsrf(),
            },
            body: JSON.stringify({ tags: [...row.tags, tag] }),
        });
    }
    bulkNotice.value = fill(props.t.tag_added, { count: String(selection.value.size) });
    bulkTag.value = '';
    reload();
}
async function bulkExport(): Promise<void> {
    for (const id of selection.value) {
        await fetch(`/api/staff/customers/${id}/export`, {
            method: 'POST',
            headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() },
        });
    }
    bulkNotice.value = fill(props.t.exports_started, { count: String(selection.value.size) });
    selection.value = new Set();
}

function ratingCell(row: DirectoryRow): string {
    return row.satisfaction_count > 0
        ? String(row.satisfaction_avg ?? '')
        : (props.t.no_rating ?? '');
}
function relative(stamp: string | null): string {
    if (stamp === null) return '—';
    return stamp.slice(0, 10);
}
</script>

<template>
    <main
        class="mx-auto max-w-6xl p-4 text-start"
        :style="{ color: 'var(--ds-text-primary)' }"
        data-testid="directory-page"
    >
        <header class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
            <h1 class="text-lg font-semibold">{{ t.title }}</h1>
            <p
                class="text-sm"
                :style="{ color: 'var(--ds-text-muted)' }"
                data-testid="directory-total"
            >
                {{ fill(t.in_scope, { count: String(total) }) }}
            </p>
        </header>

        <!-- ── Search (G04/G05/G06) ─────────────────────────────── -->
        <div class="mt-3">
            <label class="block">
                <span class="sr-only">{{ t.search }}</span>
                <input
                    v-model="query"
                    type="search"
                    data-testid="directory-search"
                    class="w-full rounded border px-3 py-2 text-sm sm:max-w-md"
                    :style="{
                        borderColor: 'var(--ds-border-strong)',
                        background: 'var(--ds-panel-bg)',
                        color: 'var(--ds-text-primary)',
                    }"
                    :placeholder="t.search"
                />
            </label>
            <p class="mt-1 text-xs" :style="{ color: 'var(--ds-text-muted)' }">
                {{ t.search_hint }}
            </p>
        </div>

        <!-- G06: the index is down — say what is missing, not an apology. -->
        <div
            v-if="degraded && searchHits !== null"
            role="note"
            data-testid="directory-degraded"
            class="mt-2 rounded border px-3 py-2 text-xs"
            :style="{ borderColor: 'var(--ds-border-strong)', color: 'var(--ds-text-primary)' }"
        >
            <p>{{ t.degraded_banner }}</p>
            <p class="mt-0.5" :style="{ color: 'var(--ds-text-muted)' }">
                {{ t.degraded_missing }}
            </p>
        </div>

        <!-- ── Search results replace the table while a query stands ── -->
        <section v-if="searchHits !== null" class="mt-3" data-testid="directory-results">
            <p class="text-xs" :style="{ color: 'var(--ds-text-muted)' }">
                {{
                    fill(searchHits.length === 1 ? t.result_one : t.result_many, {
                        count: String(searchHits.length),
                    })
                }}
            </p>
            <ul
                v-if="searchHits.length > 0"
                class="mt-1 divide-y"
                :style="{ borderColor: 'var(--ds-border-soft)' }"
            >
                <li v-for="hit in searchHits" :key="hit.id" class="py-2">
                    <a
                        :href="`/customers/${hit.id}`"
                        class="text-sm underline"
                        :style="{ color: 'var(--ds-accent)' }"
                        >{{ hitName(hit) }}</a
                    >
                    <!-- G04: the result says WHICH name matched. -->
                    <p
                        v-if="matchedLine(hit) !== null"
                        class="text-xs"
                        :style="{ color: 'var(--ds-text-muted)' }"
                        data-testid="matched-line"
                    >
                        {{ matchedLine(hit) }}
                    </p>
                </li>
            </ul>
            <!-- G09: nothing found — say what was searched. -->
            <div v-else-if="!searching" class="mt-3" data-testid="directory-search-empty">
                <p class="text-sm font-medium">{{ fill(t.none_match, { query: query.trim() }) }}</p>
                <p class="mt-1 text-xs" :style="{ color: 'var(--ds-text-muted)' }">
                    {{ fill(t.searched_line, { total: String(total) }) }}
                </p>
                <button
                    type="button"
                    class="mt-2 rounded border px-3 py-1.5 text-xs"
                    :style="{ borderColor: 'var(--ds-border-strong)' }"
                    data-testid="directory-clear-search"
                    @click="clearSearch"
                >
                    {{ t.clear_search }}
                </button>
            </div>
        </section>

        <template v-else>
            <!-- ── Filter bar (G01; G03 = the absent sixth control) ── -->
            <div class="mt-3 flex flex-wrap items-center gap-2" data-testid="directory-filters">
                <span class="text-xs font-medium" :style="{ color: 'var(--ds-text-label)' }">{{
                    t.filter
                }}</span>
                <button
                    type="button"
                    data-testid="filter-has-open"
                    class="rounded-full border px-2.5 py-1 text-xs"
                    :style="{
                        borderColor: 'var(--ds-border-strong)',
                        background: filters.has_open ? 'var(--ds-accent-soft)' : 'transparent',
                    }"
                    :aria-pressed="filters.has_open"
                    @click="filters.has_open = !filters.has_open"
                >
                    {{ t.has_open }}<template v-if="filters.has_open"> ×</template>
                </button>
                <label class="text-xs">
                    <span class="sr-only">{{ t.language }}</span>
                    <select
                        v-model="filters.language"
                        data-testid="filter-language"
                        class="rounded border px-2 py-1 text-xs"
                        :style="{
                            borderColor: 'var(--ds-border-strong)',
                            background: 'var(--ds-panel-bg)',
                            color: 'var(--ds-text-primary)',
                        }"
                    >
                        <option value="">{{ t.language }}</option>
                        <option
                            v-for="(label, code) in refOptions.language"
                            :key="code"
                            :value="code"
                        >
                            {{ label }}
                        </option>
                    </select>
                </label>
                <label class="text-xs">
                    <span class="sr-only">{{ t.country }}</span>
                    <select
                        v-model="filters.country"
                        data-testid="filter-country"
                        class="rounded border px-2 py-1 text-xs"
                        :style="{
                            borderColor: 'var(--ds-border-strong)',
                            background: 'var(--ds-panel-bg)',
                            color: 'var(--ds-text-primary)',
                        }"
                    >
                        <option value="">{{ t.country }}</option>
                        <option
                            v-for="(label, code) in refOptions.country"
                            :key="code"
                            :value="code"
                        >
                            {{ label }}
                        </option>
                    </select>
                </label>
                <label class="text-xs">
                    <span class="sr-only">{{ t.tag }}</span>
                    <input
                        v-model.lazy="filters.tag"
                        data-testid="filter-tag"
                        class="w-28 rounded border px-2 py-1 text-xs"
                        :style="{
                            borderColor: 'var(--ds-border-strong)',
                            background: 'var(--ds-panel-bg)',
                            color: 'var(--ds-text-primary)',
                        }"
                        :placeholder="t.tag"
                    />
                </label>
                <label class="text-xs">
                    <span class="sr-only">{{ t.blocked }}</span>
                    <select
                        v-model="filters.blocked"
                        data-testid="filter-blocked"
                        class="rounded border px-2 py-1 text-xs"
                        :style="{
                            borderColor: 'var(--ds-border-strong)',
                            background: 'var(--ds-panel-bg)',
                            color: 'var(--ds-text-primary)',
                        }"
                    >
                        <option value="">{{ t.blocked }}</option>
                        <option value="1">{{ t.yes }}</option>
                        <option value="0">{{ t.no }}</option>
                    </select>
                </label>
                <!-- G03: this control EXISTS only when the door said so —
                     no greyed chip, no gap where one was. -->
                <label v-if="rgAvailable" class="text-xs">
                    <span class="sr-only">{{ t.rg }}</span>
                    <select
                        v-model="filters.rg"
                        data-testid="filter-rg"
                        class="rounded border px-2 py-1 text-xs"
                        :style="{
                            borderColor: 'var(--ds-border-strong)',
                            background: 'var(--ds-panel-bg)',
                            color: 'var(--ds-text-primary)',
                        }"
                    >
                        <option value="">{{ t.rg }}</option>
                        <option value="1">{{ t.yes }}</option>
                        <option value="0">{{ t.no }}</option>
                    </select>
                </label>
                <button
                    v-if="anyFilter"
                    type="button"
                    data-testid="filter-clear-all"
                    class="text-xs underline"
                    :style="{ color: 'var(--ds-accent)' }"
                    @click="clearFilters"
                >
                    {{ t.clear_all }}
                </button>
            </div>

            <!-- ── Bulk bar (tags + export ONLY, on purpose) ────────── -->
            <div
                v-if="selection.size > 0"
                class="mt-2 flex flex-wrap items-center gap-2 rounded border px-3 py-2"
                :style="{ borderColor: 'var(--ds-border-strong)' }"
                data-testid="directory-bulk-bar"
            >
                <span class="text-xs font-medium">{{
                    fill(t.selected, { count: String(selection.size) })
                }}</span>
                <template v-if="can.edit">
                    <label class="text-xs">
                        <span class="sr-only">{{ t.add_tag }}</span>
                        <input
                            v-model="bulkTag"
                            data-testid="bulk-tag-input"
                            class="w-28 rounded border px-2 py-1 text-xs"
                            :style="{
                                borderColor: 'var(--ds-border-strong)',
                                background: 'var(--ds-panel-bg)',
                                color: 'var(--ds-text-primary)',
                            }"
                            :placeholder="t.add_tag"
                        />
                    </label>
                    <button
                        type="button"
                        data-testid="bulk-tag-apply"
                        class="rounded border px-2.5 py-1 text-xs"
                        :style="{ borderColor: 'var(--ds-border-strong)' }"
                        :disabled="bulkTag.trim() === ''"
                        @click="applyBulkTag"
                    >
                        {{ t.add_tag }}
                    </button>
                </template>
                <button
                    v-if="can.export"
                    type="button"
                    data-testid="bulk-export"
                    class="rounded border px-2.5 py-1 text-xs"
                    :style="{ borderColor: 'var(--ds-border-strong)' }"
                    @click="bulkExport"
                >
                    {{ t.export }}
                </button>
                <span class="text-xs" :style="{ color: 'var(--ds-text-muted)' }">{{
                    t.bulk_note
                }}</span>
                <button
                    type="button"
                    data-testid="bulk-clear"
                    class="text-xs underline"
                    :style="{ color: 'var(--ds-accent)' }"
                    @click="selection = new Set()"
                >
                    {{ t.clear }}
                </button>
            </div>
            <p
                v-if="bulkNotice !== ''"
                role="status"
                class="mt-1 text-xs"
                :style="{ color: 'var(--ds-text-muted)' }"
            >
                {{ bulkNotice }}
            </p>

            <!-- ── G09 loading: static placeholders, no shimmer (§9) ── -->
            <div v-if="loading" class="mt-3" data-testid="directory-loading" aria-hidden="true">
                <div
                    v-for="i in 5"
                    :key="i"
                    class="mb-2 h-8 rounded"
                    :style="{ background: 'var(--ds-panel-bg)' }"
                ></div>
                <p class="sr-only">{{ t.loading }}</p>
            </div>

            <!-- ── The table (≥sm) ──────────────────────────────────── -->
            <div v-else class="mt-3">
                <div class="hidden overflow-x-auto sm:block">
                    <table class="w-full text-sm" data-testid="directory-table">
                        <thead>
                            <tr
                                class="text-start text-xs"
                                :style="{ color: 'var(--ds-text-label)' }"
                            >
                                <th class="w-8 py-1.5">
                                    <input
                                        type="checkbox"
                                        data-testid="select-all"
                                        :checked="selection.size === rows.length && rows.length > 0"
                                        :aria-label="t.select_all"
                                        @change="toggleAll"
                                    />
                                </th>
                                <th class="py-1.5 text-start font-medium">{{ t.col_name }}</th>
                                <th class="py-1.5 text-start font-medium">
                                    {{ t.col_identities }}
                                </th>
                                <th class="py-1.5 text-start font-medium">
                                    {{ t.col_last_contact }}
                                </th>
                                <th class="py-1.5 text-start font-medium">{{ t.col_open }}</th>
                                <th class="py-1.5 text-start font-medium">{{ t.col_rating }}</th>
                                <th class="py-1.5 text-start font-medium">{{ t.col_tags }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in rows"
                                :key="row.id"
                                class="border-t"
                                :style="{ borderColor: 'var(--ds-border-soft)' }"
                                :data-testid="`directory-row-${row.id}`"
                            >
                                <td class="py-2">
                                    <input
                                        type="checkbox"
                                        :checked="selection.has(row.id)"
                                        :aria-label="fill(t.select_row, { name: rowName(row) })"
                                        @change="toggleRow(row.id)"
                                    />
                                </td>
                                <td class="py-2">
                                    <a
                                        :href="`/customers/${row.id}`"
                                        class="underline"
                                        :style="{ color: 'var(--ds-accent)' }"
                                        :aria-label="fill(t.open_profile, { name: rowName(row) })"
                                        >{{ rowName(row) }}</a
                                    >
                                    <!-- EC-08: collisions disambiguate in place. -->
                                    <span
                                        v-if="showShortId(row)"
                                        class="ms-1 text-xs"
                                        :style="{ color: 'var(--ds-text-muted)' }"
                                        :title="fill(t.short_id_hint, { short: row.short_id })"
                                        data-testid="row-short-id"
                                        >#{{ row.short_id }}</span
                                    >
                                    <span
                                        v-if="row.is_shell"
                                        class="ms-1 rounded border px-1 text-xs"
                                        :style="{
                                            borderColor: 'var(--ds-border-strong)',
                                            color: 'var(--ds-text-muted)',
                                        }"
                                        >{{ t.shell_badge }}</span
                                    >
                                    <span
                                        v-if="row.blocked"
                                        class="ms-1 rounded border px-1 text-xs"
                                        :style="{
                                            borderColor: 'var(--ds-danger-strong)',
                                            color: 'var(--ds-danger-strong)',
                                        }"
                                        data-testid="row-blocked-badge"
                                        >{{ t.blocked_badge }}</span
                                    >
                                </td>
                                <td class="py-2 text-xs" :style="{ color: 'var(--ds-text-muted)' }">
                                    <span
                                        v-for="identity in row.identities"
                                        :key="identity.type + identity.value"
                                        class="me-2"
                                        >{{ identity.display }}</span
                                    >
                                </td>
                                <td class="py-2 text-xs">{{ relative(row.last_contact_at) }}</td>
                                <td class="py-2 text-xs">{{ row.open_tickets }}</td>
                                <td class="py-2 text-xs">
                                    {{ ratingCell(row) }}
                                </td>
                                <td class="py-2 text-xs">
                                    <span
                                        v-for="tag in row.tags"
                                        :key="tag"
                                        class="me-1 rounded border px-1"
                                        :style="{ borderColor: 'var(--ds-border-soft)' }"
                                        >{{ tag }}</span
                                    >
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- ── G02: at 375 the table becomes cards ───────────── -->
                <ul class="sm:hidden" data-testid="directory-cards">
                    <DirectoryCard
                        v-for="row in rows"
                        :key="row.id"
                        :row="row"
                        :t="t"
                        :name="rowName(row)"
                        :show-short-id="showShortId(row)"
                    />
                </ul>

                <div class="mt-3 flex items-center gap-3 text-xs">
                    <p :style="{ color: 'var(--ds-text-muted)' }">
                        {{ fill(t.showing, { shown: String(rows.length), total: String(total) }) }}
                    </p>
                    <button
                        type="button"
                        data-testid="directory-previous"
                        class="rounded border px-2.5 py-1"
                        :style="{ borderColor: 'var(--ds-border-strong)' }"
                        :disabled="cursorTrail.length < 2"
                        @click="previousPage"
                    >
                        {{ t.previous }}
                    </button>
                    <button
                        type="button"
                        data-testid="directory-next"
                        class="rounded border px-2.5 py-1"
                        :style="{ borderColor: 'var(--ds-border-strong)' }"
                        :disabled="nextCursor === null"
                        @click="nextPage"
                    >
                        {{ t.next }}
                    </button>
                </div>
            </div>
        </template>
    </main>
</template>
