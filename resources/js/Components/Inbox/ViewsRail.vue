<script setup lang="ts">
// R1 — the views rail (FR-01: 240 px, collapsible to 56 px icons; the
// collapse persists via prefs). Step 8 rendered the anatomy; STEP 12 adds
// B1-S03's full behaviors: FR-08's four labelled sections (System / Queues /
// Shared / Mine, in that order), drag-to-reorder WITHIN a section with the
// Alt↑/Alt↓ keyboard equivalent (§13 — fully operable without a pointer),
// order persisted per user; the Shared empty-state card + "New saved view";
// the FR-06 editor with duplicate-to-edit on shared views. Queues render
// the Doc 11 SEAM fixture only — membership/config stay Doc 11's.
// §13: every count carries an accessible label ("My Open, 12 tickets").
import { computed, ref } from 'vue';

const props = defineProps<{
    views: { key: string; hash: string; count: number }[];
    savedViews: { id: string; name: string; own: boolean; shared: boolean }[];
    queues: { id: string; name: string; count: number }[];
    viewOrder: string[];
    canManage: boolean;
    canShare: boolean;
    collapsed: boolean;
    t: { views: Record<string, string>; shell: Record<string, string> };
}>();

const emit = defineEmits<{
    (e: 'toggle-collapse'): void;
    (e: 'select', view: string): void;
    (e: 'select-saved', id: string): void;
    (e: 'reorder', order: string[]): void;
    (e: 'new-view'): void;
    (e: 'edit-view', id: string): void;
    (e: 'duplicate-view', id: string): void;
}>();

const ICONS: Record<string, string> = {
    my_open: '◧',
    unassigned: '◇',
    mentions: '@',
    following: '☆',
    sla_at_risk: '▲',
    expiring_windows: '⏱',
    rescue: '⚑',
};

// B1-S03's DRAWN system order is the default (the frame is law); the
// user's persisted arrangement overrides it.
const CANONICAL = [
    'my_open',
    'unassigned',
    'mentions',
    'following',
    'sla_at_risk',
    'expiring_windows',
    'rescue',
];

// FR-08: the persisted order is a flat token list ('sys:my_open',
// 'queue:{id}', 'shared:{id}', 'mine:{id}'); each SECTION orders its own
// members by their token position — unknown members keep the fallback order.
function ordered<T>(items: T[], token: (item: T) => string, fallback?: (item: T) => number): T[] {
    const position = new Map(props.viewOrder.map((entry, index) => [entry, index]));
    const base = 1_000_000; // persisted entries always outrank fallbacks
    return [...items].sort((a, b) => {
        const pa = position.get(token(a)) ?? base + (fallback?.(a) ?? 0);
        const pb = position.get(token(b)) ?? base + (fallback?.(b) ?? 0);
        return pa - pb;
    });
}

const systemViews = computed(() =>
    ordered(
        props.views,
        (v) => 'sys:' + v.key,
        (v) => {
            const at = CANONICAL.indexOf(v.key);
            return at === -1 ? CANONICAL.length : at;
        },
    ),
);
const queueViews = computed(() => ordered(props.queues, (q) => 'queue:' + q.id));
const shared = computed(() =>
    ordered(
        props.savedViews.filter((v) => v.shared && !v.own),
        (v) => 'shared:' + v.id,
    ),
);
const mine = computed(() =>
    ordered(
        props.savedViews.filter((v) => v.own),
        (v) => 'mine:' + v.id,
    ),
);

function countLabel(viewKey: string, count: number): string {
    return (props.t.views.count_label ?? '')
        .replace(':view', props.t.views[viewKey] ?? viewKey)
        .replace(':count', String(count));
}

// ── Reordering (FR-08): drag, or Alt↑ / Alt↓ ──────────────────────────────
const dragToken = ref<string | null>(null);
const dropToken = ref<string | null>(null);

function sectionTokens(section: string): string[] {
    switch (section) {
        case 'sys':
            return systemViews.value.map((v) => 'sys:' + v.key);
        case 'queue':
            return queueViews.value.map((q) => 'queue:' + q.id);
        case 'shared':
            return shared.value.map((v) => 'shared:' + v.id);
        default:
            return mine.value.map((v) => 'mine:' + v.id);
    }
}

function persistSection(section: string, tokens: string[]): void {
    // The flat pref list keeps other sections' entries; this section's are
    // replaced in place (appended at the end when previously unknown).
    const others = props.viewOrder.filter((entry) => !entry.startsWith(section + ':'));
    emit('reorder', [...others, ...tokens]);
}

function move(section: string, token: string, delta: number): void {
    const tokens = sectionTokens(section);
    const from = tokens.indexOf(token);
    const to = from + delta;
    if (from === -1 || to < 0 || to >= tokens.length) return;
    tokens.splice(from, 1);
    tokens.splice(to, 0, token);
    persistSection(section, tokens);
}

function onRowKeydown(event: KeyboardEvent, section: string, token: string): void {
    if (!event.altKey) return;
    if (event.key === 'ArrowUp') {
        event.preventDefault();
        move(section, token, -1);
    } else if (event.key === 'ArrowDown') {
        event.preventDefault();
        move(section, token, 1);
    }
}

function onDragStart(token: string): void {
    dragToken.value = token;
}

function onDragOver(event: DragEvent, token: string): void {
    if (dragToken.value === null || dragToken.value === token) return;
    if (dragToken.value.split(':')[0] !== token.split(':')[0]) return; // within-section only
    event.preventDefault();
    dropToken.value = token;
}

function onDrop(section: string, token: string): void {
    const from = dragToken.value;
    dragToken.value = null;
    dropToken.value = null;
    if (from === null || from === token) return;
    const tokens = sectionTokens(section).filter((entry) => entry !== from);
    const at = tokens.indexOf(token);
    tokens.splice(at, 0, from);
    persistSection(section, tokens);
}
</script>

<template>
    <nav
        data-testid="views-rail"
        :data-collapsed="collapsed"
        :aria-label="t.shell.region_views"
        class="flex h-full shrink-0 flex-col overflow-y-auto border-e py-2"
        :class="collapsed ? 'w-14 items-center' : 'w-60'"
        :style="{ background: 'var(--ds-panel-soft)', borderColor: 'var(--ds-border)' }"
    >
        <button
            type="button"
            data-testid="rail-collapse"
            class="mb-2 self-end rounded-md px-2 py-1 text-xs"
            :class="collapsed ? 'self-center' : 'me-2'"
            :style="{ color: 'var(--ds-text-muted)' }"
            :aria-label="collapsed ? t.shell.expand_rail : t.shell.collapse_rail"
            :aria-expanded="!collapsed"
            @click="emit('toggle-collapse')"
        >
            <span aria-hidden="true">{{ collapsed ? '⇥' : '⇤' }}</span>
        </button>

        <p
            v-if="!collapsed"
            class="mb-1 px-3 text-[10px] font-semibold uppercase tracking-wide"
            :style="{ color: 'var(--ds-text-label)' }"
        >
            {{ t.views.section_system }}
        </p>
        <ul class="w-full space-y-0.5" :class="collapsed ? 'px-1' : 'px-2'">
            <li v-for="view in systemViews" :key="view.key">
                <a
                    href="#"
                    :data-view="view.key"
                    class="flex items-center gap-2 rounded-md px-2 py-1.5 text-sm"
                    :class="collapsed ? 'justify-center' : ''"
                    :style="{
                        color: 'var(--ds-text-primary)',
                        outline:
                            dropToken === 'sys:' + view.key
                                ? '2px solid var(--ds-accent)'
                                : undefined,
                    }"
                    :aria-label="countLabel(view.key, view.count)"
                    :title="collapsed ? t.views[view.key] : undefined"
                    :draggable="!collapsed"
                    @click.prevent="emit('select', view.key)"
                    @keydown="(e) => onRowKeydown(e, 'sys', 'sys:' + view.key)"
                    @dragstart="onDragStart('sys:' + view.key)"
                    @dragover="(e) => onDragOver(e, 'sys:' + view.key)"
                    @drop="onDrop('sys', 'sys:' + view.key)"
                >
                    <span
                        v-if="!collapsed"
                        aria-hidden="true"
                        class="cursor-grab text-[11px]"
                        :style="{ color: 'var(--ds-text-faint)' }"
                        >⠿</span
                    >
                    <span aria-hidden="true" :style="{ color: 'var(--ds-text-muted)' }">{{
                        ICONS[view.key] ?? '▤'
                    }}</span>
                    <template v-if="!collapsed">
                        <span class="min-w-0 flex-1 truncate">{{
                            t.views[view.key] ?? view.key
                        }}</span>
                        <span
                            :data-count="view.key"
                            class="rounded-full px-1.5 text-xs font-semibold tabular-nums"
                            :style="{
                                background: 'var(--ds-inset-bg)',
                                color: 'var(--ds-text-secondary)',
                            }"
                            aria-hidden="true"
                            >{{ view.count }}</span
                        >
                    </template>
                </a>
            </li>
        </ul>
        <p
            v-if="!collapsed"
            class="mt-1 px-3 text-[10px] leading-relaxed"
            :style="{ color: 'var(--ds-text-faint)' }"
        >
            {{ t.views.reorder_hint }}
        </p>

        <template v-if="!collapsed">
            <!-- Queues — the Doc 11 seam TYPE; hidden when the seam is empty. -->
            <template v-if="queueViews.length">
                <p
                    class="mb-1 mt-4 px-3 text-[10px] font-semibold uppercase tracking-wide"
                    :style="{ color: 'var(--ds-text-label)' }"
                >
                    {{ t.views.section_queues }}
                </p>
                <ul class="w-full space-y-0.5 px-2" data-testid="queues-section">
                    <li v-for="queue in queueViews" :key="queue.id">
                        <a
                            href="#"
                            :data-queue-view="queue.id"
                            class="flex items-center gap-2 rounded-md px-2 py-1.5 text-sm"
                            :style="{
                                color: 'var(--ds-text-primary)',
                                outline:
                                    dropToken === 'queue:' + queue.id
                                        ? '2px solid var(--ds-accent)'
                                        : undefined,
                            }"
                            :aria-label="
                                (t.views.count_label ?? '')
                                    .replace(':view', queue.name)
                                    .replace(':count', String(queue.count))
                            "
                            draggable="true"
                            @click.prevent="emit('select', 'queue:' + queue.id)"
                            @keydown="(e) => onRowKeydown(e, 'queue', 'queue:' + queue.id)"
                            @dragstart="onDragStart('queue:' + queue.id)"
                            @dragover="(e) => onDragOver(e, 'queue:' + queue.id)"
                            @drop="onDrop('queue', 'queue:' + queue.id)"
                        >
                            <span aria-hidden="true" :style="{ color: 'var(--ds-text-muted)' }"
                                >▤</span
                            >
                            <span class="min-w-0 flex-1 truncate">{{ queue.name }}</span>
                            <span
                                class="rounded-full px-1.5 text-xs font-semibold tabular-nums"
                                :style="{
                                    background: 'var(--ds-inset-bg)',
                                    color: 'var(--ds-text-secondary)',
                                }"
                                aria-hidden="true"
                                >{{ queue.count }}</span
                            >
                        </a>
                    </li>
                </ul>
            </template>

            <p
                class="mb-1 mt-4 px-3 text-[10px] font-semibold uppercase tracking-wide"
                :style="{ color: 'var(--ds-text-label)' }"
            >
                {{ t.views.section_shared }}
            </p>
            <ul v-if="shared.length" class="w-full space-y-0.5 px-2">
                <li v-for="view in shared" :key="view.id">
                    <a
                        href="#"
                        :data-saved-view="view.id"
                        class="group flex items-center gap-2 rounded-md px-2 py-1.5 text-sm"
                        :style="{
                            color: 'var(--ds-text-primary)',
                            outline:
                                dropToken === 'shared:' + view.id
                                    ? '2px solid var(--ds-accent)'
                                    : undefined,
                        }"
                        draggable="true"
                        @click.prevent="emit('select-saved', view.id)"
                        @keydown="(e) => onRowKeydown(e, 'shared', 'shared:' + view.id)"
                        @dragstart="onDragStart('shared:' + view.id)"
                        @dragover="(e) => onDragOver(e, 'shared:' + view.id)"
                        @drop="onDrop('shared', 'shared:' + view.id)"
                    >
                        <span aria-hidden="true" :style="{ color: 'var(--ds-text-muted)' }">◈</span>
                        <span class="min-w-0 flex-1 truncate">{{ view.name }}</span>
                        <!-- FR-06: shared views are read-only — duplicate to edit. -->
                        <button
                            v-if="canManage"
                            type="button"
                            :data-duplicate-view="view.id"
                            class="rounded px-1 text-[10px] opacity-0 group-hover:opacity-100 focus:opacity-100"
                            :style="{ color: 'var(--ds-text-muted)' }"
                            :aria-label="
                                (t.views.duplicate_label ?? '').replace(':name', view.name)
                            "
                            @click.stop.prevent="emit('duplicate-view', view.id)"
                        >
                            ⧉
                        </button>
                    </a>
                </li>
            </ul>
            <!-- B1-S03: the Shared empty-state card. -->
            <div
                v-else
                class="mx-2 rounded-[7px] border p-3 text-center"
                data-testid="shared-empty"
                :style="{ background: 'var(--ds-panel-bg)', borderColor: 'var(--ds-border-soft)' }"
            >
                <p class="text-[11.5px] font-semibold" :style="{ color: 'var(--ds-text-primary)' }">
                    {{ t.views.shared_empty_title }}
                </p>
                <p class="mt-1 text-[11px] leading-snug" :style="{ color: 'var(--ds-text-muted)' }">
                    {{ t.views.shared_empty_body }}
                </p>
                <button
                    v-if="canManage"
                    type="button"
                    data-testid="new-saved-view"
                    class="mt-2 rounded-[5px] px-2.5 py-1.5 text-[11px] font-semibold"
                    :style="{ background: 'var(--ds-accent)', color: 'var(--ds-accent-contrast)' }"
                    @click="emit('new-view')"
                >
                    {{ t.views.new_saved_view }}
                </button>
            </div>

            <p
                class="mb-1 mt-4 px-3 text-[10px] font-semibold uppercase tracking-wide"
                :style="{ color: 'var(--ds-text-label)' }"
            >
                {{ t.views.section_mine }}
            </p>
            <ul v-if="mine.length" class="w-full space-y-0.5 px-2">
                <li v-for="view in mine" :key="view.id">
                    <a
                        href="#"
                        :data-saved-view="view.id"
                        class="group flex items-center gap-2 rounded-md px-2 py-1.5 text-sm"
                        :style="{
                            color: 'var(--ds-text-primary)',
                            outline:
                                dropToken === 'mine:' + view.id
                                    ? '2px solid var(--ds-accent)'
                                    : undefined,
                        }"
                        draggable="true"
                        @click.prevent="emit('select-saved', view.id)"
                        @keydown="(e) => onRowKeydown(e, 'mine', 'mine:' + view.id)"
                        @dragstart="onDragStart('mine:' + view.id)"
                        @dragover="(e) => onDragOver(e, 'mine:' + view.id)"
                        @drop="onDrop('mine', 'mine:' + view.id)"
                    >
                        <span aria-hidden="true" :style="{ color: 'var(--ds-text-muted)' }">◉</span>
                        <span class="min-w-0 flex-1 truncate">{{ view.name }}</span>
                        <button
                            v-if="canManage"
                            type="button"
                            :data-edit-view="view.id"
                            class="rounded px-1 text-[10px] opacity-0 group-hover:opacity-100 focus:opacity-100"
                            :style="{ color: 'var(--ds-text-muted)' }"
                            :aria-label="(t.views.edit_label ?? '').replace(':name', view.name)"
                            @click.stop.prevent="emit('edit-view', view.id)"
                        >
                            ✎
                        </button>
                    </a>
                </li>
            </ul>
            <p
                v-else
                class="px-3 pb-1 text-[11px] leading-relaxed"
                :style="{ color: 'var(--ds-text-muted)' }"
            >
                {{ t.views.mine_empty }}
            </p>
            <button
                v-if="canManage && (shared.length > 0 || mine.length > 0)"
                type="button"
                data-testid="new-saved-view"
                class="ms-3 mt-1 self-start rounded-[5px] border px-2.5 py-1 text-[11px] font-semibold"
                :style="{ borderColor: 'var(--ds-border)', color: 'var(--ds-text-primary)' }"
                @click="emit('new-view')"
            >
                {{ t.views.new_saved_view }}
            </button>
        </template>
    </nav>
</template>
