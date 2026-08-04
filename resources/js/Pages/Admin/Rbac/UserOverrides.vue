<script setup lang="ts">
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import BackofficeShell from '../../../Components/Rbac/BackofficeShell.vue';
import CountdownChip from '../../../Components/Rbac/CountdownChip.vue';
import VerdictChip from '../../../Components/Rbac/VerdictChip.vue';

// B6-S09 — the user override editor: current role (link), the
// effective-permissions explorer (any key → verdict + WHY: source, granter,
// expiry), the add-exception flow (permission → allow/deny → optional
// expiry → MANDATORY ≥10-char reason), active overrides with countdown
// chips, "No exceptions" empty state; 375 both themes.
interface OverrideRow {
    id: string;
    permission: string;
    effect: string;
    expires_at: string | null;
    granted_by: string;
    reason: string;
}
const props = defineProps<{
    can: Record<string, boolean>;
    copy: Record<string, string>;
    chrome: Record<string, string>;
    initials: string;
    subject: { id: string; name: string; role: { id: string; name: string; color: string } | null };
    overrides: OverrideRow[];
    catalog: {
        module: string;
        module_label: string;
        keys: { key: string; label: string; high: boolean }[];
    }[];
}>();

const chromeProps = computed(() => ({
    tenantLogo: props.chrome.tenant_logo ?? '',
    search: props.chrome.search ?? '',
    notifications: props.chrome.notifications ?? '',
    online: props.chrome.online ?? '',
    backoffice: props.chrome.backoffice ?? '',
    people: props.chrome.people ?? '',
    users: props.chrome.users ?? '',
    roles: props.chrome.roles ?? '',
}));

const allKeys = computed(() => props.catalog.flatMap((m) => m.keys.map((k) => k.key)));

// The explorer.
const explorerKey = ref('');
const verdict = ref<{
    allowed: boolean;
    source: string;
    granted_by: string | null;
    expires_at: string | null;
} | null>(null);
async function explore(): Promise<void> {
    verdict.value = null;
    if (!allKeys.value.includes(explorerKey.value)) return;
    const response = await fetch(
        `/api/staff/admin/users/${props.subject.id}/effective?key=${encodeURIComponent(explorerKey.value)}`,
        {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        },
    );
    if (response.ok)
        verdict.value = ((await response.json()) as { verdict: typeof verdict.value }).verdict;
}
const verdictWhy = computed(() => {
    if (!verdict.value) return '';
    const v = verdict.value;
    let why =
        v.source === 'role'
            ? props.copy.verdict_allowed_role
            : v.source === 'override_allow'
              ? props.copy.verdict_allowed_override
              : v.source === 'override_deny'
                ? props.copy.verdict_denied_override
                : props.copy.verdict_denied_implicit;
    if (v.expires_at)
        why += ` · ${(props.copy.verdict_expires ?? '').replace(':date', new Date(v.expires_at).toLocaleDateString())}`;
    return why;
});

// The add-exception flow.
const form = ref({ permission: '', effect: 'allow', expires_at: '', reason: '' });
const formError = ref('');
const pendingNote = ref(false);
async function submit(): Promise<void> {
    formError.value = '';
    pendingNote.value = false;
    const response = await fetch(`/api/staff/admin/users/${props.subject.id}/overrides`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({
            permission: form.value.permission,
            effect: form.value.effect,
            reason: form.value.reason,
            expires_at: form.value.expires_at || null,
        }),
    });
    if (response.status === 422 || response.status === 403) {
        formError.value = ((await response.json()) as { detail?: string }).detail ?? '!';
        return;
    }
    if (response.ok) {
        const body = (await response.json()) as { pending: boolean };
        if (body.pending) {
            pendingNote.value = true;
            return;
        }
        router.reload();
    }
}

async function removeOverride(id: string): Promise<void> {
    const response = await fetch(`/api/staff/admin/users/${props.subject.id}/overrides/${id}`, {
        method: 'DELETE',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    });
    if (response.ok) router.reload();
}
</script>

<template>
    <BackofficeShell
        :title="subject.name"
        :breadcrumb="copy.breadcrumb ?? ''"
        permission-chip="admin.overrides.manage"
        :chrome="chromeProps"
        :initials="initials"
    >
        <div class="mt-3 grid gap-4 lg:grid-cols-2">
            <!-- Explorer (H02/H08) -->
            <section
                class="rounded-lg border p-3.5"
                :style="{ background: 'var(--ds-panel-bg)', borderColor: 'var(--ds-border)' }"
                :aria-label="copy.explorer_label"
            >
                <div class="text-[11.5px]" :style="{ color: 'var(--ds-text-muted)' }">
                    {{ copy.current_role }}:
                    <span class="font-semibold" :style="{ color: 'var(--ds-text-primary)' }">{{
                        subject.role?.name ?? '—'
                    }}</span>
                </div>
                <label class="mt-3 block text-[12px] font-semibold" for="explorer-key">{{
                    copy.explorer_label
                }}</label>
                <div class="mt-1 flex gap-2">
                    <input
                        id="explorer-key"
                        v-model="explorerKey"
                        list="rbac-keys"
                        class="w-full rounded-md border px-2.5 py-1.5 font-mono text-[12px]"
                        :style="{
                            borderColor: 'var(--ds-border-strong)',
                            background: 'var(--ds-panel-soft)',
                            color: 'var(--ds-text-primary)',
                        }"
                        :placeholder="copy.explorer_placeholder"
                        data-testid="explorer-input"
                        @keydown.enter.prevent="explore"
                    />
                    <datalist id="rbac-keys">
                        <option v-for="key in allKeys" :key="key" :value="key"></option>
                    </datalist>
                    <button
                        type="button"
                        class="min-h-9 rounded-md px-3 text-[12px] font-semibold"
                        :style="{
                            background: 'var(--ds-accent)',
                            color: 'var(--ds-accent-contrast)',
                        }"
                        data-testid="explorer-check"
                        @click="explore"
                    >
                        ⏎
                    </button>
                </div>
                <div v-if="verdict" class="mt-3" data-testid="explorer-verdict">
                    <VerdictChip :allowed="verdict.allowed" :why="verdictWhy ?? ''" />
                </div>
            </section>

            <!-- Add an exception (§8 flow) -->
            <section
                v-if="can['admin.overrides.manage']"
                class="rounded-lg border p-3.5"
                :style="{ background: 'var(--ds-panel-bg)', borderColor: 'var(--ds-border)' }"
                :aria-label="copy.add"
            >
                <h2 class="text-[12.5px] font-semibold">{{ copy.add }}</h2>
                <div class="mt-2 space-y-2 text-[12px]">
                    <input
                        v-model="form.permission"
                        list="rbac-keys"
                        class="w-full rounded-md border px-2.5 py-1.5 font-mono"
                        :style="{
                            borderColor: 'var(--ds-border-strong)',
                            background: 'var(--ds-panel-soft)',
                            color: 'var(--ds-text-primary)',
                        }"
                        :placeholder="copy.explorer_placeholder"
                        :aria-label="copy.explorer_placeholder"
                        data-testid="override-permission"
                    />
                    <div role="radiogroup" class="flex gap-2" :aria-label="copy.add">
                        <label class="flex min-h-9 items-center gap-1.5">
                            <input v-model="form.effect" type="radio" value="allow" />
                            {{ copy.effect_allow }}
                        </label>
                        <label class="flex min-h-9 items-center gap-1.5">
                            <input
                                v-model="form.effect"
                                type="radio"
                                value="deny"
                                data-testid="override-deny"
                            />
                            {{ copy.effect_deny }}
                        </label>
                    </div>
                    <label class="block">
                        <span :style="{ color: 'var(--ds-text-muted)' }">{{
                            copy.expiry_label
                        }}</span>
                        <input
                            v-model="form.expires_at"
                            type="datetime-local"
                            class="mt-1 w-full rounded-md border px-2.5 py-1.5"
                            :style="{
                                borderColor: 'var(--ds-border-strong)',
                                background: 'var(--ds-panel-soft)',
                                color: 'var(--ds-text-primary)',
                            }"
                        />
                    </label>
                    <label class="block">
                        <span :style="{ color: 'var(--ds-text-muted)' }">{{
                            copy.reason_label
                        }}</span>
                        <textarea
                            v-model="form.reason"
                            rows="2"
                            class="mt-1 w-full rounded-md border px-2.5 py-1.5"
                            :style="{
                                borderColor: 'var(--ds-border-strong)',
                                background: 'var(--ds-panel-soft)',
                                color: 'var(--ds-text-primary)',
                            }"
                            data-testid="override-reason"
                        ></textarea>
                    </label>
                    <p
                        v-if="formError"
                        class="text-[11.5px]"
                        :style="{ color: 'var(--ds-danger-text)' }"
                        role="alert"
                        data-testid="override-error"
                    >
                        {{ formError }}
                    </p>
                    <p
                        v-if="pendingNote"
                        class="text-[11.5px]"
                        :style="{ color: 'var(--ds-warn-text)' }"
                        role="status"
                    >
                        {{ copy.pending_note }}
                    </p>
                    <button
                        type="button"
                        class="min-h-9 rounded-md px-3.5 font-semibold"
                        :style="{
                            background: 'var(--ds-accent)',
                            color: 'var(--ds-accent-contrast)',
                        }"
                        data-testid="override-submit"
                        @click="submit"
                    >
                        {{ copy.submit }}
                    </button>
                </div>
            </section>
        </div>

        <!-- Active overrides with countdown chips (H05/H10) -->
        <section
            class="mt-4 rounded-lg border"
            :style="{ background: 'var(--ds-panel-bg)', borderColor: 'var(--ds-border)' }"
            :aria-label="copy.active_title"
        >
            <h2
                class="border-b px-3.5 py-2 text-[12.5px] font-semibold"
                :style="{ borderColor: 'var(--ds-border-soft)' }"
            >
                {{ copy.active_title }}
            </h2>
            <p
                v-if="overrides.length === 0"
                class="px-3.5 py-3 text-[12px]"
                :style="{ color: 'var(--ds-text-muted)' }"
                data-testid="overrides-empty"
            >
                {{ copy.empty }}
            </p>
            <ul v-else>
                <li
                    v-for="override in overrides"
                    :key="override.id"
                    class="flex flex-wrap items-center gap-2 border-b px-3.5 py-2.5 text-[12px] last:border-b-0"
                    :style="{ borderColor: 'var(--ds-border-soft)' }"
                    :data-testid="`override-row-${override.permission}`"
                >
                    <span class="font-mono text-[11.5px]">{{ override.permission }}</span>
                    <VerdictChip
                        :allowed="override.effect === 'allow'"
                        :why="
                            (override.effect === 'allow' ? copy.effect_allow : copy.effect_deny) ??
                            ''
                        "
                    />
                    <CountdownChip
                        v-if="override.expires_at"
                        :expires-at="override.expires_at"
                        :prefix="copy.expires_chip ?? ''"
                    />
                    <span class="text-[11.5px]" :style="{ color: 'var(--ds-text-muted)' }">{{
                        override.reason
                    }}</span>
                    <button
                        v-if="can['admin.overrides.manage']"
                        type="button"
                        class="ms-auto text-[11.5px] font-medium underline-offset-2 hover:underline"
                        :style="{ color: 'var(--ds-danger-text)' }"
                        :data-testid="`override-remove-${override.permission}`"
                        @click="removeOverride(override.id)"
                    >
                        {{ copy.remove }}
                    </button>
                </li>
            </ul>
        </section>
    </BackofficeShell>
</template>
