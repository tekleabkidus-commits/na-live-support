<script setup lang="ts">
// The §9 brand card shell shared by the auth screens (Doc 02 §8, §9, §14).
//
// Neutral styling by design: theming tokens are Doc 16's, recorded in
// BUILD-STATUS — what is load-bearing here is the structure §13 needs (a
// single column ≤ 480 px, ≥ 44 px touch targets, logical properties per C-15)
// and §8's two always-present card fixtures: the forgot-password link and the
// language switcher. All strings arrive as props (N-03).
//
// The switcher writes the plain `locale` cookie ApplyUserLocale's guest hop
// reads (exactly en|am server-side; the client offers only those two), then
// reloads — copy is translated server-side, so a locale change is a re-render
// by construction, not a client-side dictionary swap.

defineProps<{
    locale: string;
    copy: {
        forgot: string;
        languageLabel: string;
        langEnglish: string;
        langAmharic: string;
    };
}>();

function switchLocale(target: string, current: string): void {
    if (target === current) {
        return;
    }
    document.cookie = `locale=${target}; path=/; max-age=31536000; secure; samesite=lax`;
    window.location.reload();
}
</script>

<template>
    <main class="flex min-h-screen flex-col items-center justify-center bg-slate-950 p-4">
        <div
            class="w-full max-w-md rounded-lg border border-slate-800 bg-slate-900 p-6 text-slate-100"
            data-testid="auth-card"
        >
            <slot />

            <p class="mt-6 text-sm">
                <a
                    href="/forgot-password"
                    data-testid="forgot-link"
                    class="inline-block min-h-11 py-2 text-slate-300 underline hover:text-slate-100"
                >
                    {{ copy.forgot }}
                </a>
            </p>
        </div>

        <nav
            :aria-label="copy.languageLabel"
            data-testid="language-switcher"
            class="mt-4 flex gap-2 text-sm"
        >
            <button
                type="button"
                data-testid="lang-en"
                class="min-h-11 min-w-11 rounded px-3 py-2"
                :class="
                    locale === 'en'
                        ? 'bg-slate-800 text-slate-100'
                        : 'text-slate-400 hover:text-slate-200'
                "
                :aria-pressed="locale === 'en'"
                @click="switchLocale('en', locale)"
            >
                {{ copy.langEnglish }}
            </button>
            <button
                type="button"
                data-testid="lang-am"
                class="min-h-11 min-w-11 rounded px-3 py-2"
                :class="
                    locale === 'am'
                        ? 'bg-slate-800 text-slate-100'
                        : 'text-slate-400 hover:text-slate-200'
                "
                :aria-pressed="locale === 'am'"
                @click="switchLocale('am', locale)"
            >
                {{ copy.langAmharic }}
            </button>
        </nav>
    </main>
</template>
