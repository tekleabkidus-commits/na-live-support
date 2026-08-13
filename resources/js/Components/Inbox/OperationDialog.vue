<script setup lang="ts">
// B1-S04's operation dialogs (S07 step 14) — ESCALATE and MARK-AS-SPAM,
// drawn from the house dialog idiom (MergeDialog) and the B5 confirmation
// weights: the verb on the primary (never OK/Confirm), the consequence in
// nouns BEFORE the action, inline refusals in place, 375 full-screen.
//
// Escalate paths: supervisor review and a NAMED person (the assignees
// directory — the same read the A-palette uses). The DEPARTMENT path is a
// DEFERRED seam: departments are opaque ids until Doc 11's structures land
// (TransferTicket's own recorded posture), so no target directory exists
// to draw — deferred, not invented.
import { computed, ref, watch } from 'vue';

const props = defineProps<{
    open: boolean;
    kind: 'escalate' | 'spam';
    ticketId: string;
    t: Record<string, string>;
    xsrf: () => string;
}>();

const emit = defineEmits<{
    (e: 'close'): void;
    (e: 'done'): void;
}>();

const reason = ref('');
const path = ref<'supervisor_review' | 'named'>('supervisor_review');
const targetId = ref('');
const blockConversation = ref(false);
const busy = ref(false);
const refusal = ref('');
const directory = ref<{ id: string; name: string }[]>([]);

watch(
    () => [props.open, props.kind] as const,
    async () => {
        if (!props.open) return;
        reason.value = '';
        path.value = 'supervisor_review';
        targetId.value = '';
        blockConversation.value = false;
        refusal.value = '';
        if (props.kind === 'escalate' && directory.value.length === 0) {
            const response = await fetch('/inbox/assignees', {
                headers: { Accept: 'application/json' },
            }).catch(() => null);
            if (response?.ok) {
                const body = (await response.json()) as { assignees: { id: string; name: string }[] };
                directory.value = body.assignees ?? [];
            }
        }
    },
    { immediate: true },
);

const confirmBlocked = computed(
    () =>
        reason.value.trim().length < 10 ||
        (props.kind === 'escalate' && path.value === 'named' && targetId.value === ''),
);

async function execute(): Promise<void> {
    if (busy.value || confirmBlocked.value) return;
    busy.value = true;
    refusal.value = '';
    const payload: Record<string, unknown> =
        props.kind === 'escalate'
            ? {
                  path: path.value,
                  target_id: path.value === 'named' ? targetId.value : null,
                  reason: reason.value.trim(),
              }
            : { reason: reason.value.trim(), block_conversation: blockConversation.value };
    try {
        const response = await fetch(`/inbox/t/${props.ticketId}/${props.kind}`, {
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
        const body = (await response.json().catch(() => null)) as { detail?: string } | null;
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
        data-testid="operation-backdrop"
    >
        <div
            role="dialog"
            aria-modal="true"
            :aria-label="kind === 'escalate' ? t.escalate_title : t.spam_title"
            data-testid="operation-dialog"
            :data-kind="kind"
            class="flex h-full w-full flex-col overflow-y-auto p-4 sm:h-auto sm:max-h-[85vh] sm:w-[480px] sm:rounded-lg"
            :style="{ background: 'var(--ds-panel-bg)' }"
        >
            <p class="text-sm font-semibold">
                {{ kind === 'escalate' ? t.escalate_title : t.spam_title }}
            </p>

            <template v-if="kind === 'escalate'">
                <p class="mt-0.5 text-xs" :style="{ color: 'var(--ds-text-muted)' }">
                    {{ t.escalate_help }}
                </p>
                <fieldset class="mt-3">
                    <legend class="text-xs font-medium">{{ t.escalate_path_label }}</legend>
                    <label class="mt-1.5 flex items-center gap-2 text-sm">
                        <input v-model="path" type="radio" value="supervisor_review" data-testid="path-supervisor" />
                        {{ t.path_supervisor }}
                    </label>
                    <label class="mt-1 flex items-center gap-2 text-sm">
                        <input v-model="path" type="radio" value="named" data-testid="path-named" />
                        {{ t.path_named }}
                    </label>
                    <select
                        v-if="path === 'named'"
                        v-model="targetId"
                        data-testid="escalate-target"
                        class="mt-1.5 w-full rounded-md border px-2 py-1.5 text-sm"
                        :style="{
                            borderColor: 'var(--ds-border-strong)',
                            background: 'var(--ds-panel-soft)',
                        }"
                    >
                        <option value="" disabled>{{ t.pick_target }}</option>
                        <option v-for="person in directory" :key="person.id" :value="person.id">
                            {{ person.name }}
                        </option>
                    </select>
                    <!-- Doc 11 seam: the department path awaits a directory. -->
                    <p class="mt-1.5 text-[11px]" :style="{ color: 'var(--ds-text-faint)' }">
                        {{ t.path_department_deferred }}
                    </p>
                </fieldset>
                <p class="mt-2 text-[11px]" :style="{ color: 'var(--ds-text-faint)' }">
                    {{ t.escalate_keeps }}
                </p>
            </template>

            <template v-else>
                <p class="mt-0.5 text-xs" :style="{ color: 'var(--ds-text-muted)' }">
                    {{ t.spam_help }}
                </p>
                <label class="mt-3 flex items-start gap-2 text-xs">
                    <input
                        v-model="blockConversation"
                        type="checkbox"
                        data-testid="spam-block"
                        class="mt-0.5"
                    />
                    <span>
                        {{ t.spam_block_label }}
                        <span class="block" :style="{ color: 'var(--ds-text-faint)' }">
                            {{ t.spam_block_consequence }}
                        </span>
                    </span>
                </label>
            </template>

            <label class="mt-3 block text-xs font-medium" for="op-reason">
                {{ t.reason_label }}
            </label>
            <textarea
                id="op-reason"
                v-model="reason"
                rows="3"
                maxlength="500"
                data-testid="operation-reason"
                :placeholder="t.reason_min_hint"
                class="mt-1 w-full resize-y rounded-md border px-2 py-1.5 text-sm"
                :style="{
                    borderColor: 'var(--ds-border-strong)',
                    background: 'var(--ds-panel-soft)',
                    color: 'var(--ds-text-primary)',
                }"
            ></textarea>

            <p
                v-if="refusal !== ''"
                role="alert"
                data-testid="operation-refusal"
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
                    data-testid="operation-cancel"
                    class="min-h-[44px] rounded-md border px-3 text-xs"
                    :style="{ borderColor: 'var(--ds-border)' }"
                    @click="emit('close')"
                >
                    {{ t.cancel }}
                </button>
                <button
                    type="button"
                    data-testid="operation-confirm"
                    class="min-h-[44px] rounded-md px-3 text-xs font-semibold"
                    :style="{
                        background: kind === 'spam' ? 'var(--ds-danger-strong)' : 'var(--ds-text-ink)',
                        color: 'var(--ds-page-bg)',
                        opacity: confirmBlocked || busy ? 0.4 : 1,
                    }"
                    :disabled="confirmBlocked || busy"
                    @click="void execute()"
                >
                    {{ kind === 'escalate' ? t.escalate_verb : t.spam_verb }}
                </button>
            </div>
        </div>
    </div>
</template>
