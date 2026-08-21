<script setup lang="ts">
// Doc 09 FR-18/US-07/AC-08 Kill switches (S09 step 12, Option A [B8]): the
// surface × channel × endpoint grid; OVERSIZED toggles, red-when-off with
// "OFF since {time} by {actor}", confirmation before a flip (FR-18 "big red
// UI with confirmation"), role="switch" semantics (§13). The flip is the
// audited domain door; the grid re-renders from its answer.
import { ref } from 'vue';
import { adminCall } from './adminApi';

type Row = {
    id: string;
    surface: string;
    channel: string | null;
    endpoint_ref: string | null;
    is_off: boolean;
    reason: string | null;
    flipped_at: string | null;
    flipped_by_name: string | null;
};
const props = defineProps<{
    t: Record<string, string>;
    grid: Row[];
    surfaces: string[];
    endpoints: { ref: string; label: string; channel: string }[];
}>();

const grid = ref<Row[]>(props.grid);
const confirming = ref<{
    surface: string;
    channel: string | null;
    endpoint_ref: string | null;
    off: boolean;
} | null>(null);
const reason = ref('');
const message = ref('');

function cells(): {
    surface: string;
    channel: string | null;
    endpoint_ref: string | null;
    label: string;
}[] {
    const out: {
        surface: string;
        channel: string | null;
        endpoint_ref: string | null;
        label: string;
    }[] = [];
    for (const surface of props.surfaces) {
        out.push({ surface, channel: null, endpoint_ref: null, label: props.t.scope_all ?? '' });
        out.push({
            surface,
            channel: 'telegram',
            endpoint_ref: null,
            label: (props.t.scope_channel ?? '') + ' telegram',
        });
        for (const e of props.endpoints.filter((x) => x.ref !== 'default'))
            out.push({ surface, channel: e.channel, endpoint_ref: e.ref, label: e.label });
    }
    return out;
}
function state(c: {
    surface: string;
    channel: string | null;
    endpoint_ref: string | null;
}): Row | undefined {
    return grid.value.find(
        (r) =>
            r.surface === c.surface && r.channel === c.channel && r.endpoint_ref === c.endpoint_ref,
    );
}
function fill(tpl: string | undefined, vars: Record<string, string>): string {
    let out = tpl ?? '';
    for (const [k, v] of Object.entries(vars)) out = out.replaceAll(':' + k, v);
    return out;
}
async function confirmFlip(): Promise<void> {
    if (!confirming.value) return;
    try {
        const r = await adminCall<{ grid: Row[] }>('/admin/ai/kill-switches/flip', {
            ...confirming.value,
            reason: reason.value || null,
            confirm: true,
        });
        grid.value = r.grid;
        message.value = '';
    } catch (e) {
        message.value = (e as Error).message;
    } finally {
        confirming.value = null;
        reason.value = '';
    }
}
</script>

<template>
    <main
        class="mx-auto max-w-4xl space-y-4 p-4 text-start text-sm"
        :style="{ color: 'var(--ds-text-primary)' }"
    >
        <h1 class="text-lg font-semibold">✦ {{ t.killswitches_title }}</h1>
        <p class="text-xs" :style="{ color: 'var(--ds-text-muted)' }">{{ t.killswitches_help }}</p>
        <p
            v-if="message"
            role="status"
            class="rounded border px-2 py-1 text-xs"
            :style="{ borderColor: 'var(--ds-border-strong)' }"
        >
            {{ message }}
        </p>

        <div class="grid gap-3 sm:grid-cols-2" data-testid="killswitch-grid">
            <div
                v-for="c in cells()"
                :key="c.surface + '|' + c.channel + '|' + c.endpoint_ref"
                class="rounded border p-3"
                :style="{
                    borderColor: state(c)?.is_off
                        ? 'var(--ds-status-danger-fg)'
                        : 'var(--ds-border-strong)',
                    background: state(c)?.is_off
                        ? 'var(--ds-status-danger-bg)'
                        : 'var(--ds-panel-bg)',
                }"
            >
                <p class="text-xs font-semibold">{{ t['surface_' + c.surface] }} · {{ c.label }}</p>
                <button
                    type="button"
                    role="switch"
                    :aria-checked="!(state(c)?.is_off ?? false)"
                    :data-testid="
                        'switch-' +
                        c.surface +
                        '-' +
                        (c.channel ?? 'all') +
                        '-' +
                        (c.endpoint_ref ?? 'all')
                    "
                    class="mt-2 h-12 w-full rounded text-base font-bold"
                    :style="
                        state(c)?.is_off
                            ? {
                                  background: 'var(--ds-status-danger-fg)',
                                  color: 'var(--ds-panel-bg)',
                              }
                            : {
                                  background: 'var(--ds-status-resolved-bg)',
                                  color: 'var(--ds-status-resolved-fg)',
                                  border: '1px solid var(--ds-status-resolved-fg)',
                              }
                    "
                    @click="
                        confirming = {
                            surface: c.surface,
                            channel: c.channel,
                            endpoint_ref: c.endpoint_ref,
                            off: !(state(c)?.is_off ?? false),
                        }
                    "
                >
                    {{ state(c)?.is_off ? t.off_label : t.on_label }}
                </button>
                <p
                    v-if="state(c)?.is_off"
                    class="mt-1 text-[10.5px]"
                    :data-testid="'since-' + c.surface"
                    :style="{ color: 'var(--ds-status-danger-fg)' }"
                >
                    {{
                        fill(t.off_since, {
                            time: (state(c)?.flipped_at ?? '').slice(0, 19),
                            actor: state(c)?.flipped_by_name ?? '—',
                        })
                    }}
                </p>
            </div>
        </div>

        <div
            v-if="confirming"
            role="dialog"
            aria-modal="true"
            :aria-label="t.confirm_title"
            data-testid="killswitch-confirm"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-6"
            @click.self="confirming = null"
        >
            <div
                class="w-full max-w-sm rounded border p-4"
                :style="{
                    background: 'var(--ds-panel-bg)',
                    borderColor: 'var(--ds-border-strong)',
                }"
            >
                <h2 class="text-sm font-semibold">
                    {{ confirming.off ? t.confirm_off : t.confirm_on }}
                </h2>
                <p class="mt-1 text-xs" :style="{ color: 'var(--ds-text-muted)' }">
                    {{ t['surface_' + confirming.surface] }} ·
                    {{ confirming.endpoint_ref ?? confirming.channel ?? t.scope_all }}
                </p>
                <label class="mt-2 block text-xs"
                    >{{ t.reason
                    }}<input
                        v-model="reason"
                        data-testid="killswitch-reason"
                        class="mt-1 w-full rounded border px-2 py-1"
                        :style="{
                            borderColor: 'var(--ds-border-strong)',
                            background: 'var(--ds-panel-bg)',
                        }"
                /></label>
                <div class="mt-3 flex justify-end gap-2">
                    <button
                        type="button"
                        class="rounded border px-3 py-1 text-xs"
                        @click="confirming = null"
                    >
                        {{ t.cancel }}
                    </button>
                    <button
                        type="button"
                        data-testid="killswitch-confirm-yes"
                        class="rounded px-3 py-1 text-xs font-semibold"
                        :style="
                            confirming.off
                                ? {
                                      background: 'var(--ds-status-danger-fg)',
                                      color: 'var(--ds-panel-bg)',
                                  }
                                : {
                                      background: 'var(--ds-accent)',
                                      color: 'var(--ds-accent-contrast)',
                                  }
                        "
                        @click="confirmFlip"
                    >
                        {{ t.confirm }}
                    </button>
                </div>
            </div>
        </div>
    </main>
</template>
