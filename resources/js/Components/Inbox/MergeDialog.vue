<script setup lang="ts">
// B1-S04 (S07 step 14) — the merge dialog, drawn whole: source cards that
// WILL CLOSE, the surviving target, the SERVER-COMPUTED "What happens"
// panel (the preview door's machinery truth — nothing here derives
// effects), the type-MERGE confirm gate above two sources, and the 24 h
// reversal stated ON the dialog (Doc 06 Rec-4's own demand). At 375 the
// dialog is full-screen (the drawn mobile posture).
import { computed, ref, watch } from 'vue';

type Card = {
    ticket_id: string;
    number: number | string;
    subject: string | null;
    created_at: string;
    message_count: number;
    tags: string[];
    sla_state: string | null;
};

type Preview = {
    same_customer: boolean;
    customer_name: string;
    target: Card;
    sources: Card[];
    effects: {
        tags_joining: string[];
        followers_joining: number;
        sla_governs_number: number | string;
        reversible_hours: number;
    };
    confirm_phrase_required: boolean;
};

const props = defineProps<{
    open: boolean;
    ticketIds: string[];
    t: Record<string, string>;
    xsrf: () => string;
}>();

const emit = defineEmits<{
    (e: 'close'): void;
    (e: 'merged', survivorId: string): void;
}>();

const preview = ref<Preview | null>(null);
const phrase = ref('');
const busy = ref(false);
const refusal = ref('');

watch(
    () => [props.open, props.ticketIds.join(',')] as const,
    async () => {
        if (!props.open || props.ticketIds.length < 2) return;
        preview.value = null;
        phrase.value = '';
        refusal.value = '';
        const query = props.ticketIds.map((id) => `ids[]=${encodeURIComponent(id)}`).join('&');
        const response = await fetch(`/inbox/merge/preview?${query}`, {
            headers: { Accept: 'application/json' },
        });
        if (!response.ok) {
            const body = (await response.json().catch(() => null)) as { detail?: string } | null;
            refusal.value = body?.detail ?? props.t.refused ?? '';
            return;
        }
        preview.value = (await response.json()) as Preview;
    },
    { immediate: true },
);

const confirmBlocked = computed(
    () =>
        preview.value === null ||
        !preview.value.same_customer ||
        (preview.value.confirm_phrase_required && phrase.value !== 'MERGE'),
);

async function executeMerge(): Promise<void> {
    if (busy.value || preview.value === null || confirmBlocked.value) return;
    busy.value = true;
    refusal.value = '';
    try {
        const response = await fetch('/inbox/merge', {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': props.xsrf(),
            },
            body: JSON.stringify({
                target_id: preview.value.target.ticket_id,
                source_ids: preview.value.sources.map((source) => source.ticket_id),
            }),
        });
        if (response.ok) {
            const body = (await response.json()) as { survivor_id: string };
            emit('merged', body.survivor_id);
            emit('close');
            return;
        }
        const body = (await response.json().catch(() => null)) as { detail?: string } | null;
        refusal.value = body?.detail ?? props.t.refused ?? '';
    } finally {
        busy.value = false;
    }
}

function timeShort(iso: string): string {
    return new Intl.DateTimeFormat(undefined, { month: 'short', day: 'numeric' }).format(
        new Date(iso),
    );
}
</script>

<template>
    <div
        v-if="open"
        class="fixed inset-0 z-50 flex items-center justify-center p-0 sm:p-6"
        :style="{ background: 'rgba(0, 0, 0, 0.4)' }"
        data-testid="merge-backdrop"
    >
        <div
            role="dialog"
            aria-modal="true"
            :aria-label="t.merge_title"
            data-testid="merge-dialog"
            class="flex h-full w-full flex-col overflow-y-auto p-4 sm:h-auto sm:max-h-[85vh] sm:w-[560px] sm:rounded-lg"
            :style="{ background: 'var(--ds-panel-bg)' }"
        >
            <p class="text-sm font-semibold">
                {{ (t.merge_title ?? '').replace(':count', String(ticketIds.length)) }}
            </p>

            <template v-if="preview !== null">
                <p class="mt-0.5 text-xs" :style="{ color: 'var(--ds-text-muted)' }">
                    {{
                        preview.same_customer
                            ? (t.merge_same_customer ?? '').replace(':name', preview.customer_name)
                            : (t.merge_cross_customer ?? '')
                    }}
                </p>

                <div class="mt-3 flex flex-col gap-2 sm:flex-row sm:items-stretch">
                    <div class="flex-1">
                        <p
                            class="mb-1 text-[11px] font-semibold"
                            :style="{ color: 'var(--ds-text-muted)' }"
                        >
                            {{ t.merge_sources }}
                        </p>
                        <div
                            v-for="source in preview.sources"
                            :key="source.ticket_id"
                            :data-merge-source="source.number"
                            class="mb-1 rounded-md border p-2 text-xs"
                            :style="{ borderColor: 'var(--ds-border)' }"
                        >
                            <p class="font-semibold">#{{ source.number }} · {{ source.subject }}</p>
                            <p :style="{ color: 'var(--ds-text-muted)' }">
                                {{ timeShort(source.created_at) }} ·
                                {{
                                    (t.merge_messages ?? '').replace(
                                        ':count',
                                        String(source.message_count),
                                    )
                                }}
                                <span v-if="source.tags.length > 0"
                                    >· {{ source.tags.join(', ') }}</span
                                >
                            </p>
                        </div>
                    </div>
                    <p aria-hidden="true" class="self-center text-lg">→</p>
                    <div class="flex-1">
                        <p
                            class="mb-1 text-[11px] font-semibold"
                            :style="{ color: 'var(--ds-text-muted)' }"
                        >
                            {{ t.merge_target }}
                        </p>
                        <div
                            data-testid="merge-target-card"
                            class="rounded-md border-2 p-2 text-xs"
                            :style="{ borderColor: 'var(--ds-accent)' }"
                        >
                            <p class="font-semibold">
                                #{{ preview.target.number }} · {{ preview.target.subject }}
                            </p>
                            <p :style="{ color: 'var(--ds-text-muted)' }">
                                {{ timeShort(preview.target.created_at) }} ·
                                {{
                                    (t.merge_messages ?? '').replace(
                                        ':count',
                                        String(preview.target.message_count),
                                    )
                                }}
                                <span v-if="preview.target.sla_state === 'breached'">
                                    · {{ t.sla_breached }}</span
                                >
                            </p>
                        </div>
                    </div>
                </div>

                <!-- The SERVER-COMPUTED "What happens" panel. -->
                <div
                    data-testid="merge-what-happens"
                    class="mt-3 rounded-md border p-2"
                    :style="{
                        borderColor: 'var(--ds-border-soft)',
                        background: 'var(--ds-inset-bg)',
                    }"
                >
                    <p class="mb-1 text-[11px] font-semibold">{{ t.merge_what_happens }}</p>
                    <ul class="flex list-disc flex-col gap-0.5 ps-4 text-[11.5px]">
                        <li>{{ t.merge_fact_messages }}</li>
                        <li>
                            {{
                                (t.merge_fact_unions ?? '').replace(
                                    ':number',
                                    String(preview.effects.sla_governs_number),
                                )
                            }}
                        </li>
                        <li>
                            {{
                                (t.merge_fact_sla ?? '').replace(
                                    ':number',
                                    String(preview.effects.sla_governs_number),
                                )
                            }}
                        </li>
                        <li>{{ t.merge_fact_banner }}</li>
                        <li>
                            {{
                                (t.merge_fact_reversible ?? '').replace(
                                    ':hours',
                                    String(preview.effects.reversible_hours),
                                )
                            }}
                        </li>
                    </ul>
                </div>

                <!-- The drawn gate: >2 sources → type MERGE. -->
                <div v-if="preview.confirm_phrase_required" class="mt-3">
                    <label class="text-[11.5px]" :style="{ color: 'var(--ds-text-secondary)' }">
                        {{ t.merge_phrase_label }}
                        <input
                            v-model="phrase"
                            type="text"
                            data-testid="merge-phrase"
                            class="ms-1 w-24 rounded-md border px-1.5 py-0.5 text-xs"
                            :style="{ borderColor: 'var(--ds-border)' }"
                        />
                    </label>
                </div>

                <p
                    v-if="refusal !== ''"
                    role="alert"
                    data-testid="merge-refusal"
                    class="mt-2 rounded-md border px-2 py-1 text-xs"
                    :style="{
                        borderColor: 'var(--ds-danger-strong)',
                        color: 'var(--ds-danger-strong)',
                    }"
                >
                    {{ refusal }}
                </p>

                <div class="mt-4 flex justify-end gap-2">
                    <button
                        type="button"
                        data-testid="merge-cancel"
                        class="rounded-md border px-3 py-1.5 text-xs"
                        :style="{ borderColor: 'var(--ds-border)' }"
                        @click="emit('close')"
                    >
                        {{ t.cancel }}
                    </button>
                    <button
                        type="button"
                        data-testid="merge-confirm"
                        class="rounded-md px-3 py-1.5 text-xs font-semibold"
                        :style="{
                            background: 'var(--ds-text-ink)',
                            color: 'var(--ds-page-bg)',
                            opacity: confirmBlocked ? 0.4 : 1,
                        }"
                        :disabled="confirmBlocked || busy"
                        @click="void executeMerge()"
                    >
                        {{
                            (t.merge_confirm ?? '').replace(
                                ':number',
                                String(preview.target.number),
                            )
                        }}
                    </button>
                </div>
            </template>
            <p
                v-else-if="refusal !== ''"
                role="alert"
                class="mt-2 text-xs"
                :style="{ color: 'var(--ds-danger-strong)' }"
            >
                {{ refusal }}
            </p>
            <p v-else class="mt-2 text-xs" :style="{ color: 'var(--ds-text-muted)' }">
                {{ t.loading }}
            </p>
        </div>
    </div>
</template>
