<script setup lang="ts">
// FR-31's action palettes (S07 step 13): A (assign to…), P (priority), T
// (tags), S (snooze-to-pending) — one small palette shell, four contents,
// every pick a REAL DOOR (the step-13 server half). Roving ↑↓/↵ selection,
// Esc closes, focus returns to the opener (§13's focus system).
//
// The T palette shows the ticket's current tags (x removes through the
// remove door) and suggests from the tenant vocabulary (the suggest door);
// typing a new tag and ↵ adds it. The A palette searches the assignees
// directory. P lists Doc 06's four fixed levels. S offers the one
// pending-customer snooze the transition door owns.
import { nextTick, ref, watch } from 'vue';

export type PaletteItem = { id: string; label: string; hint?: string };

const props = defineProps<{
    open: boolean;
    kind: 'assign' | 'priority' | 'tags' | 'snooze' | null;
    title: string;
    searchable: boolean;
    items: PaletteItem[];
    currentTags?: string[];
    t: Record<string, string>;
}>();

const emit = defineEmits<{
    (e: 'close'): void;
    (e: 'pick', id: string): void;
    (e: 'search', q: string): void;
    (e: 'remove-tag', tag: string): void;
}>();

const query = ref('');
const active = ref(0);
const input = ref<HTMLInputElement | null>(null);
const dialog = ref<HTMLElement | null>(null);
let opener: HTMLElement | null = null;

watch(
    () => props.open,
    (open) => {
        if (open) {
            opener = document.activeElement instanceof HTMLElement ? document.activeElement : null;
            query.value = '';
            active.value = 0;
            // §13: the palette OWNS focus while open — a non-searchable
            // palette focuses its dialog so ↑↓/↵ land here, never on the
            // global map underneath.
            void nextTick(() => (props.searchable ? input.value?.focus() : dialog.value?.focus()));
        } else {
            opener?.focus();
            opener = null;
        }
    },
);
watch(query, (q) => {
    active.value = 0;
    emit('search', q);
});

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
        event.stopPropagation();
        emit('close');
        return;
    }
    if (event.key === 'ArrowDown') {
        event.preventDefault();
        active.value = Math.min(props.items.length - 1, active.value + 1);
        return;
    }
    if (event.key === 'ArrowUp') {
        event.preventDefault();
        active.value = Math.max(0, active.value - 1);
        return;
    }
    if (event.key === 'Enter') {
        event.preventDefault();
        const item = props.items[active.value];
        if (item) {
            emit('pick', item.id);
        } else if (props.kind === 'tags' && query.value.trim() !== '') {
            emit('pick', query.value.trim());
        }
    }
}
</script>

<template>
    <div
        v-if="open"
        class="fixed inset-0 z-50 flex items-start justify-center pt-24"
        :style="{ background: 'rgba(0, 0, 0, 0.35)' }"
        data-testid="action-palette-backdrop"
        @click.self="emit('close')"
    >
        <div
            ref="dialog"
            role="dialog"
            aria-modal="true"
            :aria-label="title"
            tabindex="-1"
            data-testid="action-palette"
            :data-kind="kind"
            class="w-[420px] rounded-xl border shadow-xl outline-none"
            :style="{ background: 'var(--ds-panel-bg)', borderColor: 'var(--ds-border)' }"
            @keydown="onKeydown"
        >
            <p
                class="border-b px-3 py-2 text-xs font-semibold"
                :style="{ borderColor: 'var(--ds-border)', color: 'var(--ds-text-primary)' }"
            >
                {{ title }}
            </p>
            <div
                v-if="kind === 'tags' && (currentTags?.length ?? 0) > 0"
                class="flex flex-wrap gap-1 px-3 pt-2"
            >
                <span
                    v-for="tag in currentTags"
                    :key="tag"
                    data-testid="palette-current-tag"
                    class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px]"
                    :style="{ background: 'var(--ds-inset-bg)', color: 'var(--ds-text-ink)' }"
                >
                    {{ tag }}
                    <button
                        type="button"
                        :aria-label="(t.remove_tag ?? '').replace(':tag', tag)"
                        data-testid="palette-remove-tag"
                        @click="emit('remove-tag', tag)"
                    >
                        ✕
                    </button>
                </span>
            </div>
            <input
                v-if="searchable"
                ref="input"
                v-model="query"
                type="text"
                data-testid="action-palette-input"
                class="w-full border-b bg-transparent px-3 py-2 text-sm outline-none"
                :style="{ borderColor: 'var(--ds-border)', color: 'var(--ds-text-primary)' }"
                :placeholder="t.search_placeholder"
            />
            <ul role="listbox" :aria-label="title" class="max-h-72 overflow-y-auto py-1">
                <li
                    v-for="(item, index) in items"
                    :key="item.id"
                    role="option"
                    :aria-selected="index === active"
                    data-testid="action-palette-item"
                    class="flex cursor-pointer items-center gap-2 px-3 py-2 text-sm"
                    :style="{
                        background: index === active ? 'var(--ds-inset-bg)' : 'transparent',
                        color: 'var(--ds-text-primary)',
                    }"
                    @mouseenter="active = index"
                    @click="emit('pick', item.id)"
                >
                    {{ item.label }}
                    <span
                        v-if="item.hint"
                        class="ms-auto text-[11px]"
                        :style="{ color: 'var(--ds-text-muted)' }"
                    >
                        {{ item.hint }}
                    </span>
                </li>
                <li
                    v-if="items.length === 0"
                    class="px-3 py-2 text-xs"
                    :style="{ color: 'var(--ds-text-muted)' }"
                    data-testid="action-palette-empty"
                >
                    {{ t.empty }}
                </li>
            </ul>
        </div>
    </div>
</template>
