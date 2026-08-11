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
import { useInboxPrefs } from '../../../stores/inbox/prefs';
import { useLiveList, type ListRow } from '../../../stores/inbox/liveList';
import BulkBar from './BulkBar.vue';
import Row from './Row.vue';

const props = defineProps<{
    view: string;
    t: { list: Record<string, string>; row: Record<string, string>; bulk: Record<string, string> };
}>();

const emit = defineEmits<{ (e: 'open-ticket', id: string): void; (e: 'fallback-view'): void }>();

const list = useLiveList();
const prefs = useInboxPrefs();

const loading = ref(false);
const announcement = ref('');
const lastSelectedId = ref<string | null>(null);
const scroller = ref<HTMLElement | null>(null);

async function fetchPage(cursor: string | null): Promise<void> {
    loading.value = true;
    try {
        const sort = currentSort.value;
        const params = new URLSearchParams({ view: props.view });
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
        void fetchPage(null);
    },
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
        }),
    };
});
onBeforeUnmount(() => {
    rememberScroll();
    window.removeEventListener('pagehide', rememberScroll);
});
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

        <!-- §13's full listbox + roving-tabindex pattern lands with FR-31's
             keyboard map (step 13) — nested interactive controls (checkbox +
             open button) are invalid INSIDE role=option, so until the roving
             focus model exists this is a semantic list, honestly. -->
        <ul
            ref="scroller"
            data-testid="ticket-list"
            class="min-h-0 flex-1 overflow-y-auto"
            @pointerenter="list.setPointerInside(true)"
            @pointerleave="list.setPointerInside(false)"
        >
            <Row
                v-for="row in list.orderedRows"
                :key="row.ticket_id"
                :row="row"
                :selected="list.selected.includes(row.ticket_id)"
                :entering="list.entering[row.ticket_id] === true"
                :leaving="list.leaving[row.ticket_id] === true"
                :t="t.row"
                @toggle-select="onToggleSelect"
                @open="(id) => emit('open-ticket', id)"
            />
            <li
                v-if="list.orderedRows.length === 0 && !loading"
                class="p-4 text-sm"
                :style="{ color: 'var(--ds-text-muted)' }"
            >
                {{ t.list.empty }}
            </li>
            <li v-if="list.nextCursor" class="p-3">
                <button
                    type="button"
                    data-testid="load-more"
                    class="w-full rounded-md border px-2 py-1.5 text-xs"
                    :style="{ borderColor: 'var(--ds-border)', color: 'var(--ds-text-secondary)' }"
                    @click="fetchPage(list.nextCursor)"
                >
                    {{ t.list.load_more }}
                </button>
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
            :selected-count="list.selected.length"
            :tray-ids="list.trayIds"
            :total="list.total"
            :token-count="list.selectionToken?.count ?? null"
            :t="t.bulk"
            @select-all="selectAllInView"
            @clear="list.clearSelection()"
            @deselect="(id) => list.deselect(id)"
        />
    </div>
</template>
