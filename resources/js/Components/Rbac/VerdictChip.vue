<script setup lang="ts">
import { computed } from 'vue';

// §8/§13: the verdict chip carries the WHY, readable by screen readers —
// "Denied — user override, set by …, expires …" (B6-S09 H02).
const props = defineProps<{
    allowed: boolean;
    why: string;
}>();

const palette = computed(() =>
    props.allowed
        ? { background: 'var(--ds-success-bg)', color: 'var(--ds-success-text)' }
        : { background: 'var(--ds-danger-bg)', color: 'var(--ds-danger-text)' },
);
</script>

<template>
    <span
        class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11.5px] font-semibold"
        :style="palette"
    >
        <span aria-hidden="true">{{ allowed ? '✓' : '✕' }}</span>
        <span>{{ why }}</span>
    </span>
</template>
