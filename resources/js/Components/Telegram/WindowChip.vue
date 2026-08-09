<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue';

// FR-22/§9: the reply-window countdown chip. THE SHARED COMPONENT — Doc 07's
// inbox list rows consume this SAME component (plan §7 obligation 4), so it
// lives in a neutral namespace and takes only data. Format per §9:
//   mm:ss under 10 min, h:mm above. Fixed tokens (open/soon/expired) from the
// design system, both colourways; icon+text, never colour alone (§13).
// Reduced-motion (§9): the chip NEVER pulses — a static colour shift only.
// SR announcements are PERIODIC (§13), not per-second: a polite live region
// updated each minute, never each tick.
const props = defineProps<{
    expiresAt: string | null;
    state: 'open' | 'closing' | 'expired' | 'none';
    labels: { closesIn: string; expired: string; noWindow: string };
}>();

const now = ref(Date.now());
let tick: ReturnType<typeof setInterval> | undefined;
onMounted(() => {
    tick = setInterval(() => (now.value = Date.now()), 1000);
});
onUnmounted(() => {
    if (tick) clearInterval(tick);
});

const remainingMs = computed(() =>
    props.expiresAt ? new Date(props.expiresAt).getTime() - now.value : 0,
);

const timeLabel = computed(() => {
    if (props.state === 'none' || props.expiresAt === null) return props.labels.noWindow;
    if (props.state === 'expired' || remainingMs.value <= 0) return props.labels.expired;
    const totalSec = Math.floor(remainingMs.value / 1000);
    if (totalSec < 600) {
        const m = Math.floor(totalSec / 60);
        const s = totalSec % 60;
        return `${props.labels.closesIn} ${m}:${String(s).padStart(2, '0')}`; // mm:ss
    }
    const h = Math.floor(totalSec / 3600);
    const m = Math.floor((totalSec % 3600) / 60);
    return `${props.labels.closesIn} ${h}:${String(m).padStart(2, '0')}`; // h:mm
});

// PERIODIC SR text — recomputed only to the minute, so the live region does
// not chatter every second (§13).
const srLabel = computed(() => {
    if (props.state === 'expired' || remainingMs.value <= 0) return props.labels.expired;
    const minutes = Math.ceil(remainingMs.value / 60000);
    return `${props.labels.closesIn} ${minutes}`;
});

const tokens = computed(() => {
    if (props.state === 'expired')
        return { bg: 'var(--ds-window-expired-bg)', fg: 'var(--ds-window-expired-fg)', icon: '⊘' };
    if (props.state === 'closing')
        return { bg: 'var(--ds-window-soon-bg)', fg: 'var(--ds-window-soon-fg)', icon: '⏳' };
    return { bg: 'var(--ds-window-open-bg)', fg: 'var(--ds-window-open-fg)', icon: '⏱' };
});
</script>

<template>
    <span
        class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium tabular-nums"
        :style="{ background: tokens.bg, color: tokens.fg }"
        :data-window-state="state"
    >
        <span aria-hidden="true">{{ tokens.icon }}</span>
        <span aria-hidden="true">{{ timeLabel }}</span>
        <!-- periodic, minute-resolution live region (§13) -->
        <span class="sr-only" role="status" aria-live="polite">{{ srLabel }}</span>
    </span>
</template>
