<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import BackofficeShell from '../../../Components/Rbac/BackofficeShell.vue';
import RiskBadge from '../../../Components/Rbac/RiskBadge.vue';
import ScopeSegment from '../../../Components/Rbac/ScopeSegment.vue';
import TriStateCheckbox from '../../../Components/Rbac/TriStateCheckbox.vue';

// B6-S08 — the permission matrix: per-module scope bar (G04), tri-state
// select-all with aria-checked="mixed" (G05), registry-driven key rows with
// shield badges (G06), live search (G08), right summary (G09), keyboard map
// (G21: arrows move, space toggles, / focuses search, Esc closes), impact
// modal with diff + focus trap, unsaved guard, accordion ≤1024, 375
// read-only. EC-03's version rides every save; four-eyes pendings surface
// after it.
interface CatalogKey {
    key: string;
    label: string;
    description: string;
    high: boolean;
    scoped: boolean;
}
interface CatalogModule {
    module: string;
    module_label: string;
    keys: CatalogKey[];
}
const props = defineProps<{
    can: Record<string, boolean>;
    copy: Record<string, string>;
    chrome: Record<string, string>;
    initials: string;
    role: {
        id: string;
        name: string;
        description: string | null;
        color: string;
        is_system: boolean;
        version: number;
        permissions: string[];
        scopes: Record<string, string>;
        users_count: number;
    };
    catalog: CatalogModule[];
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

const editable = computed(() => props.can['admin.roles.manage'] === true);
const selected = ref(new Set(props.role.permissions));
const scopes = ref<Record<string, string>>({ ...props.role.scopes });
const search = ref('');
const searchBox = ref<HTMLInputElement | null>(null);
const showImpact = ref(false);
const impactRef = ref<HTMLElement | null>(null);
const saving = ref(false);
const pendingKeys = ref<string[]>([]);
const conflictWinner = ref<string[] | null>(null);
const expanded = ref(new Set(props.catalog.map((m) => m.module)));
const focusedCell = ref<string | null>(null);

function fill(template: string, values: Record<string, string | number>): string {
    return Object.entries(values).reduce(
        (out, [k, v]) => out.replaceAll(`:${k}`, String(v)),
        template,
    );
}

const dirty = computed(
    () =>
        JSON.stringify([...selected.value].sort()) !==
            JSON.stringify([...props.role.permissions].sort()) ||
        JSON.stringify(scopes.value) !== JSON.stringify(props.role.scopes),
);

const filtered = computed(() =>
    props.catalog
        .map((module) => ({
            ...module,
            keys: module.keys.filter(
                (k) =>
                    search.value === '' ||
                    k.key.includes(search.value.toLowerCase()) ||
                    k.label.toLowerCase().includes(search.value.toLowerCase()),
            ),
        }))
        .filter((module) => module.keys.length > 0),
);

const summary = computed(() => {
    const high = props.catalog
        .flatMap((m) => m.keys)
        .filter((k) => k.high && selected.value.has(k.key)).length;
    return fill(props.copy.summary ?? '', {
        permissions: selected.value.size,
        high,
        people: props.role.users_count,
    });
});

const added = computed(() =>
    [...selected.value].filter((k) => !props.role.permissions.includes(k)).sort(),
);
const removed = computed(() => props.role.permissions.filter((k) => !selected.value.has(k)).sort());

function moduleState(module: CatalogModule): 'checked' | 'unchecked' | 'mixed' {
    const on = module.keys.filter((k) => selected.value.has(k.key)).length;
    if (on === 0) return 'unchecked';
    if (on === module.keys.length) return 'checked';
    return 'mixed';
}

function toggleModule(module: CatalogModule): void {
    if (!editable.value) return;
    const state = moduleState(module);
    const next = new Set(selected.value);
    for (const k of module.keys) {
        if (state === 'checked') {
            next.delete(k.key);
        } else {
            next.add(k.key);
        }
    }
    selected.value = next;
}

function toggleExpanded(module: string): void {
    if (expanded.value.has(module)) {
        expanded.value.delete(module);
    } else {
        expanded.value.add(module);
    }
}

function toggleKey(key: string): void {
    if (!editable.value) return;
    const next = new Set(selected.value);
    if (next.has(key)) {
        next.delete(key);
    } else {
        next.add(key);
    }
    selected.value = next;
}

// §9's keyboard map over the matrix cells.
const cellOrder = computed(() => filtered.value.flatMap((m) => m.keys.map((k) => k.key)));
function onMatrixKeydown(event: KeyboardEvent): void {
    if (event.key === '/') {
        event.preventDefault();
        searchBox.value?.focus();
        return;
    }
    const order = cellOrder.value;
    const index = focusedCell.value ? order.indexOf(focusedCell.value) : -1;
    if (event.key === 'ArrowDown' || event.key === 'ArrowRight') {
        event.preventDefault();
        focusCell(order[Math.min(index + 1, order.length - 1)] ?? order[0]);
    } else if (event.key === 'ArrowUp' || event.key === 'ArrowLeft') {
        event.preventDefault();
        focusCell(order[Math.max(index - 1, 0)] ?? order[0]);
    } else if (event.key === ' ' && focusedCell.value) {
        event.preventDefault();
        toggleKey(focusedCell.value);
    }
}
function focusCell(key: string | undefined): void {
    if (!key) return;
    focusedCell.value = key;
    document.querySelector<HTMLElement>(`[data-cell="${key}"]`)?.focus();
}

// Unsaved-changes guard (§8).
function beforeUnload(event: BeforeUnloadEvent): void {
    if (dirty.value) event.preventDefault();
}
onMounted(() => window.addEventListener('beforeunload', beforeUnload));
onBeforeUnmount(() => window.removeEventListener('beforeunload', beforeUnload));

// Impact modal focus trap (§13).
function trapFocus(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
        showImpact.value = false;
        return;
    }
    if (event.key !== 'Tab' || !impactRef.value) return;
    const focusables = impactRef.value.querySelectorAll<HTMLElement>('button, [href], input');
    const first = focusables[0];
    const last = focusables[focusables.length - 1];
    if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last?.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first?.focus();
    }
}

async function save(): Promise<void> {
    saving.value = true;
    conflictWinner.value = null;
    const response = await fetch(`/api/staff/admin/roles/${props.role.id}`, {
        method: 'PUT',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            Accept: 'application/json',
        },
        body: JSON.stringify({
            name: props.role.name,
            description: props.role.description,
            color: props.role.color,
            permissions: [...selected.value],
            scopes: scopes.value,
            version: props.role.version,
        }),
    });
    saving.value = false;
    showImpact.value = false;
    if (response.status === 409) {
        const body = (await response.json()) as { winner?: { permissions?: string[] } };
        conflictWinner.value = body.winner?.permissions ?? [];
        return;
    }
    if (response.ok) {
        const body = (await response.json()) as { pending?: string[] };
        pendingKeys.value = body.pending ?? [];
        window.location.reload();
    }
}

const scopeOptions = computed(() => [
    { value: 'Own', label: props.copy.scope_own ?? '' },
    { value: 'Team', label: props.copy.scope_team ?? '' },
    { value: 'Dept', label: props.copy.scope_dept ?? '' },
    { value: 'All', label: props.copy.scope_all ?? '' },
]);
const scopedModules = computed(() => props.catalog.filter((m) => m.keys.some((k) => k.scoped)));
const totalKeyCount = computed(() => props.catalog.reduce((n, m) => n + m.keys.length, 0));
</script>

<template>
    <BackofficeShell
        :title="role.name"
        :breadcrumb="copy.breadcrumb ?? ''"
        permission-chip="admin.roles.manage"
        :chrome="chromeProps"
        :initials="initials"
    >
        <!-- 375 read-only notice (§9's drawn ruling) -->
        <div
            class="mt-3 rounded-lg border px-3.5 py-2.5 text-[12px] md:hidden"
            :style="{
                background: 'var(--ds-panel-soft)',
                borderColor: 'var(--ds-border)',
                color: 'var(--ds-text-secondary)',
            }"
            role="note"
            data-testid="phone-readonly-notice"
        >
            <div class="font-semibold" :style="{ color: 'var(--ds-text-primary)' }">
                {{ copy.phone_notice }}
            </div>
            {{ fill(copy.phone_notice_body ?? '', { count: totalKeyCount }) }}
        </div>

        <div class="mt-3 flex gap-4 max-md:hidden">
            <div class="min-w-0 flex-1">
                <!-- G04: per-module scope bar -->
                <section
                    class="rounded-lg border p-3.5"
                    :style="{ background: 'var(--ds-panel-bg)', borderColor: 'var(--ds-border)' }"
                    :aria-label="copy.scope_title"
                >
                    <h2 class="text-[12.5px] font-semibold">{{ copy.scope_title }}</h2>
                    <div class="mt-2 grid gap-3 lg:grid-cols-2">
                        <div v-for="module in scopedModules" :key="module.module">
                            <div
                                class="text-[11px] font-semibold uppercase tracking-wider"
                                :style="{ color: 'var(--ds-text-label)' }"
                            >
                                {{ module.module_label }}
                            </div>
                            <ScopeSegment
                                class="mt-1"
                                :model-value="scopes[module.module] ?? null"
                                :options="scopeOptions"
                                :helper="
                                    fill(copy.scope_helper ?? '', {
                                        module: module.module_label,
                                        scope:
                                            scopeOptions.find(
                                                (o) => o.value === (scopes[module.module] ?? ''),
                                            )?.label ?? '—',
                                    })
                                "
                                :disabled="!editable"
                                @update:model-value="
                                    (value) => (scopes = { ...scopes, [module.module]: value })
                                "
                            />
                        </div>
                    </div>
                </section>

                <!-- G05/G06/G08: the matrix -->
                <section
                    class="mt-3 rounded-lg border"
                    :style="{ background: 'var(--ds-panel-bg)', borderColor: 'var(--ds-border)' }"
                    @keydown="onMatrixKeydown"
                >
                    <div class="border-b p-2.5" :style="{ borderColor: 'var(--ds-border-soft)' }">
                        <input
                            ref="searchBox"
                            v-model="search"
                            type="search"
                            class="w-full rounded-md border px-2.5 py-1.5 text-[12px]"
                            :style="{
                                borderColor: 'var(--ds-border-strong)',
                                background: 'var(--ds-panel-soft)',
                                color: 'var(--ds-text-primary)',
                            }"
                            :placeholder="copy.search"
                            :aria-label="copy.search"
                            data-testid="matrix-search"
                        />
                        <div class="mt-1 text-[10.5px]" :style="{ color: 'var(--ds-text-faint)' }">
                            {{ copy.keyboard_hint }}
                        </div>
                    </div>

                    <div v-for="module in filtered" :key="module.module">
                        <!-- Module header row: tri-state select-all; ≤1024 the header is the accordion toggle -->
                        <div
                            class="sticky top-0 flex items-center gap-2.5 border-b px-3.5 py-2"
                            :style="{
                                background: 'var(--ds-panel-soft)',
                                borderColor: 'var(--ds-border-soft)',
                            }"
                        >
                            <TriStateCheckbox
                                :state="moduleState(module)"
                                :label="
                                    fill(copy.select_all ?? '', { module: module.module_label })
                                "
                                :disabled="!editable"
                                @toggle="toggleModule(module)"
                            />
                            <button
                                type="button"
                                class="flex flex-1 items-center gap-2 text-start text-[12px] font-semibold"
                                :aria-expanded="expanded.has(module.module)"
                                @click="toggleExpanded(module.module)"
                            >
                                {{ module.module_label }}
                                <span class="lg:hidden" aria-hidden="true">{{
                                    expanded.has(module.module) ? '▾' : '▸'
                                }}</span>
                            </button>
                        </div>
                        <ul
                            v-show="expanded.has(module.module)"
                            class="max-lg:border-b"
                            :style="{ borderColor: 'var(--ds-border-soft)' }"
                        >
                            <li
                                v-for="entry in module.keys"
                                :key="entry.key"
                                class="flex items-center gap-2.5 border-b px-3.5 py-2 last:border-b-0"
                                :style="{ borderColor: 'var(--ds-border-soft)' }"
                            >
                                <button
                                    type="button"
                                    role="checkbox"
                                    :aria-checked="selected.has(entry.key)"
                                    :aria-label="entry.label"
                                    :disabled="!editable"
                                    :data-cell="entry.key"
                                    :data-testid="`matrix-cell-${entry.key}`"
                                    tabindex="0"
                                    class="inline-flex size-[15px] flex-none items-center justify-center rounded-[3px] border-[1.5px] text-[10px] focus-visible:outline focus-visible:outline-2"
                                    :style="{
                                        borderColor: selected.has(entry.key)
                                            ? 'var(--ds-accent)'
                                            : 'var(--ds-border-strong)',
                                        background: selected.has(entry.key)
                                            ? 'var(--ds-accent)'
                                            : 'var(--ds-panel-bg)',
                                        color: 'var(--ds-accent-contrast)',
                                        outlineColor: 'var(--ds-focus-ring)',
                                    }"
                                    @click="toggleKey(entry.key)"
                                    @focus="focusedCell = entry.key"
                                >
                                    <span v-if="selected.has(entry.key)" aria-hidden="true">✓</span>
                                </button>
                                <span
                                    class="text-[11.5px]"
                                    :style="{
                                        color: selected.has(entry.key)
                                            ? 'var(--ds-text-primary)'
                                            : 'var(--ds-text-muted)',
                                    }"
                                    :title="entry.description"
                                >
                                    {{ entry.label }}
                                </span>
                                <RiskBadge
                                    v-if="entry.high"
                                    :label="copy.high_risk_badge ?? 'high-risk'"
                                />
                                <span
                                    class="ms-auto font-mono text-[10px]"
                                    :style="{ color: 'var(--ds-text-faint)' }"
                                    >{{ entry.key }}</span
                                >
                            </li>
                        </ul>
                    </div>
                </section>
            </div>

            <!-- G09: the summary sidebar -->
            <aside class="w-56 flex-none max-lg:hidden" :aria-label="summary">
                <div
                    class="rounded-lg border p-3.5"
                    :style="{ background: 'var(--ds-panel-bg)', borderColor: 'var(--ds-border)' }"
                >
                    <div class="text-[12px] font-semibold" data-testid="matrix-summary">
                        {{ summary }}
                    </div>
                    <button
                        v-if="editable"
                        type="button"
                        class="mt-3 min-h-9 w-full rounded-md text-[12.5px] font-semibold disabled:opacity-50"
                        :style="{
                            background: 'var(--ds-accent)',
                            color: 'var(--ds-accent-contrast)',
                        }"
                        :disabled="!dirty || saving"
                        data-testid="matrix-save"
                        @click="showImpact = true"
                    >
                        {{ saving ? copy.saving : copy.save }}
                    </button>
                    <div
                        v-if="conflictWinner !== null"
                        class="mt-3 rounded-md border px-2.5 py-2 text-[11.5px]"
                        :style="{
                            background: 'var(--ds-danger-bg)',
                            borderColor: 'var(--ds-danger-border)',
                            color: 'var(--ds-danger-text)',
                        }"
                        role="alert"
                        data-testid="conflict-panel"
                    >
                        {{ copy.conflict_reapply }}
                    </div>
                    <div
                        v-if="pendingKeys.length > 0"
                        class="mt-3 rounded-md border px-2.5 py-2 text-[11.5px]"
                        :style="{
                            background: 'var(--ds-warn-bg)',
                            borderColor: 'var(--ds-warn-border)',
                            color: 'var(--ds-warn-text)',
                        }"
                        role="status"
                    >
                        {{ fill(copy.pending_note ?? '', { keys: pendingKeys.join(', ') }) }}
                    </div>
                </div>
            </aside>
        </div>

        <!-- The impact modal (§8): diff + affected count, focus-trapped (§13) -->
        <div
            v-if="showImpact"
            class="fixed inset-0 z-10 flex items-center justify-center p-4"
            style="background: rgba(0, 0, 0, 0.4)"
            role="dialog"
            aria-modal="true"
            :aria-label="fill(copy.impact_title ?? '', { name: role.name })"
            @keydown="trapFocus"
        >
            <div
                ref="impactRef"
                class="w-full max-w-md rounded-lg border p-4"
                :style="{ background: 'var(--ds-panel-bg)', borderColor: 'var(--ds-border)' }"
            >
                <h2 class="text-[14px] font-semibold">
                    {{ fill(copy.impact_title ?? '', { name: role.name }) }}
                </h2>
                <p class="mt-1 text-[12px]" :style="{ color: 'var(--ds-text-muted)' }">
                    {{ fill(copy.impact_people ?? '', { count: role.users_count }) }}
                </p>
                <div class="mt-3 space-y-1.5 text-[11.5px]" data-testid="impact-diff">
                    <div
                        v-for="key in added"
                        :key="`add-${key}`"
                        class="rounded px-2 py-1 font-mono"
                        :style="{
                            background: 'var(--ds-success-bg)',
                            color: 'var(--ds-success-text)',
                        }"
                    >
                        + {{ key }} <span class="font-sans">({{ copy.impact_added }})</span>
                    </div>
                    <div
                        v-for="key in removed"
                        :key="`rm-${key}`"
                        class="rounded px-2 py-1 font-mono"
                        :style="{
                            background: 'var(--ds-danger-bg)',
                            color: 'var(--ds-danger-text)',
                        }"
                    >
                        − {{ key }} <span class="font-sans">({{ copy.impact_removed }})</span>
                    </div>
                </div>
                <div class="mt-4 flex justify-end gap-2">
                    <button
                        type="button"
                        class="min-h-9 rounded-md border px-3 text-[12px]"
                        :style="{ borderColor: 'var(--ds-border-strong)' }"
                        @click="showImpact = false"
                    >
                        {{ copy.unsaved_stay }}
                    </button>
                    <button
                        type="button"
                        class="min-h-9 rounded-md px-3 text-[12px] font-semibold"
                        :style="{
                            background: 'var(--ds-accent)',
                            color: 'var(--ds-accent-contrast)',
                        }"
                        data-testid="impact-confirm"
                        @click="save"
                    >
                        {{ copy.impact_confirm }}
                    </button>
                </div>
            </div>
        </div>
    </BackofficeShell>
</template>
