<script setup lang="ts">
// FR-28 — the agent-status control (S07 step 12). Online / Away / Offline
// through the server door (views.manage — the R-26 sixth sibling); the
// heartbeat posts every 30 s while online (loss > 90 s materializes
// system-away SERVER-side — AgentStatus's physics, never re-derived here);
// idle for `awayIdleMinutes` raises BR-06's 60 s CANCELABLE countdown toast
// before Away is set with reason auto_idle. A forced status renders WITH
// attribution ("set by Dawit") — surveillance theater is not the point;
// routing truth is.
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps<{
    t: Record<string, string>;
    initial: { status: string; reason: string | null; set_by_name: string | null };
    awayIdleMinutes: number;
}>();

const status = ref(props.initial.status);
const setByName = ref<string | null>(props.initial.set_by_name);
const menu = ref(false);
const countdown = ref<number | null>(null); // seconds left, null = no toast

let heartbeatTimer: ReturnType<typeof setInterval> | null = null;

// FS-01 (step 14): the heartbeat's answer carries the projector staleness
// signal up to the workspace — the banner's live feed, on the cadence the
// control already owns.
const emit = defineEmits<{
    (e: 'staleness', payload: { stale: number | null; pending: boolean }): void;
}>();
let idleTimer: ReturnType<typeof setTimeout> | null = null;
let countdownTimer: ReturnType<typeof setInterval> | null = null;

function xsrf(): string {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    return match?.[1] ? decodeURIComponent(match[1]) : '';
}

async function post(path: string, body?: Record<string, unknown>): Promise<Response> {
    return fetch(path, {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-XSRF-TOKEN': xsrf(),
        },
        body: body ? JSON.stringify(body) : undefined,
    });
}

async function setStatus(next: 'online' | 'away' | 'offline', reason = 'manual'): Promise<void> {
    menu.value = false;
    const response = await post('/inbox/status', { status: next, reason });
    if (!response.ok) return;
    const body = (await response.json()) as { status: string; set_by_name: string | null };
    status.value = body.status;
    setByName.value = body.set_by_name;
}

function resetIdle(): void {
    if (countdown.value !== null) return; // the toast owns the decision now
    if (idleTimer) clearTimeout(idleTimer);
    idleTimer = setTimeout(startCountdown, props.awayIdleMinutes * 60_000);
}

// BR-06: 60 s, cancelable — cancel returns to the idle clock, expiry sets
// Away with reason auto_idle.
function startCountdown(): void {
    if (status.value !== 'online') return;
    countdown.value = 60;
    countdownTimer = setInterval(() => {
        if (countdown.value === null) return;
        countdown.value -= 1;
        if (countdown.value <= 0) {
            stopCountdown();
            void setStatus('away', 'auto_idle');
        }
    }, 1000);
}

function stopCountdown(): void {
    if (countdownTimer) clearInterval(countdownTimer);
    countdownTimer = null;
    countdown.value = null;
}

function cancelCountdown(): void {
    stopCountdown();
    resetIdle();
}

onMounted(() => {
    heartbeatTimer = setInterval(() => {
        if (status.value === 'online') {
            void post('/inbox/status/heartbeat').then(async (response) => {
                if (!response.ok) return;
                const body = (await response.json().catch(() => null)) as {
                    projector_stale_seconds?: number | null;
                    projector_pending?: boolean;
                } | null;
                if (body !== null) {
                    emit('staleness', {
                        stale: body.projector_stale_seconds ?? null,
                        pending: body.projector_pending ?? false,
                    });
                }
            });
        }
    }, 30_000);
    window.addEventListener('mousemove', resetIdle, { passive: true });
    window.addEventListener('keydown', resetIdle, { passive: true });
    resetIdle();

    // The scripted hook (the step-11/12 discipline): the browser proof
    // triggers the countdown deterministically instead of idling minutes.
    (window as unknown as Record<string, unknown>).__inboxStatus = {
        startCountdown,
        current: () => ({
            status: status.value,
            setByName: setByName.value,
            countdown: countdown.value,
        }),
        refresh: async () => {
            const response = await fetch('/inbox/status', {
                headers: { Accept: 'application/json' },
            });
            const body = (await response.json()) as { status: string; set_by_name: string | null };
            status.value = body.status;
            setByName.value = body.set_by_name;
        },
    };
});
onBeforeUnmount(() => {
    if (heartbeatTimer) clearInterval(heartbeatTimer);
    if (idleTimer) clearTimeout(idleTimer);
    stopCountdown();
    window.removeEventListener('mousemove', resetIdle);
    window.removeEventListener('keydown', resetIdle);
});

const label = computed(() => props.t['status_' + status.value] ?? status.value);
</script>

<template>
    <div class="relative">
        <button
            type="button"
            data-testid="agent-status"
            class="flex items-center gap-1.5 rounded-md px-2 py-1.5 text-xs"
            :aria-label="t.status_label + ': ' + label"
            :aria-expanded="menu"
            :style="{ color: 'var(--ds-text-muted)' }"
            @click="menu = !menu"
        >
            <span
                aria-hidden="true"
                class="inline-block h-2 w-2 rounded-full"
                :style="{
                    background:
                        status === 'online'
                            ? 'var(--ds-success-text)'
                            : status === 'away'
                              ? 'var(--ds-warn-text)'
                              : 'var(--ds-text-muted)',
                }"
            ></span>
            <span class="status-word">{{ label }}</span>
            <span
                v-if="setByName"
                data-testid="status-forced-attribution"
                class="text-[10.5px]"
                :style="{ color: 'var(--ds-text-muted)' }"
                >{{ (t.set_by ?? '').replace(':name', setByName) }}</span
            >
        </button>

        <div
            v-if="menu"
            data-testid="status-menu"
            role="menu"
            class="absolute end-0 top-full z-40 mt-1 w-40 rounded-md border py-1 shadow-lg"
            :style="{ background: 'var(--ds-panel-bg)', borderColor: 'var(--ds-border)' }"
        >
            <button
                v-for="option in ['online', 'away', 'offline']"
                :key="option"
                type="button"
                role="menuitem"
                :data-status-option="option"
                class="block w-full px-3 py-1.5 text-start text-[12px]"
                :style="{
                    color: 'var(--ds-text-primary)',
                    fontWeight: status === option ? 600 : 400,
                }"
                @click="setStatus(option as 'online' | 'away' | 'offline')"
            >
                {{ t['status_' + option] }}
            </button>
        </div>

        <!-- BR-06's countdown toast: polite announcement, one-tap cancel. -->
        <div
            v-if="countdown !== null"
            data-testid="away-countdown-toast"
            role="status"
            class="fixed bottom-4 end-4 z-50 flex items-center gap-3 rounded-lg border px-4 py-3 shadow-xl"
            :style="{ background: 'var(--ds-panel-bg)', borderColor: 'var(--ds-border)' }"
        >
            <span class="text-[12.5px]" :style="{ color: 'var(--ds-text-primary)' }">{{
                (t.away_countdown ?? '').replace(':seconds', String(countdown))
            }}</span>
            <button
                type="button"
                data-testid="away-countdown-cancel"
                class="rounded-md border px-2.5 py-1 text-[12px] font-semibold"
                :style="{ borderColor: 'var(--ds-border)', color: 'var(--ds-text-primary)' }"
                @click="cancelCountdown"
            >
                {{ t.away_cancel }}
            </button>
        </div>
    </div>
</template>

<style scoped>
/* P-F1-TOPBAR (countersigned shape): under 640 px the status WORD hides; the dot and the
   aria-label stay, so the top bar fits 375 in both languages (B8-D37). */
@media (max-width: 639px) {
    .status-word {
        position: absolute;
        inline-size: 1px;
        block-size: 1px;
        overflow: hidden;
        clip: rect(0 0 0 0);
        white-space: nowrap;
    }
}
</style>
