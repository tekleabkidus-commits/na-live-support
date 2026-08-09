<script setup lang="ts">
import { computed } from 'vue';

// FR-29/§9: the channel-health rollup. Fixed tokens (healthy/degraded/down)
// both colourways; icon+text, never colour alone (§13). Reduced-motion: the
// health widget holds still (B6-A01 Q10).
const props = defineProps<{ status: 'healthy' | 'degraded' | 'down'; label: string }>();

const tokens = computed(() => {
    switch (props.status) {
        case 'healthy':
            return { color: 'var(--ds-health-healthy)', icon: '●' };
        case 'degraded':
            return { color: 'var(--ds-health-degraded)', icon: '◐' };
        default:
            return { color: 'var(--ds-health-down)', icon: '○' };
    }
});
</script>

<template>
    <span class="inline-flex items-center gap-1.5 text-xs font-semibold" :data-health="status">
        <span aria-hidden="true" :style="{ color: tokens.color }">{{ tokens.icon }}</span>
        <span :style="{ color: 'var(--ds-text-primary)' }">{{ label }}</span>
    </span>
</template>
