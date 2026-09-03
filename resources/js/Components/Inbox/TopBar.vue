<script setup lang="ts">
// FR-03 — the top bar, in the frame's order: tenant identity, global search
// (⌘K affordance; the palette itself is FR-23–25, step 12), New ticket,
// channel-health indicator, notification bell with badge (FR-26's surface,
// step 12 — the badge renders server truth, never a client count), agent
// status control (FR-28, step 12 owns the sheet; the control renders the
// word), profile menu with the §9 density toggle. Every string is a t-key
// (C-08); the ⌘K glyph is a physical-key label, not copy.
import { ref } from 'vue';
import NotificationBell from './NotificationBell.vue';
import HealthIndicator from './HealthIndicator.vue';
import StatusControl from './StatusControl.vue';

defineProps<{
    tenantName: string;
    health: { worst: 'healthy' | 'degraded' | 'down' | 'unknown'; states: Record<string, string> };
    canCreateTicket: boolean;
    density: 'comfortable' | 'compact';
    bellUnread: number;
    mutedUntil: string | null;
    agentStatus: { status: string; reason: string | null; set_by_name: string | null };
    awayIdleMinutes: number;
    t: {
        topbar: Record<string, string>;
        health: Record<string, string>;
        density: Record<string, string>;
        bell: Record<string, string>;
        status: Record<string, string>;
    };
}>();

const emit = defineEmits<{
    (e: 'staleness', payload: { stale: number | null; pending: boolean }): void;
    (e: 'set-density', value: 'comfortable' | 'compact'): void;
    (e: 'open-thread', payload: { ticketId: string; conversationId: string | null }): void;
    (e: 'mute', until: string | null): void;
    (e: 'open-palette'): void;
}>();

const profileOpen = ref(false);

function toggleDensity(value: 'comfortable' | 'compact'): void {
    emit('set-density', value);
}
</script>

<template>
    <header
        data-testid="inbox-topbar"
        class="flex h-14 min-w-0 shrink-0 items-center gap-3 border-b px-4 max-[639px]:gap-2 max-[639px]:px-3"
        :style="{ background: 'var(--ds-panel-bg)', borderColor: 'var(--ds-border)' }"
    >
        <span
            class="min-w-0 truncate text-sm font-semibold"
            :style="{ color: 'var(--ds-text-primary)' }"
            data-testid="tenant-name"
        >
            {{ tenantName }}
        </span>

        <button
            type="button"
            data-testid="global-search"
            class="flex min-w-0 flex-1 items-center gap-2 rounded-md border px-3 py-1.5 text-sm sm:max-w-md"
            :style="{
                background: 'var(--ds-inset-bg)',
                borderColor: 'var(--ds-border-soft)',
                color: 'var(--ds-text-muted)',
            }"
            :aria-label="t.topbar.search_label"
            @click="emit('open-palette')"
        >
            <span aria-hidden="true">⌕</span>
            <span class="truncate">{{ t.topbar.search_placeholder }}</span>
            <kbd
                class="ms-auto rounded border px-1 text-[10px]"
                :style="{ borderColor: 'var(--ds-border-soft)', color: 'var(--ds-text-faint)' }"
                aria-hidden="true"
                >⌘K</kbd
            >
        </button>

        <button
            v-if="canCreateTicket"
            type="button"
            data-testid="new-ticket"
            class="shrink-0 rounded-md px-3 py-1.5 text-sm font-semibold"
            :style="{ background: 'var(--ds-accent)', color: 'var(--ds-accent-contrast)' }"
        >
            + {{ t.topbar.new_ticket }}
        </button>

        <HealthIndicator :worst="health.worst" :states="health.states" :t="t.health" />

        <NotificationBell
            :t="t.bell"
            :initial-unread="bellUnread"
            :muted-until="mutedUntil"
            @open-thread="(payload) => emit('open-thread', payload)"
            @mute="(until) => emit('mute', until)"
        />

        <StatusControl
            :t="t.status"
            :initial="agentStatus"
            :away-idle-minutes="awayIdleMinutes"
            @staleness="(p) => emit('staleness', p)"
        />

        <div class="relative shrink-0">
            <button
                type="button"
                data-testid="profile-menu"
                class="rounded-full border px-2.5 py-1.5 text-xs font-bold"
                :style="{ borderColor: 'var(--ds-border)', color: 'var(--ds-text-primary)' }"
                :aria-label="t.topbar.profile"
                :aria-expanded="profileOpen"
                @click="profileOpen = !profileOpen"
            >
                ☰
            </button>
            <div
                v-if="profileOpen"
                class="absolute end-0 top-full z-40 mt-2 w-56 rounded-lg border p-3 shadow-lg"
                :style="{ background: 'var(--ds-panel-bg)', borderColor: 'var(--ds-border)' }"
            >
                <!-- §9 density toggle: 64/52 px rows, persisted (prefs). -->
                <fieldset data-testid="density-toggle">
                    <legend
                        class="mb-1.5 text-xs font-semibold"
                        :style="{ color: 'var(--ds-text-label)' }"
                    >
                        {{ t.density.label }}
                    </legend>
                    <div class="flex gap-1.5">
                        <button
                            v-for="option in ['comfortable', 'compact'] as const"
                            :key="option"
                            type="button"
                            :data-testid="'density-' + option"
                            class="flex-1 rounded-md border px-2 py-1 text-xs"
                            :aria-pressed="density === option"
                            :style="{
                                borderColor:
                                    density === option
                                        ? 'var(--ds-accent)'
                                        : 'var(--ds-border-soft)',
                                color:
                                    density === option
                                        ? 'var(--ds-accent)'
                                        : 'var(--ds-text-secondary)',
                            }"
                            @click="toggleDensity(option)"
                        >
                            {{ t.density[option] }}
                        </button>
                    </div>
                </fieldset>
            </div>
        </div>
    </header>
</template>
