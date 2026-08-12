<script setup lang="ts">
// §8 first-run (S07 step 13), drawn in B1-S03: FOUR coach marks — views,
// list, composer modes, ⌘K — "STEP n OF 4", Skip, Back/Next, dot progress.
// Dismiss-forever persists per user; re-openable from the ? overlay. The
// card is the drawn dark-on-light/light-on-dark floating card; each step
// names its region (the anchor targets carry data-coach so the browser
// suite can assert the pairing without pixel claims).
import { computed, ref, watch } from 'vue';

const props = defineProps<{
    open: boolean;
    t: Record<string, string>;
}>();

const emit = defineEmits<{ (e: 'dismiss'): void }>();

const step = ref(0);
watch(
    () => props.open,
    (open) => {
        if (open) step.value = 0;
    },
);

const STEPS = ['views', 'list', 'modes', 'palette'] as const;
const current = computed(() => STEPS[step.value] ?? 'views');

function next(): void {
    if (step.value >= STEPS.length - 1) {
        emit('dismiss'); // finishing IS dismissing forever (§8)
        return;
    }
    step.value += 1;
}
function back(): void {
    step.value = Math.max(0, step.value - 1);
}

// Computed, not a template ternary — string-lint reads template text nodes
// and a multiline expression looks like copy (the Row.vue precedent).
const nextLabel = computed(() => (step.value >= 3 ? props.t.done : props.t.next));
</script>

<template>
    <div
        v-if="open"
        class="fixed inset-0 z-50 flex items-center justify-center"
        :style="{ background: 'rgba(0, 0, 0, 0.25)' }"
        data-testid="coach-backdrop"
    >
        <div
            role="dialog"
            aria-modal="true"
            :aria-label="t.title"
            data-testid="coach-card"
            :data-step="current"
            class="w-[300px] rounded-[10px] p-3.5 shadow-xl"
            :style="{ background: 'var(--ds-text-ink)', color: 'var(--ds-page-bg)' }"
        >
            <div class="flex items-baseline gap-2">
                <span class="text-[10.5px] font-semibold tracking-wide" :style="{ opacity: 0.75 }">
                    {{ (t.step_of ?? '').replace(':n', String(step + 1)).replace(':total', '4') }}
                </span>
                <button
                    type="button"
                    class="ms-auto text-[11px]"
                    :style="{ opacity: 0.75 }"
                    data-testid="coach-skip"
                    @click="emit('dismiss')"
                >
                    {{ t.skip }}
                </button>
            </div>
            <p class="mt-1.5 text-[13.5px] font-semibold">{{ t['title_' + current] }}</p>
            <p class="mt-1 text-xs leading-relaxed" :style="{ opacity: 0.85 }">
                {{ t['body_' + current] }}
            </p>
            <div class="mt-3 flex items-center gap-2">
                <span class="inline-flex gap-1">
                    <span
                        v-for="(s, index) in STEPS"
                        :key="s"
                        class="h-[5px] w-[5px] rounded-full"
                        :style="{ background: 'currentColor', opacity: index === step ? 1 : 0.35 }"
                    ></span>
                </span>
                <button
                    v-if="step > 0"
                    type="button"
                    class="ms-auto rounded border px-2.5 py-1 text-[11.5px]"
                    :style="{ borderColor: 'currentColor', opacity: 0.85 }"
                    data-testid="coach-back"
                    @click="back"
                >
                    {{ t.back }}
                </button>
                <button
                    type="button"
                    :class="step > 0 ? '' : 'ms-auto'"
                    class="rounded px-3 py-1 text-[11.5px] font-semibold"
                    :style="{ background: 'var(--ds-page-bg)', color: 'var(--ds-text-ink)' }"
                    data-testid="coach-next"
                    @click="next"
                >
                    {{ nextLabel }}
                </button>
            </div>
        </div>
    </div>
</template>
