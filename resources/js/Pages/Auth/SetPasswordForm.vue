<script setup lang="ts">
// Doc 02 FR-16/FR-17 — the shared set-a-password form (reset + invitation),
// now with §9's password field (visibility toggle + strength meter) and §8's
// invitation profile-completion half (step 13).
//
// One component because the two flows must behave identically (same policy
// feedback shape, same done-state) — the differences are the endpoint, the
// copy, and whether the profile fields render (`profile` prop: invitation
// only), which all arrive as props. No user-facing literals live here (N-03);
// the per-rule errors render the server's own localized problem-details
// `errors`, which is FR-16's "live policy validation" surface.
//
// THE METER IS TEXT (§13: "strength conveyed as text, not color") and argues
// from the same fact the server enforces: the tenant's minimum length, passed
// as a prop. Below the minimum is weak — a meter that says "fair" about a
// password the server will refuse is worse than none. The meter never blocks
// submission; the server's verdict is the verdict (the meter is advice, the
// policy is law).
import { computed, ref } from 'vue';
import PasswordField from '../../Components/Auth/PasswordField.vue';

const props = defineProps<{
    endpoint: string;
    token: string;
    minLength: number;
    copy: {
        title: string;
        intro?: string;
        passwordLabel: string;
        confirmLabel: string;
        submit: string;
        done: string;
        lengthHint: string;
        mismatch: string;
        showPassword: string;
        hidePassword: string;
        strengthLabel: string;
        strengthWeak: string;
        strengthFair: string;
        strengthStrong: string;
    };
    profile?: {
        nameLabel: string;
        languageLabel: string;
        langEnglish: string;
        langAmharic: string;
        name: string;
        locale: string;
    };
}>();

const password = ref('');
const confirmation = ref('');
const profileName = ref(props.profile?.name ?? '');
const profileLocale = ref(props.profile?.locale ?? 'en');
const busy = ref(false);
const done = ref(false);
const errors = ref<string[]>([]);

// §13 text-only strength, from the tenant's own minimum: weak below it,
// strong at minimum+4 with three character classes, fair between.
const strengthText = computed<string | null>(() => {
    if (password.value === '') {
        return null;
    }

    const classes = [/[a-z]/, /[A-Z]/, /\d/, /[^A-Za-z0-9]/].filter((pattern) =>
        pattern.test(password.value),
    ).length;

    const level =
        password.value.length < props.minLength
            ? props.copy.strengthWeak
            : password.value.length >= props.minLength + 4 && classes >= 3
              ? props.copy.strengthStrong
              : props.copy.strengthFair;

    return props.copy.strengthLabel.replace(':level', level);
});

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return match?.[1] ? decodeURIComponent(match[1]) : '';
}

async function submit(): Promise<void> {
    errors.value = [];

    if (password.value !== confirmation.value) {
        errors.value = [props.copy.mismatch];
        return;
    }

    busy.value = true;
    try {
        const body: Record<string, unknown> = {
            token: props.token,
            password: password.value,
            password_confirmation: confirmation.value,
        };

        if (props.profile) {
            body.name = profileName.value;
            body.locale = profileLocale.value;
        }

        const response = await fetch(props.endpoint, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            body: JSON.stringify(body),
        });

        if (response.ok) {
            done.value = true;
            return;
        }

        const payload = (await response.json().catch(() => ({}))) as {
            detail?: string;
            errors?: Record<string, string[]>;
        };

        errors.value =
            payload.errors && Object.keys(payload.errors).length > 0
                ? Object.values(payload.errors).flat()
                : [typeof payload.detail === 'string' ? payload.detail : String(response.status)];
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
            <!-- §8: the invitation's set-password screen doubles as profile
                 completion (name, language; avatar deferred to the media
                 pipeline — Docs 04/08, recorded in BUILD-STATUS). -->
            <template v-if="profile">
                <div>
                    <label class="block text-sm font-medium" for="profile-name">{{
                        profile.nameLabel
                    }}</label>
                    <input
                        id="profile-name"
                        v-model="profileName"
                        type="text"
                        autocomplete="name"
                        data-testid="profile-name"
                        class="mt-1 w-full rounded border border-gray-300 p-3"
                    />
                </div>
                <div>
                    <label class="block text-sm font-medium" for="profile-locale">{{
                        profile.languageLabel
                    }}</label>
                    <select
                        id="profile-locale"
                        v-model="profileLocale"
                        data-testid="profile-locale"
                        class="mt-1 w-full rounded border border-gray-300 p-3"
                    >
                        <option value="en">{{ profile.langEnglish }}</option>
                        <option value="am">{{ profile.langAmharic }}</option>
                    </select>
                </div>
            </template>

            <PasswordField
                v-model="password"
                input-id="new-password"
                :label="copy.passwordLabel"
                autocomplete="new-password"
                :show-label="copy.showPassword"
                :hide-label="copy.hidePassword"
                described-by="password-hint"
                meter-id="password-strength"
                :meter-text="strengthText"
                input-class="border border-gray-300 bg-white text-gray-900"
                chrome-class="text-gray-600 hover:text-gray-900"
            />
            <p id="password-hint" class="text-xs text-gray-500">{{ copy.lengthHint }}</p>

            <PasswordField
                v-model="confirmation"
                input-id="confirm-password"
                :label="copy.confirmLabel"
                autocomplete="new-password"
                :show-label="copy.showPassword"
                :hide-label="copy.hidePassword"
                input-class="border border-gray-300 bg-white text-gray-900"
                chrome-class="text-gray-600 hover:text-gray-900"
            />

            <ul v-if="errors.length" class="rounded bg-red-50 p-3 text-sm text-red-800" role="alert">
                <li v-for="message in errors" :key="message">{{ message }}</li>
            </ul>

            <button
                type="submit"
                :disabled="busy"
                :aria-busy="busy"
                class="min-h-11 w-full rounded bg-gray-900 p-3 text-white disabled:opacity-50"
            >
                {{ copy.submit }}
            </button>
        </form>
    </main>
</template>
