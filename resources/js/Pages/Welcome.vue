<script setup lang="ts">
// Session 0 placeholder page. The literal strings below are registered in
// ci/string-lint-allowlist.php: the i18n layer itself is Doc 01 FR-09
// (Session 1) and this page is replaced by the real agent app in Doc 07.
defineProps<{ appBooted: boolean }>();
</script>

<template>
    <main class="flex min-h-screen items-center justify-center bg-slate-950">
        <div class="text-center">
            <h1 data-testid="placeholder-heading" class="text-3xl font-semibold text-slate-100">
                Na Live Support
            </h1>
            <p class="mt-2 text-slate-400">
                Session 0 bootstrap
                <span
                    data-testid="boot-status"
                    class="ml-2 rounded bg-emerald-900 px-2 py-0.5 text-sm text-emerald-200"
                    >{{ appBooted ? 'app booted' : 'app not booted' }}</span
                >
            </p>
        </div>
    </main>
</template>
