<script setup lang="ts">
// R4 — the context panel (FR-01: 380 px, collapsible; §9: the three tabs
// keep FIXED ORDER — Ticket, Customer, Copilot — with badge dots: Customer
// = RG banner presence per Doc 08's rules, Copilot = new suggestion per
// Doc 09's). Step 8 ships the tab chrome and the SLOTS; the Ticket tab's
// property contract is Doc 06's (renders at step 14's operations pass),
// Customer/Copilot content are Doc 08/09's — the slot text says exactly
// that, no invented content. Badge state arrives via props (the seam
// fixtures drive it in the browser proof). §13: dots carry accessible
// labels, never colour alone.
import { ref } from 'vue';

defineProps<{
    badges: { customer: boolean; copilot: boolean };
    t: { tabs: Record<string, string>; shell: Record<string, string> };
}>();

const TABS = ['ticket', 'customer', 'copilot'] as const;

const active = ref<(typeof TABS)[number]>('ticket');
</script>

<template>
    <aside
        data-testid="context-panel"
        :aria-label="t.shell.region_context"
        class="flex h-full w-full flex-col"
        :style="{ background: 'var(--ds-panel-bg)' }"
    >
        <div
            role="tablist"
            class="flex shrink-0 border-b"
            :style="{ borderColor: 'var(--ds-border)' }"
            :aria-label="t.shell.region_context"
        >
            <button
                v-for="tab in TABS"
                :key="tab"
                type="button"
                role="tab"
                :data-testid="'tab-' + tab"
                :aria-selected="active === tab"
                :tabindex="active === tab ? 0 : -1"
                class="relative flex-1 px-2 py-2 text-sm font-medium"
                :style="{
                    color: active === tab ? 'var(--ds-text-primary)' : 'var(--ds-text-muted)',
                    borderBottom:
                        active === tab ? '2px solid var(--ds-accent)' : '2px solid transparent',
                }"
                @click="active = tab"
            >
                {{ t.tabs[tab] }}
                <!-- §9 badge dots, with §13 labels. -->
                <span
                    v-if="tab === 'customer' && badges.customer"
                    data-testid="badge-customer"
                    class="absolute -mt-0.5 ms-0.5 inline-block h-1.5 w-1.5 rounded-full"
                    :style="{ background: 'var(--ds-danger-strong)' }"
                    role="img"
                    :aria-label="t.tabs.customer_badge"
                ></span>
                <span
                    v-if="tab === 'copilot' && badges.copilot"
                    data-testid="badge-copilot"
                    class="absolute -mt-0.5 ms-0.5 inline-block h-1.5 w-1.5 rounded-full"
                    :style="{ background: 'var(--ds-ai-fg)' }"
                    role="img"
                    :aria-label="t.tabs.copilot_badge"
                ></span>
            </button>
        </div>

        <div class="min-h-0 flex-1 overflow-y-auto p-3">
            <!-- The SLOTS. Parents fill them when the owning docs land;
                 the fallback states the seam honestly. -->
            <div v-if="active === 'ticket'" role="tabpanel" data-testid="panel-ticket">
                <slot name="ticket">
                    <p class="text-sm" :style="{ color: 'var(--ds-text-muted)' }">
                        {{ t.tabs.ticket_slot }}
                    </p>
                </slot>
            </div>
            <div v-else-if="active === 'customer'" role="tabpanel" data-testid="panel-customer">
                <slot name="customer">
                    <p class="text-sm" :style="{ color: 'var(--ds-text-muted)' }">
                        {{ t.tabs.customer_slot }}
                    </p>
                </slot>
            </div>
            <div v-else role="tabpanel" data-testid="panel-copilot">
                <slot name="copilot">
                    <p class="text-sm" :style="{ color: 'var(--ds-text-muted)' }">
                        {{ t.tabs.copilot_slot }}
                    </p>
                </slot>
            </div>
        </div>
    </aside>
</template>
