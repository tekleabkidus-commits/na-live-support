<script setup lang="ts">
// Doc 09 FR-11/FR-18/US-06 Thresholds + Scope (S09 step 12, Option A [B8]):
// sliders per endpoint, SIMULATE yesterday (before/after columns + deltas —
// readable as a text table, §13), publish ONLY with the simulation's token
// (BR-07 physics; the button unlocks after a simulation of these exact
// values). Scope = the chatbot on/off per endpoint, through the same door.
import { computed, ref, watch } from 'vue';
import { adminCall } from './adminApi';

type Sim = {
    day: string;
    before: Record<string, number>;
    after: Record<string, number>;
    deltas: Record<string, number>;
    total: number;
    token: string;
    values: Record<string, number>;
};
const props = defineProps<{
    t: Record<string, string>;
    published: { payload: Record<string, unknown> } | null;
    endpoints: { ref: string; label: string; channel: string; chatbot_enabled: boolean }[];
    history: { id: string; key: string; version: number; status: string; created_at: string }[];
}>();

const endpoint = ref('default');
const p = props.published?.payload ?? {};
const values = ref({
    rg_min: Number(p.rg_min ?? 0.8),
    spam_min: Number(p.spam_min ?? 0.9),
    handoff_below: Number(p.handoff_below ?? 0.3),
    auto_resolve_min: Number(p.auto_resolve_min ?? 0.85),
});
const enabled = ref(props.endpoints.find((e) => e.ref === 'default')?.chatbot_enabled ?? false);
const sim = ref<Sim | null>(null);
const message = ref('');
const keys = ['rg_min', 'spam_min', 'handoff_below', 'auto_resolve_min'] as const;

const simMatchesValues = computed(() => {
    const current = sim.value;
    return (
        current !== null &&
        keys.every((k) => Math.abs((current.values[k] ?? NaN) - values.value[k]) < 1e-9)
    );
});

watch(endpoint, (ref_) => {
    enabled.value = props.endpoints.find((e) => e.ref === ref_)?.chatbot_enabled ?? false;
    sim.value = null;
});
watch(
    values,
    () => {
        if (!simMatchesValues.value) sim.value = null;
    },
    { deep: true },
);

async function simulate(): Promise<void> {
    try {
        sim.value = await adminCall<Sim>('/admin/ai/thresholds/simulate', {
            endpoint: endpoint.value,
            ...values.value,
        });
        message.value = '';
    } catch (e) {
        message.value = (e as Error).message;
    }
}
async function publish(): Promise<void> {
    if (!sim.value) return;
    try {
        await adminCall('/admin/ai/thresholds/publish', {
            endpoint: endpoint.value,
            day: sim.value.day,
            token: sim.value.token,
            chatbot_enabled: enabled.value,
            ...values.value,
        });
        window.location.reload();
    } catch (e) {
        message.value = (e as Error).message;
    }
}
</script>

<template>
    <main
        class="mx-auto max-w-3xl space-y-4 p-4 text-start text-sm"
        :style="{ color: 'var(--ds-text-primary)' }"
    >
        <h1 class="text-lg font-semibold">✦ {{ t.thresholds_title }}</h1>
        <p
            v-if="message"
            role="status"
            data-testid="thresholds-message"
            class="rounded border px-2 py-1 text-xs"
            :style="{ borderColor: 'var(--ds-border-strong)' }"
        >
            {{ message }}
        </p>

        <label class="block text-xs"
            >{{ t.endpoint }}
            <select
                v-model="endpoint"
                data-testid="endpoint-select"
                class="mt-1 rounded border px-2 py-1"
                :style="{
                    borderColor: 'var(--ds-border-strong)',
                    background: 'var(--ds-panel-bg)',
                }"
            >
                <option v-for="e in endpoints" :key="e.ref" :value="e.ref">
                    {{ e.label }} ({{ e.channel }}) — {{ e.chatbot_enabled ? t.on : t.off }}
                </option>
            </select>
        </label>
        <label class="flex items-center gap-2 text-xs"
            ><input v-model="enabled" type="checkbox" data-testid="chatbot-enabled" />
            {{ t.chatbot_enabled }}</label
        >

        <div class="grid grid-cols-2 gap-3 text-xs">
            <label v-for="k in keys" :key="k"
                >{{ t['th_' + k] }}: <span class="tabular-nums">{{ values[k].toFixed(2) }}</span>
                <input
                    v-model.number="values[k]"
                    type="range"
                    min="0"
                    max="1"
                    step="0.01"
                    class="w-full"
                    :data-testid="'slider-' + k"
                />
            </label>
        </div>

        <div class="flex gap-2">
            <button
                type="button"
                data-testid="simulate"
                class="rounded border px-3 py-1 text-xs"
                :style="{ borderColor: 'var(--ds-ai-fg)', color: 'var(--ds-ai-fg)' }"
                @click="simulate"
            >
                {{ t.simulate }}
            </button>
            <button
                type="button"
                data-testid="thresholds-publish"
                class="rounded px-3 py-1 text-xs font-semibold"
                :style="{ background: 'var(--ds-ai-fg)', color: 'var(--ds-panel-bg)' }"
                :disabled="!simMatchesValues"
                :title="simMatchesValues ? '' : t.simulate_first"
                @click="publish"
            >
                {{ t.publish }}
            </button>
        </div>

        <section
            v-if="sim"
            :aria-label="t.simulation"
            data-testid="simulation-results"
            class="rounded border p-2 text-xs"
            :style="{ borderColor: 'var(--ds-ai-fg)', background: 'var(--ds-ai-bg)' }"
        >
            <p>
                {{ t.simulated_day }}: {{ sim.day.slice(0, 10) }} · {{ t.total }}: {{ sim.total }}
            </p>
            <table class="mt-1 w-full">
                <thead>
                    <tr>
                        <th class="text-start">{{ t.outcome }}</th>
                        <th class="text-end">{{ t.before }}</th>
                        <th class="text-end">{{ t.after }}</th>
                        <th class="text-end">Δ</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(n, k) in sim.after" :key="k">
                        <td>{{ k }}</td>
                        <td class="text-end tabular-nums">{{ sim.before[k] ?? 0 }}</td>
                        <td class="text-end tabular-nums">{{ n }}</td>
                        <td class="text-end tabular-nums" :data-testid="'delta-' + k">
                            {{ (sim.deltas[k] ?? 0) > 0 ? '+' : '' }}{{ sim.deltas[k] ?? 0 }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>

        <section :aria-label="t.history" class="text-xs">
            <h2 class="font-semibold">{{ t.history }}</h2>
            <ul class="mt-1 space-y-1">
                <li v-for="h in history" :key="h.id">
                    <span class="tabular-nums">v{{ h.version }}</span> {{ h.key }} ·
                    {{ h.status }} ·
                    <span :style="{ color: 'var(--ds-text-muted)' }">{{
                        h.created_at.slice(0, 19)
                    }}</span>
                </li>
            </ul>
        </section>
    </main>
</template>
