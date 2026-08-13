<script setup lang="ts">
// C16 (B2-S04 v2, S07 step 14 Unit D) — the confirm for wide bulk
// operations, from the house dialog idiom (OperationDialog/MergeDialog) and
// the B5 weights: the verb on the primary, the consequence in nouns BEFORE
// the action, 375 full-screen. Above the drawn 50-ticket line the confirm
// is TYPED, not clicked ("Type close 63 to continue" — exact match, the
// MergeDialog gate's idiom); at or under it the same dialog confirms by
// click. The open-window line is server truth for token selections (mint's
// open_windows) and cached-row truth for id selections — the client never
// derives window state, it counts stamps the server already sent.
import { computed, ref, watch } from 'vue';

const props = defineProps<{
    open: boolean;
    action: string;
    count: number;
    openWindows: number | null;
    t: Record<string, string>;
}>();

const emit = defineEmits<{
    (e: 'close'): void;
    (e: 'confirm'): void;
}>();

const typed = ref('');

watch(
    () => props.open,
    () => {
        typed.value = '';
    },
);

const fill = (key: string): string => (props.t[key] ?? '').replace(':count', String(props.count));

const phraseRequired = computed(() => props.count > 50);
const phrase = computed(() => fill('phrase_' + props.action));
const title = computed(() => fill('confirm_title_' + props.action));
const blocked = computed(() => phraseRequired.value && typed.value !== phrase.value);
</script>

<template>
    <div
        v-if="open"
        class="fixed inset-0 z-50 flex items-center justify-center p-0 sm:p-6"
        :style="{ background: 'rgba(0, 0, 0, 0.4)' }"
        data-testid="bulk-confirm-backdrop"
    >
        <div
            role="dialog"
            aria-modal="true"
            :aria-label="title"
            data-testid="bulk-confirm-dialog"
            :data-action="action"
            class="flex h-full w-full flex-col overflow-y-auto p-4 sm:h-auto sm:max-h-[85vh] sm:w-[440px] sm:rounded-lg"
            :style="{ background: 'var(--ds-panel-bg)' }"
        >
            <p class="text-sm font-semibold">{{ title }}</p>

            <!-- The consequences, in nouns, BEFORE the action (B5). -->
            <p
                v-if="phraseRequired"
                class="mt-1.5 text-xs"
                :style="{ color: 'var(--ds-text-muted)' }"
            >
                {{ fill('confirm_over_line') }}
            </p>
            <p
                v-if="openWindows !== null && openWindows > 0"
                data-testid="bulk-confirm-windows"
                class="mt-1.5 text-xs"
                :style="{ color: 'var(--ds-text-muted)' }"
            >
                {{ (t.confirm_open_windows ?? '').replace(':windows', String(openWindows)) }}
            </p>

            <!-- C16: typed, not clicked, above the 50 line. -->
            <div v-if="phraseRequired" class="mt-3">
                <label class="block text-xs font-medium" for="bulk-confirm-phrase">
                    {{ (t.confirm_type_label ?? '').replace(':phrase', phrase) }}
                </label>
                <input
                    id="bulk-confirm-phrase"
                    v-model="typed"
                    type="text"
                    autocomplete="off"
                    data-testid="bulk-confirm-phrase"
                    :placeholder="phrase"
                    class="mt-1 w-full rounded-md border px-2 py-1.5 text-sm"
                    :style="{
                        borderColor: 'var(--ds-border-strong)',
                        background: 'var(--ds-panel-soft)',
                        color: 'var(--ds-text-primary)',
                    }"
                />
            </div>

            <div class="mt-4 flex justify-end gap-2">
                <button
                    type="button"
                    data-testid="bulk-confirm-cancel"
                    class="min-h-[44px] rounded-md border px-3 text-xs"
                    :style="{ borderColor: 'var(--ds-border)' }"
                    @click="emit('close')"
                >
                    {{ t.confirm_cancel }}
                </button>
                <button
                    type="button"
                    data-testid="bulk-confirm-go"
                    class="min-h-[44px] rounded-md px-3 text-xs font-semibold"
                    :style="{
                        background: 'var(--ds-text-ink)',
                        color: 'var(--ds-page-bg)',
                        opacity: blocked ? 0.4 : 1,
                    }"
                    :disabled="blocked"
                    @click="emit('confirm')"
                >
                    {{ title }}
                </button>
            </div>
        </div>
    </div>
</template>
