<script setup lang="ts">
// B2-S06's inline refusal: role=alert, in place, never a browser dialog; tokens only.
defineProps<{ text: string }>();
</script>
<template>
    <p
        v-if="text"
        role="alert"
        data-testid="wf-refusal"
        class="mt-2 rounded-md border px-3 py-2 text-xs"
        :style="{
            background: 'var(--ds-danger-bg)',
            borderColor: 'var(--ds-danger-border)',
            color: 'var(--ds-danger-text)',
        }"
    >
        {{ text }}
    </p>
</template>
