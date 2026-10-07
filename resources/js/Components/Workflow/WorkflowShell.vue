<script setup lang="ts">
// Doc 12 §8 (S12 step 14) — the drawn Backoffice chrome the Organization page carries
// (B5-S09 J01: 52px topbar + 200px nav), with the WORKFLOW group live. SESSION-F1 [B12-batch]
// (frozen B9-S05…S13 §M M01): the same chrome now comes from the shared BackofficeShell —
// Organization a real link out; People & roles / Channels drawn dim (Docs 03/05 own them);
// Knowledge base its own row (P8); Workflow with its seven rows as an indented sub-group;
// the breadcrumb "Backoffice › Workflow › {page}"; sub / actions / banner slots for the
// pages' headers. Tokens only; RTL-safe; no animation.
import { computed } from 'vue';
import BackofficeShell from '../Backoffice/BackofficeShell.vue';

const props = defineProps<{
    copy: Record<string, unknown>;
    active: 'rules' | 'sla' | 'hours' | 'macros' | 'fields' | 'forms' | 'csat' | 'hub';
    title: string;
    summary?: string;
    crumb?: { label: string; href?: string }[];
    readNote?: string;
}>();
// eslint-disable-next-line @typescript-eslint/no-explicit-any
const c = props.copy as any;
const links: { key: typeof props.active; href: string; navKey: string }[] = [
    { key: 'rules', href: '/admin/workflow/rules', navKey: 'automation' },
    { key: 'sla', href: '/admin/workflow/sla', navKey: 'sla' },
    { key: 'hours', href: '/admin/workflow/hours', navKey: 'hours' },
    { key: 'macros', href: '/admin/workflow/macros', navKey: 'macros' },
    { key: 'fields', href: '/admin/workflow/fields', navKey: 'fields' },
    { key: 'forms', href: '/admin/workflow/forms', navKey: 'forms' },
    { key: 'csat', href: '/admin/workflow/csat', navKey: 'csat' },
];
// The shared shell reads its nav words from a flat t-map; Doc 12's copy keeps them under nav.*
const t = computed<Record<string, string>>(() => ({
    nav_backoffice: c.nav?.backoffice ?? '',
    nav_organization: c.nav?.organization ?? '',
    nav_people: c.nav?.people ?? '',
    nav_channels: c.nav?.channels ?? '',
    nav_kb: c.nav?.kb ?? '',
    nav_workflow: c.nav?.workflow ?? '',
    nav_widgets: c.nav?.widgets ?? '', // P-NAV-WIDGETS (S13 Batch C): the shared shell's Doc 13 row needs its name here too
    nav_sms_channel: c.nav?.sms_channel ?? '', // P-NAV-SMS (S14): the shared shell's Doc 14 rows need their names here too
    nav_sms_settings: c.nav?.sms_settings ?? '',
}));
const crumb = computed(() => {
    if (props.crumb) return props.crumb;
    const base = [
        { label: c.nav?.backoffice ?? '' },
        { label: c.nav?.workflow ?? '', href: '/admin/workflow' },
    ];
    if (props.active === 'hub') return base;
    const link = links.find((l) => l.key === props.active);
    return [...base, { label: c.nav?.[link?.navKey ?? ''] ?? props.title }];
});
</script>

<template>
    <BackofficeShell :t="t" active="workflow" :title="title" :crumb="crumb" :read-note="readNote">
        <template #nav-sub>
            <a
                v-for="link in links"
                :key="link.key"
                :href="link.href"
                :data-testid="`wf-nav-${link.key}`"
                class="bo-nav-a"
                :class="{ on: active === link.key }"
                :aria-current="active === link.key ? 'page' : undefined"
                >{{ c.nav[link.navKey] }}</a
            >
        </template>
        <template #sub>
            <slot name="sub"
                ><span v-if="summary">{{ summary }}</span></slot
            >
        </template>
        <template #actions><slot name="actions" /></template>
        <template #banner><slot name="banner" /></template>
        <div class="wf-body"><slot /></div>
    </BackofficeShell>
</template>

<style>
.wf-body {
    padding: 12px 18px 24px;
    flex: 1;
    display: grid;
    gap: 12px;
    align-content: start;
    min-width: 0;
}
@media (max-width: 639px) {
    .wf-body {
        padding: 10px 12px;
    }
}
</style>
