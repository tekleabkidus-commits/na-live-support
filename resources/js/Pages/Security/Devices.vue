<script setup lang="ts">
// Doc 02 FR-12 — the Security page (profile → Security), and FR-06's 2FA
// enrolment as §8's three-step wizard (step 13).
//
// Every visible string arrives via the `copy` prop, translated server-side in
// the request locale (I-5/C-08, N-03) — this file contains no user-facing
// literals. Data comes from /api/staff/security/sessions on mount; the page is
// a shell (see SecurityPageController).
//
// §8 rules encoded here: current device pinned first (the API pre-sorts, the
// template trusts the order), single revoke confirms INLINE (two-step button,
// no dialog), "revoke all others" uses a modal. §9's empty state renders when
// only the current session exists; the table shows a skeleton while loading.
//
// THE WIZARD (scan QR → enter code → save codes) OWNS TWO §13 BEHAVIOURS:
// focus moves to the step heading on every transition, and the codes step
// BLOCKS completion until "I have saved these" is checked OR the file was
// downloaded (§8's gate — the OR is the specification, not a convenience).
// Regenerated codes flow through the same codes step, gate included: a
// shown-once set is a shown-once set however it was minted (SEC-03).
import { onMounted, ref } from 'vue';
import SegmentedCodeInput from '../../Components/Auth/SegmentedCodeInput.vue';
import SessionTimeoutSentinel from '../../Components/Auth/SessionTimeoutSentinel.vue';

interface SessionRow {
    digest: string;
    current: boolean;
    device_label: string;
    ip: string | null;
    location: string | null;
    country_code: string | null;
    signed_in_at: string | null;
    first_seen_at: string | null;
    last_active_at: string;
}

interface Copy {
    title: string;
    subtitle: string;
    device: string;
    firstSeen: string;
    lastActive: string;
    ip: string;
    location: string;
    currentBadge: string;
    locationUnavailable: string;
    revoke: string;
    revokeConfirm: string;
    revokeOthers: string;
    revokeOthersTitle: string;
    revokeOthersBody: string;
    cancel: string;
    onlyHere: string;
    loadFailed: string;
    twoFactorTitle: string;
    twoFactorOn: string;
    twoFactorOff: string;
    twoFactorEnable: string;
    twoFactorScan: string;
    twoFactorManualSecret: string;
    twoFactorCodeLabel: string;
    twoFactorCodesWarning: string;
    twoFactorCodesSaved: string;
    twoFactorDownload: string;
    twoFactorRegenerate: string;
    twoFactorDisable: string;
    twoFactorDisableCodeLabel: string;
    wizardScanTitle: string;
    wizardVerifyTitle: string;
    wizardCodesTitle: string;
    wizardContinue: string;
    wizardDone: string;
    copySecret: string;
    copyAllCodes: string;
    copied: string;
}

interface SentinelProps {
    email: string;
    copy: {
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
    };
}

const props = defineProps<{ copy: Copy; twoFactorEnabled: boolean; sentinel: SentinelProps }>();

const sessions = ref<SessionRow[]>([]);
const loading = ref(true);
const loadFailed = ref(false);
const confirmingDigest = ref<string | null>(null);
const showRevokeOthersModal = ref(false);
const busy = ref(false);

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return match?.[1] ? decodeURIComponent(match[1]) : '';
}

async function load(): Promise<void> {
    loadFailed.value = false;
    loading.value = true;
    try {
        const response = await fetch('/api/staff/security/sessions', {
            headers: { Accept: 'application/json' },
        });
        if (!response.ok) {
            throw new Error(String(response.status));
        }
        const body = (await response.json()) as { sessions: SessionRow[] };
        sessions.value = body.sessions;
    } catch {
        loadFailed.value = true;
    } finally {
        loading.value = false;
    }
}

async function revoke(digest: string): Promise<void> {
    // First press arms the inline confirmation (§8); second press acts.
    if (confirmingDigest.value !== digest) {
        confirmingDigest.value = digest;
        return;
    }

    busy.value = true;
    try {
        await fetch(`/api/staff/security/sessions/${digest}`, {
            method: 'DELETE',
            headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrfToken() },
        });
    } finally {
        busy.value = false;
        confirmingDigest.value = null;
        void load();
    }
}

async function revokeOthers(): Promise<void> {
    busy.value = true;
    try {
        await fetch('/api/staff/security/sessions/revoke-others', {
            method: 'POST',
            headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrfToken() },
        });
    } finally {
        busy.value = false;
        showRevokeOthersModal.value = false;
        void load();
    }
}

// C-03: timestamps arrive UTC; presentation applies the viewer's zone. §14's
// locale-aware dates come from the browser locale, which the app shell keeps
// aligned with the user's language.
function formatWhen(iso: string | null): string {
    if (!iso) {
        return '—';
    }
    return new Date(iso).toLocaleString();
}

// ── FR-06: the enrolment wizard (§8: scan → verify → codes). ────────────────
type WizardStep = 'scan' | 'verify' | 'codes';

const twoFactorOn = ref(props.twoFactorEnabled);
const wizardStep = ref<WizardStep | null>(null);
const enrolment = ref<{ secret: string; qr_svg: string; enrolment_id: string } | null>(null);
const enrolCode = ref('');
const manageCode = ref('');
const recoveryCodes = ref<string[]>([]);
const twoFactorError = ref<string | null>(null);

// §8's gate: EITHER affordance opens the door; nothing else does.
const codesSaved = ref(false);
const codesDownloaded = ref(false);

const copiedSecret = ref(false);
const copiedCodes = ref(false);

const scanHeading = ref<HTMLElement | null>(null);
const verifyHeading = ref<HTMLElement | null>(null);
const codesHeading = ref<HTMLElement | null>(null);

// §13: focus lands on the step heading at every wizard transition, so a
// screen-reader user hears where they are instead of a silent DOM swap.
// Wired to the Transition's after-enter — with mode="out-in" the incoming
// step is not in the DOM until the leave finishes, so a nextTick-after-state-
// change fires against a heading that does not exist yet (and under
// prefers-reduced-motion Vue still fires after-enter immediately).
function focusWizardHeading(): void {
    const step = wizardStep.value;
    if (step === null) {
        return;
    }
    const heading = { scan: scanHeading, verify: verifyHeading, codes: codesHeading }[step];
    heading.value?.focus();
}

async function twoFactorPost(
    path: string,
    body: Record<string, string>,
    method = 'POST',
): Promise<{ ok: boolean; body: Record<string, unknown> }> {
    twoFactorError.value = null;
    const response = await fetch(path, {
        method,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-XSRF-TOKEN': xsrfToken(),
        },
        body: JSON.stringify(body),
    });
    const json = (await response.json().catch(() => ({}))) as Record<string, unknown>;
    if (!response.ok) {
        // Problem-details `detail` is the server's already-localized copy.
        twoFactorError.value =
            typeof json.detail === 'string' ? json.detail : String(response.status);
    }
    return { ok: response.ok, body: json };
}

async function beginEnrolment(): Promise<void> {
    const { ok, body } = await twoFactorPost('/api/staff/security/2fa', {});
    if (ok) {
        enrolment.value = body as unknown as {
            secret: string;
            qr_svg: string;
            enrolment_id: string;
        };
        enrolCode.value = '';
        wizardStep.value = 'scan';
    }
}

async function confirmEnrolment(code: string): Promise<void> {
    if (!enrolment.value || busy.value) {
        return;
    }
    busy.value = true;
    try {
        const { ok, body } = await twoFactorPost('/api/staff/security/2fa/confirm', {
            code,
            enrolment_id: enrolment.value.enrolment_id,
        });
        if (ok) {
            recoveryCodes.value = (body.recovery_codes as string[] | undefined) ?? [];
            twoFactorOn.value = true;
            enrolment.value = null;
            enrolCode.value = '';
            openCodesStep();
        } else {
            enrolCode.value = '';
        }
    } finally {
        busy.value = false;
    }
}

function openCodesStep(): void {
    codesSaved.value = false;
    codesDownloaded.value = false;
    copiedCodes.value = false;
    wizardStep.value = 'codes';
}

function finishWizard(): void {
    // Unreachable while the gate holds — the button is disabled — but guarded
    // anyway: a keyboard-dispatched click on a disabled button is a browser
    // bug this screen should not turn into a silent codes loss.
    if (!codesSaved.value && !codesDownloaded.value) {
        return;
    }
    recoveryCodes.value = [];
    wizardStep.value = null;
}

async function disableTwoFactor(): Promise<void> {
    const { ok } = await twoFactorPost(
        '/api/staff/security/2fa',
        { code: manageCode.value },
        'DELETE',
    );
    if (ok) {
        twoFactorOn.value = false;
        manageCode.value = '';
        recoveryCodes.value = [];
        wizardStep.value = null;
    }
}

async function regenerateCodes(): Promise<void> {
    const { ok, body } = await twoFactorPost('/api/staff/security/2fa/recovery-codes', {
        code: manageCode.value,
    });
    if (ok) {
        recoveryCodes.value = (body.recovery_codes as string[] | undefined) ?? [];
        manageCode.value = '';
        // SEC-03: a regenerated set is also shown once — same step, same gate.
        openCodesStep();
    }
}

function downloadCodes(): void {
    const blob = new Blob([recoveryCodes.value.join('\n')], { type: 'text/plain' });
    const anchor = document.createElement('a');
    anchor.href = URL.createObjectURL(blob);
    anchor.download = 'recovery-codes.txt';
    anchor.click();
    URL.revokeObjectURL(anchor.href);
    codesDownloaded.value = true;
}

async function copySecret(): Promise<void> {
    if (!enrolment.value) {
        return;
    }
    try {
        await navigator.clipboard.writeText(enrolment.value.secret);
        copiedSecret.value = true;
    } catch {
        // Clipboard permission refused: the visible text remains selectable;
        // nothing to report beyond not flipping the "copied" feedback.
    }
}

async function copyAllCodes(): Promise<void> {
    try {
        await navigator.clipboard.writeText(recoveryCodes.value.join('\n'));
        copiedCodes.value = true;
    } catch {
        // As above — the grid itself stays selectable.
    }
}

onMounted(() => {
    void load();
});
</script>

<template>
    <main class="mx-auto min-h-screen max-w-4xl bg-slate-950 px-4 py-10 text-slate-100">
        <!-- Step 13: §8's timeout toast and EC-02's re-login modal ride every
             staff Inertia page; Doc 07's shell inherits this mount. -->
        <SessionTimeoutSentinel :email="props.sentinel.email" :copy="props.sentinel.copy" />

        <h1 class="text-2xl font-semibold">{{ props.copy.title }}</h1>
        <p class="mt-1 text-sm text-slate-400">{{ props.copy.subtitle }}</p>

        <p
            v-if="loadFailed"
            role="alert"
            data-testid="devices-load-failed"
            class="mt-6 rounded border border-red-800 bg-red-950 p-3 text-sm text-red-200"
        >
            {{ props.copy.loadFailed }}
        </p>

        <!-- §9: skeleton while the table loads — never a full-page blocker. -->
        <div
            v-else-if="loading"
            class="mt-6 space-y-3"
            data-testid="devices-skeleton"
            aria-hidden="true"
        >
            <div class="h-6 animate-pulse rounded bg-slate-800"></div>
            <div class="h-6 animate-pulse rounded bg-slate-800"></div>
            <div class="h-6 animate-pulse rounded bg-slate-800"></div>
        </div>

        <div v-else class="mt-6 overflow-x-auto">
            <table class="w-full text-start text-sm" data-testid="devices-table">
                <thead>
                    <tr class="border-b border-slate-800 text-start text-slate-400">
                        <th class="py-2 pe-4 text-start font-medium">{{ props.copy.device }}</th>
                        <th class="py-2 pe-4 text-start font-medium">{{ props.copy.location }}</th>
                        <th class="py-2 pe-4 text-start font-medium">{{ props.copy.ip }}</th>
                        <th class="py-2 pe-4 text-start font-medium">{{ props.copy.firstSeen }}</th>
                        <th class="py-2 pe-4 text-start font-medium">
                            {{ props.copy.lastActive }}
                        </th>
                        <th class="py-2 text-start"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in sessions"
                        :key="row.digest"
                        class="border-b border-slate-900"
                        :data-testid="row.current ? 'device-row-current' : 'device-row'"
                    >
                        <td class="py-3 pe-4">
                            {{ row.device_label }}
                            <span
                                v-if="row.current"
                                data-testid="current-badge"
                                class="ms-2 rounded bg-emerald-900 px-2 py-0.5 text-xs text-emerald-200"
                            >
                                {{ props.copy.currentBadge }}
                            </span>
                        </td>
                        <td class="py-3 pe-4">
                            <template v-if="row.location">{{ row.location }}</template>
                            <span v-else data-testid="location-unavailable" class="text-slate-400">
                                {{ props.copy.locationUnavailable }}
                            </span>
                        </td>
                        <td class="py-3 pe-4 font-mono text-xs">{{ row.ip ?? '—' }}</td>
                        <td class="py-3 pe-4">{{ formatWhen(row.first_seen_at) }}</td>
                        <td class="py-3 pe-4">{{ formatWhen(row.last_active_at) }}</td>
                        <td class="py-3 text-end">
                            <button
                                v-if="!row.current"
                                type="button"
                                :disabled="busy"
                                data-testid="revoke-button"
                                class="min-h-11 rounded border px-3 py-1 text-xs"
                                :class="
                                    confirmingDigest === row.digest
                                        ? 'border-red-700 bg-red-900 text-red-100'
                                        : 'border-slate-700 text-slate-300 hover:border-slate-500'
                                "
                                @click="revoke(row.digest)"
                            >
                                {{
                                    confirmingDigest === row.digest
                                        ? props.copy.revokeConfirm
                                        : props.copy.revoke
                                }}
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>

            <p
                v-if="sessions.length === 1 && sessions[0]?.current"
                data-testid="devices-empty-state"
                class="mt-4 text-sm text-slate-400"
            >
                {{ props.copy.onlyHere }}
            </p>

            <button
                v-if="sessions.length > 1"
                type="button"
                data-testid="revoke-others-button"
                class="mt-6 min-h-11 rounded border border-red-800 px-4 py-2 text-sm text-red-200 hover:bg-red-950"
                @click="showRevokeOthersModal = true"
            >
                {{ props.copy.revokeOthers }}
            </button>
        </div>

        <!-- FR-06: the 2FA card. Off → the §8 wizard (scan → verify → codes,
             gate on the codes step) → on (disable / regenerate, code-proven;
             regenerate re-enters the codes step). -->
        <section
            class="mt-10 rounded-lg border border-slate-800 bg-slate-900 p-6"
            data-testid="two-factor-card"
        >
            <h2 class="text-lg font-semibold">
                {{ props.copy.twoFactorTitle }}
                <span
                    data-testid="two-factor-status"
                    class="ms-2 rounded px-2 py-0.5 text-xs"
                    :class="
                        twoFactorOn
                            ? 'bg-emerald-900 text-emerald-200'
                            : 'bg-slate-800 text-slate-400'
                    "
                >
                    {{ twoFactorOn ? props.copy.twoFactorOn : props.copy.twoFactorOff }}
                </span>
            </h2>

            <p
                v-if="twoFactorError"
                id="two-factor-error"
                role="alert"
                data-testid="two-factor-error"
                class="mt-3 rounded border border-red-800 bg-red-950 p-2 text-sm text-red-200"
            >
                {{ twoFactorError }}
            </p>

            <!-- Off, wizard not started. -->
            <button
                v-if="!twoFactorOn && wizardStep === null"
                type="button"
                data-testid="two-factor-enable"
                class="mt-4 min-h-11 rounded border border-slate-700 px-4 py-2 text-sm text-slate-200 hover:border-slate-500"
                @click="beginEnrolment()"
            >
                {{ props.copy.twoFactorEnable }}
            </button>

            <Transition name="step" mode="out-in" @after-enter="focusWizardHeading">
                <!-- Wizard step 1: scan (QR + copyable manual secret). -->
                <div
                    v-if="wizardStep === 'scan' && enrolment"
                    key="scan"
                    class="mt-4"
                    data-testid="wizard-scan"
                >
                    <h3
                        ref="scanHeading"
                        tabindex="-1"
                        data-testid="wizard-scan-heading"
                        class="text-base font-semibold focus:outline-none"
                    >
                        {{ props.copy.wizardScanTitle }}
                    </h3>
                    <p class="mt-2 text-sm text-slate-400">{{ props.copy.twoFactorScan }}</p>
                    <!-- Server-rendered SVG from our own backend (bacon-qr-code), not
                         user-influenced markup. §13: the secret is exposed as
                         accessible TEXT beside the QR, not only as pixels. -->
                    <!-- eslint-disable vue/no-v-html -->
                    <div
                        class="mt-3 inline-block rounded bg-white p-2"
                        v-html="enrolment.qr_svg"
                    ></div>
                    <!-- eslint-enable vue/no-v-html -->
                    <p class="mt-2 text-xs text-slate-400">
                        {{ props.copy.twoFactorManualSecret }}:
                        <code class="font-mono text-slate-200" data-testid="manual-secret">{{
                            enrolment.secret
                        }}</code>
                    </p>
                    <div class="mt-2" aria-live="polite">
                        <button
                            type="button"
                            data-testid="copy-secret"
                            class="min-h-11 rounded border border-slate-700 px-3 py-1 text-xs text-slate-300"
                            @click="copySecret()"
                        >
                            {{ copiedSecret ? props.copy.copied : props.copy.copySecret }}
                        </button>
                    </div>
                    <button
                        type="button"
                        data-testid="wizard-scan-continue"
                        class="mt-4 min-h-11 rounded bg-slate-100 px-4 py-2 text-sm font-medium text-slate-900"
                        @click="wizardStep = 'verify'"
                    >
                        {{ props.copy.wizardContinue }}
                    </button>
                </div>

                <!-- Wizard step 2: verify — the same one-field segmented input
                     as the login card, auto-submitting on the 6th digit. -->
                <div
                    v-else-if="wizardStep === 'verify'"
                    key="verify"
                    class="mt-4"
                    data-testid="wizard-verify"
                >
                    <h3
                        ref="verifyHeading"
                        tabindex="-1"
                        data-testid="wizard-verify-heading"
                        class="text-base font-semibold focus:outline-none"
                    >
                        {{ props.copy.wizardVerifyTitle }}
                    </h3>
                    <div class="mt-3">
                        <!-- No auto-focus here, deliberately: §13's rule is that
                             WIZARD transitions focus the step heading (asserted
                             by the browser suite); §8's input auto-focus clause
                             belongs to the login card's challenge step. -->
                        <SegmentedCodeInput
                            v-model="enrolCode"
                            input-id="enrol-code"
                            :label="props.copy.twoFactorCodeLabel"
                            :described-by="twoFactorError ? 'two-factor-error' : undefined"
                            @complete="(value) => confirmEnrolment(value)"
                        />
                    </div>
                </div>

                <!-- Wizard step 3: the one and only showing of recovery codes
                     (SEC-03), behind §8's gate: checkbox OR download. -->
                <div
                    v-else-if="wizardStep === 'codes'"
                    key="codes"
                    class="mt-4"
                    data-testid="wizard-codes"
                >
                    <h3
                        ref="codesHeading"
                        tabindex="-1"
                        data-testid="wizard-codes-heading"
                        class="text-base font-semibold focus:outline-none"
                    >
                        {{ props.copy.wizardCodesTitle }}
                    </h3>
                    <p class="mt-2 text-sm text-amber-300">
                        {{ props.copy.twoFactorCodesWarning }}
                    </p>
                    <div
                        class="mt-3 grid grid-cols-2 gap-2 font-mono text-sm text-slate-200 sm:grid-cols-4"
                        data-testid="recovery-codes"
                    >
                        <span v-for="recoveryCode in recoveryCodes" :key="recoveryCode">{{
                            recoveryCode
                        }}</span>
                    </div>
                    <div class="mt-3 flex flex-wrap gap-3" aria-live="polite">
                        <button
                            type="button"
                            data-testid="download-codes"
                            class="min-h-11 rounded border border-slate-700 px-3 py-1 text-xs text-slate-300"
                            @click="downloadCodes()"
                        >
                            {{ props.copy.twoFactorDownload }}
                        </button>
                        <button
                            type="button"
                            data-testid="copy-all-codes"
                            class="min-h-11 rounded border border-slate-700 px-3 py-1 text-xs text-slate-300"
                            @click="copyAllCodes()"
                        >
                            {{ copiedCodes ? props.copy.copied : props.copy.copyAllCodes }}
                        </button>
                    </div>
                    <label class="mt-4 flex min-h-11 items-center gap-2 text-sm text-slate-300">
                        <input
                            v-model="codesSaved"
                            type="checkbox"
                            data-testid="codes-saved-checkbox"
                            class="size-4 rounded border-slate-700 bg-slate-950"
                        />
                        {{ props.copy.twoFactorCodesSaved }}
                    </label>
                    <button
                        type="button"
                        data-testid="wizard-finish"
                        :disabled="!codesSaved && !codesDownloaded"
                        class="mt-4 min-h-11 rounded bg-slate-100 px-4 py-2 text-sm font-medium text-slate-900 disabled:opacity-50"
                        @click="finishWizard()"
                    >
                        {{ props.copy.wizardDone }}
                    </button>
                </div>
            </Transition>

            <!-- On, wizard closed: disable / regenerate, both code-proven. -->
            <div
                v-if="twoFactorOn && wizardStep === null"
                class="mt-4"
                data-testid="two-factor-manage"
            >
                <label class="block text-sm text-slate-300" for="manage-code">
                    {{ props.copy.twoFactorDisableCodeLabel }}
                </label>
                <input
                    id="manage-code"
                    v-model="manageCode"
                    data-testid="two-factor-manage-code"
                    inputmode="numeric"
                    autocomplete="one-time-code"
                    class="mt-1 block w-40 rounded border border-slate-700 bg-slate-950 px-3 py-2 font-mono"
                />
                <div class="mt-3 flex gap-3">
                    <button
                        type="button"
                        data-testid="two-factor-regenerate"
                        class="min-h-11 rounded border border-slate-700 px-3 py-1 text-xs text-slate-300"
                        @click="regenerateCodes()"
                    >
                        {{ props.copy.twoFactorRegenerate }}
                    </button>
                    <button
                        type="button"
                        data-testid="two-factor-disable"
                        class="min-h-11 rounded border border-red-800 px-3 py-1 text-xs text-red-200"
                        @click="disableTwoFactor()"
                    >
                        {{ props.copy.twoFactorDisable }}
                    </button>
                </div>
            </div>
        </section>

        <!-- §8: "revoke all others" uses a modal. -->
        <div
            v-if="showRevokeOthersModal"
            class="fixed inset-0 flex items-center justify-center bg-black/60 p-4"
            data-testid="revoke-others-modal"
        >
            <div
                role="alertdialog"
                aria-modal="true"
                class="w-full max-w-md rounded-lg border border-slate-800 bg-slate-900 p-6"
            >
                <h2 class="text-lg font-semibold">{{ props.copy.revokeOthersTitle }}</h2>
                <p class="mt-2 text-sm text-slate-400">{{ props.copy.revokeOthersBody }}</p>
                <div class="mt-6 flex justify-end gap-3">
                    <button
                        type="button"
                        class="min-h-11 rounded border border-slate-700 px-4 py-2 text-sm text-slate-300"
                        @click="showRevokeOthersModal = false"
                    >
                        {{ props.copy.cancel }}
                    </button>
                    <button
                        type="button"
                        :disabled="busy"
                        data-testid="revoke-others-confirm"
                        class="min-h-11 rounded bg-red-800 px-4 py-2 text-sm text-red-50"
                        @click="revokeOthers()"
                    >
                        {{ props.copy.revokeOthers }}
                    </button>
                </div>
            </div>
        </div>
    </main>
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

@media (prefers-reduced-motion: reduce) {
    .step-enter-active,
    .step-leave-active {
        transition: none;
    }
}
</style>
