<script setup lang="ts">
// §9: the risk badge is NEVER color-only — icon + text, both themes (the
// drawn shield glyph with the word beside it).
defineProps<{ label: string }>();
</script>

<template>
    <span
        class="inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-[10.5px] font-semibold"
        :style="{
            background: 'var(--ds-warn-bg)',
            color: 'var(--ds-warn-text)',
            border: '1px solid var(--ds-warn-border)',
        }"
    >
        <span aria-hidden="true">🛡</span>{{ label }}
    </span>
</template>
