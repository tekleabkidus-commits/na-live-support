<script setup lang="ts">
// Doc 02 FR-16 — request a reset link. The `sent` state is the UNIFORM answer
// (SEC-01/AC-01): it renders identically whether or not the address matched
// anything, because the server told us nothing either way.
import { ref } from 'vue';

defineProps<{
    copy: {
        title: string;
        intro: string;
        emailLabel: string;
        submit: string;
        sent: string;
    };
}>();

const email = ref('');
const busy = ref(false);
const sent = ref(false);

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return match?.[1] ? decodeURIComponent(match[1]) : '';
}

async function submit(): Promise<void> {
    busy.value = true;
    try {
        await fetch('/forgot-password', {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            body: JSON.stringify({ email: email.value }),
        });
        // The response body is deliberately uninformative; the sent state is
        // shown regardless (matching the server's own uniformity).
        sent.value = true;
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <main class="mx-auto flex min-h-screen max-w-md flex-col justify-center p-6">
        <h1 class="text-xl font-semibold">{{ copy.title }}</h1>

        <p v-if="sent" class="mt-6 rounded bg-green-50 p-4 text-sm text-green-800" role="status">
            {{ copy.sent }}
        </p>

        <template v-else>
            <p class="mt-2 text-sm text-gray-600">{{ copy.intro }}</p>

            <form class="mt-6 space-y-4" @submit.prevent="submit">
                <div>
                    <label class="block text-sm font-medium" for="email">{{
                        copy.emailLabel
                    }}</label>
                    <input
                        id="email"
                        v-model="email"
                        type="email"
                        autocomplete="email"
                        required
                        class="mt-1 w-full rounded border border-gray-300 p-2"
                    />
                </div>

                <button
                    type="submit"
                    :disabled="busy"
                    class="w-full rounded bg-gray-900 p-2 text-white disabled:opacity-50"
                >
                    {{ copy.submit }}
                </button>
            </form>
        </template>
    </main>
</template>
