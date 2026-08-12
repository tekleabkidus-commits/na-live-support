<script setup lang="ts">
// FR-31/AC-08 — the ? overlay (S07 step 13), drawn in B1-S03: four groups
// (List / Go to / Ticket / Composer), keycap chips, "Press ? any time".
// It renders FROM lib/shortcuts.ts's BINDINGS — the parity AC-08 demands is
// structural (one map), and the spec still proves it against the DOM.
// §8: the first-run coach marks are re-openable from here.
// §14: labels are localized; the physical keycaps stay layout-aware only to
// v1's documented US-QWERTY extent (§19 owns remapping).
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { BINDINGS, type Binding } from '../../lib/shortcuts';

const props = defineProps<{
    open: boolean;
    t: Record<string, string>;
}>();

const emit = defineEmits<{ (e: 'close'): void; (e: 'open-coach'): void }>();

const card = ref<HTMLElement | null>(null);

// Rows: bindings sharing a labelKey merge their keycaps (J/K, Enter/O).
type OverlayRow = { labelKey: string; keys: string[]; ids: string[] };
function rowsFor(section: Binding['section']): OverlayRow[] {
    const rows: OverlayRow[] = [];
    for (const binding of BINDINGS.filter((b) => b.section === section)) {
        const existing = rows.find((r) => r.labelKey === binding.labelKey);
        if (existing) {
            existing.keys.push(...binding.keys);
            existing.ids.push(binding.id);
        } else {
            rows.push({ labelKey: binding.labelKey, keys: [...binding.keys], ids: [binding.id] });
        }
    }
    return rows;
}
const sections = computed(() => [
    { key: 'list', title: props.t.section_list ?? '', rows: rowsFor('list') },
    { key: 'goto', title: props.t.section_goto ?? '', rows: rowsFor('goto') },
    { key: 'ticket', title: props.t.section_ticket ?? '', rows: rowsFor('ticket') },
    { key: 'composer', title: props.t.section_composer ?? '', rows: rowsFor('composer') },
]);

// §13: the overlay traps focus while open and returns it on close.
let opener: HTMLElement | null = null;
watch(
    () => props.open,
    (open) => {
        if (open) {
            opener = document.activeElement instanceof HTMLElement ? document.activeElement : null;
            requestAnimationFrame(() => card.value?.focus());
        } else {
            opener?.focus();
            opener = null;
        }
    },
);

function onKeydown(event: KeyboardEvent): void {
    if (props.open && event.key === 'Escape') {
        event.stopPropagation();
        emit('close');
    }
}
onMounted(() => window.addEventListener('keydown', onKeydown, true));
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown, true));
</script>

<template>
    <div
        v-if="open"
        class="fixed inset-0 z-50 flex items-center justify-center p-4"
        :style="{ background: 'rgba(0, 0, 0, 0.35)' }"
        data-testid="shortcut-overlay-backdrop"
        @click.self="emit('close')"
    >
        <div
            ref="card"
            role="dialog"
            aria-modal="true"
            :aria-label="t.title"
            tabindex="-1"
            data-testid="shortcut-overlay"
            class="max-h-[85vh] w-[560px] overflow-y-auto rounded-xl border p-5 shadow-xl"
            :style="{ background: 'var(--ds-panel-bg)', borderColor: 'var(--ds-border)' }"
        >
            <div class="mb-3 flex items-baseline gap-2">
                <span
                    class="text-[15px] font-semibold"
                    :style="{ color: 'var(--ds-text-primary)' }"
                >
                    {{ t.title }}
                </span>
                <span class="text-[11.5px]" :style="{ color: 'var(--ds-text-secondary)' }">
                    {{ t.hint_prefix }}
                    <kbd class="rounded border px-1" :style="{ borderColor: 'var(--ds-border)' }"
                        >?</kbd
                    >
                    {{ t.hint_suffix }}
                </span>
                <button
                    type="button"
                    class="ms-auto text-sm"
                    data-testid="shortcut-overlay-close"
                    :aria-label="t.close"
                    :style="{ color: 'var(--ds-text-secondary)' }"
                    @click="emit('close')"
                >
                    ✕
                </button>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div v-for="section in sections" :key="section.key">
                    <p
                        class="mb-1.5 text-[10px] font-semibold uppercase tracking-wider"
                        :style="{ color: 'var(--ds-text-muted)' }"
                    >
                        {{ section.title }}
                    </p>
                    <div
                        v-for="row in section.rows"
                        :key="row.labelKey"
                        class="mb-1.5 flex items-center gap-2 text-xs"
                        :style="{ color: 'var(--ds-text-primary)' }"
                        :data-binding-ids="row.ids.join(',')"
                        data-testid="shortcut-row"
                    >
                        <kbd
                            v-for="key in row.keys"
                            :key="key"
                            class="rounded border px-1.5 py-0.5 text-[10.5px]"
                            :style="{
                                background: 'var(--ds-inset-bg)',
                                borderColor: 'var(--ds-border)',
                            }"
                            >{{ key }}</kbd
                        >
                        <span>{{ t['label_' + row.labelKey] }}</span>
                    </div>
                </div>
            </div>
            <div
                class="mt-4 border-t pt-3 text-[11px]"
                :style="{ borderColor: 'var(--ds-border)', color: 'var(--ds-text-secondary)' }"
            >
                <button
                    type="button"
                    class="underline"
                    data-testid="shortcut-overlay-coach"
                    @click="emit('open-coach')"
                >
                    {{ t.reopen_coach }}
                </button>
                <span class="ms-2">{{ t.layout_note }}</span>
            </div>
        </div>
    </div>
</template>
