<script setup lang="ts">
// Doc 02 FR-12 — the Security page (profile → Security).
//
// Every visible string arrives via the `copy` prop, translated server-side in
// the request locale (I-5/C-08, N-03) — this file contains no user-facing
// literals. Data comes from /api/staff/security/sessions on mount; the page is
// a shell (see SecurityPageController).
//
// §8 rules encoded here: current device pinned first (the API pre-sorts, the
// template trusts the order), single revoke confirms INLINE (two-step button,
// no dialog), "revoke all others" uses a modal. §9's empty state renders when
// only the current session exists.
import { onMounted, ref } from 'vue';

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
}

const props = defineProps<{ copy: Copy }>();

const sessions = ref<SessionRow[]>([]);
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

onMounted(() => {
    void load();
});
</script>

<template>
    <main class="mx-auto min-h-screen max-w-4xl bg-slate-950 px-4 py-10 text-slate-100">
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
                            <span v-else data-testid="location-unavailable" class="text-slate-500">
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
                                class="rounded border px-3 py-1 text-xs"
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
                class="mt-4 text-sm text-slate-500"
            >
                {{ props.copy.onlyHere }}
            </p>

            <button
                v-if="sessions.length > 1"
                type="button"
                data-testid="revoke-others-button"
                class="mt-6 rounded border border-red-800 px-4 py-2 text-sm text-red-200 hover:bg-red-950"
                @click="showRevokeOthersModal = true"
            >
                {{ props.copy.revokeOthers }}
            </button>
        </div>

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
                        class="rounded border border-slate-700 px-4 py-2 text-sm text-slate-300"
                        @click="showRevokeOthersModal = false"
                    >
                        {{ props.copy.cancel }}
                    </button>
                    <button
                        type="button"
                        :disabled="busy"
                        data-testid="revoke-others-confirm"
                        class="rounded bg-red-800 px-4 py-2 text-sm text-red-50"
                        @click="revokeOthers()"
                    >
                        {{ props.copy.revokeOthers }}
                    </button>
                </div>
            </div>
        </div>
    </main>
</template>
