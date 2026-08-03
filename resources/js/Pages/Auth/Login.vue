<script setup lang="ts">
// The §8 login card (Doc 02 §8, §9, §13; FR-01, FR-02, FR-07, FR-11, FR-13, EC-02's cousin).
//
// ─────────────────────────────────────────────────────────────────────────────
// STATES, exactly §8's: idle → submitting (button spinner, locked fields) →
// error (inline, generic) → 2FA step (auto-focus, paste-friendly, auto-submit
// on 6th digit) → redirect. The 2FA step is a state of THIS card, matching the
// server (POST /login answers `two_factor_required`; a reload mid-challenge
// re-enters via the `challengePending` prop).
//
// THE ERROR RENDERING IS PART OF AC-01. Every credential failure displays the
// server's `detail` verbatim through ONE code path — this file must never
// branch on WHICH failure occurred, because a client that renders unknown
// addresses differently from wrong passwords rebuilds the enumeration oracle
// the server closed. The only branch keys are transport-level: HTTP status,
// the `status` field of a success, and the `restart_login` extension member.
//
// FOCUS moves to the step heading on every transition (§13); transitions run
// 150 ms and collapse to none under prefers-reduced-motion (§9).
//
// `returnTo` arrived server-sanitized (SafeReturnUrl — same-origin relative
// paths only); this file navigates to it and never to anything client-derived.
// ─────────────────────────────────────────────────────────────────────────────
import { nextTick, ref, watch } from 'vue';
import AuthCard from '../../Components/Auth/AuthCard.vue';
import PasswordField from '../../Components/Auth/PasswordField.vue';
import SegmentedCodeInput from '../../Components/Auth/SegmentedCodeInput.vue';

interface Copy {
    title: string;
    emailLabel: string;
    passwordLabel: string;
    showPassword: string;
    hidePassword: string;
    remember: string;
    submit: string;
    submitting: string;
    forgot: string;
    expiredNotice: string;
    errorFallback: string;
    languageLabel: string;
    langEnglish: string;
    langAmharic: string;
    continue: string;
    challengeTitle: string;
    challengeIntro: string;
    codeLabel: string;
    useRecovery: string;
    useTotp: string;
    recoveryLabel: string;
    recoverySubmit: string;
    recoveryUsed: string;
    lowCodesBanner: string;
}

const props = defineProps<{
    challengePending: boolean;
    sessionExpired: boolean;
    returnTo: string;
    locale: string;
    copy: Copy;
}>();

type Step = 'credentials' | 'challenge' | 'done';

const step = ref<Step>(props.challengePending ? 'challenge' : 'credentials');
const busy = ref(false);
const error = ref<string | null>(null);

const email = ref('');
const password = ref('');
const remember = ref(false);

const challengeMode = ref<'totp' | 'recovery'>('totp');
const code = ref('');
const recoveryCode = ref('');
const codeInput = ref<InstanceType<typeof SegmentedCodeInput> | null>(null);

const recoveryRemaining = ref<number | null>(null);
const recoveryLow = ref(false);

const credentialsHeading = ref<HTMLElement | null>(null);
const challengeHeading = ref<HTMLElement | null>(null);
const doneHeading = ref<HTMLElement | null>(null);

// §13: focus moves to the step heading on wizard transitions. The headings
// carry tabindex="-1" — focusable by script, invisible to the tab order.
watch(step, async () => {
    await nextTick();
    const heading = {
        credentials: credentialsHeading,
        challenge: challengeHeading,
        done: doneHeading,
    }[step.value];
    heading.value?.focus();
});

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return match?.[1] ? decodeURIComponent(match[1]) : '';
}

async function post(
    path: string,
    body: Record<string, unknown>,
): Promise<{ status: number; body: Record<string, unknown> }> {
    const response = await fetch(path, {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-XSRF-TOKEN': xsrfToken(),
        },
        body: JSON.stringify(body),
    });
    const json = (await response.json().catch(() => ({}))) as Record<string, unknown>;

    return { status: response.status, body: json };
}

function detailOf(body: Record<string, unknown>): string {
    return typeof body.detail === 'string' && body.detail !== ''
        ? body.detail
        : props.copy.errorFallback;
}

function finish(body: Record<string, unknown>): void {
    if (typeof body.recovery_codes_remaining === 'number') {
        // FR-07: a recovery sign-in shows the remaining count before moving on.
        recoveryRemaining.value = body.recovery_codes_remaining;
        recoveryLow.value = body.recovery_codes_low === true;
        step.value = 'done';

        return;
    }

    window.location.assign(props.returnTo);
}

async function submitCredentials(): Promise<void> {
    if (busy.value) {
        return;
    }
    busy.value = true;
    error.value = null;

    try {
        const { status, body } = await post('/login', {
            email: email.value,
            password: password.value,
            remember: remember.value,
        });

        if (status === 200 && body.status === 'signed_in') {
            finish(body);

            return;
        }

        if (status === 401 && body.status === 'two_factor_required') {
            challengeMode.value = 'totp';
            code.value = '';
            step.value = 'challenge';

            return;
        }

        // ONE rendering for every refusal — see the file docblock (AC-01).
        error.value = detailOf(body);
    } catch {
        error.value = props.copy.errorFallback;
    } finally {
        busy.value = false;
    }
}

async function submitChallenge(payload: Record<string, unknown>): Promise<void> {
    if (busy.value) {
        return;
    }
    busy.value = true;
    error.value = null;

    try {
        const { status, body } = await post('/login/two-factor', payload);

        if (status === 200) {
            finish(body);

            return;
        }

        if (body.restart_login === true) {
            // FR-07's void (or a challenge that no longer exists): back to the
            // password, carrying the server's own explanation.
            password.value = '';
            code.value = '';
            recoveryCode.value = '';
            step.value = 'credentials';
            error.value = detailOf(body);

            return;
        }

        error.value = detailOf(body);
        code.value = '';
        codeInput.value?.focus();
    } catch {
        error.value = props.copy.errorFallback;
    } finally {
        busy.value = false;
    }
}

function switchChallengeMode(mode: 'totp' | 'recovery'): void {
    challengeMode.value = mode;
    error.value = null;
    code.value = '';
    recoveryCode.value = '';
}

function recoveryUsedText(): string {
    return props.copy.recoveryUsed.replace(':count', String(recoveryRemaining.value ?? 0));
}

function lowCodesText(): string {
    return props.copy.lowCodesBanner.replace(':count', String(recoveryRemaining.value ?? 0));
}
</script>

<template>
    <AuthCard
        :locale="locale"
        :copy="{
            forgot: copy.forgot,
            languageLabel: copy.languageLabel,
            langEnglish: copy.langEnglish,
            langAmharic: copy.langAmharic,
        }"
    >
        <Transition name="step" mode="out-in">
            <!-- ── Credentials step ─────────────────────────────────────── -->
            <section v-if="step === 'credentials'" key="credentials">
                <h1
                    ref="credentialsHeading"
                    tabindex="-1"
                    class="text-xl font-semibold focus:outline-none"
                >
                    {{ copy.title }}
                </h1>

                <p
                    v-if="sessionExpired"
                    role="status"
                    data-testid="session-expired-notice"
                    class="mt-4 rounded border border-amber-800 bg-amber-950 p-3 text-sm text-amber-200"
                >
                    {{ copy.expiredNotice }}
                </p>

                <form class="mt-6 space-y-4" novalidate @submit.prevent="submitCredentials">
                    <div>
                        <label class="block text-sm font-medium" for="login-email">
                            {{ copy.emailLabel }}
                        </label>
                        <input
                            id="login-email"
                            v-model="email"
                            type="email"
                            autocomplete="email"
                            required
                            :disabled="busy"
                            :aria-describedby="error ? 'login-error' : undefined"
                            class="mt-1 w-full rounded border border-slate-700 bg-slate-950 p-3 text-slate-100"
                        />
                    </div>

                    <PasswordField
                        v-model="password"
                        input-id="login-password"
                        :label="copy.passwordLabel"
                        autocomplete="current-password"
                        :show-label="copy.showPassword"
                        :hide-label="copy.hidePassword"
                        :described-by="error ? 'login-error' : undefined"
                    />

                    <label class="flex min-h-11 items-center gap-2 text-sm text-slate-300">
                        <input
                            v-model="remember"
                            type="checkbox"
                            :disabled="busy"
                            class="size-4 rounded border-slate-700 bg-slate-950"
                        />
                        {{ copy.remember }}
                    </label>

                    <p
                        v-if="error"
                        id="login-error"
                        role="alert"
                        data-testid="login-error"
                        class="rounded border border-red-800 bg-red-950 p-3 text-sm text-red-200"
                    >
                        {{ error }}
                    </p>

                    <button
                        type="submit"
                        :disabled="busy"
                        :aria-busy="busy"
                        data-testid="login-submit"
                        class="flex min-h-11 w-full items-center justify-center gap-2 rounded bg-slate-100 p-3 font-medium text-slate-900 disabled:opacity-60"
                    >
                        <span v-if="busy" class="spinner" aria-hidden="true"></span>
                        {{ busy ? copy.submitting : copy.submit }}
                    </button>
                </form>
            </section>

            <!-- ── 2FA challenge step ───────────────────────────────────── -->
            <section v-else-if="step === 'challenge'" key="challenge">
                <h1
                    ref="challengeHeading"
                    tabindex="-1"
                    data-testid="challenge-heading"
                    class="text-xl font-semibold focus:outline-none"
                >
                    {{ copy.challengeTitle }}
                </h1>
                <p class="mt-2 text-sm text-slate-400">{{ copy.challengeIntro }}</p>

                <p
                    v-if="error"
                    id="challenge-error"
                    role="alert"
                    data-testid="challenge-error"
                    class="mt-4 rounded border border-red-800 bg-red-950 p-3 text-sm text-red-200"
                >
                    {{ error }}
                </p>

                <div v-if="challengeMode === 'totp'" class="mt-4">
                    <SegmentedCodeInput
                        ref="codeInput"
                        v-model="code"
                        input-id="challenge-code"
                        :label="copy.codeLabel"
                        :described-by="error ? 'challenge-error' : undefined"
                        :auto-focus="true"
                        @complete="(value) => submitChallenge({ code: value })"
                    />
                    <button
                        type="button"
                        data-testid="use-recovery"
                        class="mt-4 min-h-11 text-sm text-slate-300 underline"
                        @click="switchChallengeMode('recovery')"
                    >
                        {{ copy.useRecovery }}
                    </button>
                </div>

                <form
                    v-else
                    class="mt-4 space-y-4"
                    @submit.prevent="submitChallenge({ recovery_code: recoveryCode })"
                >
                    <div>
                        <label class="block text-sm text-slate-300" for="challenge-recovery">
                            {{ copy.recoveryLabel }}
                        </label>
                        <input
                            id="challenge-recovery"
                            v-model="recoveryCode"
                            type="text"
                            autocomplete="off"
                            spellcheck="false"
                            required
                            :disabled="busy"
                            :aria-describedby="error ? 'challenge-error' : undefined"
                            class="mt-1 w-full rounded border border-slate-700 bg-slate-950 p-3 font-mono text-slate-100"
                            data-testid="recovery-input"
                        />
                    </div>
                    <button
                        type="submit"
                        :disabled="busy"
                        :aria-busy="busy"
                        data-testid="recovery-submit"
                        class="flex min-h-11 w-full items-center justify-center gap-2 rounded bg-slate-100 p-3 font-medium text-slate-900 disabled:opacity-60"
                    >
                        <span v-if="busy" class="spinner" aria-hidden="true"></span>
                        {{ copy.recoverySubmit }}
                    </button>
                    <button
                        type="button"
                        data-testid="use-totp"
                        class="min-h-11 text-sm text-slate-300 underline"
                        @click="switchChallengeMode('totp')"
                    >
                        {{ copy.useTotp }}
                    </button>
                </form>
            </section>

            <!-- ── Recovery-used interstitial (FR-07's remaining count) ─── -->
            <section v-else key="done">
                <h1
                    ref="doneHeading"
                    tabindex="-1"
                    data-testid="recovery-done-heading"
                    class="text-xl font-semibold focus:outline-none"
                >
                    {{ recoveryUsedText() }}
                </h1>
                <p
                    v-if="recoveryLow"
                    role="status"
                    data-testid="low-codes-banner"
                    class="mt-4 rounded border border-amber-800 bg-amber-950 p-3 text-sm text-amber-200"
                >
                    {{ lowCodesText() }}
                </p>
                <a
                    :href="returnTo"
                    data-testid="continue-link"
                    class="mt-6 flex min-h-11 w-full items-center justify-center rounded bg-slate-100 p-3 font-medium text-slate-900"
                >
                    {{ copy.continue }}
                </a>
            </section>
        </Transition>
    </AuthCard>
</template>

<style scoped>
/* §9: 150 ms step transitions, none under prefers-reduced-motion. */
.step-enter-active,
.step-leave-active {
    transition: opacity 150ms ease;
}

.step-enter-from,
.step-leave-to {
    opacity: 0;
}

.spinner {
    inline-size: 1rem;
    block-size: 1rem;
    border: 2px solid currentColor;
    border-inline-start-color: transparent;
    border-radius: 9999px;
    animation: spin 700ms linear infinite;
}

@media (prefers-reduced-motion: reduce) {
    .step-enter-active,
    .step-leave-active {
        transition: none;
    }

    .spinner {
        animation: none;
    }
}

@keyframes spin {
    to {
        transform: rotate(360deg);
    }
}
</style>
