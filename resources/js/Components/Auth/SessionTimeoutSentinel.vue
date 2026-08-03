<script setup lang="ts">
// The session sentinel: §8's T-2 min timeout toast and EC-02's re-login modal
// (Doc 02 §8, FR-11, EC-02; owner rulings at step-13 plan approval; A-2 in
// docs/ANSWERS-FROM-DOC-AUTHOR.md).
//
// ─────────────────────────────────────────────────────────────────────────────
// SCOPE, BY OWNER RULING: this component mounts on the STAFF INERTIA SURFACE
// ONLY. It never mounts on widget surfaces, and its fetch interception is
// confined to same-origin `/api/staff/*` requests — it never wraps the bearer
// API, whose FR-20 error contract is consumed by integrations, not modals.
// Doc 07's app shell inherits this component; today its host is the Security
// page, the one authenticated Inertia page that exists.
//
// THE TOAST argues from server facts: `auth.session.deadline` returns
// `effective_seconds_remaining` computed by the server, and the toast is
// scheduled RELATIVE to receipt — client clock skew cannot move it. "Stay
// signed in" calls the same endpoint, whose traversal of the middleware stack
// is what rolls the idle clock (never the absolute one; the endpoint's test
// pins that).
//
// THE MODAL TRIGGER is interpretation A-2: Doc 01 C-10 makes a dead session's
// staff-API answer a 404 indistinguishable from a missing resource, so a bare
// status can never be the trigger. On a 401/404 from a guarded fetch, the
// sentinel probes the deadline route with the UNWRAPPED fetch: probe OK → the
// original response was genuine and passes through; probe dead → the modal
// opens, and the original request replays after re-login — the caller never
// sees the failure, which is EC-02's "preserving in-page state" made literal.
// The modal's own requests always use the unwrapped fetch (no recursion), and
// it re-arms CSRF by GETting /login first (the dead session's token died with
// it).
// ─────────────────────────────────────────────────────────────────────────────
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import PasswordField from './PasswordField.vue';
import SegmentedCodeInput from './SegmentedCodeInput.vue';

interface SentinelCopy {
    timeoutWarning: string;
    staySignedIn: string;
    reloginTitle: string;
    reloginIntro: string;
    passwordLabel: string;
    showPassword: string;
    hidePassword: string;
    submit: string;
    submitting: string;
    errorFallback: string;
    codeLabel: string;
    useRecovery: string;
    useTotp: string;
    recoveryLabel: string;
    recoverySubmit: string;
}

const props = defineProps<{
    email: string;
    copy: SentinelCopy;
}>();

const WARN_BEFORE_SECONDS = 120; // §8: the warning fires at T-2 min.

const rawFetch = window.fetch.bind(window);

const toastVisible = ref(false);
const modalVisible = ref(false);
const busy = ref(false);
const error = ref<string | null>(null);
const password = ref('');
const code = ref('');
const recoveryCode = ref('');
const challenge = ref(false);
const challengeMode = ref<'totp' | 'recovery'>('totp');
const modalHeading = ref<HTMLElement | null>(null);

let warnTimer: number | undefined;
let pendingReLogin: Promise<void> | null = null;
let resolveReLogin: (() => void) | null = null;

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return match?.[1] ? decodeURIComponent(match[1]) : '';
}

function schedule(effectiveSecondsRemaining: number): void {
    window.clearTimeout(warnTimer);
    const untilWarn = effectiveSecondsRemaining - WARN_BEFORE_SECONDS;

    if (untilWarn <= 0) {
        toastVisible.value = true;

        return;
    }

    toastVisible.value = false;
    warnTimer = window.setTimeout(() => {
        toastVisible.value = true;
    }, untilWarn * 1000);
}

async function refreshDeadline(): Promise<boolean> {
    try {
        const response = await rawFetch('/api/staff/session/deadline', {
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) {
            return false;
        }

        const body = (await response.json()) as { effective_seconds_remaining: number };
        schedule(body.effective_seconds_remaining);

        return true;
    } catch {
        // Network trouble is not evidence of session death; keep the current
        // schedule and let the next user action find out.
        return true;
    }
}

async function staySignedIn(): Promise<void> {
    const alive = await refreshDeadline();

    if (!alive) {
        // Too late — the session already expired. Straight to EC-02.
        toastVisible.value = false;
        void requireReLogin();
    }
}

function requireReLogin(): Promise<void> {
    if (pendingReLogin) {
        return pendingReLogin;
    }

    pendingReLogin = new Promise<void>((resolve) => {
        resolveReLogin = resolve;
    });

    toastVisible.value = false;
    challenge.value = false;
    challengeMode.value = 'totp';
    password.value = '';
    code.value = '';
    recoveryCode.value = '';
    error.value = null;
    modalVisible.value = true;

    // The dead session took its CSRF token with it; a guest GET of /login
    // re-arms the cookie the modal's POST needs. Fire-and-forget: the POST
    // reads the cookie at submit time.
    void rawFetch('/login', { headers: { Accept: 'text/html' } });

    void nextTick(() => {
        modalHeading.value?.focus();
    });

    return pendingReLogin;
}

function completeReLogin(): void {
    modalVisible.value = false;
    resolveReLogin?.();
    pendingReLogin = null;
    resolveReLogin = null;
    void refreshDeadline();
}

async function modalPost(
    path: string,
    body: Record<string, unknown>,
): Promise<{ status: number; body: Record<string, unknown> }> {
    const response = await rawFetch(path, {
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

async function submitPassword(): Promise<void> {
    if (busy.value) {
        return;
    }
    busy.value = true;
    error.value = null;

    try {
        const { status, body } = await modalPost('/login', {
            email: props.email,
            password: password.value,
        });

        if (status === 200 && body.status === 'signed_in') {
            completeReLogin();

            return;
        }

        if (status === 401 && body.status === 'two_factor_required') {
            challenge.value = true;
            code.value = '';

            return;
        }

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
        const { status, body } = await modalPost('/login/two-factor', payload);

        if (status === 200) {
            completeReLogin();

            return;
        }

        if (body.restart_login === true) {
            challenge.value = false;
            password.value = '';
            code.value = '';
            recoveryCode.value = '';
            error.value = detailOf(body);

            return;
        }

        error.value = detailOf(body);
        code.value = '';
    } catch {
        error.value = props.copy.errorFallback;
    } finally {
        busy.value = false;
    }
}

function isGuardedRequest(input: RequestInfo | URL): boolean {
    const raw =
        typeof input === 'string' ? input : input instanceof URL ? input.href : input.url;
    let url: URL;
    try {
        url = new URL(raw, window.location.origin);
    } catch {
        return false;
    }

    // The ruled scope: same-origin staff API only. The bearer surface shares
    // the /api prefix but never runs in this page context; the path check
    // keeps the boundary structural rather than circumstantial.
    return url.origin === window.location.origin && url.pathname.startsWith('/api/staff/');
}

async function guardedFetch(
    input: RequestInfo | URL,
    init?: RequestInit,
): Promise<Response> {
    const response = await rawFetch(input, init);

    if ((response.status !== 401 && response.status !== 404) || !isGuardedRequest(input)) {
        return response;
    }

    // A-2: confirm before modal-ing. A live session answering the probe means
    // the 404 was a genuine answer about a genuinely missing thing.
    try {
        const probe = await rawFetch('/api/staff/session/deadline', {
            headers: { Accept: 'application/json' },
        });
        if (probe.ok) {
            return response;
        }
    } catch {
        return response;
    }

    await requireReLogin();

    return rawFetch(input, init);
}

onMounted(() => {
    window.fetch = guardedFetch;
    void refreshDeadline();
});

onBeforeUnmount(() => {
    window.fetch = rawFetch;
    window.clearTimeout(warnTimer);
});
</script>

<template>
    <div>
        <!-- §8: the T-2 min warning toast. -->
        <div
            v-if="toastVisible"
            role="alert"
            data-testid="timeout-toast"
            class="fixed bottom-4 inset-inline-4 z-40 mx-auto flex max-w-md items-center justify-between gap-3 rounded-lg border border-amber-800 bg-amber-950 p-4 text-sm text-amber-100 shadow-lg sm:inset-inline-auto sm:end-4"
        >
            <span>{{ copy.timeoutWarning }}</span>
            <button
                type="button"
                data-testid="stay-signed-in"
                class="min-h-11 shrink-0 rounded border border-amber-700 px-3 py-1 font-medium text-amber-100 hover:bg-amber-900"
                @click="staySignedIn()"
            >
                {{ copy.staySignedIn }}
            </button>
        </div>

        <!-- EC-02: the re-login modal. Everything behind it stays mounted —
             that is the "preserving in-page state" clause, structurally. -->
        <div
            v-if="modalVisible"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4"
            data-testid="relogin-modal"
        >
            <div
                role="dialog"
                aria-modal="true"
                aria-labelledby="relogin-title"
                class="w-full max-w-sm rounded-lg border border-slate-800 bg-slate-900 p-6 text-slate-100"
            >
                <h2
                    id="relogin-title"
                    ref="modalHeading"
                    tabindex="-1"
                    class="text-lg font-semibold focus:outline-none"
                >
                    {{ copy.reloginTitle }}
                </h2>
                <p class="mt-2 text-sm text-slate-400">{{ copy.reloginIntro }}</p>

                <p
                    v-if="error"
                    id="relogin-error"
                    role="alert"
                    data-testid="relogin-error"
                    class="mt-4 rounded border border-red-800 bg-red-950 p-3 text-sm text-red-200"
                >
                    {{ error }}
                </p>

                <form
                    v-if="!challenge"
                    class="mt-4 space-y-4"
                    novalidate
                    @submit.prevent="submitPassword"
                >
                    <PasswordField
                        v-model="password"
                        input-id="relogin-password"
                        :label="copy.passwordLabel"
                        autocomplete="current-password"
                        :show-label="copy.showPassword"
                        :hide-label="copy.hidePassword"
                        :described-by="error ? 'relogin-error' : undefined"
                    />
                    <button
                        type="submit"
                        :disabled="busy"
                        :aria-busy="busy"
                        data-testid="relogin-submit"
                        class="min-h-11 w-full rounded bg-slate-100 p-3 font-medium text-slate-900 disabled:opacity-60"
                    >
                        {{ busy ? copy.submitting : copy.submit }}
                    </button>
                </form>

                <div v-else-if="challengeMode === 'totp'" class="mt-4">
                    <SegmentedCodeInput
                        v-model="code"
                        input-id="relogin-code"
                        :label="copy.codeLabel"
                        :described-by="error ? 'relogin-error' : undefined"
                        :auto-focus="true"
                        @complete="(value) => submitChallenge({ code: value })"
                    />
                    <button
                        type="button"
                        class="mt-4 min-h-11 text-sm text-slate-300 underline"
                        @click="challengeMode = 'recovery'"
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
                        <label class="block text-sm text-slate-300" for="relogin-recovery">
                            {{ copy.recoveryLabel }}
                        </label>
                        <input
                            id="relogin-recovery"
                            v-model="recoveryCode"
                            type="text"
                            autocomplete="off"
                            spellcheck="false"
                            required
                            :aria-describedby="error ? 'relogin-error' : undefined"
                            class="mt-1 w-full rounded border border-slate-700 bg-slate-950 p-3 font-mono text-slate-100"
                        />
                    </div>
                    <button
                        type="submit"
                        :disabled="busy"
                        :aria-busy="busy"
                        class="min-h-11 w-full rounded bg-slate-100 p-3 font-medium text-slate-900 disabled:opacity-60"
                    >
                        {{ copy.recoverySubmit }}
                    </button>
                    <button
                        type="button"
                        class="min-h-11 text-sm text-slate-300 underline"
                        @click="challengeMode = 'totp'"
                    >
                        {{ copy.useTotp }}
                    </button>
                </form>
            </div>
        </div>
    </div>
</template>
