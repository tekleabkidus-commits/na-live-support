<script setup lang="ts">
/**
 * BR-05 / §9 — the tombstone page (S08 step 11): a REAL surface, never a
 * 404. Merged-into link, and the undo button only in-window and permitted
 * (the countdown fact is the SERVER's stamp — never client arithmetic).
 */
import { ref } from 'vue';

const props = defineProps<{
    t: Record<string, string>;
    customerId: string;
    survivorId: string;
    survivorName: string | null;
    snapshotId: string | null;
    expiresAt: string | null;
    canUndo: boolean;
}>();

const undoing = ref(false);
const reason = ref('');
const error = ref('');

function xsrf(): string {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    return match?.[1] !== undefined ? decodeURIComponent(match[1]) : '';
}

async function undo(): Promise<void> {
    error.value = '';
    const response = await fetch(
        `/api/staff/customers/${props.survivorId}/unmerge/${props.snapshotId}`,
        {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': xsrf(),
            },
            body: JSON.stringify({ reason: reason.value }),
        },
    );
    if (response.ok) {
        window.location.href = `/customers/${props.customerId}`; // restored — the page is a profile again
        return;
    }
    const body = (await response.json()) as { detail?: string };
    error.value = body.detail ?? String(response.status);
}
</script>

<template>
    <main
        class="mx-auto max-w-lg p-6 text-start"
        :style="{ color: 'var(--ds-text-primary)' }"
        data-testid="tombstone-page"
    >
        <h1 class="text-lg font-semibold">{{ t.title }}</h1>
        <!-- The tombstone redirect target, announced (§13). -->
        <p class="mt-2 text-sm" role="status">
            {{ (t.merged_into ?? '').replace(':name', survivorName ?? survivorId) }}
        </p>
        <p class="mt-1 text-sm" :style="{ color: 'var(--ds-text-muted)' }">{{ t.body }}</p>
        <p class="mt-3">
            <a
                :href="`/customers/${survivorId}`"
                data-testid="tombstone-survivor-link"
                class="underline"
                :style="{ color: 'var(--ds-accent)' }"
                >{{ t.open_survivor }}</a
            >
        </p>

        <section v-if="canUndo && snapshotId" class="mt-5" data-testid="tombstone-undo">
            <p class="text-xs" :style="{ color: 'var(--ds-text-muted)' }">
                {{ (t.undo_until ?? '').replace(':date', String(expiresAt).slice(0, 10)) }}
            </p>
            <template v-if="!undoing">
                <button
                    type="button"
                    data-testid="tombstone-undo-open"
                    class="mt-1 rounded border px-3 py-1.5 text-sm"
                    :style="{ borderColor: 'var(--ds-border-strong)' }"
                    @click="undoing = true"
                >
                    {{ t.undo }}
                </button>
            </template>
            <template v-else>
                <label class="mt-2 block text-xs" :style="{ color: 'var(--ds-text-label)' }">
                    {{ t.undo_reason }}
                    <input
                        v-model="reason"
                        data-testid="tombstone-undo-reason"
                        class="mt-1 w-full rounded border px-2 py-1 text-sm"
                        :style="{
                            borderColor: 'var(--ds-border-strong)',
                            background: 'var(--ds-panel-bg)',
                            color: 'var(--ds-text-primary)',
                        }"
                    />
                </label>
                <button
                    type="button"
                    data-testid="tombstone-undo-confirm"
                    class="mt-2 rounded border px-3 py-1.5 text-sm"
                    :style="{ borderColor: 'var(--ds-border-strong)' }"
                    :disabled="reason.trim().length < 5"
                    @click="undo"
                >
                    {{ t.undo }}
                </button>
                <p v-if="error" class="mt-1 text-xs" :style="{ color: 'var(--ds-danger-strong)' }">
                    {{ error }}
                </p>
            </template>
        </section>
    </main>
</template>
