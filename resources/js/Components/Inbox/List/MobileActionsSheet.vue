<script setup lang="ts">
// B2-S01's swipe-left ACTIONS SHEET (S07 step 13), drawn verbatim: grabber,
// "Name · #number" header with the snippet, ≥52 px rows — Resolve, Pending
// customer, Priority — Urgent (status dots from the fixed Doc 06 tokens),
// divider, Cancel. Every row is a REAL door; the caller executes.
const props = defineProps<{
    open: boolean;
    title: string;
    subtitle: string;
    can: { resolve: boolean; priority: boolean };
    t: Record<string, string>;
}>();

const emit = defineEmits<{
    (e: 'close'): void;
    (e: 'pick', action: 'resolve' | 'pending' | 'priority_urgent'): void;
}>();

function pick(action: 'resolve' | 'pending' | 'priority_urgent'): void {
    emit('pick', action);
}
void props;
</script>

<template>
    <div
        v-if="open"
        class="fixed inset-0 z-50 flex flex-col justify-end"
        :style="{ background: 'rgba(29, 28, 24, 0.35)' }"
        data-testid="mobile-actions-backdrop"
        @click.self="emit('close')"
    >
        <div
            role="dialog"
            aria-modal="true"
            :aria-label="title"
            data-testid="mobile-actions-sheet"
            class="rounded-t-2xl pb-4 pt-2.5"
            :style="{ background: 'var(--ds-panel-bg)' }"
        >
            <div
                class="mx-auto mb-3 h-1 w-9 rounded-full"
                :style="{ background: 'var(--ds-border-strong)' }"
            ></div>
            <div class="px-4 pb-1">
                <p class="text-sm font-semibold" :style="{ color: 'var(--ds-text-primary)' }">
                    {{ title }}
                </p>
                <p class="mt-0.5 text-[11.5px]" :style="{ color: 'var(--ds-text-secondary)' }">
                    {{ subtitle }}
                </p>
            </div>
            <div class="px-2 pt-2">
                <button
                    v-if="can.resolve"
                    type="button"
                    data-testid="sheet-resolve"
                    class="flex w-full items-center gap-3 px-3 text-start text-sm"
                    :style="{ minHeight: '52px', color: 'var(--ds-text-primary)' }"
                    @click="pick('resolve')"
                >
                    <span
                        class="h-2 w-2 rounded-full"
                        :style="{ background: 'var(--ds-status-resolved-fg)' }"
                    ></span>
                    {{ t.sheet_resolve }}
                </button>
                <button
                    v-if="can.resolve"
                    type="button"
                    data-testid="sheet-pending"
                    class="flex w-full items-center gap-3 px-3 text-start text-sm"
                    :style="{ minHeight: '52px', color: 'var(--ds-text-primary)' }"
                    @click="pick('pending')"
                >
                    <span
                        class="h-2 w-2 rounded-full"
                        :style="{ background: 'var(--ds-status-pending-customer-fg)' }"
                    ></span>
                    {{ t.sheet_pending }}
                </button>
                <button
                    v-if="can.priority"
                    type="button"
                    data-testid="sheet-priority-urgent"
                    class="flex w-full items-center gap-3 px-3 text-start text-sm"
                    :style="{ minHeight: '52px', color: 'var(--ds-text-primary)' }"
                    @click="pick('priority_urgent')"
                >
                    <span
                        class="h-2 w-2 rounded-full"
                        :style="{ background: 'var(--ds-priority-urgent)' }"
                    ></span>
                    {{ t.sheet_priority_urgent }}
                </button>
                <div
                    class="mx-3 my-1.5 h-px"
                    :style="{ background: 'var(--ds-border-soft)' }"
                ></div>
                <button
                    type="button"
                    data-testid="sheet-cancel"
                    class="flex w-full items-center gap-3 px-3 text-start text-sm"
                    :style="{ minHeight: '52px', color: 'var(--ds-text-secondary)' }"
                    @click="emit('close')"
                >
                    {{ t.sheet_cancel }}
                </button>
            </div>
        </div>
    </div>
</template>
