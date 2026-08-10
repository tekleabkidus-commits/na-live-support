<script setup lang="ts">
// B6-S02 — Business connection lifecycle (FR-07…10). Frames C01–C15: connect
// checklist (C01), confirm gate (C02) and its failures (C03/C04/C05/C09),
// registry (C08/C15), loss states (C10–C14). Tokens only; both colourways;
// both viewports; strings server-localized. 375 checklist is phone-operable
// (≥44px controls) — the connect flow happens next to the support phone.
import { ref } from 'vue';
import ConnectionStatePill from '../../../Components/Telegram/ConnectionStatePill.vue';
import StepChecklist from '../../../Components/Telegram/StepChecklist.vue';

type Connection = {
    id: string;
    account_name: string;
    account_username: string | null;
    state: 'pending' | 'active' | 'paused' | 'revoked';
    can_reply: boolean;
    last_inbound: string | null;
    sends_blocked: number;
};

defineProps<{
    connections: Connection[];
    pending: Connection | null; // C02 confirm gate
    t: Record<string, string>;
    checklist: Array<{ label: string; status: 'done' | 'active' | 'pending'; hint?: string }>;
}>();

const confirmName = ref('');
</script>

<template>
    <div
        class="min-h-screen"
        :style="{ background: 'var(--ds-page-bg)', color: 'var(--ds-text-primary)' }"
    >
        <div class="mx-auto max-w-[1100px] px-4 py-6 sm:px-6">
            <h1 class="text-lg font-semibold">{{ t.title }}</h1>

            <!-- C01 — connect checklist (phone-operable at 375) -->
            <section data-frame="C01" class="mt-6">
                <h2 class="text-sm font-semibold" :style="{ color: 'var(--ds-text-primary)' }">
                    {{ t.checklist_title }}
                </h2>
                <div class="mt-3">
                    <StepChecklist
                        :steps="checklist"
                        :status-words="{
                            done: t.step_done ?? '',
                            active: t.step_active ?? '',
                            pending: t.step_pending ?? '',
                        }"
                    />
                </div>
            </section>

            <!-- C02 — the confirm gate (SEC-04): typed-name confirmation -->
            <section
                v-if="pending"
                data-frame="C02"
                class="mt-8 rounded-xl border p-5"
                :style="{ borderColor: 'var(--ds-warn-border)', background: 'var(--ds-warn-bg)' }"
            >
                <div class="flex items-center gap-2">
                    <ConnectionStatePill state="pending" :label="t.state_pending ?? ''" />
                    <h2 class="text-sm font-semibold" :style="{ color: 'var(--ds-warn-text)' }">
                        {{ t.confirm_title }}
                    </h2>
                </div>
                <p class="mt-2 text-sm" :style="{ color: 'var(--ds-text-primary)' }">
                    {{ t.confirm_account }} <strong>{{ pending.account_name }}</strong>
                </p>
                <label
                    class="mt-3 block text-xs font-medium"
                    :style="{ color: 'var(--ds-text-label)' }"
                    for="confirm-name"
                    >{{ t.confirm_type_label }}</label
                >
                <input
                    id="confirm-name"
                    v-model="confirmName"
                    class="mt-1 w-full rounded-lg border px-3 py-2 text-sm"
                    :style="{
                        borderColor: 'var(--ds-border-strong)',
                        background: 'var(--ds-panel-bg)',
                        color: 'var(--ds-text-primary)',
                    }"
                />
                <div class="mt-3 flex gap-2">
                    <button
                        type="button"
                        data-action="confirm"
                        class="min-h-[44px] rounded-lg px-4 text-sm font-semibold"
                        :disabled="confirmName !== pending.account_name"
                        :style="
                            confirmName === pending.account_name
                                ? {
                                      background: 'var(--ds-accent)',
                                      color: 'var(--ds-accent-contrast)',
                                  }
                                : {
                                      background: 'var(--ds-inset-bg)',
                                      color: 'var(--ds-text-muted)',
                                  }
                        "
                    >
                        {{ t.confirm_yes }}
                    </button>
                    <button
                        type="button"
                        data-action="reject"
                        class="min-h-[44px] rounded-lg border px-4 text-sm font-medium"
                        :style="{
                            borderColor: 'var(--ds-danger-border)',
                            color: 'var(--ds-danger-text)',
                        }"
                    >
                        {{ t.confirm_reject }}
                    </button>
                </div>
            </section>

            <!-- C08/C15 — connections registry -->
            <section
                data-frame="C08"
                class="mt-8 overflow-hidden rounded-xl border"
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
                                {{ t.col_account }}
                            </th>
                            <th class="px-4 py-2 text-[11px] font-semibold uppercase tracking-wide">
                                {{ t.col_state }}
                            </th>
                            <th
                                class="hidden px-4 py-2 text-[11px] font-semibold uppercase tracking-wide sm:table-cell"
                            >
                                {{ t.col_last_inbound }}
                            </th>
                            <th
                                class="hidden px-4 py-2 text-[11px] font-semibold uppercase tracking-wide sm:table-cell"
                            >
                                {{ t.col_sends_blocked }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="c in connections"
                            :key="c.id"
                            class="border-t"
                            :style="{ borderColor: 'var(--ds-border-soft)' }"
                            :data-connection="c.id"
                        >
                            <td class="px-4 py-3">
                                <p class="font-medium" :style="{ color: 'var(--ds-text-primary)' }">
                                    {{ c.account_name }}
                                </p>
                                <p
                                    v-if="c.account_username"
                                    class="text-xs"
                                    :style="{ color: 'var(--ds-text-muted)' }"
                                >
                                    @{{ c.account_username }}
                                </p>
                            </td>
                            <td class="px-4 py-3">
                                <ConnectionStatePill
                                    :state="c.state"
                                    :label="t['state_' + c.state] ?? ''"
                                />
                            </td>
                            <td
                                class="hidden px-4 py-3 text-xs sm:table-cell"
                                :style="{ color: 'var(--ds-text-secondary)' }"
                            >
                                {{ c.last_inbound ?? '—' }}
                            </td>
                            <td
                                class="hidden px-4 py-3 text-xs sm:table-cell"
                                :style="{ color: 'var(--ds-text-secondary)' }"
                            >
                                {{ c.sends_blocked }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </section>
        </div>
    </div>
</template>
