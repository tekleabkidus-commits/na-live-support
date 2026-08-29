<script setup lang="ts">
// Doc 10 FR-16/SS-04 (S10 step 12 → [B9]): the citation landing — the LABELED-DEGRADE
// pattern (mandate §5): a once-cited URL always answers a STATE (Current / Retired-with-
// current-link / Retired / Unknown), never a 404 and never a silent empty. `shown` is
// the frozen snapshot's title when the resolver can serve one.
const props = defineProps<{
    t: Record<string, string>;
    state: string;
    article_id: string | null;
    version_id: string | null;
    shown: { title?: string; language?: string } | null;
    current_url: string | null;
    tombstone: string;
}>();

const stateKey = (
    { current: 'cite_current', retired: 'cite_retired', unknown: 'cite_unknown' } as Record<
        string,
        string
    >
)[props.state];
</script>

<template>
    <main
        class="mx-auto max-w-xl space-y-3 p-6 text-start text-sm"
        :style="{ color: 'var(--ds-text-primary)', background: 'var(--ds-page-bg)' }"
    >
        <p
            data-testid="kb-tombstone-state"
            class="rounded border px-3 py-2"
            :style="{
                borderColor: state === 'current' ? 'var(--ds-accent)' : 'var(--ds-note-border)',
                background: 'var(--ds-panel-bg)',
            }"
        >
            {{ t[stateKey ?? 'cite_unknown'] ?? tombstone }}
        </p>
        <h1 v-if="shown?.title" class="text-lg font-semibold" data-testid="kb-tombstone-title">
            {{ shown.title }}
        </h1>
        <p v-if="current_url">
            <a
                :href="current_url"
                data-testid="kb-tombstone-current-link"
                class="underline"
                :style="{ color: 'var(--ds-accent)' }"
                >{{ t.cite_view_current }}</a
            >
        </p>
    </main>
</template>
