<script setup lang="ts">
// B6-S01 — Telegram bot registry & connection method (FR-01…05).
// Frames: A01–A08 (registry, empty, loading, delete-blocked/cascade, wizard
// step 1 states) + B01–B06 (mode selector, redirect validation, typed
// confirm). Tokens only; both colourways via [data-theme]; both viewports via
// responsive classes; strings server-localized.
import { computed, ref } from 'vue';
import HealthRollupPill from '../../../Components/Telegram/HealthRollupPill.vue';

type Bot = {
    id: string;
    username: string;
    display_name: string;
    mode: 'direct' | 'business' | 'hybrid';
    status: 'active' | 'archived';
    health: 'healthy' | 'degraded' | 'down';
    connections: number;
};

const props = defineProps<{
    bots: Bot[];
    loading?: boolean;
    t: Record<string, string>; // server-localized strings (en/am)
    mode: 'direct' | 'business' | 'hybrid';
}>();

const wizardToken = ref('');
const wizardState = ref<'empty' | 'validating' | 'rejected' | 'validated'>('empty');
const selectedMode = ref(props.mode);

const isEmpty = computed(() => props.bots.length === 0);
</script>

<template>
    <div
        class="min-h-screen"
        :style="{ background: 'var(--ds-page-bg)', color: 'var(--ds-text-primary)' }"
    >
        <div class="mx-auto max-w-[1100px] px-4 py-6 sm:px-6">
            <h1 class="text-lg font-semibold" :style="{ color: 'var(--ds-text-primary)' }">
                {{ t.title }}
            </h1>
            <p class="mt-1 text-sm" :style="{ color: 'var(--ds-text-muted)' }">{{ t.subtitle }}</p>

            <!-- A07 — empty state -->
            <div
                v-if="isEmpty && !loading"
                data-frame="A07"
                class="mt-6 rounded-xl border border-dashed p-8 text-center"
                :style="{
                    borderColor: 'var(--ds-border-strong)',
                    background: 'var(--ds-panel-soft)',
                }"
            >
                <p class="text-sm font-medium" :style="{ color: 'var(--ds-text-primary)' }">
                    {{ t.empty_title }}
                </p>
                <p class="mx-auto mt-1 max-w-md text-xs" :style="{ color: 'var(--ds-text-muted)' }">
                    {{ t.empty_body }}
                </p>
            </div>

            <!-- A08 — loading -->
            <div v-else-if="loading" data-frame="A08" class="mt-6 space-y-2" aria-busy="true">
                <div
                    v-for="i in 3"
                    :key="i"
                    class="h-12 animate-pulse rounded-lg"
                    :style="{ background: 'var(--ds-panel-soft)' }"
                />
            </div>

            <!-- A01/A05 — bot registry table -->
            <div
                v-else
                data-frame="A01"
                class="mt-6 overflow-hidden rounded-xl border"
                :style="{ borderColor: 'var(--ds-border)', background: 'var(--ds-panel-bg)' }"
            >
                <table class="w-full text-start text-sm">
                    <thead>
                        <tr
                            :style="{
                                background: 'var(--ds-panel-soft)',
                                color: 'var(--ds-text-label)',
                            }"
                        >
                            <th class="px-4 py-2 text-[11px] font-semibold uppercase tracking-wide">
                                {{ t.col_bot }}
                            </th>
                            <th class="px-4 py-2 text-[11px] font-semibold uppercase tracking-wide">
                                {{ t.col_mode }}
                            </th>
                            <th class="px-4 py-2 text-[11px] font-semibold uppercase tracking-wide">
                                {{ t.col_health }}
                            </th>
                            <th
                                class="hidden px-4 py-2 text-[11px] font-semibold uppercase tracking-wide sm:table-cell"
                            >
                                {{ t.col_connections }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="bot in bots"
                            :key="bot.id"
                            class="border-t"
                            :style="{ borderColor: 'var(--ds-border-soft)' }"
                            :data-bot="bot.username"
                        >
                            <td class="px-4 py-3">
                                <p class="font-medium" :style="{ color: 'var(--ds-text-primary)' }">
                                    {{ bot.display_name }}
                                </p>
                                <p class="text-xs" :style="{ color: 'var(--ds-text-muted)' }">
                                    @{{ bot.username }}
                                </p>
                            </td>
                            <td class="px-4 py-3">
                                <span
                                    class="rounded-full px-2 py-0.5 text-[11px] font-medium"
                                    :style="{
                                        background: 'var(--ds-inset-bg)',
                                        color: 'var(--ds-text-secondary)',
                                    }"
                                    >{{ t['mode_' + bot.mode] ?? '' }}</span
                                >
                            </td>
                            <td class="px-4 py-3">
                                <HealthRollupPill
                                    :status="bot.health"
                                    :label="t['health_' + bot.health] ?? ''"
                                />
                            </td>
                            <td
                                class="hidden px-4 py-3 text-sm sm:table-cell"
                                :style="{ color: 'var(--ds-text-secondary)' }"
                            >
                                {{ bot.connections }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- §C — add-bot wizard step 1 (A02/A03/A04) -->
            <section
                data-frame="A02"
                class="mt-8 rounded-xl border p-5"
                :style="{ borderColor: 'var(--ds-border)', background: 'var(--ds-panel-bg)' }"
            >
                <h2 class="text-sm font-semibold" :style="{ color: 'var(--ds-text-primary)' }">
                    {{ t.wizard_title }}
                </h2>
                <label
                    class="mt-3 block text-xs font-medium"
                    :style="{ color: 'var(--ds-text-label)' }"
                    for="tg-token"
                    >{{ t.token_label }}</label
                >
                <input
                    id="tg-token"
                    v-model="wizardToken"
                    type="password"
                    class="mt-1 w-full rounded-lg border px-3 py-2 text-sm"
                    :style="{
                        borderColor: 'var(--ds-border-strong)',
                        background: 'var(--ds-panel-soft)',
                        color: 'var(--ds-text-primary)',
                    }"
                    :placeholder="t.token_placeholder"
                />
                <!-- A04 — token rejected -->
                <p
                    v-if="wizardState === 'rejected'"
                    data-frame="A04"
                    class="mt-2 text-xs"
                    role="alert"
                    :style="{ color: 'var(--ds-danger-text)' }"
                >
                    {{ t.token_rejected }}
                </p>
                <p
                    v-else-if="wizardState === 'validated'"
                    data-frame="A02-validated"
                    class="mt-2 text-xs"
                    :style="{ color: 'var(--ds-success-text)' }"
                >
                    {{ t.token_validated }}
                </p>
            </section>

            <!-- §D — mode selector (B01) segmented control -->
            <section
                data-frame="B01"
                class="mt-8 rounded-xl border p-5"
                :style="{ borderColor: 'var(--ds-border)', background: 'var(--ds-panel-bg)' }"
            >
                <h2 class="text-sm font-semibold" :style="{ color: 'var(--ds-text-primary)' }">
                    {{ t.mode_title }}
                </h2>
                <div
                    class="mt-3 inline-flex rounded-lg border p-0.5"
                    role="radiogroup"
                    :aria-label="t.mode_title"
                    :style="{
                        borderColor: 'var(--ds-border-strong)',
                        background: 'var(--ds-panel-soft)',
                    }"
                >
                    <button
                        v-for="m in ['direct', 'business', 'hybrid']"
                        :key="m"
                        type="button"
                        role="radio"
                        :aria-checked="selectedMode === m"
                        :data-mode-option="m"
                        class="rounded-md px-3 py-1.5 text-xs font-medium"
                        :style="
                            selectedMode === m
                                ? {
                                      background: 'var(--ds-accent)',
                                      color: 'var(--ds-accent-contrast)',
                                  }
                                : { color: 'var(--ds-text-secondary)' }
                        "
                        @click="selectedMode = m as typeof selectedMode"
                    >
                        {{ t['mode_' + m] ?? '' }}
                        <!-- Class-1 (technical/a11y): the green "recommended"
                             hue fails contrast on the SELECTED button's dark
                             accent (N-18 has no override), so on the selected
                             option it takes the accent-contrast colour; the
                             green shows on the unselected option as drawn. -->
                        <span
                            v-if="m === 'business'"
                            class="ms-1 text-[10px]"
                            :style="{
                                color:
                                    selectedMode === m
                                        ? 'var(--ds-accent-contrast)'
                                        : 'var(--ds-success-text)',
                            }"
                            >{{ t.mode_recommended }}</span
                        >
                    </button>
                </div>
                <p class="mt-2 text-xs" :style="{ color: 'var(--ds-text-muted)' }">
                    {{ t['mode_help_' + selectedMode] }}
                </p>
            </section>
        </div>
    </div>
</template>
