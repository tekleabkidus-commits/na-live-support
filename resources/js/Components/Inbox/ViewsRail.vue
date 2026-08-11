<script setup lang="ts">
// R1 — the views rail (FR-01: 240 px, collapsible to 56 px icons; the
// collapse persists via prefs). Step 8 renders the rail's anatomy: the
// seven FR-05 system views with their server ZCARD counts (T-03 — never a
// client derivation) and the viewer's saved views under Shared/Mine.
// B1-S03's full rail behaviors (reordering, editor) land at step 12; the
// Queues section is Doc 11's (seam stated, nothing invented).
// §13: every count carries an accessible label ("My Open, 12 tickets").
import { computed } from 'vue';

const props = defineProps<{
    views: { key: string; hash: string; count: number }[];
    savedViews: { id: string; name: string; own: boolean; shared: boolean }[];
    collapsed: boolean;
    t: { views: Record<string, string>; shell: Record<string, string> };
}>();

const emit = defineEmits<{ (e: 'toggle-collapse'): void; (e: 'select', view: string): void }>();

const ICONS: Record<string, string> = {
    my_open: '◧',
    unassigned: '◇',
    mentions: '@',
    following: '☆',
    sla_at_risk: '▲',
    expiring_windows: '⏱',
    rescue: '⚑',
};

const shared = computed(() => props.savedViews.filter((v) => v.shared && !v.own));
const mine = computed(() => props.savedViews.filter((v) => v.own));

function countLabel(viewKey: string, count: number): string {
    return (props.t.views.count_label ?? '')
        .replace(':view', props.t.views[viewKey] ?? viewKey)
        .replace(':count', String(count));
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
            <li v-for="view in views" :key="view.key">
                <a
                    href="#"
                    :data-view="view.key"
                    class="flex items-center gap-2 rounded-md px-2 py-1.5 text-sm"
                    :class="collapsed ? 'justify-center' : ''"
                    :style="{ color: 'var(--ds-text-primary)' }"
                    :aria-label="countLabel(view.key, view.count)"
                    :title="collapsed ? t.views[view.key] : undefined"
                    @click.prevent="emit('select', view.key)"
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

        <template v-if="!collapsed">
            <p
                v-if="shared.length"
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
                        class="flex items-center gap-2 rounded-md px-2 py-1.5 text-sm"
                        :style="{ color: 'var(--ds-text-primary)' }"
                    >
                        <span aria-hidden="true" :style="{ color: 'var(--ds-text-muted)' }">◈</span>
                        <span class="min-w-0 flex-1 truncate">{{ view.name }}</span>
                    </a>
                </li>
            </ul>

            <p
                v-if="mine.length"
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
                        class="flex items-center gap-2 rounded-md px-2 py-1.5 text-sm"
                        :style="{ color: 'var(--ds-text-primary)' }"
                    >
                        <span aria-hidden="true" :style="{ color: 'var(--ds-text-muted)' }">◉</span>
                        <span class="min-w-0 flex-1 truncate">{{ view.name }}</span>
                    </a>
                </li>
            </ul>
        </template>
    </nav>
</template>
