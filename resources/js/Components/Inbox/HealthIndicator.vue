<script setup lang="ts">
// FR-03: the channel-health rollup in the top bar (Doc 05 FR-29's consumer —
// the Doc 05 FS-01 closing row). The DOT is the semantic health component
// (fixed --ds-health-* tokens, icon+word never colour alone, §13); the
// BANNER-ON-OPEN is an outage bar and therefore uses the NEUTRAL INK RAMP
// (owner ruling R-25 — status hues stay reserved to their semantic
// components). State arrives from step 3's ChannelHealthState via page
// props; the indicator RENDERS state, it never infers it ("unknown" is a
// real word here, never a fabricated healthy).
import { computed, ref } from 'vue';
import HealthRollupPill from '../Telegram/HealthRollupPill.vue';

const props = defineProps<{
    worst: 'healthy' | 'degraded' | 'down' | 'unknown';
    states: Record<string, string>;
    t: Record<string, string>;
}>();

const open = ref(false);

const label = computed((): string => {
    switch (props.worst) {
        case 'healthy':
            return props.t.all_healthy ?? '';
        case 'degraded':
            return props.t.degraded ?? '';
        case 'down':
            return props.t.down ?? '';
        default:
            return props.t.unknown ?? '';
    }
});

const endpoints = computed(() =>
    Object.entries(props.states).map(([endpoint, status]) => ({ endpoint, status })),
);
</script>

<template>
    <div class="relative">
        <button
            type="button"
            data-testid="health-indicator"
            :data-health-worst="worst"
            class="flex items-center gap-1.5 rounded-md px-2 py-1 text-xs font-semibold"
            :style="{ color: 'var(--ds-text-primary)' }"
            :aria-expanded="open"
            :aria-label="t.label + ': ' + label"
            @click="open = !open"
        >
            <HealthRollupPill v-if="worst !== 'unknown'" :status="worst" :label="label" />
            <span v-else :style="{ color: 'var(--ds-text-muted)' }">◌ {{ label }}</span>
        </button>

        <!-- FR-03: degraded/down shows the banner ON OPEN. R-25: neutral ink
             ramp only — inset surface, ink text, soft border. -->
        <div
            v-if="open"
            data-testid="health-banner"
            role="region"
            :aria-label="t.banner_title"
            class="absolute end-0 top-full z-40 mt-2 w-72 rounded-lg border p-3 shadow-lg"
            :style="{
                background: 'var(--ds-inset-bg)',
                borderColor: 'var(--ds-border)',
                color: 'var(--ds-text-ink)',
            }"
        >
            <div class="mb-2 flex items-center justify-between">
                <span class="text-xs font-semibold" :style="{ color: 'var(--ds-text-ink)' }">{{
                    t.banner_title
                }}</span>
                <button
                    type="button"
                    class="text-xs"
                    :style="{ color: 'var(--ds-text-muted)' }"
                    :aria-label="t.banner_close"
                    @click="open = false"
                >
                    ✕
                </button>
            </div>
            <p
                v-if="endpoints.length === 0"
                class="text-xs"
                :style="{ color: 'var(--ds-text-muted)' }"
            >
                {{ t.unknown }}
            </p>
            <ul v-else class="space-y-1.5">
                <li
                    v-for="row in endpoints"
                    :key="row.endpoint"
                    class="flex items-center justify-between gap-2 text-xs"
                    :data-health-endpoint="row.endpoint"
                >
                    <span class="truncate" :style="{ color: 'var(--ds-text-secondary)' }">{{
                        row.endpoint
                    }}</span>
                    <!-- The endpoint's own status word keeps its semantic dot
                         (the dot is the semantic component, not the bar). -->
                    <HealthRollupPill
                        v-if="
                            row.status === 'healthy' ||
                            row.status === 'degraded' ||
                            row.status === 'down'
                        "
                        :status="row.status"
                        :label="row.status"
                    />
                </li>
            </ul>
        </div>
    </div>
</template>
