<script setup lang="ts">
// R2's contents (S07 step 9): header with the FR-12 total + FR-10 sort
// override (persisted per view via prefs.sort_overrides), the FR-11 live
// list (pointer-hold + the "list updated — resort" affordance + §13 polite
// announcements), FR-12 cursor "load more" + scroll restoration (the pane
// remembers scrollTop per view in sessionStorage and restores it on
// back-navigation), FR-13 selection with shift-range and the T-05
// "select all in view" mint, EC-02's tray, EC-09's queue-removed toast.
//
// TEST HOOK: window.__inboxList drives SCRIPTED DELTA STREAMS (AC-03's
// wording) and the EC-09 removal — the same store methods the realtime
// consumer calls; the harness scripts what Reverb will feed.
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { runCommand } from '../../../lib/commands';
import { useInboxConnection } from '../../../stores/inbox/connection';
import { useInboxPrefs } from '../../../stores/inbox/prefs';
import { useLiveList, type ListRow } from '../../../stores/inbox/liveList';
import BulkBar from './BulkBar.vue';
import FilterBar from './FilterBar.vue';
import MobileActionsSheet from './MobileActionsSheet.vue';
import Row from './Row.vue';

const props = defineProps<{
    mergeAllowed?: boolean;
    view: string;
    mobile?: boolean;
    userId?: string;
    can?: { assignSelf: boolean; assign: boolean; statusChange: boolean; priority: boolean };
    t: {
        list: Record<string, string>;
        row: Record<string, string>;
        bulk: Record<string, string>;
        filter_bar: Record<string, string>;
        mobile: Record<string, string>;
    };
}>();

const emit = defineEmits<{
    (e: 'merge-selection'): void;
    (
        e: 'open-ticket',
        payload: { ticketId: string; conversationId: string; number: number | string | null },
    ): void;
    (e: 'fallback-view'): void;
}>();

const list = useLiveList();
const prefs = useInboxPrefs();
const connection = useInboxConnection();

const loading = ref(false);
const announcement = ref('');
const lastSelectedId = ref<string | null>(null);
const scroller = ref<HTMLElement | null>(null);

async function fetchPage(cursor: string | null): Promise<void> {
    loading.value = true;
    try {
        const sort = currentSort.value;
        // FR-06 (step 12): a 'saved:{id}' key renders the saved view through
        // the same door — the ?saved= branch ListController shipped at step 9.
        const params = props.view.startsWith('saved:')
            ? new URLSearchParams({ saved: props.view.slice(6) })
            : new URLSearchParams({ view: props.view });
        // FR-24: the bar's filters compose WITHIN the view (the server ANDs).
        if (activeFilters.value) {
            params.set('filters', JSON.stringify(activeFilters.value));
        }
        if (cursor) {
            params.set('cursor', cursor);
        }
        if (sort) {
            params.set('sort', sort);
        }
        const response = await fetch('/inbox/list?' + params.toString(), {
            headers: { Accept: 'application/json' },
        });
        if (!response.ok) {
            return;
        }
        const body = (await response.json()) as {
            rows: ListRow[];
            next_cursor: string | null;
            total: number;
        };
        if (cursor) {
            list.appendPage(body.rows, body.next_cursor);
        } else {
            list.hydrate(props.view, body.rows, body.total, body.next_cursor);
            requestAnimationFrame(restoreScroll); // after the rows paint
        }
    } finally {
        loading.value = false;
    }
}

// FR-24: the in-view filter bar's state — per pane, reset on view change.
const activeFilters = ref<Record<string, unknown> | null>(null);
function onFilters(filters: Record<string, unknown> | null): void {
    activeFilters.value = filters;
    void fetchPage(null);
}

// FR-10: the override persists per view (prefs.sort_overrides — FR-08's blob).
const currentSort = ref<string>(prefs.sortOverrides[props.view] ?? '');
function setSort(sort: string): void {
    currentSort.value = sort;
    prefs.setSortOverride(props.view, sort);
    void fetchPage(null);
}

// FR-12: scroll restoration on back-navigation — position kept per view.
function rememberScroll(): void {
    if (scroller.value) {
        sessionStorage.setItem('inbox-scroll-' + props.view, String(scroller.value.scrollTop));
    }
}
function restoreScroll(): void {
    const saved = sessionStorage.getItem('inbox-scroll-' + props.view);
    if (saved && scroller.value) {
        scroller.value.scrollTop = Number(saved);
    }
}

function onToggleSelect(id: string, shiftKey: boolean): void {
    if (shiftKey && lastSelectedId.value) {
        list.rangeSelect(lastSelectedId.value, id);
    } else {
        list.toggleSelect(id);
    }
    lastSelectedId.value = id;
}

async function selectAllInView(): Promise<void> {
    const xsrf = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    const response = await fetch('/inbox/selections', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-XSRF-TOKEN': xsrf?.[1] ? decodeURIComponent(xsrf[1]) : '',
        },
        body: JSON.stringify({ view_key: props.view }),
    });
    if (response.ok) {
        const body = (await response.json()) as { id: string; count: number };
        list.selectionToken = body; // the SERVER count is the shown truth
        list.selected = [...list.order];
    }
}

// §13: batched changes announce politely, focus is never stolen.
watch(
    () => list.pendingChanges,
    (count) => {
        if (count > 0) {
            announcement.value = (props.t.list.updated_announcement ?? '').replace(
                ':count',
                String(count),
            );
        }
    },
);

// Rail navigation: a new view refetches from the top (its own remembered
// scroll restores via sessionStorage on the next mount of that view).
watch(
    () => props.view,
    (next, previous) => {
        // Remember the OUTGOING view's position before the refetch swaps rows.
        if (scroller.value && previous) {
            sessionStorage.setItem('inbox-scroll-' + previous, String(scroller.value.scrollTop));
        }
        currentSort.value = prefs.sortOverrides[props.view] ?? '';
        activeFilters.value = null; // FR-24: filters are per-view, not sticky
        void fetchPage(null);
    },
);

// FR-29/T-07 (step 13): sync reconciliation and FS-05 recovery refetch the
// active view through the same door — the client applies server truth by
// refetch (T-02's full-row-fetch idiom), never by re-deriving projector
// semantics locally.
watch(
    () => connection.viewRefetchTick,
    () => void fetchPage(null),
);

// EC-09: the current view vanished — toast + fall back to My Open.
watch(
    () => list.removedViewToast,
    (removed) => {
        if (removed) {
            setTimeout(() => {
                list.removedViewToast = false;
                emit('fallback-view');
            }, 2500);
        }
    },
);

onMounted(() => {
    void fetchPage(null);
    // Vue's unmount hooks do not fire on a browser unload — pagehide is the
    // moment back-navigation restoration depends on.
    window.addEventListener('pagehide', rememberScroll);
    // The scripted-stream hook (AC-03): what Reverb will feed in production,
    // the spec feeds here — SAME store entry points.
    (window as unknown as Record<string, unknown>).__inboxList = {
        applyDelta: (delta: Parameters<typeof list.applyDelta>[0]) => list.applyDelta(delta),
        viewRemoved: (viewKey: string) => list.onViewRemoved(viewKey),
        flush: () => list.flushResort(),
        state: () => ({
            order: [...list.order],
            affordance: list.resortAffordance,
            selected: [...list.selected],
            // step 13: the FR-31 specs assert view switches + row patches.
            viewKey: list.viewKey,
            activeId: list.activeId,
        }),
        row: (id: string) => JSON.parse(JSON.stringify(list.rows[id] ?? null)) as unknown,
    };
});
onBeforeUnmount(() => {
    rememberScroll();
    window.removeEventListener('pagehide', rememberScroll);
});

// ══ B2-S01 (step 13): the mobile interactions ══════════════════════════════
const MOBILE_SELECTION_CAP = 25; // Doc 06 §9's phone cap, drawn as the strip

function mobileXsrf(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return match?.[1] ? decodeURIComponent(match[1]) : '';
}
function doorHeaders(): Record<string, string> {
    return {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-XSRF-TOKEN': mobileXsrf(),
    };
}

// Swipe-right claim: optimistic (T-06 command object), the drawn three
// states — committed flash on the row, then the undo strip. Undo renders
// only for holders of tickets.assign (BR-01: no affordance without the
// door); haptic at commit where the device has one.
const claimedFlashId = ref<string | null>(null);
const undoStrip = ref<{ ticketId: string; number: string } | null>(null);
const mobileToast = ref('');
function toastMobile(message: string): void {
    mobileToast.value = message;
    setTimeout(() => {
        if (mobileToast.value === message) mobileToast.value = '';
    }, 3_500);
}

async function onSwipeClaim(ticketId: string): Promise<void> {
    if (!props.can?.assignSelf) return;
    if (typeof navigator !== 'undefined' && 'vibrate' in navigator) {
        navigator.vibrate?.(10); // commit, not reveal (the drawn note)
    }
    const result = await runCommand({
        optimistic: () => {
            const row = list.rows[ticketId];
            const previous = row?.assignee_id;
            if (row && props.userId) row.assignee_id = props.userId;
            return previous;
        },
        execute: () =>
            fetch(`/inbox/t/${ticketId}/claim`, { method: 'POST', headers: doorHeaders() }),
        rollback: (previous) => {
            const row = list.rows[ticketId];
            if (row) row.assignee_id = previous;
        },
    });
    if (!result.ok) {
        toastMobile(
            result.code === 'already_claimed'
                ? (props.t.mobile.already_claimed ?? '')
                : (props.t.mobile.action_refused ?? ''),
        );
        connection.viewRefetchTick += 1;
        return;
    }
    const number = String(list.rows[ticketId]?.number ?? '');
    claimedFlashId.value = ticketId;
    setTimeout(() => {
        if (claimedFlashId.value === ticketId) claimedFlashId.value = null;
    }, 1_500);
    undoStrip.value = { ticketId, number };
    connection.viewRefetchTick += 1;
}

async function onUndoClaim(): Promise<void> {
    const strip = undoStrip.value;
    if (!strip) return;
    undoStrip.value = null;
    await fetch(`/inbox/t/${strip.ticketId}/unassign`, { method: 'POST', headers: doorHeaders() });
    connection.viewRefetchTick += 1;
}

// Swipe-left actions sheet — every row a real door.
const sheetFor = ref<ListRow | null>(null);
function onSwipeActions(ticketId: string): void {
    sheetFor.value = list.rows[ticketId] ?? null;
}
async function onSheetPick(action: 'resolve' | 'pending' | 'priority_urgent'): Promise<void> {
    const row = sheetFor.value;
    sheetFor.value = null;
    if (!row) return;
    const response =
        action === 'priority_urgent'
            ? await fetch(`/inbox/t/${row.ticket_id}/priority`, {
                  method: 'POST',
                  headers: doorHeaders(),
                  body: JSON.stringify({ priority: 'urgent' }),
              })
            : await fetch(`/inbox/thread/${String(row.conversation_id)}/transition`, {
                  method: 'POST',
                  headers: doorHeaders(),
                  body: JSON.stringify({
                      to: action === 'resolve' ? 'resolve' : 'pending_customer',
                  }),
              });
    if (!response.ok) {
        toastMobile(props.t.mobile.action_refused ?? '');
    }
    connection.viewRefetchTick += 1;
}

// Long-press selection mode: the drawn top bar (✕ / N selected / Select
// all) + the amber cap strip at 25. Execution rides the SAME bulk machinery
// as desktop (the BulkBar below the list is the real door — the drawn
// bottom-bar actions map onto it).
const selectionMode = ref(false);
const capStrip = ref(false);
function onLongPress(ticketId: string): void {
    if (!props.mobile) return;
    selectionMode.value = true;
    mobileToggleSelect(ticketId);
}
function mobileToggleSelect(ticketId: string): void {
    if (!list.selected.includes(ticketId) && list.selected.length >= MOBILE_SELECTION_CAP) {
        capStrip.value = true; // "25 is the limit on a phone…"
        return;
    }
    list.toggleSelect(ticketId);
    if (list.selected.length < MOBILE_SELECTION_CAP) {
        capStrip.value = false;
    }
}
function mobileSelectAll(): void {
    // "Select all" honors the phone cap: the first 25 visible rows.
    for (const id of list.order) {
        if (list.selected.length >= MOBILE_SELECTION_CAP) {
            capStrip.value = true;
            break;
        }
        if (!list.selected.includes(id)) {
            list.selected = [...list.selected, id];
        }
    }
}
function exitSelectionMode(): void {
    selectionMode.value = false;
    capStrip.value = false;
    list.clearSelection();
}

// Pull-to-refresh: pull past 60 px at the top → release refetches. The
// refetch happens after RELEASE — nothing ever re-sorts under the thumb
// (the drawn note; FR-11's law extends to the pull).
const pullState = ref<'idle' | 'pulling' | 'refreshing'>('idle');
let pullStartY = 0;
function onListPointerDown(event: PointerEvent): void {
    if (!props.mobile || scroller.value?.scrollTop !== 0) return;
    pullStartY = event.clientY;
}
function onListPointerMove(event: PointerEvent): void {
    if (!props.mobile || pullStartY === 0 || pullState.value === 'refreshing') return;
    if (scroller.value?.scrollTop === 0 && event.clientY - pullStartY > 60) {
        pullState.value = 'pulling';
    }
}
async function onListPointerUp(): Promise<void> {
    pullStartY = 0;
    if (pullState.value !== 'pulling') return;
    pullState.value = 'refreshing';
    await fetchPage(null);
    pullState.value = 'idle';
}

// ── FR-31/§13 (step 13): the roving-tabindex listbox ───────────────────────
// J/K arrive globally (the shortcut map moves list.activeId — highlight and
// scroll follow, FOCUS DOES NOT: live updates and shortcuts never steal it,
// §13's focus policy). The ARROWS arrive from a focused option — there the
// roving pattern moves focus WITH the active row.
function moveActive(delta: 1 | -1, moveFocus: boolean): void {
    list.activateStep(delta);
    requestAnimationFrame(() => {
        const el = list.activeId ? document.getElementById('inbox-row-' + list.activeId) : null;
        el?.scrollIntoView({ block: 'nearest' });
        if (moveFocus) {
            el?.focus();
        }
    });
}
// The global J/K path (no focus move) — scroll keeps the active row seen.
watch(
    () => list.activeId,
    (id) => {
        if (id) {
            requestAnimationFrame(() =>
                document.getElementById('inbox-row-' + id)?.scrollIntoView({ block: 'nearest' }),
            );
        }
    },
);
</script>

<template>
    <div class="flex min-h-0 flex-1 flex-col">
        <div
            class="flex items-center gap-2 border-b px-3 py-2"
            :style="{ borderColor: 'var(--ds-border)' }"
        >
            <span
                class="text-sm font-semibold"
                :style="{ color: 'var(--ds-text-primary)' }"
                data-testid="list-total"
            >
                {{ (t.list.total ?? '').replace(':total', String(list.total)) }}
            </span>
            <label
                class="ms-auto flex items-center gap-1 text-xs"
                :style="{ color: 'var(--ds-text-muted)' }"
            >
                {{ t.list.sort_label }}
                <select
                    data-testid="sort-select"
                    class="rounded border px-1 py-0.5 text-xs"
                    :style="{
                        borderColor: 'var(--ds-border-soft)',
                        background: 'var(--ds-panel-bg)',
                        color: 'var(--ds-text-primary)',
                    }"
                    :value="currentSort"
                    @change="setSort(($event.target as HTMLSelectElement).value)"
                >
                    <option value="">{{ t.list.sort_default }}</option>
                    <option value="last_activity">{{ t.list.sort_last_activity }}</option>
                    <option value="created">{{ t.list.sort_created }}</option>
                    <option value="sla_due">{{ t.list.sort_sla_due }}</option>
                    <option value="priority">{{ t.list.sort_priority }}</option>
                </select>
            </label>
        </div>

        <!-- FR-24: the in-view filter bar (composes WITHIN the view). -->
        <FilterBar :t="t.filter_bar" @change="onFilters" />

        <!-- §13 polite live region — announcements, never focus theft. -->
        <p class="sr-only" role="status" aria-live="polite" data-testid="list-announcement">
            {{ announcement }}
        </p>

        <!-- FR-11's affordance: the batch held because the pointer is inside. -->
        <button
            v-if="list.resortAffordance"
            type="button"
            data-testid="resort-affordance"
            class="mx-3 mt-2 rounded-md border px-2 py-1 text-xs font-semibold"
            :style="{
                background: 'var(--ds-inset-bg)',
                borderColor: 'var(--ds-border)',
                color: 'var(--ds-text-ink)',
            }"
            @click="list.flushResort()"
        >
            {{ t.list.resort }}
        </button>

        <!-- EC-09's toast (role=status: informational, not an error). -->
        <p
            v-if="list.removedViewToast"
            data-testid="queue-removed-toast"
            role="status"
            class="mx-3 mt-2 rounded-md border px-2 py-1 text-xs"
            :style="{
                background: 'var(--ds-inset-bg)',
                borderColor: 'var(--ds-border)',
                color: 'var(--ds-text-ink)',
            }"
        >
            {{ t.list.queue_removed }}
        </p>

        <!-- B2-S01: mobile SELECTION MODE top bar (✕ / N selected / Select all)
             + the amber 25-cap strip. Execution rides the BulkBar's doors. -->
        <div
            v-if="mobile && selectionMode"
            data-testid="mobile-selection-bar"
            class="flex items-center gap-2.5 px-3.5 py-1"
            :style="{ background: 'var(--ds-text-ink)', color: 'var(--ds-page-bg)' }"
        >
            <button
                type="button"
                data-testid="mobile-selection-exit"
                class="min-h-[44px] min-w-[44px] text-start text-[15px]"
                :aria-label="t.mobile.exit_selection"
                @click="exitSelectionMode"
            >
                ✕
            </button>
            <span class="flex-1 text-[13.5px] font-semibold">
                {{
                    (t.mobile.selected_count ?? '').replace(':count', String(list.selected.length))
                }}
            </span>
            <button
                type="button"
                data-testid="mobile-select-all"
                class="min-h-[44px] text-[12.5px]"
                @click="mobileSelectAll"
            >
                {{ t.mobile.select_all }}
            </button>
        </div>
        <p
            v-if="mobile && capStrip"
            data-testid="mobile-cap-strip"
            role="status"
            class="px-3.5 py-2.5 text-[11.5px]"
            :style="{ background: 'var(--ds-warn-bg)', color: 'var(--ds-warn-text)' }"
        >
            {{ t.mobile.cap_strip }}
        </p>

        <!-- B2-S01: pull-to-refresh strips — release triggers, never a
             re-sort under the thumb. -->
        <p
            v-if="mobile && pullState === 'pulling'"
            data-testid="pull-release-strip"
            class="px-3 py-3 text-center text-xs"
            :style="{ background: 'var(--ds-inset-bg)', color: 'var(--ds-text-secondary)' }"
        >
            ↓ {{ t.mobile.release_refresh }}
        </p>
        <p
            v-if="mobile && pullState === 'refreshing'"
            data-testid="pull-refreshing-strip"
            role="status"
            class="px-3 py-3 text-center text-xs"
            :style="{ background: 'var(--ds-inset-bg)', color: 'var(--ds-text-secondary)' }"
        >
            ◌ {{ t.mobile.refreshing }}
        </p>

        <!-- §13's roving tabindex — the step-9 deferral landed with FR-31
             (step 13) as a listbox, converted to a GRID at step-13 completion
             (A-36, class-1 technical necessity): rows carry interactive
             children, which role="option" cannot lawfully hold (WCAG 4.1.2)
             and a grid row's cells can. The interaction contract is
             unchanged: the active row holds tabindex 0, arrows mirror J/K,
             and focus is never stolen by live updates or shortcuts. -->
        <ul
            ref="scroller"
            role="grid"
            :aria-label="t.list.listbox_label"
            data-testid="ticket-list"
            class="min-h-0 flex-1 overflow-y-auto"
            @pointerenter="list.setPointerInside(true)"
            @pointerleave="list.setPointerInside(false)"
            @pointerdown="onListPointerDown"
            @pointermove="onListPointerMove"
            @pointerup="onListPointerUp"
        >
            <Row
                v-for="row in list.orderedRows"
                :key="row.ticket_id"
                :row="row"
                :selected="list.selected.includes(row.ticket_id)"
                :active="list.activeId === row.ticket_id"
                :entering="list.entering[row.ticket_id] === true"
                :leaving="list.leaving[row.ticket_id] === true"
                :mobile="mobile"
                :claimed-flash="claimedFlashId === row.ticket_id"
                :t="t.row"
                @activate="(id: string) => (list.activeId = id)"
                @move="(delta: 1 | -1) => moveActive(delta, true)"
                @toggle-select="onToggleSelect"
                @swipe-claim="onSwipeClaim"
                @swipe-actions="onSwipeActions"
                @long-press="onLongPress"
                @open="
                    (id) =>
                        mobile && selectionMode
                            ? mobileToggleSelect(id)
                            : emit('open-ticket', {
                                  ticketId: id,
                                  conversationId: String(list.rows[id]?.conversation_id ?? ''),
                                  number: (list.rows[id]?.number ?? null) as number | string | null,
                              })
                "
            />
            <li
                v-if="list.orderedRows.length === 0 && !loading"
                role="row"
                class="p-4 text-sm"
                :style="{ color: 'var(--ds-text-muted)' }"
            >
                <div role="gridcell">{{ t.list.empty }}</div>
            </li>
            <li v-if="list.nextCursor" role="row" class="p-3">
                <div role="gridcell">
                    <button
                        type="button"
                        data-testid="load-more"
                        class="w-full rounded-md border px-2 py-1.5 text-xs"
                        :style="{
                            borderColor: 'var(--ds-border)',
                            color: 'var(--ds-text-secondary)',
                        }"
                        @click="fetchPage(list.nextCursor)"
                    >
                        {{ t.list.load_more }}
                    </button>
                </div>
            </li>
        </ul>

        <p
            class="border-t px-3 py-1 text-[10px]"
            :style="{ borderColor: 'var(--ds-border-soft)', color: 'var(--ds-text-faint)' }"
        >
            {{
                (t.list.loaded ?? '')
                    .replace(':shown', String(list.orderedRows.length))
                    .replace(':total', String(list.total))
            }}
            · {{ t.list.keyboard_hint }}
        </p>

        <BulkBar
            :merge-allowed="mergeAllowed"
            :selected-count="list.selected.length"
            :tray-ids="list.trayIds"
            :total="list.total"
            :token-count="list.selectionToken?.count ?? null"
            :t="t.bulk"
            @select-all="selectAllInView"
            @merge="emit('merge-selection')"
            @clear="list.clearSelection()"
            @deselect="(id) => list.deselect(id)"
        />

        <!-- B2-S01: the claim UNDO strip (dark bar, ≥44 px button). Undo is
             capability-gated — an agent who cannot unassign sees the fact,
             not a lying affordance (BR-01). -->
        <div
            v-if="mobile && undoStrip"
            data-testid="claim-undo-strip"
            class="flex items-center gap-2.5 px-3.5 py-2"
            :style="{ background: 'var(--ds-text-ink)', color: 'var(--ds-page-bg)' }"
        >
            <span class="min-w-0 flex-1 text-[12.5px]">
                {{ (t.mobile.claimed ?? '').replace(':number', undoStrip.number) }}
            </span>
            <button
                v-if="can?.assign"
                type="button"
                data-testid="claim-undo"
                class="rounded-md border px-4 text-[12.5px] font-semibold"
                :style="{ borderColor: 'var(--ds-border-strong)', minHeight: '44px' }"
                @click="onUndoClaim"
            >
                {{ t.mobile.undo }}
            </button>
        </div>

        <p
            v-if="mobileToast"
            data-testid="mobile-toast"
            role="status"
            class="px-3.5 py-2 text-[11.5px]"
            :style="{ background: 'var(--ds-warn-bg)', color: 'var(--ds-warn-text)' }"
        >
            {{ mobileToast }}
        </p>

        <MobileActionsSheet
            :open="sheetFor !== null"
            :title="
                String(sheetFor?.customer_display_name ?? '') +
                ' · #' +
                String(sheetFor?.number ?? '')
            "
            :subtitle="String(sheetFor?.snippet ?? sheetFor?.subject ?? '')"
            :can="{
                resolve: can?.statusChange ?? false,
                priority: can?.priority ?? false,
            }"
            :t="t.mobile"
            @close="sheetFor = null"
            @pick="onSheetPick"
        />
    </div>
</template>
