<script setup lang="ts">
// §8/§9: the segmented scope control (Own / Team / Department / All) with
// the plain-language ICU helper sentence beneath (B6-S08 G04). The helper
// string arrives already localized (ICU templating happens in the i18n
// layer, §14).
defineProps<{
    modelValue: string | null;
    options: { value: string; label: string }[];
    helper: string;
    disabled?: boolean;
}>();
const emit = defineEmits<{ 'update:modelValue': [string] }>();
</script>

<template>
    <div>
        <div
            role="radiogroup"
            class="inline-flex overflow-hidden rounded-md border"
            :style="{ borderColor: 'var(--ds-border-strong)' }"
        >
            <button
                v-for="option in options"
                :key="option.value"
                type="button"
                role="radio"
                :aria-checked="modelValue === option.value"
                :disabled="disabled"
                class="min-h-8 px-3 text-[12px] font-medium focus-visible:outline focus-visible:outline-2"
                :style="{
                    background:
                        modelValue === option.value ? 'var(--ds-accent)' : 'var(--ds-panel-bg)',
                    color:
                        modelValue === option.value
                            ? 'var(--ds-accent-contrast)'
                            : 'var(--ds-text-secondary)',
                    outlineColor: 'var(--ds-focus-ring)',
                }"
                @click="emit('update:modelValue', option.value)"
            >
                {{ option.label }}
            </button>
        </div>
        <p class="mt-1 text-[11.5px]" :style="{ color: 'var(--ds-text-muted)' }">{{ helper }}</p>
    </div>
</template>
