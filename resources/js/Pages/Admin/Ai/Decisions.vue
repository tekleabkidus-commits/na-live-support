<script setup lang="ts">
// Doc 09 SEC-06/FR-20 Decisions (S09 step 12, Option A [B8]): the curated
// decision-log read door (payload pair structurally absent), cursor-paged,
// surface filter (the supervisor feed is surface=supervisor).
import { onMounted, ref } from 'vue';

type Row = Record<string, string | number | null>;
defineProps<{ t: Record<string, string> }>();
const rows = ref<Row[]>([]);
const cursor = ref<string | null>(null);
const surface = ref('');
const failed = ref(false);

async function load(reset = false): Promise<void> {
    if (reset) {
        rows.value = [];
        cursor.value = null;
    }
    const q = new URLSearchParams({ limit: '50' });
    if (cursor.value) q.set('cursor', cursor.value);
    if (surface.value) q.set('surface', surface.value);
    try {
        const r = await fetch(`/api/staff/ai/decisions?${q}`, {
            headers: { Accept: 'application/json' },
        });
        if (!r.ok) throw new Error(String(r.status));
        const json = (await r.json()) as { data: Row[]; next_cursor: string | null };
        rows.value.push(...json.data);
        cursor.value = json.next_cursor;
        failed.value = false;
    } catch {
        failed.value = true;
    }
}
onMounted(() => void load());
</script>

<template>
    <main
        class="mx-auto max-w-5xl space-y-3 p-4 text-start text-xs"
        :style="{ color: 'var(--ds-text-primary)' }"
    >
        <h1 class="text-lg font-semibold">✦ {{ t.decisions_title }}</h1>
        <label
            >{{ t.surface }}
            <select
                v-model="surface"
                data-testid="decisions-surface"
                class="ms-1 rounded border px-2 py-1"
                :style="{
                    borderColor: 'var(--ds-border-strong)',
                    background: 'var(--ds-panel-bg)',
                }"
                @change="load(true)"
            >
                <option value="">{{ t.all }}</option>
                <option value="chatbot">{{ t.surface_chatbot }}</option>
                <option value="copilot">{{ t.surface_copilot }}</option>
                <option value="supervisor">{{ t.surface_supervisor }}</option>
            </select>
        </label>
        <p v-if="failed" role="status">{{ t.load_failed }}</p>
        <div class="overflow-x-auto">
            <table class="w-full" data-testid="decisions-table">
                <thead>
                    <tr>
                        <th
                            v-for="h in [
                                'created_at',
                                'surface',
                                'task',
                                'action',
                                'intent',
                                'confidence',
                                'error_category',
                                'outcome',
                                'tokens_in',
                                'tokens_out',
                                'latency_ms',
                            ]"
                            :key="h"
                            class="text-start"
                        >
                            {{ h }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="r in rows" :key="String(r.id)">
                        <td
                            v-for="h in [
                                'created_at',
                                'surface',
                                'task',
                                'action',
                                'intent',
                                'confidence',
                                'error_category',
                                'outcome',
                                'tokens_in',
                                'tokens_out',
                                'latency_ms',
                            ]"
                            :key="h"
                            class="pe-2 tabular-nums"
                        >
                            {{
                                h === 'created_at' ? String(r[h] ?? '').slice(0, 19) : (r[h] ?? '—')
                            }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <button
            v-if="cursor"
            type="button"
            class="rounded border px-3 py-1"
            :style="{ borderColor: 'var(--ds-border-strong)' }"
            @click="load()"
        >
            {{ t.load_more }}
        </button>
    </main>
</template>
