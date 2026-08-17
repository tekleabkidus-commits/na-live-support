<script setup lang="ts">
/**
 * B4-S06 G02 — one 375 card per customer: who, how to reach them, how many
 * open, how long ago (the rest lives on the profile). One ≥44px target.
 */
import type { DirectoryRow } from '../../Pages/Customers/Directory.vue';

const props = defineProps<{
    row: DirectoryRow;
    t: Record<string, string>;
    name: string;
    showShortId: boolean;
}>();

const fill = (template: string | undefined, vars: Record<string, string>): string => {
    let out = template ?? '';
    for (const [key, value] of Object.entries(vars)) out = out.replaceAll(':' + key, value);
    return out;
};

function relative(stamp: string | null): string {
    if (stamp === null) return '—';
    return stamp.slice(0, 10);
}

const initials = props.name
    .split(/\s+/)
    .slice(0, 2)
    .map((part) => part.slice(0, 1))
    .join('');
</script>

<template>
    <li
        class="border-t"
        :style="{ borderColor: 'var(--ds-border-soft)' }"
        :data-testid="`directory-card-${row.id}`"
    >
        <a
            :href="`/customers/${row.id}`"
            class="flex min-h-[76px] items-center gap-3 py-2"
            :aria-label="fill(t.open_profile, { name })"
        >
            <span
                aria-hidden="true"
                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-xs"
                :style="{ background: 'var(--ds-panel-bg)', color: 'var(--ds-text-muted)' }"
                >{{ initials }}</span
            >
            <span class="min-w-0 flex-1">
                <span class="block truncate text-sm font-medium">
                    {{ name }}
                    <span
                        v-if="showShortId"
                        class="text-xs font-normal"
                        :style="{ color: 'var(--ds-text-muted)' }"
                        >#{{ row.short_id }}</span
                    >
                    <span
                        v-if="row.blocked"
                        class="ms-1 rounded border px-1 text-xs font-normal"
                        :style="{
                            borderColor: 'var(--ds-danger-strong)',
                            color: 'var(--ds-danger-strong)',
                        }"
                        >{{ t.blocked_badge }}</span
                    >
                </span>
                <span class="block truncate text-xs" :style="{ color: 'var(--ds-text-muted)' }">
                    <span
                        v-for="identity in row.identities"
                        :key="identity.type + identity.value"
                        class="me-2"
                        >{{ identity.display }}</span
                    >
                    · {{ fill(t.open_count, { count: String(row.open_tickets) }) }}
                    <template v-if="row.satisfaction_count > 0">
                        · {{ row.satisfaction_avg }}</template
                    >
                </span>
            </span>
            <span class="shrink-0 text-xs" :style="{ color: 'var(--ds-text-muted)' }">{{
                relative(row.last_contact_at)
            }}</span>
        </a>
    </li>
</template>
