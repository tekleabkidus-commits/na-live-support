<script setup lang="ts">
// Doc 02 FR-16/FR-17 — the shared set-a-password form (reset + invitation).
//
// One component because the two flows must behave identically (same policy
// feedback shape, same done-state) — the differences are the endpoint and the
// copy, which arrive as props. No user-facing literals live here (N-03); the
// per-rule errors render the server's own localized problem-details `errors`,
// which is FR-16's "live policy validation" surface until the views step adds
// the designed strength meter on top of the same data.
import { ref } from 'vue';

const props = defineProps<{
    endpoint: string;
    token: string;
    copy: {
        title: string;
        intro?: string;
        passwordLabel: string;
        confirmLabel: string;
        submit: string;
        done: string;
        lengthHint: string;
        mismatch: string;
    };
}>();

const password = ref('');
const confirmation = ref('');
const busy = ref(false);
const done = ref(false);
const errors = ref<string[]>([]);

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

async function submit(): Promise<void> {
    errors.value = [];

    if (password.value !== confirmation.value) {
        errors.value = [props.copy.mismatch];
        return;
    }

    busy.value = true;
    try {
        const response = await fetch(props.endpoint, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            body: JSON.stringify({
                token: props.token,
                password: password.value,
                password_confirmation: confirmation.value,
            }),
        });

        if (response.ok) {
            done.value = true;
            return;
        }

        const body = (await response.json().catch(() => ({}))) as {
            detail?: string;
            errors?: Record<string, string[]>;
        };

        errors.value = body.errors
            ? Object.values(body.errors).flat()
            : [typeof body.detail === 'string' ? body.detail : String(response.status)];
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <main class="mx-auto flex min-h-screen max-w-md flex-col justify-center p-6">
        <h1 class="text-xl font-semibold">{{ copy.title }}</h1>
        <p v-if="copy.intro && !done" class="mt-2 text-sm text-gray-600">{{ copy.intro }}</p>

        <p v-if="done" class="mt-6 rounded bg-green-50 p-4 text-sm text-green-800" role="status">
            {{ copy.done }}
        </p>

        <form v-else class="mt-6 space-y-4" @submit.prevent="submit">
            <div>
                <label class="block text-sm font-medium" for="new-password">{{ copy.passwordLabel }}</label>
                <input
                    id="new-password"
                    v-model="password"
                    type="password"
                    autocomplete="new-password"
                    required
                    class="mt-1 w-full rounded border border-gray-300 p-2"
                    :aria-describedby="'password-hint'"
                />
                <p id="password-hint" class="mt-1 text-xs text-gray-500">{{ copy.lengthHint }}</p>
            </div>

            <div>
                <label class="block text-sm font-medium" for="confirm-password">{{ copy.confirmLabel }}</label>
                <input
                    id="confirm-password"
                    v-model="confirmation"
                    type="password"
                    autocomplete="new-password"
                    required
                    class="mt-1 w-full rounded border border-gray-300 p-2"
                />
            </div>

            <ul v-if="errors.length" class="rounded bg-red-50 p-3 text-sm text-red-800" role="alert">
                <li v-for="message in errors" :key="message">{{ message }}</li>
            </ul>

            <button
                type="submit"
                :disabled="busy"
                class="w-full rounded bg-gray-900 p-2 text-white disabled:opacity-50"
            >
                {{ copy.submit }}
            </button>
        </form>
    </main>
</template>
