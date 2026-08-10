<script setup lang="ts">
// B6-S03 — Reply-window administration (FR-21; rows D01–D04). At 1440 the
// window_hours + closing settings are EDITABLE (C2: drawn operator editing ⇒
// tenant_editable true); at 375 the surface is READ-ONLY behind the viewport
// notice (row J19, Doc 16 §9). D04 is the calibration record + the staging-
// only simulator. Tokens only; both colourways; the WindowChip is the SHARED
// component Doc 07 consumes.
import { ref } from 'vue';
import WindowChip from '../../../Components/Telegram/WindowChip.vue';

const props = defineProps<{
    windowHours: number;
    closingHours: number;
    closingNotify: boolean;
    calibration: { observed_hours: number | null; connection: string } | null;
    readonly: boolean; // true at 375 (row J19)
    staging: boolean; // D04 simulator is staging-only
    sampleExpiry: string | null; // for the shared chip preview
    t: Record<string, string>;
}>();

const hours = ref(props.windowHours);
</script>

<template>
    <div
        class="min-h-screen"
        :style="{ background: 'var(--ds-page-bg)', color: 'var(--ds-text-primary)' }"
    >
        <div class="mx-auto max-w-[760px] px-4 py-6 sm:px-6">
            <h1 class="text-lg font-semibold">{{ t.title }}</h1>

            <!-- Row J19 viewport notice — read-only on small screens -->
            <p
                v-if="readonly"
                data-frame="D-readonly"
                class="mt-3 rounded-lg border px-3 py-2 text-xs"
                :style="{
                    borderColor: 'var(--ds-border-strong)',
                    background: 'var(--ds-inset-bg)',
                    color: 'var(--ds-text-muted)',
                }"
            >
                {{ t.readonly_notice }}
            </p>

            <!-- D01/D02 — window_hours + closing settings (per-key row contract) -->
            <section
                data-frame="D01"
                class="mt-6 rounded-xl border p-5"
                :style="{ borderColor: 'var(--ds-border)', background: 'var(--ds-panel-bg)' }"
            >
                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0">
                        <label
                            class="block text-sm font-medium"
                            :style="{ color: 'var(--ds-text-primary)' }"
                            for="win-hours"
                            >{{ t.window_hours_label }}</label
                        >
                        <p class="mt-0.5 text-xs" :style="{ color: 'var(--ds-text-muted)' }">
                            {{ t.window_hours_help }}
                        </p>
                    </div>
                    <input
                        id="win-hours"
                        v-model.number="hours"
                        type="number"
                        min="1"
                        max="24"
                        :disabled="readonly"
                        :aria-readonly="readonly"
                        data-setting="window_hours"
                        class="w-20 rounded-lg border px-3 py-2 text-end text-sm"
                        :style="{
                            borderColor: 'var(--ds-border-strong)',
                            background: readonly ? 'var(--ds-inset-bg)' : 'var(--ds-panel-soft)',
                            color: 'var(--ds-text-primary)',
                        }"
                    />
                </div>
                <p class="mt-2 text-[11px]" :style="{ color: 'var(--ds-text-faint)' }">
                    {{ t.window_hours_bounds }}
                </p>
            </section>

            <!-- The SHARED window chip preview (Doc 07 consumes the same one) -->
            <section data-frame="D03" class="mt-6 flex items-center gap-3">
                <span class="text-xs" :style="{ color: 'var(--ds-text-muted)' }">{{
                    t.preview_label
                }}</span>
                <WindowChip
                    :expires-at="sampleExpiry"
                    :state="sampleExpiry ? 'closing' : 'none'"
                    :labels="{
                        closesIn: t.chip_closes_in ?? '',
                        expired: t.chip_expired ?? '',
                        noWindow: t.chip_no_window ?? '',
                    }"
                />
            </section>

            <!-- D04 — calibration record + staging-only simulator -->
            <section
                data-frame="D04"
                class="mt-6 rounded-xl border p-5"
                :style="{ borderColor: 'var(--ds-border)', background: 'var(--ds-panel-soft)' }"
            >
                <h2 class="text-sm font-semibold" :style="{ color: 'var(--ds-text-primary)' }">
                    {{ t.calibration_title }}
                </h2>
                <p
                    v-if="calibration"
                    class="mt-2 text-sm"
                    :style="{ color: 'var(--ds-text-secondary)' }"
                >
                    {{ t.calibration_observed }} <strong>{{ calibration.observed_hours }}h</strong>
                </p>
                <p v-else class="mt-2 text-sm" :style="{ color: 'var(--ds-text-muted)' }">
                    {{ t.calibration_none }}
                </p>

                <!-- simulate-window-expiry is STAGING ONLY (FR-30) -->
                <button
                    v-if="staging && !readonly"
                    type="button"
                    data-action="simulate-expiry"
                    class="mt-3 min-h-[44px] rounded-lg border px-4 text-sm font-medium"
                    :style="{
                        borderColor: 'var(--ds-border-strong)',
                        color: 'var(--ds-text-secondary)',
                    }"
                >
                    {{ t.simulate_expiry }}
                </button>
            </section>
        </div>
    </div>
</template>
