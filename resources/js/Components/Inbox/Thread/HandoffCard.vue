<script setup lang="ts">
// Doc 06 FR-11's handoff packet, rendered — AC-08's card (the Doc 06
// closing row): intent + confidence, sentiment, urgency, suggested
// department/priority, "customer already saw" — pinned at its ticket's
// start, collapsible. AI-origin content carries the AI accent (§9 platform
// rule). The packet is IMMUTABLE server truth (Doc 06 AC-08's grant
// physics); this card only reads.
import { computed, ref } from 'vue';

const props = defineProps<{
    packet: Record<string, unknown>;
    t: Record<string, string>;
}>();

const collapsed = ref(false);

const str = (key: string): string => {
    const value = props.packet[key];
    return typeof value === 'string' ? value : '';
};

const seen = computed(() =>
    Array.isArray(props.packet.customer_already_saw)
        ? (props.packet.customer_already_saw as string[])
        : [],
);
const confidence = computed(() =>
    typeof props.packet.intent_confidence === 'number' ? props.packet.intent_confidence : null,
);
</script>

<template>
    <aside
        data-role="handoff-card"
        class="my-2 rounded-lg border p-2"
        :style="{
            background: 'var(--ds-ai-bg)',
            borderColor: 'var(--ds-border)',
            color: 'var(--ds-text-primary)',
        }"
        :aria-label="t.handoff_title"
    >
        <div class="flex items-center justify-between">
            <p class="text-xs font-semibold" :style="{ color: 'var(--ds-ai-fg)' }">
                ✦ {{ t.handoff_title }}
            </p>
            <button
                type="button"
                data-role="handoff-toggle"
                class="text-[10px] underline"
                :style="{ color: 'var(--ds-text-muted)' }"
                :aria-expanded="!collapsed"
                @click="collapsed = !collapsed"
            >
                {{ collapsed ? t.handoff_expand : t.handoff_collapse }}
            </button>
        </div>
        <div v-if="!collapsed" class="mt-1 space-y-1 text-xs">
            <p v-if="str('summary')" data-role="handoff-summary">{{ str('summary') }}</p>
            <p v-if="str('intent')">
                <span class="font-semibold">{{ t.handoff_intent }}:</span> {{ str('intent') }}
                <template v-if="confidence !== null"> · {{ confidence }}</template>
            </p>
            <p v-if="str('sentiment')">
                <span class="font-semibold">{{ t.handoff_sentiment }}:</span> {{ str('sentiment') }}
            </p>
            <p v-if="str('urgency')">
                <span class="font-semibold">{{ t.handoff_urgency }}:</span> {{ str('urgency') }}
            </p>
            <p v-if="seen.length" data-role="handoff-seen">
                <span class="font-semibold">{{ t.handoff_seen }}:</span> {{ seen.join(', ') }}
            </p>
        </div>
    </aside>
</template>
