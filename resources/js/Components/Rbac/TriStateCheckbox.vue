<script setup lang="ts">
// §9/§13: the module select-all — checked / unchecked / indeterminate with
// aria-checked="mixed" exposed exactly as drawn (B6-S08 G05).
const props = defineProps<{
    state: 'checked' | 'unchecked' | 'mixed';
    label: string;
    disabled?: boolean;
}>();
const emit = defineEmits<{ toggle: [] }>();

function onActivate(): void {
    if (!props.disabled) emit('toggle');
}
</script>

<template>
    <button
        type="button"
        role="checkbox"
        :aria-checked="state === 'mixed' ? 'mixed' : state === 'checked'"
        :aria-label="label"
        :disabled="disabled"
        class="inline-flex size-[15px] flex-none items-center justify-center rounded-[3px] border-[1.5px] text-[10px] focus-visible:outline focus-visible:outline-2"
        :style="{
            borderColor: state === 'unchecked' ? 'var(--ds-border-strong)' : 'var(--ds-accent)',
            background: state === 'unchecked' ? 'var(--ds-panel-bg)' : 'var(--ds-accent)',
            color: 'var(--ds-accent-contrast)',
            outlineColor: 'var(--ds-focus-ring)',
        }"
        @click="onActivate"
        @keydown.space.prevent="onActivate"
    >
        <span v-if="state === 'checked'" aria-hidden="true">✓</span>
        <span v-else-if="state === 'mixed'" aria-hidden="true">−</span>
    </button>
</template>
