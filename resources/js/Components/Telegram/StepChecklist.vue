<script setup lang="ts">
// FR-07/§13: the connect checklist with per-step status icons. Each step's
// status change ANNOUNCES via role="status" (§13, B6-A01 Q09) — a polite
// live region per step. Icon+text, never colour alone.
defineProps<{
    steps: Array<{ label: string; status: 'done' | 'active' | 'pending'; hint?: string }>;
    statusWords: { done: string; active: string; pending: string };
}>();

const icon = (s: string) => (s === 'done' ? '✓' : s === 'active' ? '⟳' : '○');
const tint = (s: string) =>
    s === 'done'
        ? 'var(--ds-success-text)'
        : s === 'active'
          ? 'var(--ds-focus-ring)'
          : 'var(--ds-text-muted)';
</script>

<template>
    <ol class="flex flex-col gap-3">
        <li
            v-for="(step, i) in steps"
            :key="i"
            class="flex items-start gap-3 rounded-lg border p-3"
            :style="{ background: 'var(--ds-panel-soft)', borderColor: 'var(--ds-border)' }"
        >
            <span
                aria-hidden="true"
                class="mt-0.5 text-sm font-bold"
                :style="{ color: tint(step.status) }"
                >{{ icon(step.status) }}</span
            >
            <div class="min-w-0 flex-1">
                <p class="text-sm font-medium" :style="{ color: 'var(--ds-text-primary)' }">
                    {{ step.label }}
                </p>
                <p
                    v-if="step.hint"
                    class="mt-0.5 text-xs"
                    :style="{ color: 'var(--ds-text-muted)' }"
                >
                    {{ step.hint }}
                </p>
                <!-- §13: announce the step's status change (not colour-only) -->
                <span class="sr-only" role="status" aria-live="polite">
                    {{ statusWords[step.status] }}
                </span>
            </div>
        </li>
    </ol>
</template>
