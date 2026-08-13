<script setup lang="ts">
// B1-S04 SPLIT + B2-S04 C7 UNMERGE (S07 step 14), one small dialog host:
//
//  split      the selection came from the thread; this names the optional
//             subject and carries the verb. D11: when the cap of 3 is
//             already live (or the door refuses split_cap_reached) the
//             dialog renders the drawn cap CARD — the three linked tickets
//             listed by number/subject/status, close-or-resolve-one advice.
//  unmerge    C7's two drawn states: AVAILABLE (source card, the message
//             count, "returns to Open", the 24 h countdown against the
//             SERVER-computed boundary) and REFUSED (snapshot pruned —
//             what you can still do is SPLIT, which keeps the audit trail
//             honest instead of pretending the merge never happened).
import { computed, ref, watch } from 'vue';

type LinkedRow = { number: number | string; subject: string | null; status: string };

const props = defineProps<{
    open: boolean;
    kind: 'split' | 'unmerge';
    ticketId: string;
    ticketNumber: number | string | null;
    messageIds: string[];
    linkedWork: LinkedRow[];
    unmergeUntil: string | null;
    mergedMessageCount: number | null;
    mergedIntoNumber: number | string | null;
    t: Record<string, string>;
    xsrf: () => string;
}>();

const emit = defineEmits<{
    (e: 'close'): void;
    (e: 'done'): void;
    (e: 'split-instead'): void;
}>();

const subject = ref('');
const busy = ref(false);
const refusal = ref('');
const capReached = ref(false);

watch(
    () => props.open,
    () => {
        if (!props.open) return;
        subject.value = '';
        refusal.value = '';
        // D11 pre-emptive: three live linked tickets = the cap card, before
        // any selection is wasted.
        capReached.value = props.kind === 'split' && props.linkedWork.length >= 3;
    },
);

const label = (key: string, replacements: Record<string, string> = {}): string => {
    let value = props.t[key] ?? '';
    for (const [token, replacement] of Object.entries(replacements)) {
        value = value.replaceAll(':' + token, replacement);
    }
    return value;
};

const unmergeAvailable = computed(
    () => props.unmergeUntil !== null && new Date(props.unmergeUntil).getTime() > Date.now(),
);
const unmergeRemaining = computed(() => {
    if (!unmergeAvailable.value || props.unmergeUntil === null) return '';
    const hours = Math.max(1, Math.floor((new Date(props.unmergeUntil).getTime() - Date.now()) / 3_600_000));
    return label('unmerge_window_in', { hours: String(hours) });
});

async function execute(): Promise<void> {
    if (busy.value) return;
    busy.value = true;
    refusal.value = '';
    const path = props.kind === 'split' ? `/inbox/t/${props.ticketId}/split` : `/inbox/t/${props.ticketId}/unmerge`;
    const payload =
        props.kind === 'split'
            ? { message_ids: props.messageIds, subject: subject.value.trim() || null }
            : {};
    try {
        const response = await fetch(path, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': props.xsrf(),
            },
            body: JSON.stringify(payload),
        });
        if (response.ok) {
            emit('done');
            emit('close');
            return;
        }
        const body = (await response.json().catch(() => null)) as {
            detail?: string;
            refusal_code?: string;
        } | null;
        if (body?.refusal_code === 'split_cap_reached') {
            capReached.value = true;
            return;
        }
        refusal.value = body?.detail ?? props.t.refused ?? '';
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div
        v-if="open"
        class="fixed inset-0 z-50 flex items-center justify-center p-0 sm:p-6"
        :style="{ background: 'rgba(0, 0, 0, 0.4)' }"
        data-testid="split-unmerge-backdrop"
    >
        <div
            role="dialog"
            aria-modal="true"
            :aria-label="kind === 'split' ? t.split_title : t.unmerge_title"
            data-testid="split-unmerge-dialog"
            :data-kind="kind"
            class="flex h-full w-full flex-col overflow-y-auto p-4 sm:h-auto sm:max-h-[85vh] sm:w-[480px] sm:rounded-lg"
            :style="{ background: 'var(--ds-panel-bg)' }"
        >
            <!-- D11 — the cap of 3, the drawn card. -->
            <template v-if="kind === 'split' && capReached">
                <p class="text-sm font-semibold">{{ t.split_cap_title }}</p>
                <p class="mt-0.5 text-xs" :style="{ color: 'var(--ds-text-muted)' }">
                    {{ t.split_cap_detail }}
                </p>
                <ul data-testid="split-cap-card" class="mt-3 flex flex-col gap-1.5">
                    <li
                        v-for="row in linkedWork"
                        :key="String(row.number)"
                        class="rounded-md border p-2 text-xs"
                        :style="{ borderColor: 'var(--ds-border-soft)' }"
                    >
                        <span class="font-semibold">#{{ row.number }}</span>
                        · {{ row.subject ?? '' }}
                        <span :style="{ color: 'var(--ds-text-muted)' }">· {{ row.status }}</span>
                    </li>
                </ul>
                <div class="mt-4 flex justify-end">
                    <button
                        type="button"
                        data-testid="split-cap-close"
                        class="min-h-[44px] rounded-md border px-3 text-xs"
                        :style="{ borderColor: 'var(--ds-border)' }"
                        @click="emit('close')"
                    >
                        {{ t.close }}
                    </button>
                </div>
            </template>

            <!-- SPLIT — subject + the verb. -->
            <template v-else-if="kind === 'split'">
                <p class="text-sm font-semibold">{{ t.split_title }}</p>
                <p class="mt-0.5 text-xs" :style="{ color: 'var(--ds-text-muted)' }">
                    {{ label('split_detail', { count: String(messageIds.length) }) }}
                </p>
                <label class="mt-3 block text-xs font-medium" for="split-subject">
                    {{ t.split_subject_label }}
                </label>
                <input
                    id="split-subject"
                    v-model="subject"
                    type="text"
                    maxlength="120"
                    data-testid="split-subject"
                    class="mt-1 w-full rounded-md border px-2 py-1.5 text-sm"
                    :style="{
                        borderColor: 'var(--ds-border-strong)',
                        background: 'var(--ds-panel-soft)',
                    }"
                />
                <p
                    v-if="refusal !== ''"
                    role="alert"
                    data-testid="split-refusal"
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
                        data-testid="split-cancel"
                        class="min-h-[44px] rounded-md border px-3 text-xs"
                        :style="{ borderColor: 'var(--ds-border)' }"
                        @click="emit('close')"
                    >
                        {{ t.cancel }}
                    </button>
                    <button
                        type="button"
                        data-testid="split-confirm"
                        class="min-h-[44px] rounded-md px-3 text-xs font-semibold"
                        :style="{
                            background: 'var(--ds-text-ink)',
                            color: 'var(--ds-page-bg)',
                            opacity: busy ? 0.4 : 1,
                        }"
                        :disabled="busy"
                        @click="void execute()"
                    >
                        {{ t.split_verb }}
                    </button>
                </div>
            </template>

            <!-- C7 UNMERGE — available, or refused with the honest next move. -->
            <template v-else>
                <p class="text-sm font-semibold">{{ t.unmerge_title }}</p>
                <p class="mt-0.5 text-xs" :style="{ color: 'var(--ds-text-muted)' }">
                    {{
                        label('unmerge_source_line', {
                            number: String(ticketNumber ?? ''),
                            target: String(mergedIntoNumber ?? ''),
                        })
                    }}
                </p>
                <template v-if="unmergeAvailable">
                    <div
                        data-testid="unmerge-available"
                        class="mt-3 rounded-md border p-2 text-xs"
                        :style="{ borderColor: 'var(--ds-border)' }"
                    >
                        <p class="font-semibold">#{{ ticketNumber }}</p>
                        <p :style="{ color: 'var(--ds-text-muted)' }">
                            {{
                                label('unmerge_returns', {
                                    count: String(mergedMessageCount ?? 0),
                                })
                            }}
                        </p>
                    </div>
                    <p class="mt-2 text-[11px]" :style="{ color: 'var(--ds-text-faint)' }">
                        {{ unmergeRemaining }} {{ t.unmerge_after_note }}
                    </p>
                    <p
                        v-if="refusal !== ''"
                        role="alert"
                        data-testid="unmerge-refusal"
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
                            data-testid="unmerge-cancel"
                            class="min-h-[44px] rounded-md border px-3 text-xs"
                            :style="{ borderColor: 'var(--ds-border)' }"
                            @click="emit('close')"
                        >
                            {{ t.cancel }}
                        </button>
                        <button
                            type="button"
                            data-testid="unmerge-confirm"
                            class="min-h-[44px] rounded-md px-3 text-xs font-semibold"
                            :style="{
                                background: 'var(--ds-text-ink)',
                                color: 'var(--ds-page-bg)',
                                opacity: busy ? 0.4 : 1,
                            }"
                            :disabled="busy"
                            @click="void execute()"
                        >
                            {{ t.unmerge_verb }}
                        </button>
                    </div>
                </template>
                <template v-else>
                    <p
                        data-testid="unmerge-refused"
                        class="mt-3 rounded-md border px-2 py-1.5 text-xs"
                        :style="{
                            borderColor: 'var(--ds-warn-strong, var(--ds-border-strong))',
                            color: 'var(--ds-warn-strong, var(--ds-text-primary))',
                        }"
                    >
                        <span aria-hidden="true">⚠</span> {{ t.unmerge_pruned }}
                    </p>
                    <p class="mt-2 text-[11px]" :style="{ color: 'var(--ds-text-faint)' }">
                        {{ t.unmerge_split_advice }}
                    </p>
                    <div class="mt-4 flex justify-end gap-2">
                        <button
                            type="button"
                            data-testid="unmerge-close"
                            class="min-h-[44px] rounded-md border px-3 text-xs"
                            :style="{ borderColor: 'var(--ds-border)' }"
                            @click="emit('close')"
                        >
                            {{ t.close }}
                        </button>
                        <button
                            type="button"
                            data-testid="unmerge-split-instead"
                            class="min-h-[44px] rounded-md px-3 text-xs font-semibold"
                            :style="{ background: 'var(--ds-text-ink)', color: 'var(--ds-page-bg)' }"
                            @click="emit('split-instead')"
                        >
                            {{ t.split_instead }}
                        </button>
                    </div>
                </template>
            </template>
        </div>
    </div>
</template>
