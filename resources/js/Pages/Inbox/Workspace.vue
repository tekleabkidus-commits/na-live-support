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
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import ActionPalette, { type PaletteItem } from '../../Components/Inbox/ActionPalette.vue';
import CoachMarks from '../../Components/Inbox/CoachMarks.vue';
import CommandPalette from '../../Components/Inbox/CommandPalette.vue';
import ContextPanel from '../../Components/Inbox/ContextPanel.vue';
import MergeDialog from '../../Components/Inbox/MergeDialog.vue';
import OperationDialog from '../../Components/Inbox/OperationDialog.vue';
import SplitUnmergeDialog from '../../Components/Inbox/SplitUnmergeDialog.vue';
import TicketPanel from '../../Components/Inbox/TicketPanel.vue';
import SavedViewEditor from '../../Components/Inbox/SavedViewEditor.vue';
import ShortcutOverlay from '../../Components/Inbox/ShortcutOverlay.vue';
import ListPane from '../../Components/Inbox/List/ListPane.vue';
import ThreadPane from '../../Components/Inbox/Thread/ThreadPane.vue';
import TopBar from '../../Components/Inbox/TopBar.vue';
import ViewsRail from '../../Components/Inbox/ViewsRail.vue';
import { runCommand } from '../../lib/commands';
import { attachShortcuts } from '../../lib/shortcuts';
import { useLiveList } from '../../stores/inbox/liveList';
import {
    notifierEmissions,
    notify,
    type NotifierPrefs,
    type NotifyEvent,
} from '../../lib/notifier';
import {
    flushQueue,
    initOfflineMachine,
    recoverStore,
    refreshQueueMirror,
    setJitterMax,
    signalDrop,
    signalUp,
} from '../../lib/offline';
import { anchorServerNow, serverClockOffsetMs } from '../../lib/serverClock';
import { leaderState, startLeaderElection } from '../../lib/tabLeader';
import { useInboxConnection } from '../../stores/inbox/connection';
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
        offline: Record<string, string>;
        shortcuts: Record<string, string>;
        coach: Record<string, string>;
        ticket_panel: Record<string, string>;
        action_palette: Record<string, string>;
        mobile: Record<string, string>;
    };
    tenantName: string;
    views: ViewRow[];
    savedViews: { id: string; name: string; own: boolean; shared: boolean }[];
    prefs: Record<string, unknown>;
    health: { states: Record<string, string>; worst: 'healthy' | 'degraded' | 'down' | 'unknown' };
    projectorStaleSeconds: number | null;
    tenantId: string;
    buildId: string;
    can: {
        views_manage: boolean;
        views_share: boolean;
        tickets_create: boolean;
        tickets_assign: boolean;
        tickets_assign_self: boolean;
        tickets_priority_change: boolean;
        tickets_tags_manage: boolean;
        tickets_status_change: boolean;
        tickets_edit: boolean;
        tickets_follow: boolean;
        tickets_merge: boolean;
        tickets_reply: boolean;
        tickets_escalate: boolean;
        tickets_spam_mark: boolean;
        tickets_split: boolean;
    };
    tabBadges: { customer: boolean; copilot: boolean };
    transitions: Record<string, string[]>;
    queueViews: { id: string; name: string; count: number }[];
    agentStatus: { status: string; reason: string | null; set_by_name: string | null };
    awayIdleMinutes: number;
    bellUnread: number;
    initialThread: { conversation_id: string; ticket_id: string | null } | null;
    features: { voice_recording: boolean };
    serverNow: string;
    userId: string;
}>();

const prefsStore = useInboxPrefs();
prefsStore.hydrate(props.prefs as Parameters<typeof prefsStore.hydrate>[0]);
const connection = useInboxConnection();

// ── FR-29/FS-05 (step 13): the offline machine + the guarded session store ─
// FS-05's schema tripwire: a stamp mismatch or unparseable session blob on
// load means the last session's stores cannot be trusted — recover, tell the
// agent, refetch. Bump the constant on any session-store shape change.
const CLIENT_STORE_SCHEMA = '1';
const offlineAnnouncement = ref('');

function announceOffline(kind: string): void {
    offlineAnnouncement.value = props.t.offline?.['announce_' + kind] ?? kind;
}

/**
 * FS-05: reset + refetch, COMPOSER TEXT ABOVE ALL. The snapshot happens
 * before anything is cleared; the thread remount (the refetch FS-05 names)
 * would otherwise destroy the typed text — the restore hands it back.
 * Sabotage #17 disables exactly this preserve/restore pair.
 */
async function recoverSession(): Promise<void> {
    // 1. Preserve FIRST (drafts are the last thing ever dropped).
    const preserved = { ...connection.composerLiveText };
    // 2. Reset the per-session stores (scroll positions, composer modes,
    //    session-local prefs) and restamp the schema.
    for (const key of Object.keys(sessionStorage)) {
        if (key.startsWith('inbox-')) {
            sessionStorage.removeItem(key);
        }
    }
    sessionStorage.setItem('inbox-client-schema', CLIENT_STORE_SCHEMA);
    // 3. Recover the send queue (valid rows kept, garbage counted + reported).
    await recoverStore();
    // 4. Refetch the active surfaces — the thread REMOUNTS (key bump below).
    connection.preservedText = preserved;
    connection.threadRemountTick += 1;
    connection.viewRefetchTick += 1;
    connection.storeRebuilt = true;
    announceOffline('rebuilt');
}

function guardSessionStores(): void {
    let corrupt = false;
    const stamp = sessionStorage.getItem('inbox-client-schema');
    if (stamp !== null && stamp !== CLIENT_STORE_SCHEMA) {
        corrupt = true;
    }
    const sessionLocal = sessionStorage.getItem('inbox-prefs-session-local');
    if (sessionLocal !== null) {
        try {
            JSON.parse(sessionLocal);
        } catch {
            corrupt = true;
        }
    }
    if (corrupt) {
        void recoverSession();
    } else if (stamp === null) {
        sessionStorage.setItem('inbox-client-schema', CLIENT_STORE_SCHEMA);
    }
}

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

    // EC-07: the handshake anchor — every later sync re-anchors.
    anchorServerNow(props.serverNow);

    // FS-05's guard runs BEFORE anything reads the session stores in anger.
    guardSessionStores();

    // FR-29: the machine — browser events now, Reverb's connection callbacks
    // later, the test hook always, all through the same signal seam.
    initOfflineMachine({
        buildId: props.buildId,
        xsrf: workspaceXsrf,
        onEvents: (ticketIds) => {
            // Convergence by refetch (T-02's full-row-fetch idiom): the slice
            // names what changed; the active surfaces refetch themselves.
            void ticketIds;
            connection.viewRefetchTick += 1;
            connection.threadRefetchTick += 1;
        },
        onOverflow: () => {
            connection.viewRefetchTick += 1;
            connection.threadRefetchTick += 1;
        },
        announce: announceOffline,
        onFlushed: (conversationId) => {
            if (activeThread.value === conversationId) {
                connection.threadRefetchTick += 1;
            }
            connection.viewRefetchTick += 1;
        },
    });

    // The scripted seam (FR-29): what Reverb's connection callbacks will
    // drive in production, the specs drive here — SAME machine entry points.
    (window as unknown as Record<string, unknown>).__inboxOffline = {
        drop: () => signalDrop('socket-drop'),
        up: () => signalUp(),
        state: () => ({
            state: connection.state,
            queued: connection.queued.length,
            flushed: connection.flushedCount,
            failures: connection.flushFailures.map((f) => f.reason),
            lost: connection.lostFromStore,
            skew: connection.skewDetected,
            rebuilt: connection.storeRebuilt,
            clockOffsetMs: serverClockOffsetMs(),
        }),
        flush: () => void flushQueue(),
        refreshMirror: () => void refreshQueueMirror(),
        setJitterMax: (ms: number) => setJitterMax(ms),
        recover: () => void recoverSession(),
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
    activeTicketId.value = payload.ticketId;
    mobileScreen.value = 'thread';
}

// FR-23: ⌘K / Ctrl-K opens the palette — since step 13 it rides the ONE
// FR-31 dispatcher (lib/shortcuts) with every other binding.
const paletteOpen = ref(false);

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

// FR-30: refresh is USER-initiated, never forced (the banner's own button).
function reloadPage(): void {
    window.location.reload();
}

// §9 density → the row-height variable list rows (step 9) consume.
const density = computed(() => prefsStore.density);
function setDensity(value: 'comfortable' | 'compact'): void {
    prefsStore.setDensity(value);
}

// ══ FR-31 (step 13): the keyboard map — one dispatcher over lib/shortcuts ══
const list = useLiveList();
const overlayOpen = ref(false);
const coachOpen = ref(false);

// B2-S03 (step 14): the ticket rows the open thread lifted up — the
// property panel renders the LAST row (the thread's live ticket).
type PanelTicketRow = Record<string, unknown> & {
    id: string;
    number: number | string;
    status: string;
};
const panelTickets = ref<PanelTicketRow[]>([]);
// D1: switching threads empties the panel INTO A SKELETON, never a stale
// ticket — the loading state is a shape, not a lie about the old data.
const panelLoading = ref(false);
// Each thread LIFT bumps this — the panel's timeline reloads even when the
// same ticket reopens (its own watch keys on ticket id + this tick).
const panelLoadTick = ref(0);
watch(
    () => activeThread.value,
    () => {
        panelTickets.value = [];
        panelLoading.value = activeThread.value !== null;
    },
);
const PANEL_LIVE = ['new', 'open', 'pending_customer', 'pending_internal', 'escalated'];
const panelTicket = computed(() => {
    // Same law as the thread banner: the latest LIVE ticket governs; a
    // retired linked-work child never outranks a live origin.
    const rows = panelTickets.value as { status?: string }[];
    const live = rows.filter((row) => PANEL_LIVE.includes(String(row.status ?? '')));
    return ((live.length > 0 ? live[live.length - 1] : rows[rows.length - 1]) ?? null) as never;
});
// FS-01 (step 14): the staleness banner's live state — seeded by the page
// prop, refreshed by every 30 s heartbeat. The banner needs BOTH staleness
// past the threshold AND pending work (a quiet stack is not an outage).
const projectorStale = ref<{ stale: number | null; pending: boolean }>({
    stale: props.projectorStaleSeconds,
    // The prop alone cannot know about pending work — until the first
    // heartbeat answers, an aged-but-idle stack must not banner.
    pending: false,
});
function onStaleness(payload: { stale: number | null; pending: boolean }): void {
    projectorStale.value = payload;
}
const staleBannerVisible = computed(
    () =>
        projectorStale.value.stale !== null &&
        projectorStale.value.stale > 30 &&
        projectorStale.value.pending,
);
const staleAsOf = computed(() => {
    const stale = projectorStale.value.stale ?? 0;
    const asOf = new Date(Date.now() + serverClockOffsetMs() - stale * 1000);
    return new Intl.DateTimeFormat(undefined, { hour: '2-digit', minute: '2-digit' }).format(asOf);
});

// D7: the lost race's offered next move — Unassigned, top row lit.
// B1-S04: the merge dialog over the selection.
const mergeOpen = ref(false);
// B1-S04 operation dialogs (step 14): escalate / mark-as-spam.
const operationOpen = ref(false);
const operationKind = ref<'escalate' | 'spam'>('escalate');
const operationTicketId = ref('');
function openOperation(payload: { kind: 'escalate' | 'spam'; ticketId: string }): void {
    operationKind.value = payload.kind;
    operationTicketId.value = payload.ticketId;
    operationOpen.value = true;
}
// B1-S04 split + C7 unmerge (step 14): selection arms in the thread, the
// dialog lives here; the cap card opens DIRECTLY when 3 are already live.
const splitArmTick = ref(0);
const splitUnmergeOpen = ref(false);
const splitUnmergeKind = ref<'split' | 'unmerge'>('split');
const splitMessageIds = ref<string[]>([]);
const splitTicketId = ref('');
function onOpenSplit(): void {
    const ticket = panelTicket.value as { id?: string; linked_work?: unknown[] } | null;
    splitTicketId.value = String(ticket?.id ?? '');
    if ((ticket?.linked_work?.length ?? 0) >= 3) {
        splitUnmergeKind.value = 'split';
        splitMessageIds.value = [];
        splitUnmergeOpen.value = true;
        return;
    }
    splitArmTick.value += 1;
}
function onSplitSelection(payload: { ticketId: string; messageIds: string[] }): void {
    splitTicketId.value = payload.ticketId;
    splitMessageIds.value = payload.messageIds;
    splitUnmergeKind.value = 'split';
    splitUnmergeOpen.value = true;
}
function onOpenUnmerge(): void {
    const ticket = panelTicket.value as { id?: string } | null;
    splitTicketId.value = String(ticket?.id ?? '');
    splitUnmergeKind.value = 'unmerge';
    splitUnmergeOpen.value = true;
}
const mergeIds = ref<string[]>([]);
function openMerge(): void {
    mergeIds.value = [...list.selectedInView];
    mergeOpen.value = mergeIds.value.length >= 2;
}
function onMerged(): void {
    list.clearSelection();
    onPanelChanged();
}
function onClaimNext(): void {
    activeView.value = 'unassigned';
    list.activeId = null;
    list.activateStep(1);
}
function onPanelChanged(): void {
    connection.viewRefetchTick += 1;
    connection.threadRefetchTick += 1;
}
const actionToast = ref('');

// The map's ticket actions target the ACTIVE LIST ROW when one is lit,
// else the open thread's ticket (Gmail's semantics, US-01's shift).
const activeTicketId = ref<string | null>(props.initialThread?.ticket_id ?? null);
// D1 (advisor ruling 2026-08-13): a lit id whose row has LEFT the view is
// not "lit" — the store clears it on refetch/flush, and these resolvers
// fall back to the open thread rather than dereferencing a ghost. The old
// shape returned null there, and every ticket action after a keyboard
// claim (the claim-refetch drops the row) became a silent no-op — the
// defect that left all sixty shift tickets claimed-but-unresolved.
function currentTicketId(): string | null {
    if (list.activeId && list.rows[list.activeId]) {
        return list.activeId;
    }
    return activeTicketId.value;
}
function currentConversationId(): string | null {
    if (list.activeId) {
        const row = list.rows[list.activeId];
        if (row) return String(row.conversation_id);
    }
    return activeThread.value;
}
// BR-01: an action with no target anywhere (no lit row, no open thread)
// says so instead of doing nothing.
function requireTarget(id: string | null): boolean {
    if (id !== null) return true;
    toast(props.t.shortcuts.no_target ?? '');
    return false;
}

function toast(message: string): void {
    actionToast.value = message;
    setTimeout(() => {
        if (actionToast.value === message) actionToast.value = '';
    }, 4_000);
}

function composerCommand(command: string): void {
    window.dispatchEvent(new CustomEvent('inbox:composer-command', { detail: { command } }));
}

// ── The action palettes (A/P/T/S) on the step-13 doors ────────────────────
const palette = ref<{
    kind: 'assign' | 'priority' | 'tags' | 'snooze' | null;
    items: PaletteItem[];
    currentTags: string[];
}>({ kind: null, items: [], currentTags: [] });

function closeActionPalette(): void {
    palette.value = { kind: null, items: [], currentTags: [] };
}

async function openAssignPalette(): Promise<void> {
    if (!props.can.tickets_assign || !requireTarget(currentTicketId())) return;
    palette.value = { kind: 'assign', items: [], currentTags: [] };
    await searchAssignees('');
}
async function searchAssignees(q: string): Promise<void> {
    const response = await fetch('/inbox/assignees?q=' + encodeURIComponent(q), {
        headers: { Accept: 'application/json' },
    });
    if (!response.ok) return;
    const body = (await response.json()) as { assignees: { id: string; name: string }[] };
    if (palette.value.kind === 'assign') {
        palette.value.items = body.assignees.map((a) => ({ id: a.id, label: a.name }));
    }
}

function openPriorityPalette(): void {
    if (!props.can.tickets_priority_change || !requireTarget(currentTicketId())) return;
    palette.value = {
        kind: 'priority',
        currentTags: [],
        items: (['urgent', 'high', 'normal', 'low'] as const).map((level) => ({
            id: level,
            label: props.t.action_palette['priority_' + level] ?? level,
        })),
    };
}

async function openTagPalette(): Promise<void> {
    if (!props.can.tickets_tags_manage || !requireTarget(currentTicketId())) return;
    const id = currentTicketId();
    const row = id ? list.rows[id] : null;
    palette.value = {
        kind: 'tags',
        items: [],
        currentTags: Array.isArray(row?.tags) ? (row?.tags as string[]) : [],
    };
    await searchTags('');
}
async function searchTags(q: string): Promise<void> {
    const response = await fetch('/inbox/tags/suggest?q=' + encodeURIComponent(q), {
        headers: { Accept: 'application/json' },
    });
    if (!response.ok) return;
    const body = (await response.json()) as { tags: string[] };
    if (palette.value.kind === 'tags') {
        palette.value.items = body.tags
            .filter((tag) => !palette.value.currentTags.includes(tag))
            .map((tag) => ({ id: tag, label: tag }));
    }
}

function openSnoozePalette(): void {
    if (!props.can.tickets_status_change || !requireTarget(currentConversationId())) return;
    palette.value = {
        kind: 'snooze',
        currentTags: [],
        items: [
            {
                id: 'pending_customer',
                label: props.t.action_palette.snooze_pending ?? '',
                hint: props.t.action_palette.snooze_hint ?? '',
            },
        ],
    };
}

async function onPalettePick(id: string): Promise<void> {
    const kind = palette.value.kind;
    const ticketId = currentTicketId();
    const conversationId = currentConversationId();
    closeActionPalette();
    if (kind === 'assign' && ticketId) {
        const result = await runCommand({
            optimistic: () => null,
            execute: () =>
                fetch(`/inbox/t/${ticketId}/assign`, {
                    method: 'POST',
                    headers: actionHeaders(),
                    body: JSON.stringify({ assignee_id: id }),
                }),
            rollback: () => undefined,
        });
        finishRowAction(result.ok, ticketId, result.code);
        return;
    }
    if (kind === 'priority' && ticketId) {
        // T-06: optimistic priority patch, rolled back visibly on refusal.
        const result = await runCommand({
            optimistic: () => {
                const row = list.rows[ticketId];
                const previous = row?.priority;
                if (row) row.priority = id;
                return previous;
            },
            execute: () =>
                fetch(`/inbox/t/${ticketId}/priority`, {
                    method: 'POST',
                    headers: actionHeaders(),
                    body: JSON.stringify({ priority: id }),
                }),
            rollback: (previous) => {
                const row = list.rows[ticketId];
                if (row) row.priority = previous;
            },
        });
        finishRowAction(result.ok, ticketId, result.code);
        return;
    }
    if (kind === 'tags' && ticketId) {
        const result = await runCommand({
            optimistic: () => null,
            execute: () =>
                fetch(`/inbox/t/${ticketId}/tags/add`, {
                    method: 'POST',
                    headers: actionHeaders(),
                    body: JSON.stringify({ tags: [id] }),
                }),
            rollback: () => undefined,
        });
        finishRowAction(result.ok, ticketId, result.code);
        return;
    }
    if (kind === 'snooze' && conversationId) {
        const result = await runCommand({
            optimistic: () => null,
            execute: () =>
                fetch(`/inbox/thread/${conversationId}/transition`, {
                    method: 'POST',
                    headers: actionHeaders(),
                    body: JSON.stringify({ to: 'pending_customer' }),
                }),
            rollback: () => undefined,
        });
        finishRowAction(result.ok, ticketId, result.code);
    }
}

async function onPaletteRemoveTag(tag: string): Promise<void> {
    const ticketId = currentTicketId();
    if (!ticketId) return;
    palette.value.currentTags = palette.value.currentTags.filter((current) => current !== tag);
    const result = await runCommand({
        optimistic: () => null,
        execute: () =>
            fetch(`/inbox/t/${ticketId}/tags/remove`, {
                method: 'POST',
                headers: actionHeaders(),
                body: JSON.stringify({ tags: [tag] }),
            }),
        rollback: () => undefined,
    });
    finishRowAction(result.ok, ticketId, result.code);
}

function onPaletteSearch(q: string): void {
    if (palette.value.kind === 'assign') void searchAssignees(q);
    if (palette.value.kind === 'tags') void searchTags(q);
}

function actionHeaders(): Record<string, string> {
    return {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-XSRF-TOKEN': workspaceXsrf(),
    };
}

// After any row action: refetch the view (the row may move or leave) and
// surface refusals (BR-01 — visible, never silent).
function finishRowAction(ok: boolean, ticketId: string | null, code: string | null): void {
    void ticketId;
    connection.viewRefetchTick += 1;
    connection.threadRefetchTick += 1;
    if (!ok) {
        toast((props.t.shortcuts.action_refused ?? '').replace(':reason', code ?? 'error'));
    }
}

// I — claim, the T-06 showcase: the row optimistically becomes mine and the
// loser's rollback names the winner (FR-16's race message).
async function claimActive(): Promise<void> {
    if (!props.can.tickets_assign_self) return;
    const ticketId = currentTicketId();
    if (!requireTarget(ticketId)) return;
    if (!ticketId) return; // narrowing for the closures below
    const result = await runCommand({
        optimistic: () => {
            const row = list.rows[ticketId];
            const previous = row?.assignee_id;
            if (row) row.assignee_id = props.userId;
            return previous;
        },
        execute: () =>
            fetch(`/inbox/t/${ticketId}/claim`, { method: 'POST', headers: actionHeaders() }),
        rollback: (previous) => {
            const row = list.rows[ticketId];
            if (row) row.assignee_id = previous;
        },
    });
    if (!result.ok && result.code === 'already_claimed') {
        toast(props.t.shortcuts.already_claimed ?? '');
        connection.viewRefetchTick += 1;
        return;
    }
    finishRowAction(result.ok, ticketId, result.code);
}

async function resolveActive(): Promise<void> {
    if (!props.can.tickets_status_change) return;
    const conversationId = currentConversationId();
    if (!requireTarget(conversationId)) return;
    if (!conversationId) return; // narrowing for the closure below
    const result = await runCommand({
        optimistic: () => null,
        execute: () =>
            fetch(`/inbox/thread/${conversationId}/transition`, {
                method: 'POST',
                headers: actionHeaders(),
                body: JSON.stringify({ to: 'resolve' }),
            }),
        rollback: () => undefined,
    });
    finishRowAction(result.ok, currentTicketId(), result.code);
}

function shortcutEscape(): void {
    if (overlayOpen.value) {
        overlayOpen.value = false;
        return;
    }
    if (palette.value.kind !== null) {
        closeActionPalette();
        return;
    }
    if (paletteOpen.value) {
        paletteOpen.value = false;
        return;
    }
    if (drawerOpen.value) {
        drawerOpen.value = false;
        return;
    }
    if (list.selected.length > 0) {
        list.clearSelection();
        return;
    }
    list.activeId = null;
}

let detachShortcuts: (() => void) | null = null;
onMounted(() => {
    detachShortcuts = attachShortcuts({
        listNext: () => list.activateStep(1),
        listPrev: () => list.activateStep(-1),
        openActive: () => {
            const id = list.activeId;
            const row = id ? list.rows[id] : null;
            if (id && row) {
                activeTicketId.value = id;
                openThread({
                    ticketId: id,
                    conversationId: String(row.conversation_id),
                    number: (row.number ?? null) as number | string | null,
                });
            }
        },
        selectActive: () => {
            if (list.activeId) list.toggleSelect(list.activeId);
        },
        rangeSelectActive: () => {
            if (!list.activeId) return;
            const anchor = list.selected[list.selected.length - 1];
            if (anchor) {
                list.rangeSelect(anchor, list.activeId);
            } else {
                list.toggleSelect(list.activeId);
            }
        },
        escape: shortcutEscape,
        goUnassigned: () => (activeView.value = 'unassigned'),
        goMyOpen: () => (activeView.value = 'my_open'),
        goSlaAtRisk: () => (activeView.value = 'sla_at_risk'),
        goMentions: () => (activeView.value = 'mentions'),
        openPalette: () => (paletteOpen.value = true),
        assignToMe: () => void claimActive(),
        assignPalette: () => void openAssignPalette(),
        resolve: () => void resolveActive(),
        priorityPalette: openPriorityPalette,
        tagPalette: () => void openTagPalette(),
        snoozePalette: openSnoozePalette,
        focusReply: () => composerCommand('focus-reply'),
        noteMode: () => composerCommand('note-mode'),
        send: () => composerCommand('send'),
        sendAndResolve: () => composerCommand('send-resolve'),
        openOverlay: () => (overlayOpen.value = true),
    });

    // §8 first-run: the coach marks show until dismissed forever.
    if (!prefsStore.coachDismissed) {
        coachOpen.value = true;
    }
});
onBeforeUnmount(() => detachShortcuts?.());

function dismissCoach(): void {
    coachOpen.value = false;
    prefsStore.setCoachDismissed(true);
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

        <!-- FR-31 (step 13): the ? overlay, the A/P/T/S palettes, first-run. -->
        <ShortcutOverlay
            :open="overlayOpen"
            :t="t.shortcuts"
            @close="overlayOpen = false"
            @open-coach="
                () => {
                    overlayOpen = false;
                    coachOpen = true;
                }
            "
        />
        <ActionPalette
            :open="palette.kind !== null"
            :kind="palette.kind"
            :title="palette.kind ? (t.action_palette['title_' + palette.kind] ?? '') : ''"
            :searchable="palette.kind === 'assign' || palette.kind === 'tags'"
            :items="palette.items"
            :current-tags="palette.currentTags"
            :t="t.action_palette"
            @close="closeActionPalette"
            @pick="onPalettePick"
            @search="onPaletteSearch"
            @remove-tag="onPaletteRemoveTag"
        />
        <CoachMarks :open="coachOpen" :t="t.coach" @dismiss="dismissCoach" />
        <MergeDialog
            :open="mergeOpen"
            :ticket-ids="mergeIds"
            :t="t.ticket_panel"
            :xsrf="workspaceXsrf"
            @close="mergeOpen = false"
            @merged="onMerged"
        />
        <OperationDialog
            :open="operationOpen"
            :kind="operationKind"
            :ticket-id="operationTicketId"
            :t="t.ticket_panel"
            :xsrf="workspaceXsrf"
            @close="operationOpen = false"
            @done="onPanelChanged"
        />
        <SplitUnmergeDialog
            :open="splitUnmergeOpen"
            :kind="splitUnmergeKind"
            :ticket-id="splitTicketId"
            :ticket-number="(panelTicket as { number?: number | string } | null)?.number ?? null"
            :message-ids="splitMessageIds"
            :linked-work="(panelTicket as { linked_work?: never[] } | null)?.linked_work ?? []"
            :unmerge-until="(panelTicket as { unmerge_until?: string | null } | null)?.unmerge_until ?? null"
            :merged-message-count="
                (panelTicket as { merged_message_count?: number | null } | null)
                    ?.merged_message_count ?? null
            "
            :merged-into-number="
                (panelTicket as { merged_into_number?: number | string | null } | null)
                    ?.merged_into_number ?? null
            "
            :t="t.ticket_panel"
            :xsrf="workspaceXsrf"
            @close="splitUnmergeOpen = false"
            @done="onPanelChanged"
            @split-instead="
                () => {
                    splitUnmergeOpen = false;
                    splitArmTick += 1;
                }
            "
        />
        <p
            v-if="actionToast"
            data-testid="action-toast"
            role="status"
            class="fixed bottom-4 start-1/2 z-50 -translate-x-1/2 rounded-md px-3 py-2 text-xs shadow-lg"
            :style="{ background: 'var(--ds-text-ink)', color: 'var(--ds-page-bg)' }"
        >
            {{ actionToast }}
        </p>

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
            @staleness="onStaleness"
            @set-density="setDensity"
            @open-thread="onBellThread"
            @mute="(until) => prefsStore.setMutedUntil(until)"
            @open-palette="paletteOpen = true"
        />

        <!-- ═══ FR-29/FR-30/FS-05 (step 13): the whole-workspace banner stack
             (B1-S02's drawn strips). Live region: state flips are announced. -->
        <p class="sr-only" role="status" aria-live="polite" data-testid="offline-announcement">
            {{ offlineAnnouncement }}
        </p>
        <p
            v-if="connection.state === 'reconnecting'"
            data-testid="reconnecting-pill"
            role="status"
            class="px-3 py-2 text-xs font-semibold"
            :style="{ background: 'var(--ds-window-soon-bg)', color: 'var(--ds-window-soon-fg)' }"
        >
            ◌ {{ t.offline.reconnecting }}
        </p>
        <p
            v-else-if="connection.state === 'offline'"
            data-testid="offline-banner"
            role="status"
            class="px-3 py-2 text-xs font-semibold"
            :style="{ background: 'var(--ds-inset-bg)', color: 'var(--ds-text-ink)' }"
        >
            {{ t.offline.offline_banner }}
        </p>
        <!-- FS-01 (step 14): the drawn "Live updates delayed" banner — the
             projector's freeze made visible, with the correct-as-of time. -->
        <p
            v-if="staleBannerVisible"
            data-testid="projector-stale-banner"
            role="status"
            class="px-3 py-2 text-xs font-semibold"
            :style="{ background: 'var(--ds-inset-bg)', color: 'var(--ds-text-ink)' }"
        >
            {{ (t.offline.stale_banner ?? '').replace(':time', staleAsOf) }}
        </p>
        <p
            v-if="connection.skewDetected"
            data-testid="skew-banner"
            class="flex items-center gap-2 px-3 py-2 text-xs"
            :style="{
                background: 'var(--ds-panel-bg)',
                color: 'var(--ds-text-primary)',
                borderBottom: '1px solid var(--ds-border)',
            }"
        >
            {{ t.offline.new_version }}
            <button
                type="button"
                data-testid="skew-refresh"
                class="ms-auto rounded border px-2 py-0.5 text-[11px] font-semibold"
                :style="{ borderColor: 'var(--ds-border-strong)' }"
                @click="reloadPage"
            >
                {{ t.offline.refresh }}
            </button>
        </p>
        <p
            v-if="connection.storeRebuilt"
            data-testid="store-rebuilt-banner"
            role="status"
            class="px-3 py-2 text-xs"
            :style="{ background: 'var(--ds-inset-bg)', color: 'var(--ds-text-ink)' }"
        >
            {{ t.offline.session_rebuilt }}
            <template v-if="connection.lostFromStore > 0">
                {{
                    (t.offline.sends_lost ?? '').replace(':count', String(connection.lostFromStore))
                }}
            </template>
        </p>
        <p
            v-for="failure in connection.flushFailures"
            :key="failure.id"
            data-testid="flush-failure-banner"
            role="status"
            class="px-3 py-2 text-xs"
            :style="{ background: 'var(--ds-warn-bg)', color: 'var(--ds-warn-text)' }"
        >
            {{ (t.offline.flush_failed ?? '').replace(':reason', failure.reason) }}
        </p>

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
                    :merge-allowed="can.tickets_merge"
                    :view="activeView"
                    :unassigned-count="views.find((v) => v.key === 'unassigned')?.count ?? null"
                    @claim-next="onClaimNext"
                    :mobile="false"
                    :user-id="userId"
                    :can="{
                        assignSelf: can.tickets_assign_self,
                        assign: can.tickets_assign,
                        statusChange: can.tickets_status_change,
                        priority: can.tickets_priority_change,
                    }"
                    :t="{
                        list: t.list,
                        row: t.row,
                        bulk: t.bulk,
                        filter_bar: t.filter_bar,
                        mobile: t.mobile,
                    }"
                    @merge-selection="openMerge"
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
                    :key="activeThread + (activeAnchor ?? '') + '-' + connection.threadRemountTick"
                    :conversation-id="activeThread"
                    :initial-anchor="activeAnchor"
                    :split-arm-tick="splitArmTick"
                    :t="t.thread"
                    :composer-t="t.composer"
                    :voice-flag="features.voice_recording"
                    @tickets="
                        (rows) => {
                            panelTickets = rows;
                            panelLoading = false;
                            panelLoadTick += 1;
                        }
                    "
                    @open-rescue="activeView = 'rescue'"
                    @split-selection="onSplitSelection"
                    @scope-lost="
                        () => {
                            panelTickets = [];
                            panelLoading = false;
                        }
                    "
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
                <ContextPanel :badges="tabBadges" :t="{ tabs: t.tabs, shell: t.shell }">
                    <template #ticket>
                        <TicketPanel
                            :ticket="panelTicket"
                            :conversation-id="activeThread"
                            :can="can"
                            :t="t.ticket_panel"
                            :xsrf="workspaceXsrf"
                            :loading="panelLoading"
                            :load-tick="panelLoadTick"
                            :transitions="transitions"
                            @changed="onPanelChanged"
                            @claim-next="onClaimNext"
                            @open-operation="openOperation"
                    @open-split="onOpenSplit"
                    @open-unmerge="onOpenUnmerge"
                    @open-survivor="(cid) => (activeThread = cid)"
                        />
                    </template>
                </ContextPanel>
            </div>
            <div
                v-else-if="contextAsDrawer && drawerOpen"
                id="inbox-context"
                data-testid="context-drawer"
                class="fixed inset-y-0 end-0 z-30 w-[380px] border-s shadow-2xl"
                :style="{ background: 'var(--ds-panel-bg)', borderColor: 'var(--ds-border)' }"
                tabindex="-1"
            >
                <ContextPanel :badges="tabBadges" :t="{ tabs: t.tabs, shell: t.shell }">
                    <template #ticket>
                        <TicketPanel
                            :ticket="panelTicket"
                            :conversation-id="activeThread"
                            :can="can"
                            :t="t.ticket_panel"
                            :xsrf="workspaceXsrf"
                            :loading="panelLoading"
                            :load-tick="panelLoadTick"
                            :transitions="transitions"
                            @changed="onPanelChanged"
                            @claim-next="onClaimNext"
                            @open-operation="openOperation"
                    @open-split="onOpenSplit"
                    @open-unmerge="onOpenUnmerge"
                    @open-survivor="(cid) => (activeThread = cid)"
                        />
                    </template>
                </ContextPanel>
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
                        :merge-allowed="can.tickets_merge"
                        :view="activeView"
                        :unassigned-count="
                            views.find((v) => v.key === 'unassigned')?.count ?? null
                        "
                        @claim-next="onClaimNext"
                        :mobile="true"
                        :user-id="userId"
                        :can="{
                            assignSelf: can.tickets_assign_self,
                            assign: can.tickets_assign,
                            statusChange: can.tickets_status_change,
                            priority: can.tickets_priority_change,
                        }"
                        :t="{
                            list: t.list,
                            row: t.row,
                            bulk: t.bulk,
                            filter_bar: t.filter_bar,
                            mobile: t.mobile,
                        }"
                        @merge-selection="openMerge"
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
                        :key="
                            activeThread + (activeAnchor ?? '') + '-' + connection.threadRemountTick
                        "
                        :conversation-id="activeThread"
                        :initial-anchor="activeAnchor"
                    :split-arm-tick="splitArmTick"
                        :t="t.thread"
                        :composer-t="t.composer"
                        :voice-flag="features.voice_recording"
                        @tickets="
                            (rows) => {
                                panelTickets = rows;
                                panelLoading = false;
                                panelLoadTick += 1;
                            }
                        "
                        @open-rescue="activeView = 'rescue'"
                        @split-selection="onSplitSelection"
                    @scope-lost="
                            () => {
                                panelTickets = [];
                                panelLoading = false;
                            }
                        "
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
                        <ContextPanel :badges="tabBadges" :t="{ tabs: t.tabs, shell: t.shell }">
                            <template #ticket>
                                <TicketPanel
                                    :ticket="panelTicket"
                                    :conversation-id="activeThread"
                                    :can="can"
                                    :t="t.ticket_panel"
                                    :xsrf="workspaceXsrf"
                                    :loading="panelLoading"
                                    :load-tick="panelLoadTick"
                                    :transitions="transitions"
                                    @changed="onPanelChanged"
                                    @claim-next="onClaimNext"
                                    @open-operation="openOperation"
                    @open-split="onOpenSplit"
                    @open-unmerge="onOpenUnmerge"
                    @open-survivor="(cid) => (activeThread = cid)"
                                />
                            </template>
                        </ContextPanel>
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
