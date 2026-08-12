<script setup lang="ts">
// B1-S01 — the inbox shell (S07 step 8). Doc 07 FR-01/FR-02/FR-03, AC-01.
//
// The four breakpoints, off the document's own numbers:
//   ≥1280         R1 (240|56) · R2 (300–480, persisted) · R3 flex · R4 (380|collapsed)
//   1024–1279     R4 becomes an overlay drawer
//   768–1023      R1 collapses to icons PERMANENTLY (no expand control)
//   <768          two-level navigation: List screen ↔ Thread screen;
//                 R4 = swipe-up bottom sheet (same three tabs);
//                 Views = modal sheet from the header
//
// §13 (B12-A01 AC-02): the four regions are LANDMARKS (nav/section/main/
// aside with aria-labels) and skip links jump past the rail straight into
// the list — first tab press on a cold load reveals the first one.
// Region sizes/collapse and §9 density persist per user through the prefs
// store; a Read Only viewer's writes degrade session-local, silently (the
// countersigned rider — see stores/inbox/prefs.ts).
//
// R2 and R3 render REGION CHROME with honest placeholders here: the list's
// anatomy is step 9, the thread is step 10. The shell must stand before its
// organs arrive; the placeholders are t-keys, not lorem.
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import CommandPalette from '../../Components/Inbox/CommandPalette.vue';
import ContextPanel from '../../Components/Inbox/ContextPanel.vue';
import SavedViewEditor from '../../Components/Inbox/SavedViewEditor.vue';
import ListPane from '../../Components/Inbox/List/ListPane.vue';
import ThreadPane from '../../Components/Inbox/Thread/ThreadPane.vue';
import TopBar from '../../Components/Inbox/TopBar.vue';
import ViewsRail from '../../Components/Inbox/ViewsRail.vue';
import {
    notifierEmissions,
    notify,
    type NotifierPrefs,
    type NotifyEvent,
} from '../../lib/notifier';
import { leaderState, startLeaderElection } from '../../lib/tabLeader';
import {
    LIST_WIDTH_DEFAULT,
    LIST_WIDTH_MAX,
    LIST_WIDTH_MIN,
    useInboxPrefs,
} from '../../stores/inbox/prefs';

type ViewRow = { key: string; hash: string; count: number };

const props = defineProps<{
    t: {
        shell: Record<string, string>;
        topbar: Record<string, string>;
        health: Record<string, string>;
        views: Record<string, string>;
        tabs: Record<string, string>;
        density: Record<string, string>;
        list: Record<string, string>;
        row: Record<string, string>;
        bulk: Record<string, string>;
        thread: Record<string, string>;
        composer: Record<string, string>;
        notifier: Record<string, string>;
        palette: Record<string, string>;
        bell: Record<string, string>;
        status: Record<string, string>;
        views_editor: Record<string, string>;
        filter_bar: Record<string, string>;
    };
    tenantName: string;
    views: ViewRow[];
    savedViews: { id: string; name: string; own: boolean; shared: boolean }[];
    prefs: Record<string, unknown>;
    health: { states: Record<string, string>; worst: 'healthy' | 'degraded' | 'down' | 'unknown' };
    projectorStaleSeconds: number | null;
    tenantId: string;
    buildId: string;
    can: { views_manage: boolean; views_share: boolean; tickets_create: boolean };
    tabBadges: { customer: boolean; copilot: boolean };
    queueViews: { id: string; name: string; count: number }[];
    agentStatus: { status: string; reason: string | null; set_by_name: string | null };
    awayIdleMinutes: number;
    bellUnread: number;
    initialThread: { conversation_id: string; ticket_id: string | null } | null;
    features: { voice_recording: boolean };
}>();

const prefsStore = useInboxPrefs();
prefsStore.hydrate(props.prefs as Parameters<typeof prefsStore.hydrate>[0]);

// ── Breakpoints (FR-02) ────────────────────────────────────────────────────
const width = ref(typeof window === 'undefined' ? 1440 : window.innerWidth);
function onResize(): void {
    width.value = window.innerWidth;
}
onMounted(() => {
    window.addEventListener('resize', onResize);
    // FR-04: the election starts with the workspace — BEFORE any sound can
    // fire (the release's ordered minimum). The scripted hook feeds the
    // SAME notify() the realtime feed will call.
    startLeaderElection();
    (window as unknown as Record<string, unknown>).__inboxNotify = {
        fire: (event: NotifyEvent) => notify(event, livePrefs()),
        fireWith: (event: NotifyEvent, prefs: NotifierPrefs) => notify(event, prefs),
        emissions: () => notifierEmissions(),
        leader: () => leaderState(),
    };
});

// The LIVE prefs every real emission reads: FR-27's defaults (my-open ON,
// unassigned OFF — Doc 16 owns the toggles) + the bell's quick mute.
function livePrefs(): NotifierPrefs {
    return {
        soundMyOpen: true,
        soundUnassigned: false,
        reduced: false,
        mutedUntil: prefsStore.mutedUntil,
        reducedLabel: props.t.notifier?.reduced ?? '',
    };
}

// FR-26: a bell row deep-links — in place when the conversation is known,
// through the FR-15 deep-link door otherwise.
function onBellThread(payload: { ticketId: string; conversationId: string | null }): void {
    if (payload.conversationId) {
        activeThread.value = payload.conversationId;
        mobileScreen.value = 'thread';
        return;
    }
    window.location.assign('/inbox/t/' + payload.ticketId);
}
onBeforeUnmount(() => window.removeEventListener('resize', onResize));

const isMobile = computed(() => width.value < 768);
const railForcedIcons = computed(() => width.value >= 768 && width.value < 1024);
const contextAsDrawer = computed(() => width.value >= 1024 && width.value < 1280);

// ── Persisted layout state (FR-01/AC-01) ──────────────────────────────────
const railCollapsed = computed(
    () => railForcedIcons.value || (prefsStore.regions.rail_collapsed ?? false),
);
const contextCollapsed = computed(() => prefsStore.regions.context_collapsed ?? false);
const listWidth = computed(() =>
    Math.min(
        LIST_WIDTH_MAX,
        Math.max(LIST_WIDTH_MIN, prefsStore.regions.list_width ?? LIST_WIDTH_DEFAULT),
    ),
);

function toggleRail(): void {
    if (railForcedIcons.value) {
        return; // 768–1023: permanently icons (FR-02) — no expand control.
    }
    prefsStore.setRegions({ rail_collapsed: !(prefsStore.regions.rail_collapsed ?? false) });
}

function toggleContext(): void {
    prefsStore.setRegions({ context_collapsed: !(prefsStore.regions.context_collapsed ?? false) });
}

// §13: drag interactions have keyboard equivalents — the resize handle is a
// separator button; arrows move 16 px, bounded to FR-01's 300–480.
function resizeList(delta: number): void {
    prefsStore.setRegions({
        list_width: Math.min(LIST_WIDTH_MAX, Math.max(LIST_WIDTH_MIN, listWidth.value + delta)),
    });
}

let dragStartX = 0;
let dragStartWidth = 0;
function onDragStart(event: PointerEvent): void {
    dragStartX = event.clientX;
    dragStartWidth = listWidth.value;
    const move = (e: PointerEvent): void =>
        resizeList(dragStartWidth + (e.clientX - dragStartX) - listWidth.value);
    const up = (): void => {
        window.removeEventListener('pointermove', move);
        window.removeEventListener('pointerup', up);
    };
    window.addEventListener('pointermove', move);
    window.addEventListener('pointerup', up);
}

// ── Mobile two-level navigation + sheets (FR-02 <768) ─────────────────────
const mobileScreen = ref<'list' | 'thread'>(props.initialThread ? 'thread' : 'list');
const viewsSheetOpen = ref(false);
const contextSheetOpen = ref(false);
const drawerOpen = ref(false);

// The ACTIVE view (rail clicks set it; EC-09's fallback returns to My Open).
const activeView = ref('my_open');

// FR-15: the open thread — deep links arrive resolved (initialThread),
// list rows open it in place.
const activeThread = ref<string | null>(props.initialThread?.conversation_id ?? null);

const activeAnchor = ref<string | null>(null);
const activeTicketMeta = ref<{ number: number | string } | null>(null);

function openThread(payload: {
    ticketId: string;
    conversationId: string;
    number?: number | string | null;
}): void {
    activeAnchor.value = null;
    activeTicketMeta.value = payload.number != null ? { number: payload.number } : null;
    activeThread.value = payload.conversationId;
    mobileScreen.value = 'thread';
}

// FR-23: ⌘K / Ctrl-K opens the palette.
const paletteOpen = ref(false);
function onGlobalKey(event: KeyboardEvent): void {
    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        paletteOpen.value = true;
    }
}
onMounted(() => window.addEventListener('keydown', onGlobalKey));
onBeforeUnmount(() => window.removeEventListener('keydown', onGlobalKey));

const paletteViews = computed(() =>
    props.views.map((v) => ({ key: v.key, label: props.t.views[v.key] ?? v.key })),
);
const paletteSaved = computed(() => props.savedViews.map((v) => ({ id: v.id, name: v.name })));

function onPaletteView(key: string): void {
    // A saved-view id (uuid) vs a system key — saved views render through
    // the same list door since step 12 wired 'saved:{id}'.
    activeView.value = key.includes('-') ? 'saved:' + key : key;
    paletteOpen.value = false;
}
function onPaletteThread(conversationId: string): void {
    activeAnchor.value = null;
    activeTicketMeta.value = null;
    activeThread.value = conversationId;
    mobileScreen.value = 'thread';
    paletteOpen.value = false;
}

// FR-25: a message hit opens ANCHORED + highlighted.
function onPaletteMessage(payload: { conversationId: string; messageId: string }): void {
    activeAnchor.value = payload.messageId;
    activeTicketMeta.value = null;
    activeThread.value = payload.conversationId;
    mobileScreen.value = 'thread';
    paletteOpen.value = false;
}

// Palette actions are REAL DOORS: resolve rides the FR-21 transition door
// on the active thread; Away rides the FR-28 status door.
async function onPaletteAction(id: string): Promise<void> {
    paletteOpen.value = false;
    if (id === 'toggle-density') {
        setDensity(density.value === 'comfortable' ? 'compact' : 'comfortable');
        return;
    }
    if (id === 'set-away') {
        await fetch('/inbox/status', {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': workspaceXsrf(),
            },
            body: JSON.stringify({ status: 'away', reason: 'manual' }),
        });
        return;
    }
    if (id === 'resolve-active' && activeThread.value) {
        await fetch(`/inbox/thread/${activeThread.value}/transition`, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': workspaceXsrf(),
            },
            body: JSON.stringify({ to: 'resolve' }),
        });
    }
}

// ── FR-06: the saved-view editor (step 12) ─────────────────────────────────
const editorOpen = ref(false);
const editorTarget = ref<{
    id: string | null;
    name: string;
    filters: Record<string, unknown>;
    sort: { by?: string; dir?: string };
} | null>(null);

async function openEditor(viewId: string | null): Promise<void> {
    if (viewId === null) {
        editorTarget.value = { id: null, name: '', filters: {}, sort: {} };
        editorOpen.value = true;
        return;
    }
    // Prefill from the index door (raw definition JSON per row).
    const response = await fetch('/inbox/views', { headers: { Accept: 'application/json' } });
    if (!response.ok) return;
    const body = (await response.json()) as {
        views: { id: string; name: string; filters: string; sort: string }[];
    };
    const row = body.views.find((v) => v.id === viewId);
    if (!row) return;
    editorTarget.value = {
        id: row.id,
        name: row.name,
        filters: JSON.parse(row.filters) as Record<string, unknown>,
        sort: JSON.parse(row.sort) as { by?: string; dir?: string },
    };
    editorOpen.value = true;
}

// FR-06: shared views are read-only — duplicate mints an editable copy
// through the step-4 door, then the editor opens on it.
async function duplicateView(viewId: string): Promise<void> {
    const response = await fetch(`/inbox/views/${viewId}/duplicate`, {
        method: 'POST',
        headers: { Accept: 'application/json', 'X-XSRF-TOKEN': workspaceXsrf() },
    });
    if (!response.ok) return;
    const body = (await response.json()) as { id: string };
    router.reload({ only: ['savedViews'] });
    await openEditor(body.id);
}

function onEditorSaved(): void {
    editorOpen.value = false;
    router.reload({ only: ['savedViews'] });
}

function workspaceXsrf(): string {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    return match?.[1] ? decodeURIComponent(match[1]) : '';
}

// §9 density → the row-height variable list rows (step 9) consume.
const density = computed(() => prefsStore.density);
function setDensity(value: 'comfortable' | 'compact'): void {
    prefsStore.setDensity(value);
}
</script>

<template>
    <div
        class="flex h-screen flex-col"
        data-testid="inbox-workspace"
        :data-density="density"
        :data-build-id="buildId"
        :style="{
            background: 'var(--ds-page-bg)',
            '--inbox-row-height': density === 'compact' ? '52px' : '64px',
        }"
    >
        <!-- §13 / B12-A01 AC-02: skip links — first tab on a cold load
             reveals the first, and it jumps PAST the rail into the list. -->
        <div class="skip-links">
            <a href="#inbox-list" class="skip-link" data-testid="skip-to-list">{{
                t.shell.skip_to_list
            }}</a>
            <a href="#inbox-thread" class="skip-link">{{ t.shell.skip_to_thread }}</a>
            <a href="#inbox-context" class="skip-link">{{ t.shell.skip_to_context }}</a>
        </div>

        <SavedViewEditor
            :open="editorOpen"
            :t="t.views_editor"
            :can-share="can.views_share"
            :editing="editorTarget"
            @close="editorOpen = false"
            @saved="onEditorSaved"
        />

        <CommandPalette
            :open="paletteOpen"
            :views="paletteViews"
            :saved-views="paletteSaved"
            :recent-searches="prefsStore.recentSearches"
            :active-ticket="activeTicketMeta"
            :t="t.palette"
            @close="paletteOpen = false"
            @select-view="onPaletteView"
            @open-thread="onPaletteThread"
            @open-message="onPaletteMessage"
            @action="onPaletteAction"
            @remember-search="(q: string) => prefsStore.rememberSearch(q)"
        />

        <TopBar
            :tenant-name="tenantName"
            :health="health"
            :can-create-ticket="can.tickets_create"
            :density="density"
            :bell-unread="bellUnread"
            :muted-until="prefsStore.mutedUntil"
            :agent-status="agentStatus"
            :away-idle-minutes="awayIdleMinutes"
            :t="{
                topbar: t.topbar,
                health: t.health,
                density: t.density,
                bell: t.bell,
                status: t.status,
            }"
            @set-density="setDensity"
            @open-thread="onBellThread"
            @mute="(until) => prefsStore.setMutedUntil(until)"
            @open-palette="paletteOpen = true"
        />

        <!-- ═══ Desktop / tablet (≥768): the region row ═══ -->
        <div v-if="!isMobile" class="flex min-h-0 flex-1">
            <ViewsRail
                :views="views"
                :saved-views="savedViews"
                :queues="queueViews"
                :view-order="prefsStore.viewOrder"
                :can-manage="can.views_manage"
                :can-share="can.views_share"
                :collapsed="railCollapsed"
                :t="{ views: t.views, shell: t.shell }"
                @toggle-collapse="toggleRail"
                @select="(v: string) => (activeView = v)"
                @select-saved="(id: string) => (activeView = 'saved:' + id)"
                @reorder="(order: string[]) => prefsStore.setViewOrder(order)"
                @new-view="openEditor(null)"
                @edit-view="openEditor"
                @duplicate-view="duplicateView"
            />

            <!-- R2: the list region (chrome + step-9 slot). -->
            <section
                id="inbox-list"
                data-testid="list-region"
                :aria-label="t.shell.region_list"
                class="flex min-h-0 shrink-0 flex-col border-e"
                :style="{
                    width: listWidth + 'px',
                    background: 'var(--ds-panel-bg)',
                    borderColor: 'var(--ds-border)',
                }"
                tabindex="-1"
            >
                <ListPane
                    :view="activeView"
                    :t="{ list: t.list, row: t.row, bulk: t.bulk, filter_bar: t.filter_bar }"
                    @open-ticket="openThread"
                    @fallback-view="activeView = 'my_open'"
                />
            </section>

            <!-- §13: the resize handle IS keyboard-operable. -->
            <div
                role="separator"
                tabindex="0"
                aria-orientation="vertical"
                :aria-label="t.shell.resize_list"
                :aria-valuenow="listWidth"
                :aria-valuemin="LIST_WIDTH_MIN"
                :aria-valuemax="LIST_WIDTH_MAX"
                data-testid="list-resize-handle"
                class="w-1 shrink-0 cursor-col-resize focus-visible:outline"
                :style="{ background: 'var(--ds-border-soft)' }"
                @pointerdown.prevent="onDragStart"
                @keydown.left.prevent="resizeList(-16)"
                @keydown.right.prevent="resizeList(16)"
            ></div>

            <!-- R3: the thread region (chrome + step-10 slot). -->
            <main
                id="inbox-thread"
                data-testid="thread-region"
                :aria-label="t.shell.region_thread"
                class="flex min-h-0 min-w-0 flex-1 flex-col"
                :style="{ background: 'var(--ds-canvas-bg)' }"
                tabindex="-1"
            >
                <div class="flex items-center justify-end p-2">
                    <button
                        v-if="contextAsDrawer"
                        type="button"
                        data-testid="context-drawer-toggle"
                        class="rounded-md border px-2 py-1 text-xs"
                        :style="{
                            borderColor: 'var(--ds-border)',
                            color: 'var(--ds-text-secondary)',
                        }"
                        :aria-expanded="drawerOpen"
                        @click="drawerOpen = !drawerOpen"
                    >
                        {{ t.shell.open_context }}
                    </button>
                    <button
                        v-else
                        type="button"
                        data-testid="context-collapse"
                        class="rounded-md border px-2 py-1 text-xs"
                        :style="{
                            borderColor: 'var(--ds-border)',
                            color: 'var(--ds-text-secondary)',
                        }"
                        :aria-label="
                            contextCollapsed ? t.shell.expand_context : t.shell.collapse_context
                        "
                        :aria-expanded="!contextCollapsed"
                        @click="toggleContext"
                    >
                        <span aria-hidden="true">{{ contextCollapsed ? '◧' : '▐' }}</span>
                    </button>
                </div>
                <ThreadPane
                    v-if="activeThread"
                    :key="activeThread + (activeAnchor ?? '')"
                    :conversation-id="activeThread"
                    :initial-anchor="activeAnchor"
                    :t="t.thread"
                    :composer-t="t.composer"
                    :voice-flag="features.voice_recording"
                />
                <p v-else class="px-4 text-sm" :style="{ color: 'var(--ds-text-muted)' }">
                    {{ t.shell.thread_placeholder }}
                </p>
            </main>

            <!-- R4: fixed panel ≥1280; overlay drawer 1024–1279 (FR-02). -->
            <div
                v-if="!contextAsDrawer && !contextCollapsed"
                id="inbox-context"
                data-testid="context-region"
                class="h-full w-[380px] shrink-0 border-s"
                :style="{ borderColor: 'var(--ds-border)' }"
                tabindex="-1"
            >
                <ContextPanel :badges="tabBadges" :t="{ tabs: t.tabs, shell: t.shell }" />
            </div>
            <div
                v-else-if="contextAsDrawer && drawerOpen"
                id="inbox-context"
                data-testid="context-drawer"
                class="fixed inset-y-0 end-0 z-30 w-[380px] border-s shadow-2xl"
                :style="{ background: 'var(--ds-panel-bg)', borderColor: 'var(--ds-border)' }"
                tabindex="-1"
            >
                <ContextPanel :badges="tabBadges" :t="{ tabs: t.tabs, shell: t.shell }" />
            </div>
        </div>

        <!-- ═══ Mobile <768: two-level navigation (FR-02) ═══ -->
        <div v-else class="flex min-h-0 flex-1 flex-col">
            <template v-if="mobileScreen === 'list'">
                <div
                    class="flex shrink-0 items-center gap-2 border-b px-3 py-2"
                    :style="{ background: 'var(--ds-panel-bg)', borderColor: 'var(--ds-border)' }"
                >
                    <!-- Views = modal sheet from the header (FR-02). -->
                    <button
                        type="button"
                        data-testid="mobile-views-trigger"
                        class="rounded-md px-2 py-1"
                        :style="{ color: 'var(--ds-text-primary)' }"
                        :aria-label="t.shell.open_views"
                        :aria-expanded="viewsSheetOpen"
                        @click="viewsSheetOpen = true"
                    >
                        <span aria-hidden="true">☰</span>
                    </button>
                    <span
                        class="text-sm font-semibold"
                        :style="{ color: 'var(--ds-text-primary)' }"
                    >
                        {{ t.views.my_open }}
                    </span>
                </div>

                <section
                    id="inbox-list"
                    data-testid="list-region"
                    :aria-label="t.shell.region_list"
                    class="min-h-0 flex-1 overflow-y-auto"
                    tabindex="-1"
                >
                    <ListPane
                        :view="activeView"
                        :t="{ list: t.list, row: t.row, bulk: t.bulk, filter_bar: t.filter_bar }"
                        @open-ticket="openThread"
                        @fallback-view="activeView = 'my_open'"
                    />
                    <!-- Row-tap opens the thread (above); this affordance stays
                         for empty fixtures until step 10 renders the thread. -->
                    <button
                        type="button"
                        data-testid="mobile-open-thread"
                        class="mx-4 rounded-md border px-3 py-1.5 text-sm"
                        :style="{
                            borderColor: 'var(--ds-border)',
                            color: 'var(--ds-text-secondary)',
                        }"
                        @click="mobileScreen = 'thread'"
                    >
                        {{ t.shell.region_thread }}
                    </button>
                </section>

                <div
                    v-if="viewsSheetOpen"
                    data-testid="mobile-views-sheet"
                    role="dialog"
                    aria-modal="true"
                    :aria-label="t.shell.region_views"
                    class="fixed inset-0 z-40 flex flex-col justify-end"
                >
                    <div
                        class="absolute inset-0 bg-black/40"
                        data-testid="views-sheet-backdrop"
                        @click="viewsSheetOpen = false"
                    ></div>
                    <div
                        class="relative max-h-[80vh] overflow-y-auto rounded-t-2xl p-2"
                        :style="{ background: 'var(--ds-panel-bg)' }"
                    >
                        <ViewsRail
                            :views="views"
                            :saved-views="savedViews"
                            :queues="queueViews"
                            :view-order="prefsStore.viewOrder"
                            :can-manage="can.views_manage"
                            :can-share="can.views_share"
                            :collapsed="false"
                            :t="{ views: t.views, shell: t.shell }"
                            @select="
                                (v: string) => {
                                    activeView = v;
                                    viewsSheetOpen = false;
                                }
                            "
                            @select-saved="
                                (id: string) => {
                                    activeView = 'saved:' + id;
                                    viewsSheetOpen = false;
                                }
                            "
                            @reorder="(order: string[]) => prefsStore.setViewOrder(order)"
                            @new-view="openEditor(null)"
                            @edit-view="openEditor"
                            @duplicate-view="duplicateView"
                        />
                    </div>
                </div>
            </template>

            <template v-else>
                <div
                    class="flex shrink-0 items-center gap-2 border-b px-3 py-2"
                    :style="{ background: 'var(--ds-panel-bg)', borderColor: 'var(--ds-border)' }"
                >
                    <button
                        type="button"
                        data-testid="mobile-back"
                        class="rounded-md px-2 py-1"
                        :style="{ color: 'var(--ds-text-primary)' }"
                        :aria-label="t.shell.back_to_list"
                        @click="mobileScreen = 'list'"
                    >
                        <span aria-hidden="true">‹</span>
                    </button>
                    <span
                        class="min-w-0 flex-1 truncate text-sm font-semibold"
                        :style="{ color: 'var(--ds-text-primary)' }"
                    >
                        {{ t.shell.region_thread }}
                    </span>
                    <!-- R4 = swipe-up bottom sheet, same three tabs (FR-02). -->
                    <button
                        type="button"
                        data-testid="mobile-context-trigger"
                        class="rounded-md px-2 py-1"
                        :style="{ color: 'var(--ds-text-primary)' }"
                        :aria-label="t.shell.open_context"
                        :aria-expanded="contextSheetOpen"
                        @click="contextSheetOpen = true"
                    >
                        <span aria-hidden="true">▤</span>
                    </button>
                </div>

                <main
                    id="inbox-thread"
                    data-testid="thread-region"
                    :aria-label="t.shell.region_thread"
                    class="flex min-h-0 flex-1 flex-col overflow-y-auto"
                    :style="{ background: 'var(--ds-canvas-bg)' }"
                    tabindex="-1"
                >
                    <ThreadPane
                        v-if="activeThread"
                        :key="activeThread + (activeAnchor ?? '')"
                        :conversation-id="activeThread"
                        :initial-anchor="activeAnchor"
                        :t="t.thread"
                        :composer-t="t.composer"
                        :voice-flag="features.voice_recording"
                    />
                    <p v-else class="p-4 text-sm" :style="{ color: 'var(--ds-text-muted)' }">
                        {{ t.shell.thread_placeholder }}
                    </p>
                </main>

                <div
                    v-if="contextSheetOpen"
                    id="inbox-context"
                    data-testid="mobile-context-sheet"
                    role="dialog"
                    aria-modal="true"
                    :aria-label="t.shell.region_context"
                    class="fixed inset-0 z-40 flex flex-col justify-end"
                >
                    <div
                        class="absolute inset-0 bg-black/40"
                        data-testid="context-sheet-backdrop"
                        @click="contextSheetOpen = false"
                    ></div>
                    <div
                        class="relative h-[70vh] rounded-t-2xl"
                        :style="{ background: 'var(--ds-panel-bg)' }"
                    >
                        <div class="flex justify-center py-1.5" aria-hidden="true">
                            <span
                                class="h-1 w-9 rounded-full"
                                :style="{ background: 'var(--ds-border-strong)' }"
                            ></span>
                        </div>
                        <ContextPanel :badges="tabBadges" :t="{ tabs: t.tabs, shell: t.shell }" />
                    </div>
                </div>
            </template>
        </div>
    </div>
</template>

<style scoped>
/* Skip links: visually hidden until focused (B12-A01 AC-02 — "first tab
   press on a cold load reveals the skip link"). */
.skip-link {
    position: absolute;
    inset-inline-start: -9999px;
    z-index: 50;
    padding: 0.5rem 0.75rem;
    border-radius: 0.375rem;
    background: var(--ds-accent);
    color: var(--ds-accent-contrast);
    font-size: 0.875rem;
    font-weight: 600;
}
.skip-link:focus {
    inset-inline-start: 0.75rem;
    inset-block-start: 0.75rem;
}
</style>
