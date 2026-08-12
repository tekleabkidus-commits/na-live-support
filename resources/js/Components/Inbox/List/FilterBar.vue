<script setup lang="ts">
// FR-24 — the in-view filter bar (S07 step 12): quick filters that COMPOSE
// WITHIN the current view's base membership (never replace it — the server
// ANDs them), rendered as removable chips with one clear-all. The closed
// grammar is SavedViewFilters' — the same keys the saved-view editor uses.
import { computed, reactive } from 'vue';

defineProps<{
    t: Record<string, string>;
}>();

const emit = defineEmits<{ (e: 'change', filters: Record<string, unknown> | null): void }>();

const active = reactive<Record<string, unknown>>({});

const QUICK = [
    { key: 'priority', value: ['urgent', 'high'], label: 'quick_priority_high' },
    { key: 'has_draft', value: true, label: 'quick_has_draft' },
    { key: 'language', value: 'am', label: 'quick_language_am' },
] as const;

function toggle(key: string, value: unknown): void {
    if (key in active) {
        delete active[key];
    } else {
        active[key] = value;
    }
    emit('change', Object.keys(active).length ? { ...active } : null);
}

function clearAll(): void {
    Object.keys(active).forEach((key) => delete active[key]);
    emit('change', null);
}

const count = computed(() => Object.keys(active).length);
</script>

<template>
    <div
        data-testid="filter-bar"
        class="flex flex-wrap items-center gap-1.5 border-b px-3 py-1.5"
        :style="{ borderColor: 'var(--ds-border-soft)' }"
        role="group"
        :aria-label="t.label"
    >
        <button
            v-for="quick in QUICK"
            :key="quick.key"
            type="button"
            :data-filter="quick.key"
            class="rounded-full border px-2.5 py-0.5 text-[11px]"
            :aria-pressed="quick.key in active"
            :style="{
                borderColor: quick.key in active ? 'var(--ds-accent)' : 'var(--ds-border-soft)',
                color: quick.key in active ? 'var(--ds-accent)' : 'var(--ds-text-secondary)',
                fontWeight: quick.key in active ? 600 : 400,
            }"
            @click="toggle(quick.key, quick.value)"
        >
            {{ t[quick.label] }}
        </button>
        <button
            v-if="count > 0"
            type="button"
            data-testid="filter-clear-all"
            class="ms-auto text-[11px] underline decoration-dotted"
            :style="{ color: 'var(--ds-text-muted)' }"
            @click="clearAll"
        >
            {{ t.clear_all }}
        </button>
    </div>
</template>
