<script setup lang="ts">
/**
 * B4-S07 H06 — erasure starts here and finishes elsewhere. The sentence
 * that matters — "this does not delete anything" — is the FIRST thing in
 * the dialog, at every size; no delete button, no red, no confirm phrase,
 * because none of those belong to this screen (A-38/[D17]: initiation
 * only; approval and execution are the compliance workflow's).
 */
import { onMounted, ref } from 'vue';

const props = defineProps<{
    customerId: string;
    t: Record<string, string>;
}>();
const emit = defineEmits<{ close: []; requested: [] }>();

const customerName = ref('');
onMounted(async () => {
    const panel = await fetch(`/api/staff/customers/${props.customerId}/panel`, {
        headers: { Accept: 'application/json' },
    });
    if (panel.ok) {
        const data = (await panel.json()) as { header?: { display_name?: string | null } };
        customerName.value = data.header?.display_name ?? '';
    }
});

function xsrf(): string {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    return match?.[1] !== undefined ? decodeURIComponent(match[1]) : '';
}

const reason = ref('');
const error = ref('');

async function send(): Promise<void> {
    error.value = '';
    const response = await fetch(`/api/staff/customers/${props.customerId}/erasure`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-XSRF-TOKEN': xsrf(),
        },
        body: JSON.stringify({ reason: reason.value }),
    });
    if (response.ok) {
        emit('requested');
        emit('close');
        return;
    }
    const body = (await response.json().catch(() => ({}))) as { detail?: string };
    error.value = body.detail ?? String(response.status);
}
</script>

<template>
    <div
        role="dialog"
        aria-modal="true"
        :aria-label="t.erasure_title"
        class="fixed inset-0 z-40 flex items-center justify-center p-4"
        data-testid="erasure-modal"
    >
        <div
            class="absolute inset-0"
            :style="{ background: 'var(--ds-overlay)' }"
            @click="emit('close')"
        ></div>
        <div
            class="relative w-full max-w-md rounded border p-4"
            :style="{
                borderColor: 'var(--ds-border-strong)',
                background: 'var(--ds-surface)',
                color: 'var(--ds-text-primary)',
            }"
        >
            <h2 class="text-base font-semibold">{{ t.erasure_title }}</h2>
            <p class="text-sm" :style="{ color: 'var(--ds-text-muted)' }">{{ customerName }}</p>

            <!-- The first sentence in the sheet, not a footnote. -->
            <p class="mt-3 text-sm font-medium" data-testid="erasure-not-delete">
                {{ t.erasure_not_delete }}
            </p>
            <p class="mt-1 text-xs" :style="{ color: 'var(--ds-text-muted)' }">
                {{ t.erasure_body }}
            </p>

            <label class="mt-3 block text-xs" :style="{ color: 'var(--ds-text-label)' }">
                {{ t.erasure_reason }}
                <span :style="{ color: 'var(--ds-text-muted)' }">({{ t.required }})</span>
                <textarea
                    v-model="reason"
                    rows="3"
                    data-testid="erasure-reason"
                    class="mt-1 w-full rounded border px-2 py-1 text-sm"
                    :style="{
                        borderColor: 'var(--ds-border-strong)',
                        background: 'var(--ds-panel-bg)',
                        color: 'var(--ds-text-primary)',
                    }"
                ></textarea>
            </label>
            <p class="mt-1 text-xs" :style="{ color: 'var(--ds-text-muted)' }">
                {{ t.erasure_footer }}
            </p>
            <p
                v-if="error !== ''"
                class="mt-1 text-xs"
                :style="{ color: 'var(--ds-danger-strong)' }"
            >
                {{ error }}
            </p>

            <div class="mt-4 flex justify-end gap-2">
                <button
                    type="button"
                    class="rounded border px-3 py-1.5 text-sm"
                    :style="{ borderColor: 'var(--ds-border-strong)' }"
                    data-testid="erasure-cancel"
                    @click="emit('close')"
                >
                    {{ t.cancel }}
                </button>
                <button
                    type="button"
                    class="rounded border px-3 py-1.5 text-sm"
                    :style="{ borderColor: 'var(--ds-border-strong)' }"
                    :disabled="reason.trim().length < 5"
                    data-testid="erasure-send"
                    @click="send"
                >
                    {{ t.erasure_send }}
                </button>
            </div>
        </div>
    </div>
</template>
