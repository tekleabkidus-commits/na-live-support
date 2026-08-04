<script setup lang="ts">
import { computed } from 'vue';

// §8: expiring overrides / pending grants carry a countdown chip. The label
// is precomputed server-side-compatible: we render remaining time from the
// ISO timestamp against the browser clock — presentation only, never an
// enforcement decision (EC-09: the resolver's clock is the database's).
const props = defineProps<{ expiresAt: string; prefix: string }>();

const label = computed(() => {
    const remainingMs = new Date(props.expiresAt).getTime() - Date.now();
    if (remainingMs <= 0) return `${props.prefix} —`;
    const hours = Math.floor(remainingMs / 3_600_000);
    const minutes = Math.floor((remainingMs % 3_600_000) / 60_000);
    if (hours >= 48) return `${props.prefix} ${Math.floor(hours / 24)}d`;
    return `${props.prefix} ${hours}h ${minutes}m`;
});
</script>

<template>
    <span
        class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium"
        :style="{ background: 'var(--ds-warn-bg)', color: 'var(--ds-warn-text)' }"
    >
        <span aria-hidden="true">⏳</span>{{ label }}
    </span>
</template>
