<script setup lang="ts">
// Doc 02 FR-16's dedicated dead-link screen — one screen for every kind of
// dead (SEC-01; the server never said which kind). For a reset link the way
// forward is the forgot-password page; for an invitation it is EC-04's
// self-service resend, which posts from right here and shows the same uniform
// answer as every other request path.
import { ref } from 'vue';

const props = defineProps<{
    purpose: string;
    requestAgainUrl?: string;
    resendUrl?: string;
    copy: {
        title: string;
        body: string;
        requestAgain: string;
        emailLabel?: string;
        resent?: string;
    };
}>();

const email = ref('');
const busy = ref(false);
const sent = ref(false);

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return match?.[1] ? decodeURIComponent(match[1]) : '';
}

async function resend(): Promise<void> {
    if (!props.resendUrl) {
        return;
    }
    busy.value = true;
    try {
        await fetch(props.resendUrl, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            body: JSON.stringify({ email: email.value }),
        });
        sent.value = true;
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <main class="mx-auto flex min-h-screen max-w-md flex-col justify-center p-6">
        <h1 class="text-xl font-semibold">{{ copy.title }}</h1>
        <p class="mt-2 text-sm text-gray-600">{{ copy.body }}</p>

        <a
            v-if="requestAgainUrl"
            :href="requestAgainUrl"
            class="mt-6 inline-block rounded bg-gray-900 p-2 px-4 text-center text-white"
        >
            {{ copy.requestAgain }}
        </a>

        <template v-if="resendUrl">
            <p
                v-if="sent"
                class="mt-6 rounded bg-green-50 p-4 text-sm text-green-800"
                role="status"
            >
                {{ copy.resent }}
            </p>

            <form v-else class="mt-6 space-y-4" @submit.prevent="resend">
                <div>
                    <label class="block text-sm font-medium" for="resend-email">{{
                        copy.emailLabel
                    }}</label>
                    <input
                        id="resend-email"
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
                    {{ copy.requestAgain }}
                </button>
            </form>
        </template>
    </main>
</template>
