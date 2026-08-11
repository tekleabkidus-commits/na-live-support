<script setup lang="ts">
// EC-01 — the 404-pattern page (Doc 03 SEC-02's philosophy on a URL): the
// SAME page for a stale link, a foreign tenant's id, and an out-of-scope
// ticket — with "search instead", never a confirmation that anything
// exists. Served with HTTP 404 and the C-10 security metric incremented
// server-side. Sabotage #15's partner surface.
defineProps<{ t: Record<string, string> }>();
</script>

<template>
    <main
        class="flex min-h-screen flex-col items-center justify-center gap-3 p-8"
        :style="{ background: 'var(--ds-page-bg)', color: 'var(--ds-text-primary)' }"
        data-testid="notfound-pattern"
    >
        <h1 class="text-lg font-semibold">{{ t.title }}</h1>
        <p class="max-w-md text-center text-sm" :style="{ color: 'var(--ds-text-secondary)' }">
            {{ t.body }}
        </p>
        <a
            href="/inbox"
            data-testid="search-instead"
            class="rounded-md px-3 py-1.5 text-sm font-semibold"
            :style="{ background: 'var(--ds-accent)', color: 'var(--ds-accent-contrast)' }"
        >
            {{ t.search_instead }}
        </a>
    </main>
</template>
