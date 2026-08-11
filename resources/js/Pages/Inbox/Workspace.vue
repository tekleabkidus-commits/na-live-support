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
import ContextPanel from '../../Components/Inbox/ContextPanel.vue';
import ListPane from '../../Components/Inbox/List/ListPane.vue';
import TopBar from '../../Components/Inbox/TopBar.vue';
import ViewsRail from '../../Components/Inbox/ViewsRail.vue';
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
}>();

const prefsStore = useInboxPrefs();
prefsStore.hydrate(props.prefs as Parameters<typeof prefsStore.hydrate>[0]);

// ── Breakpoints (FR-02) ────────────────────────────────────────────────────
const width = ref(typeof window === 'undefined' ? 1440 : window.innerWidth);
function onResize(): void {
    width.value = window.innerWidth;
}
onMounted(() => window.addEventListener('resize', onResize));
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
const mobileScreen = ref<'list' | 'thread'>('list');
const viewsSheetOpen = ref(false);
const contextSheetOpen = ref(false);
const drawerOpen = ref(false);

// The ACTIVE view (rail clicks set it; EC-09's fallback returns to My Open).
const activeView = ref('my_open');

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

        <TopBar
            :tenant-name="tenantName"
            :health="health"
            :can-create-ticket="can.tickets_create"
            :density="density"
            :t="{ topbar: t.topbar, health: t.health, density: t.density }"
            @set-density="setDensity"
        />

        <!-- ═══ Desktop / tablet (≥768): the region row ═══ -->
        <div v-if="!isMobile" class="flex min-h-0 flex-1">
            <ViewsRail
                :views="views"
                :saved-views="savedViews"
                :collapsed="railCollapsed"
                :t="{ views: t.views, shell: t.shell }"
                @toggle-collapse="toggleRail"
                @select="(v: string) => (activeView = v)"
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
                    :t="{ list: t.list, row: t.row, bulk: t.bulk }"
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
                <p class="px-4 text-sm" :style="{ color: 'var(--ds-text-muted)' }">
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
                        :t="{ list: t.list, row: t.row, bulk: t.bulk }"
                        @open-ticket="mobileScreen = 'thread'"
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
                            :collapsed="false"
                            :t="{ views: t.views, shell: t.shell }"
                            @select="
                                (v: string) => {
                                    activeView = v;
                                    viewsSheetOpen = false;
                                }
                            "
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
                    class="min-h-0 flex-1 overflow-y-auto"
                    :style="{ background: 'var(--ds-canvas-bg)' }"
                    tabindex="-1"
                >
                    <p class="p-4 text-sm" :style="{ color: 'var(--ds-text-muted)' }">
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
