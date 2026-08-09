<script setup lang="ts">
import { computed } from 'vue';

// FR-09/§9: connection-state pill. FIXED tokens (active=green, paused=amber,
// revoked=red) from the design system, BOTH colourways — never colour-only
// (§13): every pill carries an icon AND the localized word. The `label` is
// server-localized (the house pattern); `state` is the machine value.
const props = defineProps<{ state: 'pending' | 'active' | 'paused' | 'revoked'; label: string }>();

const tokens = computed(() => {
    switch (props.state) {
        case 'active':
            return { bg: 'var(--ds-conn-active-bg)', fg: 'var(--ds-conn-active-fg)', icon: '●' };
        case 'paused':
            return { bg: 'var(--ds-conn-paused-bg)', fg: 'var(--ds-conn-paused-fg)', icon: '◐' };
        case 'revoked':
            return { bg: 'var(--ds-conn-revoked-bg)', fg: 'var(--ds-conn-revoked-fg)', icon: '✕' };
        default: // pending — neutral, awaiting confirmation (SEC-04)
            return { bg: 'var(--ds-window-open-bg)', fg: 'var(--ds-window-open-fg)', icon: '…' };
    }
});
</script>

<template>
    <span
        class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium"
        :style="{ background: tokens.bg, color: tokens.fg }"
        :data-state="state"
    >
        <span aria-hidden="true">{{ tokens.icon }}</span
        >{{ label }}
    </span>
</template>
