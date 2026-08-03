<script setup lang="ts">
// The §9 6-digit segmented code input (Doc 02 §8, §9, §13; FR-07).
//
// STRUCTURALLY ONE FIELD. §13 requires "the segmented code input is a single
// field for assistive tech (segmentation is visual only)", and this component
// enforces that BY CONSTRUCTION: there is exactly one <input>; the six cells
// are letter-spacing and a repeated background gradient. The tempting
// six-inputs-with-focus-hopping implementation is the thing this file exists
// to prevent — it reads as six unlabeled fields to a screen reader and breaks
// paste. Being one field is also what makes §8's "paste-friendly" free: the
// browser's native paste lands in the one input and the normaliser trims it.
//
// AUTO-SUBMIT ON THE 6TH DIGIT is §8's, emitted as `complete`; the parent owns
// the actual submission (and its busy-guard, so a paste emitting input+complete
// in one tick cannot double-submit).
import { nextTick, ref, watch } from 'vue';

const props = defineProps<{
    inputId: string;
    label: string;
    modelValue: string;
    describedBy?: string;
    autoFocus?: boolean;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: string];
    complete: [code: string];
}>();

const input = ref<HTMLInputElement | null>(null);

function onInput(event: Event): void {
    const raw = (event.target as HTMLInputElement).value;
    // Digits only, six at most — covers typing, paste, and autofill alike.
    const cleaned = raw.replace(/\D/g, '').slice(0, 6);

    if (cleaned !== raw) {
        (event.target as HTMLInputElement).value = cleaned;
    }

    emit('update:modelValue', cleaned);

    if (cleaned.length === 6) {
        emit('complete', cleaned);
    }
}

function focus(): void {
    input.value?.focus();
}

defineExpose({ focus });

watch(
    () => props.autoFocus,
    async (should) => {
        if (should) {
            await nextTick();
            focus();
        }
    },
    { immediate: true },
);
</script>

<template>
    <div>
        <label class="block text-sm text-slate-300" :for="inputId">{{ label }}</label>
        <!-- Codes are digit strings and stay LTR even in RTL locales; the
             explicit dir is the one sanctioned directional override (C-15). -->
        <input
            :id="inputId"
            ref="input"
            :value="modelValue"
            type="text"
            dir="ltr"
            inputmode="numeric"
            pattern="[0-9]*"
            autocomplete="one-time-code"
            maxlength="6"
            :aria-describedby="describedBy"
            class="code-input mt-2 block rounded border border-slate-700 bg-slate-950 py-3 font-mono text-2xl text-slate-100"
            data-testid="segmented-code-input"
            @input="onInput"
        />
    </div>
</template>

<style scoped>
/* Six visual cells over one real field. Metrics are in ch against the
   monospace font, so the six segments hold from 320 px up (§9) — the input
   never needs to shrink below its own content width. */
.code-input {
    --cell: 2.4ch;
    inline-size: calc(var(--cell) * 6 + 1ch);
    padding-inline-start: calc((var(--cell) - 1ch) / 2 + 0.5ch);
    letter-spacing: calc(var(--cell) - 1ch);
    background-image: repeating-linear-gradient(
        to right,
        rgb(100 116 139) 0,
        rgb(100 116 139) calc(var(--cell) - 0.6ch),
        transparent calc(var(--cell) - 0.6ch),
        transparent var(--cell)
    );
    background-size: calc(var(--cell) * 6) 2px;
    background-repeat: no-repeat;
    background-position: bottom 0.4rem left 0.5ch;
    font-variant-numeric: tabular-nums;
}
</style>
