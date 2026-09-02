<script setup lang="ts">
// Doc 12 §8 (S12 step 14) — the drawn Backoffice chrome the Organization page carries
// (B5-S09 J01: 52px topbar + 200px nav), with the WORKFLOW group live. Organization
// links out (Doc 11's page); SLA / Automation / Business hours are Doc 12's own
// screens; People / Channels stay drawn (Docs 03/05 own them). The reverse links from
// Organization and the palette are packet P-NAV's. Tokens only; RTL-safe (logical
// properties via Tailwind's ps/pe/ms/me); reduced-motion honoured (no animation here).
const props = defineProps<{
    copy: Record<string, unknown>;
    active: 'rules' | 'sla' | 'hours' | 'macros' | 'fields' | 'forms' | 'csat' | 'hub';
    title: string;
    summary?: string;
}>();
// eslint-disable-next-line @typescript-eslint/no-explicit-any
const c = props.copy as any;
const links: { key: string; href: string; navKey: string }[] = [
    { key: 'hub', href: '/admin/workflow', navKey: 'workflow' },
    { key: 'rules', href: '/admin/workflow/rules', navKey: 'automation' },
    { key: 'sla', href: '/admin/workflow/sla', navKey: 'sla' },
    { key: 'hours', href: '/admin/workflow/hours', navKey: 'hours' },
    { key: 'macros', href: '/admin/workflow/macros', navKey: 'macros' },
    { key: 'fields', href: '/admin/workflow/fields', navKey: 'fields' },
    { key: 'forms', href: '/admin/workflow/forms', navKey: 'forms' },
    { key: 'csat', href: '/admin/workflow/csat', navKey: 'csat' },
];
</script>

<template>
    <div
        class="flex min-h-screen flex-col"
        :style="{ background: 'var(--ds-page-bg)', color: 'var(--ds-text-primary)' }"
    >
        <header
            class="flex h-[52px] flex-none items-center gap-3.5 border-b px-4"
            :style="{ background: 'var(--ds-panel-bg)', borderColor: 'var(--ds-border)' }"
        >
            <span
                class="flex-none rounded-md border border-dashed px-2.5 py-1 text-xs font-semibold"
                :style="{ borderColor: 'var(--ds-border-strong)', color: 'var(--ds-text-muted)' }"
                >{{ c.nav.backoffice }}</span
            >
            <h1 class="text-sm font-semibold">{{ title }}</h1>
        </header>
        <div class="flex min-h-0 flex-1">
            <nav
                :aria-label="c.nav.backoffice"
                class="w-[200px] flex-none overflow-y-auto border-e p-2"
                :style="{ background: 'var(--ds-page-bg)', borderColor: 'var(--ds-border)' }"
            >
                <p
                    class="px-2 pb-2 text-[10.5px] font-semibold uppercase tracking-wider"
                    :style="{ color: 'var(--ds-text-label)' }"
                >
                    {{ c.nav.backoffice }}
                </p>
                <div class="flex flex-col gap-px text-[12.5px]">
                    <a
                        href="/admin/organization"
                        class="rounded-md px-2 py-1.5"
                        :style="{ color: 'var(--ds-text-primary)' }"
                        >{{ c.nav.organization }}</a
                    >
                    <span
                        v-for="key in ['people', 'channels']"
                        :key="key"
                        class="rounded-md px-2 py-1.5"
                        :style="{ color: 'var(--ds-text-muted)' }"
                        >{{ c.nav[key] }}</span
                    >
                    <a
                        v-for="link in links"
                        :key="link.key"
                        :href="link.href"
                        :data-testid="`wf-nav-${link.key}`"
                        class="rounded-md px-2 py-1.5"
                        :class="{ 'font-semibold': active === link.key }"
                        :style="
                            active === link.key
                                ? {
                                      background: 'var(--ds-accent)',
                                      color: 'var(--ds-accent-contrast)',
                                  }
                                : { color: 'var(--ds-text-primary)' }
                        "
                        :aria-current="active === link.key ? 'page' : undefined"
                        >{{ c.nav[link.navKey] }}</a
                    >
                </div>
            </nav>
            <main class="flex min-w-0 flex-1 flex-col overflow-auto">
                <div class="flex-none px-[18px] pt-3.5">
                    <h2 class="text-xl font-semibold tracking-tight">{{ title }}</h2>
                    <p
                        v-if="summary"
                        class="mt-0.5 text-xs"
                        :style="{ color: 'var(--ds-text-muted)' }"
                    >
                        {{ summary }}
                    </p>
                </div>
                <div class="min-h-0 flex-1 px-[18px] pb-6 pt-3">
                    <slot />
                </div>
            </main>
        </div>
    </div>
</template>
