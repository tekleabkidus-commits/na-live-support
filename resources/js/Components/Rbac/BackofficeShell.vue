<script setup lang="ts">
// The drawn Backoffice chrome the four RBAC frames sit in (B6-S07..S10):
// 52px topbar (tenant logo slot, search, bell, presence, avatar) and the
// 176px "Backoffice" nav rail. Doc 16 owns the full Backoffice; this shell
// renders exactly the chrome the four frozen frames draw around these
// screens — structure and tokens verbatim, content localized.
// Strings arrive server-localized (the house pattern: Laravel's i18n chain
// resolves them; the client never holds raw keys).
defineProps<{
    title: string;
    breadcrumb: string;
    permissionChip?: string;
    navBadge?: number;
    initials: string;
    chrome: {
        tenantLogo: string;
        search: string;
        notifications: string;
        online: string;
        backoffice: string;
        people: string;
        users: string;
        roles: string;
    };
}>();
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
                class="rounded-[5px] border border-dashed px-2 py-1 text-xs font-semibold"
                :style="{ color: 'var(--ds-text-muted)', borderColor: 'var(--ds-border-strong)' }"
                >{{ chrome.tenantLogo }}</span
            >
            <label
                class="flex max-w-[380px] flex-1 items-center gap-1.5 rounded-md border px-2.5 py-1.5 text-xs"
                :style="{
                    background: 'var(--ds-panel-soft)',
                    borderColor: 'var(--ds-border)',
                    color: 'var(--ds-text-muted)',
                }"
            >
                <span aria-hidden="true">⌕</span>
                <input
                    type="search"
                    class="w-full bg-transparent focus:outline-none"
                    :aria-label="chrome.search"
                    :placeholder="chrome.search"
                />
                <span
                    class="rounded border px-1 text-[10px]"
                    :style="{ borderColor: 'var(--ds-border)' }"
                    >⌘K</span
                >
            </label>
            <button
                type="button"
                class="relative ms-auto text-sm"
                :style="{ color: 'var(--ds-text-muted)' }"
                :aria-label="chrome.notifications"
            >
                <span aria-hidden="true">🔔</span>
                <span
                    v-if="navBadge"
                    class="absolute -end-2 -top-1.5 rounded-full px-1 text-[9px] font-bold"
                    :style="{
                        background: 'var(--ds-danger-strong)',
                        color: 'var(--ds-accent-contrast)',
                    }"
                    >{{ navBadge }}</span
                >
            </button>
            <span
                class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-[11.5px] font-medium"
                :style="{ borderColor: 'var(--ds-border)', color: 'var(--ds-text-secondary)' }"
            >
                <span
                    class="size-[7px] rounded-full"
                    :style="{ background: 'var(--ds-health-healthy)' }"
                    aria-hidden="true"
                ></span>
                {{ chrome.online }}
            </span>
            <span
                class="inline-flex size-7 items-center justify-center rounded-full text-[10.5px] font-semibold"
                :style="{ background: 'var(--ds-inset-bg)', color: 'var(--ds-text-muted)' }"
                aria-hidden="true"
                >{{ initials }}</span
            >
        </header>

        <div class="flex min-h-0 flex-1">
            <nav
                class="w-[176px] flex-none border-e p-2.5 max-lg:hidden"
                :style="{ background: 'var(--ds-panel-bg)', borderColor: 'var(--ds-border)' }"
                :aria-label="chrome.backoffice"
            >
                <div
                    class="px-2.5 pb-2 text-[10px] font-semibold uppercase tracking-widest"
                    :style="{ color: 'var(--ds-text-label)' }"
                >
                    {{ chrome.backoffice }}
                </div>
                <div
                    class="px-2.5 pb-1 pt-2 text-[10px] font-semibold uppercase tracking-wider"
                    :style="{ color: 'var(--ds-text-label)' }"
                >
                    {{ chrome.people }}
                </div>
                <div
                    class="flex flex-col gap-px text-[12.5px]"
                    :style="{ color: 'var(--ds-text-secondary)' }"
                >
                    <span class="rounded-md px-2.5 py-1.5">{{ chrome.users }}</span>
                    <span
                        class="flex items-center gap-2 rounded-md px-2.5 py-1.5 font-semibold"
                        :style="{
                            background: 'var(--ds-inset-bg)',
                            color: 'var(--ds-text-primary)',
                        }"
                        aria-current="page"
                    >
                        {{ chrome.roles }}
                        <span
                            v-if="navBadge"
                            class="ms-auto rounded-full px-1.5 text-[9.5px] font-bold"
                            :style="{
                                background: 'var(--ds-danger-strong)',
                                color: 'var(--ds-accent-contrast)',
                            }"
                            >{{ navBadge }}</span
                        >
                    </span>
                </div>
            </nav>

            <main class="min-w-0 flex-1 overflow-hidden p-4 lg:p-5">
                <div class="flex items-start gap-3">
                    <div class="min-w-0">
                        <div class="text-[11.5px]" :style="{ color: 'var(--ds-text-muted)' }">
                            {{ breadcrumb }}
                        </div>
                        <h1 class="mt-0.5 text-[19px] font-semibold tracking-tight">{{ title }}</h1>
                    </div>
                    <span
                        v-if="permissionChip"
                        class="ms-auto rounded border px-1.5 py-1 font-mono text-[10.5px]"
                        :style="{
                            color: 'var(--ds-text-muted)',
                            borderColor: 'var(--ds-border-strong)',
                        }"
                        >{{ permissionChip }}</span
                    >
                </div>
                <slot />
            </main>
        </div>
    </div>
</template>
