<script setup lang="ts">
// ⌘K — the command palette (S07 step 12; FR-23/24/25, B2-S02's mobile
// variant is the same component full-screen). Sections per the B1-S03
// frame: Actions (real doors only), Views, and the search sections —
// Tickets / Customers / Messages — through the SearchProxy (SEC-02's only
// path). ↑↓ move a roving selection, ↵ opens it, Esc closes; the footer
// carries the drawn hints + the FR-23 recent searches.
//
// BR-03 — SCOPE IS INVISIBLE PLUMBING: there is NO scope toggle anywhere in
// this component (or its mobile variant); the proxy injects the viewer's
// Doc 03 scope server-side and the client never sends it. Absence, not a
// lock icon: out-of-scope hits simply do not come back.
//
// FS-03: the proxy answering {unavailable:true} renders "search
// unavailable" INLINE — the Actions and Views sections keep working.
//
// FR-25: a MESSAGE hit opens its thread anchored at that message with the
// match highlighted (the Doc 04 EC-08 anchor window underneath).
import { computed, nextTick, ref, watch } from 'vue';

const props = defineProps<{
    open: boolean;
    views: { key: string; label: string }[];
    savedViews: { id: string; name: string }[];
    recentSearches: string[];
    activeTicket: { number: number | string } | null;
    t: Record<string, string>;
}>();

const emit = defineEmits<{
    (e: 'close'): void;
    (e: 'select-view', key: string): void;
    (e: 'open-thread', conversationId: string): void;
    (e: 'open-message', payload: { conversationId: string; messageId: string }): void;
    (e: 'action', id: string): void;
    (e: 'remember-search', query: string): void;
}>();

type SearchResult = {
    unavailable: boolean;
    tickets: { id: string; number: number | string; conversation_id: string; subject?: string }[];
    messages: { id: string; conversation_id: string; body?: string }[];
    customers: { id: string; display_name: string | null; username?: string | null }[];
};

const query = ref('');
const searchResult = ref<SearchResult | null>(null);
const searchLoading = ref(false);
const input = ref<HTMLInputElement | null>(null);
const selected = ref(0);

// Actions are REAL DOORS only: resolve rides the FR-21 transition door on
// the open thread; density and Away are the workspace's own controls.
const actions = computed(() => {
    const rows: { id: string; label: string }[] = [];
    if (props.activeTicket) {
        rows.push({
            id: 'resolve-active',
            label: (props.t.action_resolve ?? '').replace(
                ':number',
                '#' + String(props.activeTicket.number),
            ),
        });
    }
    rows.push({ id: 'toggle-density', label: props.t.action_toggle_density ?? '' });
    rows.push({ id: 'set-away', label: props.t.action_set_away ?? '' });
    // P8 (S10 countersigned): the shell's one KB nav entry. tickets.view gates the target route
    // (and this whole workspace), so no extra render gate exists to fail closed on.
    rows.push({ id: 'open-kb', label: props.t.action_open_kb ?? '' });
    // P-NAV (S12 countersigned): the shell's one Workflow nav entry — the P8 shape verbatim.
    rows.push({ id: 'open-workflow', label: props.t.action_open_workflow ?? '' });
    return rows;
});

// In-house fuzzy scorer (FR-24 — no wrapper): subsequence match, earlier
// and denser matches score higher.
function fuzzyScore(needle: string, haystack: string): number {
    if (needle === '') return 1;
    const n = needle.toLowerCase();
    const h = haystack.toLowerCase();
    let hi = 0;
    let score = 0;
    let streak = 0;
    for (let ni = 0; ni < n.length; ni++) {
        const found = h.indexOf(n[ni]!, hi);
        if (found === -1) return 0;
        streak = found === hi ? streak + 1 : 0;
        score += 1 + streak - found * 0.01;
        hi = found + 1;
    }
    return score;
}

const actionMatches = computed(() =>
    actions.value
        .map((action) => ({ action, score: fuzzyScore(query.value, action.label) }))
        .filter((row) => row.score > 0)
        .sort((a, b) => b.score - a.score)
        .map((row) => row.action),
);

const viewMatches = computed(() => {
    const all = [
        ...props.views.map((view) => ({ id: view.key, name: view.label, kind: 'view' as const })),
        ...props.savedViews.map((view) => ({
            id: view.id,
            name: view.name,
            kind: 'saved' as const,
        })),
    ];
    return all
        .map((view) => ({ view, score: fuzzyScore(query.value, view.name) }))
        .filter((row) => row.score > 0)
        .sort((a, b) => b.score - a.score)
        .map((row) => row.view);
});

let searchTimer: ReturnType<typeof setTimeout> | null = null;
watch(query, (value) => {
    selected.value = 0;
    if (searchTimer) clearTimeout(searchTimer);
    if (value.trim().length < 2) {
        searchResult.value = null;
        return;
    }
    searchTimer = setTimeout(() => void runSearch(value), 250);
});

async function runSearch(value: string): Promise<void> {
    searchLoading.value = true;
    try {
        // SEC-02: the request is the QUERY STRING ALONE — no scope, no
        // filter, no index. The proxy injects the viewer's scope.
        const response = await fetch('/inbox/search?q=' + encodeURIComponent(value), {
            headers: { Accept: 'application/json' },
        });
        searchResult.value = response.ok
            ? ((await response.json()) as SearchResult)
            : { unavailable: true, tickets: [], messages: [], customers: [] };
        if (response.ok) emit('remember-search', value.trim());
    } catch {
        searchResult.value = { unavailable: true, tickets: [], messages: [], customers: [] };
    } finally {
        searchLoading.value = false;
    }
}

// ── The roving selection (↑↓/↵) over every openable row, in render order ──
type PaletteRow =
    | { kind: 'action'; id: string }
    | { kind: 'view'; id: string; saved: boolean }
    | { kind: 'ticket'; conversationId: string }
    | { kind: 'message'; conversationId: string; messageId: string };

const flatRows = computed<PaletteRow[]>(() => {
    const rows: PaletteRow[] = [];
    for (const action of actionMatches.value) rows.push({ kind: 'action', id: action.id });
    for (const view of viewMatches.value)
        rows.push({ kind: 'view', id: view.id, saved: view.kind === 'saved' });
    if (searchResult.value && !searchResult.value.unavailable) {
        for (const ticket of searchResult.value.tickets)
            rows.push({ kind: 'ticket', conversationId: ticket.conversation_id });
        for (const message of searchResult.value.messages)
            rows.push({
                kind: 'message',
                conversationId: message.conversation_id,
                messageId: message.id,
            });
    }
    return rows;
});

function openRow(row: PaletteRow): void {
    switch (row.kind) {
        case 'action':
            emit('action', row.id);
            break;
        case 'view':
            emit('select-view', row.id);
            break;
        case 'ticket':
            emit('open-thread', row.conversationId);
            break;
        case 'message':
            emit('open-message', { conversationId: row.conversationId, messageId: row.messageId });
            break;
    }
}

function rowIndex(kind: PaletteRow['kind'], key: string): number {
    return flatRows.value.findIndex(
        (row) =>
            row.kind === kind &&
            (('id' in row && row.id === key) ||
                ('messageId' in row && row.messageId === key) ||
                (row.kind === 'ticket' && row.conversationId === key)),
    );
}

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
        emit('close');
    } else if (event.key === 'ArrowDown') {
        event.preventDefault();
        selected.value = Math.min(selected.value + 1, flatRows.value.length - 1);
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        selected.value = Math.max(selected.value - 1, 0);
    } else if (event.key === 'Enter') {
        event.preventDefault();
        const row = flatRows.value[selected.value];
        if (row) openRow(row);
    }
}

watch(
    () => props.open,
    async (isOpen) => {
        if (isOpen) {
            query.value = '';
            searchResult.value = null;
            selected.value = 0;
            await nextTick();
            input.value?.focus();
        }
    },
);

// §14: bidi isolation on mixed-direction snippets rides the CSS
// unicode-bidi in the row style; the highlight <mark> is built from the
// query match, plain-text only.
function highlight(body: string): { pre: string; match: string; post: string } {
    const q = query.value.trim();
    const at = body.toLowerCase().indexOf(q.toLowerCase());
    if (q === '' || at === -1) return { pre: body, match: '', post: '' };
    return {
        pre: body.slice(0, at),
        match: body.slice(at, at + q.length),
        post: body.slice(at + q.length),
    };
}
</script>

<template>
    <div
        v-if="open"
        data-testid="command-palette"
        role="dialog"
        aria-modal="true"
        :aria-label="t.title"
        class="fixed inset-0 z-50 flex items-start justify-center bg-black/40 p-4 pt-[10vh]"
        @click.self="emit('close')"
        @keydown="onKeydown"
    >
        <div
            class="flex max-h-[70vh] w-full max-w-xl flex-col overflow-hidden rounded-xl border shadow-2xl"
            :style="{ background: 'var(--ds-panel-bg)', borderColor: 'var(--ds-border)' }"
        >
            <div
                class="flex items-center gap-2 border-b px-4 py-3"
                :style="{ borderColor: 'var(--ds-border-soft)' }"
            >
                <span aria-hidden="true" :style="{ color: 'var(--ds-text-muted)' }">⌕</span>
                <input
                    ref="input"
                    v-model="query"
                    data-testid="palette-input"
                    type="text"
                    class="min-w-0 flex-1 bg-transparent text-sm outline-none"
                    :style="{ color: 'var(--ds-text-primary)' }"
                    :placeholder="t.placeholder"
                    :aria-label="t.placeholder"
                />
                <kbd
                    class="rounded border px-1.5 py-0.5 text-[10px]"
                    :style="{ borderColor: 'var(--ds-border-soft)', color: 'var(--ds-text-faint)' }"
                    aria-hidden="true"
                    >Esc</kbd
                >
            </div>

            <div class="min-h-0 flex-1 overflow-y-auto">
                <!-- Actions -->
                <section v-if="actionMatches.length" data-testid="palette-actions">
                    <p
                        class="px-4 pb-1 pt-2 text-[10px] font-semibold uppercase tracking-wide"
                        :style="{ color: 'var(--ds-text-label)' }"
                    >
                        {{ t.section_actions }}
                    </p>
                    <button
                        v-for="action in actionMatches"
                        :key="action.id"
                        type="button"
                        :data-action="action.id"
                        class="block w-full px-4 py-2 text-start text-sm"
                        :style="{
                            color: 'var(--ds-text-primary)',
                            background:
                                rowIndex('action', action.id) === selected
                                    ? 'var(--ds-panel-soft)'
                                    : 'transparent',
                        }"
                        @click="emit('action', action.id)"
                    >
                        {{ action.label }}
                    </button>
                </section>

                <!-- Views -->
                <section v-if="viewMatches.length" data-testid="palette-views">
                    <p
                        class="px-4 pb-1 pt-2 text-[10px] font-semibold uppercase tracking-wide"
                        :style="{ color: 'var(--ds-text-label)' }"
                    >
                        {{ t.section_views }}
                    </p>
                    <button
                        v-for="view in viewMatches"
                        :key="view.kind + view.id"
                        type="button"
                        :data-palette-view="view.id"
                        class="block w-full px-4 py-2 text-start text-sm"
                        :style="{
                            color: 'var(--ds-text-primary)',
                            background:
                                rowIndex('view', view.id) === selected
                                    ? 'var(--ds-panel-soft)'
                                    : 'transparent',
                        }"
                        @click="emit('select-view', view.id)"
                    >
                        {{ view.name }}
                    </button>
                </section>

                <!-- Search -->
                <section v-if="query.trim().length >= 2" data-testid="palette-search">
                    <p
                        v-if="searchLoading"
                        class="px-4 py-2 text-xs"
                        :style="{ color: 'var(--ds-text-muted)' }"
                    >
                        {{ t.searching }}
                    </p>
                    <p
                        v-else-if="searchResult?.unavailable"
                        data-testid="search-unavailable"
                        class="px-4 py-2 text-xs"
                        :style="{ color: 'var(--ds-text-muted)' }"
                    >
                        {{ t.search_unavailable }}
                    </p>
                    <template v-else-if="searchResult">
                        <template v-if="searchResult.tickets.length">
                            <p
                                class="px-4 pb-1 pt-2 text-[10px] font-semibold uppercase tracking-wide"
                                :style="{ color: 'var(--ds-text-label)' }"
                            >
                                {{ t.section_tickets }}
                            </p>
                            <button
                                v-for="ticket in searchResult.tickets"
                                :key="ticket.id"
                                type="button"
                                data-role="search-ticket"
                                class="block w-full px-4 py-2 text-start text-sm"
                                :style="{
                                    color: 'var(--ds-text-primary)',
                                    background:
                                        rowIndex('ticket', ticket.conversation_id) === selected
                                            ? 'var(--ds-panel-soft)'
                                            : 'transparent',
                                }"
                                @click="emit('open-thread', ticket.conversation_id)"
                            >
                                #{{ ticket.number }}
                                <span :style="{ color: 'var(--ds-text-muted)' }">{{
                                    ticket.subject
                                }}</span>
                            </button>
                        </template>

                        <template v-if="searchResult.customers.length">
                            <p
                                class="px-4 pb-1 pt-2 text-[10px] font-semibold uppercase tracking-wide"
                                :style="{ color: 'var(--ds-text-label)' }"
                            >
                                {{ t.section_customers }}
                            </p>
                            <p
                                v-for="customer in searchResult.customers"
                                :key="customer.id"
                                data-role="search-customer"
                                class="px-4 py-2 text-sm"
                                :style="{ color: 'var(--ds-text-primary)', unicodeBidi: 'isolate' }"
                            >
                                {{ customer.display_name }}
                                <span
                                    v-if="customer.username"
                                    class="text-[11.5px]"
                                    :style="{ color: 'var(--ds-text-muted)' }"
                                    >@{{ customer.username }}</span
                                >
                            </p>
                        </template>

                        <template v-if="searchResult.messages.length">
                            <p
                                class="px-4 pb-1 pt-2 text-[10px] font-semibold uppercase tracking-wide"
                                :style="{ color: 'var(--ds-text-label)' }"
                            >
                                {{ t.section_messages }}
                            </p>
                            <button
                                v-for="message in searchResult.messages"
                                :key="message.id"
                                type="button"
                                data-role="search-message"
                                class="block w-full px-4 py-2 text-start text-[12.5px] leading-relaxed"
                                :style="{
                                    color: 'var(--ds-text-primary)',
                                    unicodeBidi: 'isolate',
                                    background:
                                        rowIndex('message', message.id) === selected
                                            ? 'var(--ds-panel-soft)'
                                            : 'transparent',
                                }"
                                @click="
                                    emit('open-message', {
                                        conversationId: message.conversation_id,
                                        messageId: message.id,
                                    })
                                "
                            >
                                <template v-if="message.body">
                                    “…{{ highlight(message.body).pre.slice(-40)
                                    }}<mark
                                        :style="{
                                            background: 'var(--ds-warn-bg)',
                                            color: 'var(--ds-text-ink)',
                                        }"
                                        >{{ highlight(message.body).match }}</mark
                                    >{{ highlight(message.body).post.slice(0, 60) }}…”
                                </template>
                            </button>
                        </template>

                        <p
                            v-if="
                                searchResult.tickets.length === 0 &&
                                searchResult.messages.length === 0 &&
                                searchResult.customers.length === 0
                            "
                            data-testid="search-no-hits"
                            class="px-4 py-2 text-xs"
                            :style="{ color: 'var(--ds-text-muted)' }"
                        >
                            {{ t.no_hits }}
                        </p>
                    </template>
                </section>
            </div>

            <!-- The drawn footer: navigation hints + recent searches. -->
            <div
                class="flex items-center gap-3.5 border-t px-4 py-2 text-[10.5px]"
                :style="{
                    borderColor: 'var(--ds-border-soft)',
                    background: 'var(--ds-panel-soft)',
                    color: 'var(--ds-text-muted)',
                }"
            >
                <span><span class="font-mono">↑↓</span> {{ t.hint_move }}</span>
                <span><span class="font-mono">↵</span> {{ t.hint_open }}</span>
                <span
                    v-if="recentSearches.length"
                    data-testid="palette-recent"
                    class="ms-auto truncate"
                    >{{ t.recent_label }}
                    <button
                        v-for="recent in recentSearches.slice(0, 3)"
                        :key="recent"
                        type="button"
                        data-role="recent-search"
                        class="ms-1 underline decoration-dotted"
                        @click="query = recent"
                    >
                        “{{ recent }}”
                    </button>
                </span>
            </div>
        </div>
    </div>
</template>
