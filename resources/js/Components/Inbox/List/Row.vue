<!-- eslint-disable vue/multi-word-component-names -- the approved plan's
     table names this file List/Row.vue verbatim (FR-09's row); the
     directory namespaces it. -->
<script setup lang="ts">
// FR-09 — the dense 2-line row, the frame's anatomy verbatim.
// Line 1: channel glyph (+ the Doc 05 EC-04 dual-channel badge and, on the
// stale conversation, the older-activity warning — the closing row's list
// half), customer display name, ticket number, SLA pill (Doc 12 seam) or
// WindowChip (Doc 05 — the SHARED component, consumed not copied) whichever
// is most urgent, updated-at relative.
// Line 2: snippet (last public message, or subject fallback per Doc 06
// EC-11 — media-only renders a LOCALIZED kind placeholder, never a storage
// key), language chip, sentiment dot (Doc 09 seam), priority dot, assignee
// initials (or the "unassigned" ring), tag overflow (+N), draft pencil,
// unread bold + count. §13: every dot and glyph carries a text label.
import { computed } from 'vue';
import WindowChip from '../../Telegram/WindowChip.vue';
import type { ListRow } from '../../../stores/inbox/liveList';

const props = defineProps<{
    row: ListRow;
    selected: boolean;
    entering: boolean;
    leaving: boolean;
    t: Record<string, string>;
}>();

const emit = defineEmits<{
    (e: 'toggle-select', id: string, shiftKey: boolean): void;
    (e: 'open', id: string): void;
}>();

const CHANNEL_GLYPHS: Record<string, string> = { telegram: '✈', webchat: '💬', fakechan: '💬' };

const str = (key: string): string => {
    const value = props.row[key];
    return typeof value === 'string' ? value : '';
};

const unread = computed(() => Number(props.row.unread ?? 0));

const snippet = computed(() => {
    const kind = str('snippet_kind');
    if (kind === 'text') {
        // Doc 06 EC-11: subject fallback when there is no message snippet.
        return str('snippet') || str('subject');
    }
    return props.t['snippet_' + kind] ?? props.t.snippet_media ?? '';
});

// SLA pill vs window chip — "whichever is most urgent": a warned/breached
// SLA outranks the window; otherwise the window chip renders when a window
// exists.
const slaState = computed(() => str('sla_state'));
const showSla = computed(() => slaState.value === 'warned' || slaState.value === 'breached');
const windowState = computed((): 'open' | 'closing' | 'expired' | 'none' => {
    const expiresAt = str('window_expires_at');
    if (!expiresAt) {
        return 'none';
    }
    const remaining = new Date(expiresAt).getTime() - Date.now();
    if (remaining <= 0) {
        return 'expired';
    }
    return remaining < 3_600_000 ? 'closing' : 'open';
});

const relativeUpdated = computed(() => {
    const stamp = str('last_activity_at');
    if (!stamp) {
        return '';
    }
    const minutes = Math.max(0, Math.round((Date.now() - new Date(stamp).getTime()) / 60_000));
    if (minutes < 60) {
        return `${minutes}m`;
    }
    if (minutes < 1440) {
        return `${Math.floor(minutes / 60)}h`;
    }
    // §14: relative switches to absolute past 24 h.
    return new Date(stamp).toLocaleDateString();
});

const tags = computed(() => (Array.isArray(props.row.tags) ? (props.row.tags as string[]) : []));

const initials = computed(() => {
    const name = str('customer_display_name');
    return name ? name.slice(0, 2) : '·';
});

// Computed here, not in the template — string-lint reads template text
// nodes and a multiline expression looks like copy.
const tagOverflowLabel = computed(() => {
    if (tags.value.length === 0) {
        return '';
    }
    const more =
        tags.value.length > 1
            ? ' ' + (props.t.tags_more ?? '').replace(':count', String(tags.value.length - 1))
            : '';
    return tags.value[0] + more;
});

const SENTIMENT_HUES: Record<string, string> = {
    positive: 'var(--ds-success-text)',
    neutral: 'var(--ds-text-faint)',
    negative: 'var(--ds-danger-strong)',
};
</script>

<template>
    <li
        :data-ticket="row.ticket_id"
        :data-entering="entering || undefined"
        :data-leaving="leaving || undefined"
        class="list-row border-b"
        :class="{ 'row-entering': entering, 'row-leaving': leaving }"
        :style="{
            borderColor: 'var(--ds-border-soft)',
            background: selected ? 'var(--ds-row-hover)' : undefined,
        }"
    >
        <div class="flex items-center gap-2 px-3" :style="{ minHeight: 'var(--inbox-row-height)' }">
            <input
                type="checkbox"
                :checked="selected"
                :aria-label="(t.select ?? '').replace(':number', String(row.number ?? ''))"
                data-role="row-select"
                @click.stop="emit('toggle-select', row.ticket_id, ($event as MouseEvent).shiftKey)"
            />
            <button
                type="button"
                class="min-w-0 flex-1 text-start"
                data-role="row-open"
                @click="emit('open', row.ticket_id)"
            >
                <!-- Line 1 -->
                <span class="flex items-center gap-1.5">
                    <span aria-hidden="true" :style="{ color: 'var(--ds-text-muted)' }">{{
                        CHANNEL_GLYPHS[str('channel')] ?? '▤'
                    }}</span>
                    <span
                        v-if="row.dual_channel"
                        data-role="dual-channel-badge"
                        class="rounded border px-1 text-[9px] font-semibold uppercase tracking-wide"
                        :style="{
                            borderColor: 'var(--ds-border-strong)',
                            color: 'var(--ds-text-label)',
                        }"
                        >{{ t.dual_channel }}</span
                    >
                    <span
                        v-if="row.older_channel_activity"
                        data-role="older-activity-warning"
                        role="img"
                        :aria-label="t.older_activity"
                        :title="t.older_activity"
                        class="text-[10px]"
                        :style="{ color: 'var(--ds-warn-text)' }"
                        >⚠</span
                    >
                    <span
                        class="min-w-0 truncate text-sm"
                        :class="unread > 0 ? 'font-bold' : 'font-medium'"
                        :style="{ color: 'var(--ds-text-primary)' }"
                        >{{ str('customer_display_name') }}</span
                    >
                    <span class="text-xs tabular-nums" :style="{ color: 'var(--ds-text-muted)' }"
                        >#{{ row.number }}</span
                    >
                    <span
                        v-if="showSla"
                        data-role="sla-pill"
                        class="rounded px-1 text-[10px] font-semibold"
                        :style="{
                            background:
                                slaState === 'breached'
                                    ? 'var(--ds-sla-breach-bg)'
                                    : 'var(--ds-sla-soon-bg)',
                            color:
                                slaState === 'breached'
                                    ? 'var(--ds-sla-breach-fg)'
                                    : 'var(--ds-sla-soon-fg)',
                        }"
                        >▲ {{ slaState === 'breached' ? t.sla_breached : t.sla_warned }}</span
                    >
                    <WindowChip
                        v-else-if="windowState !== 'none'"
                        :expires-at="str('window_expires_at') || null"
                        :state="windowState"
                        :labels="{
                            closesIn: t.window_closes_in ?? '',
                            expired: t.window_expired ?? '',
                            noWindow: t.window_none ?? '',
                        }"
                    />
                    <span
                        class="ms-auto shrink-0 text-xs tabular-nums"
                        :style="{ color: 'var(--ds-text-faint)' }"
                        >{{ relativeUpdated }}</span
                    >
                </span>
                <!-- Line 2 -->
                <span class="mt-0.5 flex items-center gap-1.5">
                    <span
                        data-role="snippet"
                        class="min-w-0 flex-1 truncate text-xs"
                        :class="unread > 0 ? 'font-semibold' : ''"
                        :style="{
                            color:
                                unread > 0 ? 'var(--ds-text-primary)' : 'var(--ds-text-secondary)',
                        }"
                        >{{ snippet }}</span
                    >
                    <span
                        v-if="str('language')"
                        data-role="language-chip"
                        class="rounded border px-1 text-[9px] font-semibold uppercase"
                        :style="{
                            borderColor: 'var(--ds-border-soft)',
                            color: 'var(--ds-text-label)',
                        }"
                        role="img"
                        :aria-label="(t.language ?? '').replace(':language', str('language'))"
                        >{{ str('language').toUpperCase() }}</span
                    >
                    <span
                        v-if="str('sentiment')"
                        data-role="sentiment-dot"
                        role="img"
                        :aria-label="(t.sentiment ?? '').replace(':sentiment', str('sentiment'))"
                        :style="{
                            color: SENTIMENT_HUES[str('sentiment')] ?? 'var(--ds-text-faint)',
                        }"
                        >●</span
                    >
                    <span
                        data-role="priority-dot"
                        role="img"
                        :aria-label="(t.priority ?? '').replace(':priority', str('priority'))"
                        :style="{ color: `var(--ds-priority-${str('priority') || 'normal'})` }"
                        >●</span
                    >
                    <span
                        v-if="row.assignee_id"
                        data-role="assignee-avatar"
                        class="rounded-full border px-1 text-[9px] font-bold"
                        :style="{
                            borderColor: 'var(--ds-border-strong)',
                            color: 'var(--ds-text-secondary)',
                        }"
                        >{{ initials }}</span
                    >
                    <span
                        v-else
                        data-role="unassigned-ring"
                        role="img"
                        :aria-label="t.unassigned"
                        class="inline-block h-3.5 w-3.5 rounded-full border border-dashed"
                        :style="{ borderColor: 'var(--ds-text-faint)' }"
                    ></span>
                    <span
                        v-if="tags.length > 0"
                        data-role="tag-overflow"
                        class="rounded px-1 text-[9px]"
                        :style="{ background: 'var(--ds-inset-bg)', color: 'var(--ds-text-label)' }"
                        >{{ tagOverflowLabel }}</span
                    >
                    <span
                        v-if="row.has_draft"
                        data-role="draft-pencil"
                        role="img"
                        :aria-label="t.draft"
                        :style="{ color: 'var(--ds-text-muted)' }"
                        >✏️</span
                    >
                    <span
                        v-if="unread > 0"
                        data-role="unread-count"
                        class="rounded-full px-1.5 text-[10px] font-bold tabular-nums"
                        :style="{
                            background: 'var(--ds-accent)',
                            color: 'var(--ds-accent-contrast)',
                        }"
                        role="img"
                        :aria-label="(t.unread ?? '').replace(':count', String(unread))"
                        >{{ unread }}</span
                    >
                </span>
            </button>
        </div>
    </li>
</template>

<style scoped>
/* FR-11: enter = slide-in + 300 ms highlight; leave = fade + collapse
   (150 ms). §13/B12-A01: suppressed entirely under reduced motion. */
.row-entering {
    animation: row-enter 300ms ease-out;
}
.row-leaving {
    animation: row-leave 150ms ease-in forwards;
}
@keyframes row-enter {
    from {
        background: var(--ds-row-hover);
        transform: translateY(-4px);
    }
    to {
        transform: translateY(0);
    }
}
@keyframes row-leave {
    to {
        opacity: 0;
        max-height: 0;
    }
}
@media (prefers-reduced-motion: reduce) {
    .row-entering,
    .row-leaving {
        animation: none;
    }
    .row-leaving {
        opacity: 0.4;
    }
}
</style>
