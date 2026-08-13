<script setup lang="ts">
// FR-13's selection surface + EC-02's "no longer in view" tray (B1-S02's
// bulk-bar frames; the reason VISIBLE AND FOCUSABLE per the frame note).
// The tray holds selected ids whose rows left the view — they stay selected
// until deselected or executed; the T-05 drift report remains the server
// truth at execution. "Select all in view" mints the server-side token
// (>page selections — the count shown is the SERVER count from the mint).
import { computed } from 'vue';

const props = defineProps<{
    mergeAllowed?: boolean;
    selectedCount: number;
    trayIds: string[];
    total: number;
    tokenCount: number | null;
    // B1-S02's drawn action row (Assign | Tag | Priority | Close) —
    // capability render-gates (BR-01: no affordance for a door the holder
    // cannot open; the services re-check the same keys).
    canAssign?: boolean;
    canTag?: boolean;
    canPriority?: boolean;
    canClose?: boolean;
    t: Record<string, string>;
}>();

const emit = defineEmits<{
    (e: 'merge'): void;
    (e: 'select-all'): void;
    (e: 'clear'): void;
    (e: 'deselect', id: string): void;
    (e: 'action', kind: 'assign' | 'tag' | 'priority' | 'close'): void;
}>();

const label = (key: string, replacements: Record<string, string>): string => {
    let value = props.t[key] ?? '';
    for (const [token, replacement] of Object.entries(replacements)) {
        value = value.replace(':' + token, replacement);
    }
    return value;
};

const selectedLabel = computed(() =>
    label('selected', { count: String(props.tokenCount ?? props.selectedCount) }),
);
</script>

<template>
    <div
        v-if="selectedCount > 0 || trayIds.length > 0"
        data-testid="bulk-bar"
        class="border-t px-3 py-2"
        :style="{ background: 'var(--ds-panel-soft)', borderColor: 'var(--ds-border)' }"
    >
        <!-- flex-wrap: the grown action row must never overflow the list
             column (an overflowing button lands under the thread region,
             which then intercepts its clicks). -->
        <div class="flex flex-wrap items-center gap-2">
            <span
                class="text-xs font-semibold"
                :style="{ color: 'var(--ds-text-primary)' }"
                data-testid="bulk-count"
            >
                {{ selectedLabel }}
            </span>
            <button
                v-if="tokenCount === null && total > selectedCount"
                type="button"
                data-testid="select-all-in-view"
                class="text-xs underline"
                :style="{ color: 'var(--ds-accent)' }"
                @click="emit('select-all')"
            >
                {{ label('select_all', { count: String(total) }) }}
            </button>
            <!-- The drawn action row, in the drawn order. -->
            <button
                v-if="canAssign"
                type="button"
                data-testid="bulk-assign"
                class="rounded-md border px-2 py-1 text-xs font-semibold"
                :style="{ borderColor: 'var(--ds-border-strong)' }"
                @click="emit('action', 'assign')"
            >
                {{ t.action_assign }}
            </button>
            <button
                v-if="canTag"
                type="button"
                data-testid="bulk-tag"
                class="rounded-md border px-2 py-1 text-xs font-semibold"
                :style="{ borderColor: 'var(--ds-border-strong)' }"
                @click="emit('action', 'tag')"
            >
                {{ t.action_tag }}
            </button>
            <button
                v-if="canPriority"
                type="button"
                data-testid="bulk-priority"
                class="rounded-md border px-2 py-1 text-xs font-semibold"
                :style="{ borderColor: 'var(--ds-border-strong)' }"
                @click="emit('action', 'priority')"
            >
                {{ t.action_priority }}
            </button>
            <button
                v-if="canClose"
                type="button"
                data-testid="bulk-close"
                class="rounded-md border px-2 py-1 text-xs font-semibold"
                :style="{ borderColor: 'var(--ds-border-strong)' }"
                @click="emit('action', 'close')"
            >
                {{ t.action_close }}
            </button>
            <button
                v-if="mergeAllowed && selectedCount >= 2"
                type="button"
                data-testid="bulk-merge"
                class="rounded-md border px-2 py-1 text-xs font-semibold"
                :style="{ borderColor: 'var(--ds-border-strong)' }"
                @click="emit('merge')"
            >
                {{ t.merge }}
            </button>
            <button
                type="button"
                data-testid="clear-selection"
                class="ms-auto text-xs"
                :style="{ color: 'var(--ds-text-muted)' }"
                @click="emit('clear')"
            >
                {{ t.clear }}
            </button>
        </div>

        <!-- EC-02: the tray — visible, focusable, per the frame note. -->
        <div
            v-if="trayIds.length > 0"
            data-testid="drift-tray"
            role="group"
            :aria-label="label('tray_title', { count: String(trayIds.length) })"
            class="mt-2 rounded-md border p-2"
            :style="{ background: 'var(--ds-inset-bg)', borderColor: 'var(--ds-border-soft)' }"
        >
            <p class="text-[11px] font-semibold" :style="{ color: 'var(--ds-text-ink)' }">
                {{ label('tray_title', { count: String(trayIds.length) }) }}
            </p>
            <p class="mt-0.5 text-[11px]" :style="{ color: 'var(--ds-text-muted)' }">
                {{ t.tray_hint }}
            </p>
            <ul class="mt-1 flex flex-wrap gap-1.5">
                <li v-for="id in trayIds" :key="id">
                    <button
                        type="button"
                        :data-tray-id="id"
                        class="rounded border px-1.5 py-0.5 text-[10px]"
                        :style="{
                            borderColor: 'var(--ds-border-soft)',
                            color: 'var(--ds-text-secondary)',
                        }"
                        @click="emit('deselect', id)"
                    >
                        {{ t.tray_deselect }} ✕
                    </button>
                </li>
            </ul>
        </div>
    </div>
</template>
