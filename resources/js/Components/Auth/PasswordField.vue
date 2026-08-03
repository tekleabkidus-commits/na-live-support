<script setup lang="ts">
// The §9 password field: visibility toggle, optional strength meter
// (Doc 02 §9, §13; FR-16's "live policy validation + strength meter").
//
// STRENGTH IS TEXT, NOT COLOR (§13): the meter renders the localized label the
// parent computed — a colored bar may accompany it later with the designed
// screens, but the accessible signal ships first and never regresses. The
// meter is optional because the LOGIN card must not have one: §9 attaches the
// meter to password-SETTING contexts, and grading a password someone is merely
// entering would leak nothing useful and annoy everyone.
//
// The toggle is a button, not an icon div: keyboard-operable (§13), labelled
// from props (N-03), and it never moves focus off the input.
import { ref } from 'vue';

defineProps<{
    inputId: string;
    label: string;
    modelValue: string;
    autocomplete: string;
    showLabel: string;
    hideLabel: string;
    describedBy?: string;
    meterId?: string;
    meterText?: string | null;
    // Host pages differ in surface tone until Doc 16's theming lands; the
    // input's and chrome's skins are theirs to set (both defaults suit the
    // dark card), the structure and behaviour are this component's. §13 holds
    // either way: whichever tone the host picks must clear 4.5:1, and the
    // browser suite's axe sweep is what enforces that.
    inputClass?: string;
    chromeClass?: string;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: string];
}>();

const visible = ref(false);

function onInput(event: Event): void {
    emit('update:modelValue', (event.target as HTMLInputElement).value);
}
</script>

<template>
    <div>
        <label class="block text-sm font-medium" :for="inputId">{{ label }}</label>
        <div class="relative mt-1">
            <input
                :id="inputId"
                :value="modelValue"
                :type="visible ? 'text' : 'password'"
                :autocomplete="autocomplete"
                required
                :aria-describedby="
                    [describedBy, meterText ? meterId : undefined].filter(Boolean).join(' ') ||
                    undefined
                "
                class="w-full rounded p-3 pe-14"
                :class="inputClass ?? 'border border-slate-700 bg-slate-950 text-slate-100'"
                @input="onInput"
            />
            <button
                type="button"
                data-testid="password-toggle"
                class="absolute inset-y-0 end-0 min-h-11 min-w-11 px-3 text-xs"
                :class="chromeClass ?? 'text-slate-400 hover:text-slate-200'"
                :aria-label="visible ? hideLabel : showLabel"
                :aria-pressed="visible"
                @click="visible = !visible"
            >
                {{ visible ? hideLabel : showLabel }}
            </button>
        </div>
        <p
            v-if="meterText"
            :id="meterId"
            data-testid="strength-meter"
            class="mt-1 text-xs"
            :class="chromeClass ?? 'text-slate-400'"
            aria-live="polite"
        >
            {{ meterText }}
        </p>
    </div>
</template>
