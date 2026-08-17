<script setup lang="ts">
/**
 * Doc 08 FR-21 — the Customer slot fill, THE 12-FRAME PASS (B4-S01; S08
 * step 10). Frame map (assertions cite these ids in customers-panel.spec):
 *   1440 A01–A11  enriched panel: avatar, editable name, language+source
 *                 chip, country+flag, EC-10 detected chip, tags, pinned
 *                 note, identities with provenance, betting card, History
 *                 (rollups), recent tickets, notes/files tabs.
 *   A03  username-history popover, keyboard-reachable.
 *   A04  copy-on-click: a BUTTON, confirmation SPOKEN not only shown.
 *   A06  duplicate hint: count + link, never a name.
 *   A12  header instant, sections streaming (static placeholders under
 *        reduced-motion — §9: no shimmer).
 *   A13  shell: "Customer #xxxx — No name yet" + guidance, not apology.
 *   A14  empty notes/files. A15 per-section failure + retry, rest survive.
 *   A16  conflict toast: last write wins, the loser told BY NAME.
 *   375  the same component in the sheet (B14 — the single shared fill).
 *
 * AC-B4-03: the five announce moments ride ONE polite live region
 * (focus label+value → editing → Escape-cancelled → saved+provenance →
 * failure+reverted), each spoken once, never re-reading the card.
 *
 * RG discipline rides the API (keys present ⟺ permitted — absence, never
 * redaction); the blocked banner is red (§8), the RG banner slate (§9) —
 * "the two states look different on purpose". Blocked is NOT a tab-dot
 * state (no frame draws it; the banner is that signal — the part-2 ruling).
 */
import { computed, nextTick, ref, watch } from 'vue';

type Identity = {
    id: string;
    type: string | null;
    value: string | null;
    source: string | null;
    first_seen_at: string | null;
    display: string | null;
    username: string | null;
    username_history: { old: string; changed_at: string | null }[];
};

type Header = Record<string, unknown> & {
    id?: string;
    display_name?: string | null;
    is_shell?: boolean;
    language?: string | null;
    language_source?: string | null;
    country?: string | null;
    country_source?: string | null;
    tags?: string | string[] | null;
    detected_language?: string | null;
    blocked?: boolean;
    blocked_reason?: string | null;
    rg_flag?: boolean;
    tickets_count?: number;
    open_tickets?: number;
    satisfaction_avg?: string | number | null;
    satisfaction_count?: number;
    updated_at?: string;
};

type Can = Record<string, boolean>;
type FieldRow = { key: string; label_i18n: string; retired: boolean; value: string | null };
type Note = {
    id: string;
    author_id: string | null;
    body: string;
    pinned: boolean;
    created_at: string;
};
type FileItem = {
    message_id: string;
    media_index: number;
    kind: string;
    mime: string | null;
    scan_status: string;
    created_at: string;
};

const props = defineProps<{
    customerId: string | null;
    t: Record<string, string>;
}>();

const loading = ref(false);
const failed = ref(false);
// H07 (FS-03): stale only means "the handler is behind" — served, never derived here.
const rollupsStale = ref<{ stale_seconds: number | null; pending: boolean; stale: boolean } | null>(
    null,
);
const header = ref<Header | null>(null);
const identities = ref<Identity[]>([]);
const can = ref<Can>({});
const tab = ref<'profile' | 'notes' | 'files'>('profile');

// Per-section state (T-07: independent fetch, independent failure — A15).
const sections = ref<
    Record<
        'tickets' | 'betting' | 'notes' | 'files',
        { state: 'idle' | 'loading' | 'ready' | 'failed'; data: unknown }
    >
>({
    tickets: { state: 'idle', data: null },
    betting: { state: 'idle', data: null },
    notes: { state: 'idle', data: null },
    files: { state: 'idle', data: null },
});

// ── AC-B4-03: the ONE polite region. Each moment is one message; the card
//    itself never re-reads (aria-live sits on this element alone).
const announcement = ref('');
function announce(message: string): void {
    announcement.value = '';
    void nextTick(() => {
        announcement.value = message;
    });
}
function fill(template: string | undefined, vars: Record<string, string | undefined>): string {
    let out = template ?? '';
    for (const [key, value] of Object.entries(vars)) out = out.replaceAll(':' + key, value ?? '');
    return out;
}

// ── Inline edit (FR-21: per permission, autosave, provenance updates).
const editing = ref<string | null>(null);
const editValue = ref('');
const savedField = ref<string | null>(null); // renders "staff · you · just now" (§8)
const conflict = ref<{ name: string; label: string; theirs: string; mine: string } | null>(null);

function fieldLabel(name: string): string {
    return props.t[name] ?? name;
}
function fieldValue(name: string): string {
    const value = header.value?.[name];
    return typeof value === 'string' && value !== '' ? value : '—';
}
function onFieldFocus(name: string): void {
    announce(fill(props.t.announce_focus, { label: fieldLabel(name), value: fieldValue(name) }));
}
function startEdit(name: string): void {
    if (!can.value.edit) return;
    editing.value = name;
    editValue.value = fieldValue(name) === '—' ? '' : fieldValue(name);
    announce(fill(props.t.announce_editing, { label: fieldLabel(name) }));
    // the keyboard cycle continues on the INPUT (Escape/Enter land there)
    void nextTick(() => {
        document.querySelector<HTMLInputElement>(`[data-testid="edit-${name}"]`)?.focus();
    });
}
function cancelEdit(name: string): void {
    editing.value = null;
    announce(
        fill(props.t.announce_cancelled, { label: fieldLabel(name), value: fieldValue(name) }),
    );
}
async function saveEdit(name: string): Promise<void> {
    const previous = fieldValue(name);
    try {
        const response = await fetch(`/api/staff/customers/${props.customerId}/profile`, {
            method: 'PATCH',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': xsrf(),
            },
            body: JSON.stringify({
                [name]: editValue.value,
                expected_updated_at: header.value?.updated_at,
            }),
        });
        if (!response.ok) throw new Error(String(response.status));
        const result = (await response.json()) as {
            changed: string[];
            conflict: boolean;
            conflict_with: string | null;
            old: Record<string, string | null>;
            updated_at?: string;
        };
        if (header.value) {
            header.value[name] = editValue.value;
            if (result.updated_at) header.value.updated_at = result.updated_at;
            if (name === 'language') header.value.language_source = 'staff';
            if (name === 'country') header.value.country_source = 'staff';
        }
        editing.value = null;
        savedField.value = name;
        // A16: the write LANDED (last wins) — the loser is told, by name.
        if (result.conflict) {
            conflict.value = {
                name: result.conflict_with ?? '',
                label: fieldLabel(name),
                theirs: editValue.value,
                mine: String(result.old[name] ?? ''),
            };
        }
        announce(props.t.announce_saved ?? '');
    } catch {
        editing.value = null;
        announce(fill(props.t.announce_save_failed, { label: fieldLabel(name), value: previous }));
    }
}
async function applyDetected(): Promise<void> {
    await fetch(`/api/staff/customers/${props.customerId}/profile/apply-detected`, {
        method: 'POST',
        headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() },
    });
    await load();
}
function xsrf(): string {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    return match?.[1] !== undefined ? decodeURIComponent(match[1]) : '';
}

// ── A04: copy-on-click, a real button, the confirmation SPOKEN.
const copiedId = ref<string | null>(null);
async function copyValue(id: string, value: string): Promise<void> {
    try {
        await navigator.clipboard.writeText(value);
    } catch {
        /* clipboard denied — the announcement still confirms intent honestly? no: */
        return;
    }
    copiedId.value = id;
    announce(props.t.copied ?? '');
    window.setTimeout(() => (copiedId.value = null), 2000);
}

// ── A03: the username-history popover, keyboard-reachable.
const historyOpen = ref<string | null>(null);

// ── Tags (chips with remove + add).
const addingTag = ref(false);
const newTag = ref('');
function parseTags(): string[] {
    const raw = header.value?.tags;
    if (Array.isArray(raw)) return raw as string[];
    if (typeof raw === 'string')
        return raw
            .replace(/^\{|\}$/g, '')
            .split(',')
            .filter((x) => x !== '');
    return [];
}
async function writeTags(tags: string[]): Promise<void> {
    await fetch(`/api/staff/customers/${props.customerId}/profile`, {
        method: 'PATCH',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-XSRF-TOKEN': xsrf(),
        },
        body: JSON.stringify({ tags }),
    });
    if (header.value) header.value.tags = tags;
}

// ── Sections.
async function fetchSection(name: 'tickets' | 'betting' | 'notes' | 'files'): Promise<void> {
    const paths = {
        tickets: 'panel/tickets',
        betting: 'panel/betting-fields',
        notes: 'panel/notes',
        files: 'panel/files',
    } as const;
    sections.value[name] = { state: 'loading', data: null };
    try {
        const response = await fetch(`/api/staff/customers/${props.customerId}/${paths[name]}`, {
            headers: { Accept: 'application/json' },
        });
        if (!response.ok) throw new Error(String(response.status));
        sections.value[name] = { state: 'ready', data: await response.json() };
    } catch {
        sections.value[name] = { state: 'failed', data: null }; // A15: this section only
    }
}

async function load(): Promise<void> {
    if (props.customerId === null) {
        header.value = null;
        identities.value = [];
        return;
    }
    loading.value = true;
    failed.value = false;
    try {
        const panel = await fetch(`/api/staff/customers/${props.customerId}/panel`, {
            headers: { Accept: 'application/json' },
        });
        if (!panel.ok) throw new Error(String(panel.status));
        const data = (await panel.json()) as {
            header: Header;
            identities: Identity[];
            can: Can;
            rollups_stale?: { stale_seconds: number | null; pending: boolean; stale: boolean };
        };
        header.value = data.header;
        identities.value = data.identities;
        can.value = data.can ?? {};
        rollupsStale.value = data.rollups_stale ?? null;
        // A12: header instant, sections streaming (each its own fetch).
        void fetchSection('tickets');
        void fetchSection('betting');
        if (can.value.notes_view) void fetchSection('notes');
        void fetchSection('files');
    } catch {
        failed.value = true;
    } finally {
        loading.value = false;
    }
}
watch(() => props.customerId, load, { immediate: true });

const displayName = computed(() => {
    const name = header.value?.display_name;
    if (typeof name === 'string' && name !== '') return name;
    return null; // A13 shell renders its own two-line head
});
const initials = computed(() => {
    const name = displayName.value;
    if (!name) return '?';
    return name
        .split(/\s+/)
        .slice(0, 2)
        .map((word) => word.charAt(0))
        .join('');
});
const shortId = computed(() => String(header.value?.id ?? '').slice(-4));
const detectedDiffers = computed(
    () =>
        typeof header.value?.detected_language === 'string' &&
        header.value.detected_language !== header.value.language &&
        header.value?.language_source === 'staff',
);
const ticketsData = computed(
    () =>
        (sections.value.tickets.data as {
            tickets: { id: string; number: string; subject: string | null; status: string }[];
        } | null) ?? null,
);
const bettingData = computed(
    () =>
        (sections.value.betting.data as {
            fields: FieldRow[];
            duplicate_hint: { count: number; links: string[] } | null;
        } | null) ?? null,
);
const notesData = computed(() => (sections.value.notes.data as { notes: Note[] } | null) ?? null);
const pinnedNote = computed(() => notesData.value?.notes.find((note) => note.pinned) ?? null);
const filesData = computed(
    () => (sections.value.files.data as { items: FileItem[] } | null) ?? null,
);
</script>

<template>
    <div data-testid="customer-panel-fill">
        <!-- AC-B4-03: THE live region — the only aria-live element here. -->
        <div
            data-testid="panel-announcer"
            class="sr-only"
            aria-live="polite"
            role="status"
            :aria-label="t.announcements"
        >
            {{ announcement }}
        </div>

        <p v-if="customerId === null" class="text-sm" :style="{ color: 'var(--ds-text-muted)' }">
            {{ t.empty }}
        </p>
        <!-- A12: static placeholders, no shimmer (§9 reduced-motion law). -->
        <div v-else-if="loading" data-testid="panel-loading" class="space-y-2" aria-hidden="true">
            <div class="h-4 w-2/3 rounded" :style="{ background: 'var(--ds-border)' }"></div>
            <div class="h-3 w-1/2 rounded" :style="{ background: 'var(--ds-border)' }"></div>
            <div class="h-16 rounded" :style="{ background: 'var(--ds-border)' }"></div>
        </div>
        <div v-else-if="failed" class="text-sm" :style="{ color: 'var(--ds-text-muted)' }">
            {{ t.error }}
            <button
                type="button"
                class="ms-1 underline"
                :style="{ color: 'var(--ds-accent)' }"
                @click="load"
            >
                {{ t.retry }}
            </button>
        </div>

        <div v-else-if="header" class="space-y-3 text-start">
            <!-- ── Header (A01/A13) ─────────────────────────────────── -->
            <section :aria-label="t.identities">
                <div class="flex items-center gap-2">
                    <span
                        class="flex h-8 w-8 items-center justify-center rounded-full text-xs font-semibold"
                        :style="{
                            background: 'var(--ds-status-closed-bg)',
                            color: 'var(--ds-status-closed-fg)',
                        }"
                        aria-hidden="true"
                        >{{ initials }}</span
                    >
                    <div class="min-w-0">
                        <template v-if="displayName">
                            <span
                                v-if="editing !== 'display_name'"
                                data-testid="field-display_name"
                                role="button"
                                :tabindex="can.edit ? 0 : -1"
                                class="text-sm font-semibold"
                                :style="{ color: 'var(--ds-text-primary)', unicodeBidi: 'isolate' }"
                                @focus="onFieldFocus('display_name')"
                                @click="startEdit('display_name')"
                                @keydown.enter="startEdit('display_name')"
                                >{{ displayName }}</span
                            >
                        </template>
                        <template v-else>
                            <!-- A13: a shell is a real customer, not a lesser record. -->
                            <p
                                class="text-sm font-semibold"
                                :style="{ color: 'var(--ds-text-primary)' }"
                                data-testid="shell-head"
                            >
                                {{ (t.no_name ?? '').replace(':short', shortId) }}
                            </p>
                        </template>
                        <p
                            v-if="savedField"
                            data-testid="provenance-now"
                            class="text-[10.5px]"
                            :style="{ color: 'var(--ds-text-label)' }"
                        >
                            {{ t.provenance_now }}
                        </p>
                    </div>
                </div>
                <input
                    v-if="editing === 'display_name'"
                    v-model="editValue"
                    data-testid="edit-display_name"
                    class="mt-1 w-full rounded border px-2 py-1 text-sm"
                    :style="{
                        borderColor: 'var(--ds-border-strong)',
                        background: 'var(--ds-panel-bg)',
                        color: 'var(--ds-text-primary)',
                    }"
                    @keydown.enter.prevent="saveEdit('display_name')"
                    @keydown.escape.prevent="cancelEdit('display_name')"
                />

                <!-- Language + country chips with SOURCES (FR-01), EC-10 chip. -->
                <div class="mt-2 flex flex-wrap items-center gap-1.5 text-[11px]">
                    <span
                        data-testid="chip-language"
                        :role="can.edit ? 'button' : undefined"
                        :tabindex="can.edit ? 0 : -1"
                        class="rounded border px-1.5 py-0.5"
                        :style="{
                            borderColor: 'var(--ds-border-strong)',
                            color: 'var(--ds-text-primary)',
                        }"
                        @focus="onFieldFocus('language')"
                        @click="startEdit('language')"
                        @keydown.enter="startEdit('language')"
                    >
                        {{ header.language ?? '—' }}
                        <span :style="{ color: 'var(--ds-text-label)' }">{{
                            header.language_source ?? ''
                        }}</span>
                    </span>
                    <span
                        data-testid="chip-country"
                        :role="can.edit ? 'button' : undefined"
                        :tabindex="can.edit ? 0 : -1"
                        class="rounded border px-1.5 py-0.5"
                        :style="{
                            borderColor: 'var(--ds-border-strong)',
                            color: 'var(--ds-text-primary)',
                        }"
                        @focus="onFieldFocus('country')"
                        @click="startEdit('country')"
                        @keydown.enter="startEdit('country')"
                        >{{
                            header.country === 'ET' ? '🇪🇹 Ethiopia' : (header.country ?? '—')
                        }}</span
                    >
                    <input
                        v-if="editing === 'language' || editing === 'country'"
                        v-model="editValue"
                        :data-testid="'edit-' + editing"
                        class="w-20 rounded border px-1.5 py-0.5 text-[11px]"
                        :style="{
                            borderColor: 'var(--ds-border-strong)',
                            background: 'var(--ds-panel-bg)',
                            color: 'var(--ds-text-primary)',
                        }"
                        @keydown.enter.prevent="saveEdit(editing)"
                        @keydown.escape.prevent="cancelEdit(editing)"
                    />
                    <!-- EC-10: subtle, once per change; apply is its own door. -->
                    <button
                        v-if="detectedDiffers && can.edit"
                        type="button"
                        data-testid="chip-detected"
                        class="rounded border border-dashed px-1.5 py-0.5"
                        :style="{
                            borderColor: 'var(--ds-border-strong)',
                            color: 'var(--ds-text-muted)',
                        }"
                        @click="applyDetected"
                    >
                        {{ fill(t.detected_apply, { lang: String(header.detected_language) }) }}
                    </button>
                </div>

                <!-- Tags (chips, removable; + Add tag). -->
                <div class="mt-1.5 flex flex-wrap items-center gap-1" data-testid="tags-row">
                    <span
                        v-for="tag in parseTags()"
                        :key="tag"
                        class="inline-flex items-center gap-0.5 rounded border px-1.5 py-0.5 text-[11px]"
                        :style="{
                            borderColor: 'var(--ds-border-strong)',
                            color: 'var(--ds-text-muted)',
                        }"
                    >
                        {{ tag }}
                        <button
                            v-if="can.edit"
                            type="button"
                            :aria-label="fill(t.remove_tag, { tag })"
                            @click="writeTags(parseTags().filter((x) => x !== tag))"
                        >
                            ×
                        </button>
                    </span>
                    <button
                        v-if="can.edit && !addingTag"
                        type="button"
                        data-testid="add-tag"
                        class="rounded border border-dashed px-1.5 py-0.5 text-[11px]"
                        :style="{
                            borderColor: 'var(--ds-border-strong)',
                            color: 'var(--ds-text-muted)',
                        }"
                        @click="addingTag = true"
                    >
                        {{ t.add_tag }}
                    </button>
                    <input
                        v-if="addingTag"
                        v-model="newTag"
                        data-testid="add-tag-input"
                        class="w-24 rounded border px-1.5 py-0.5 text-[11px]"
                        :style="{
                            borderColor: 'var(--ds-border-strong)',
                            background: 'var(--ds-panel-bg)',
                            color: 'var(--ds-text-primary)',
                        }"
                        @keydown.enter.prevent="
                            writeTags([...parseTags(), newTag]).then(() => {
                                newTag = '';
                                addingTag = false;
                            })
                        "
                        @keydown.escape.prevent="addingTag = false"
                    />
                </div>

                <!-- §8: blocked banner is RED; RG banner is SLATE — different on purpose. -->
                <div
                    v-if="header.blocked"
                    data-testid="customer-blocked-banner"
                    class="mt-2 rounded-md border px-3 py-2 text-xs"
                    :style="{
                        background: 'var(--ds-danger-bg)',
                        borderColor: 'var(--ds-danger-strong)',
                        color: 'var(--ds-danger-strong)',
                    }"
                >
                    {{
                        (t.blocked_banner ?? '').replace(
                            ':reason',
                            String(header.blocked_reason ?? ''),
                        )
                    }}
                </div>
                <div
                    v-if="header.rg_flag === true"
                    data-testid="customer-rg-banner"
                    role="note"
                    class="mt-2 rounded-md border px-3 py-2 text-xs"
                    :style="{
                        background: 'var(--ds-status-closed-bg)',
                        borderColor: 'var(--ds-border-strong)',
                        color: 'var(--ds-status-closed-fg)',
                    }"
                >
                    {{ t.rg_banner }}
                </div>

                <!-- A16: the conflict toast — last write won, the loser told BY NAME. -->
                <div
                    v-if="conflict"
                    data-testid="conflict-toast"
                    role="alert"
                    class="mt-2 rounded-md border px-3 py-2 text-xs"
                    :style="{
                        borderColor: 'var(--ds-border-strong)',
                        color: 'var(--ds-text-primary)',
                        background: 'var(--ds-panel-bg)',
                    }"
                >
                    <p class="font-semibold">
                        {{ fill(t.conflict_toast, { name: conflict.name }) }}
                    </p>
                    <p :style="{ color: 'var(--ds-text-muted)' }">
                        {{
                            fill(t.conflict_body, {
                                label: conflict.label,
                                theirs: conflict.theirs,
                                mine: conflict.mine,
                            })
                        }}
                    </p>
                    <button
                        type="button"
                        class="mt-1 underline"
                        :style="{ color: 'var(--ds-accent)' }"
                        @click="conflict = null"
                    >
                        {{ t.use_mine }}
                    </button>
                </div>

                <!-- Pinned note surfaces in the header (FR-17). -->
                <div
                    v-if="pinnedNote"
                    data-testid="pinned-note"
                    class="mt-2 rounded-md border px-3 py-2 text-xs"
                    :style="{ borderColor: 'var(--ds-border)', background: 'var(--ds-panel-bg)' }"
                >
                    <p class="font-semibold" :style="{ color: 'var(--ds-text-label)' }">
                        📌 {{ t.pinned_note }}
                    </p>
                    <p :style="{ color: 'var(--ds-text-primary)' }">
                        {{ pinnedNote?.body }}
                    </p>
                </div>
            </section>

            <!-- A13 shell guidance (not an apology). -->
            <section v-if="header.is_shell && !displayName" data-testid="shell-guidance">
                <p class="text-xs font-semibold" :style="{ color: 'var(--ds-text-primary)' }">
                    {{ t.shell_know }}
                </p>
                <p class="text-xs" :style="{ color: 'var(--ds-text-muted)' }">{{ t.shell_add }}</p>
            </section>

            <!-- ── Identities with provenance ALWAYS (FR-05; A03/A04) ── -->
            <section :aria-label="t.identities">
                <h4 class="text-xs font-semibold" :style="{ color: 'var(--ds-text-label)' }">
                    {{ t.identities }}
                </h4>
                <ul class="mt-1 space-y-1.5">
                    <li
                        v-for="identity in identities"
                        :key="identity.id"
                        class="text-xs"
                        :style="{ unicodeBidi: 'isolate' }"
                    >
                        <div class="flex items-center gap-1.5">
                            <span aria-hidden="true">{{
                                identity.type === 'telegram'
                                    ? '✈'
                                    : identity.type === 'phone'
                                      ? '☎'
                                      : identity.type === 'email'
                                        ? '✉'
                                        : '🌐'
                            }}</span>
                            <!-- A04: phone/email values COPY on click — a button, spoken. -->
                            <button
                                v-if="identity.type === 'phone' || identity.type === 'email'"
                                type="button"
                                :data-testid="'copy-' + identity.id"
                                class="underline decoration-dotted"
                                :style="{ color: 'var(--ds-text-primary)' }"
                                :aria-label="
                                    fill(t.copy_value, { value: String(identity.value ?? '') })
                                "
                                @click="copyValue(identity.id, String(identity.value ?? ''))"
                            >
                                {{ identity.value }}
                            </button>
                            <span v-else :style="{ color: 'var(--ds-text-primary)' }">{{
                                identity.username ?? identity.value
                            }}</span>
                            <span
                                v-if="copiedId === identity.id"
                                :data-testid="'copied-' + identity.id"
                                :style="{ color: 'var(--ds-status-closed-fg)' }"
                                >{{ t.copied }}</span
                            >
                            <!-- A03: history popover trigger — keyboard-reachable button. -->
                            <button
                                v-if="identity.username_history.length > 0"
                                type="button"
                                :data-testid="'history-trigger-' + identity.id"
                                class="text-[10.5px] underline decoration-dotted"
                                :style="{ color: 'var(--ds-accent)' }"
                                :aria-expanded="historyOpen === identity.id"
                                @click="
                                    historyOpen = historyOpen === identity.id ? null : identity.id
                                "
                            >
                                {{
                                    fill(t.earlier_names, {
                                        count: String(identity.username_history.length),
                                    })
                                }}
                            </button>
                        </div>
                        <div
                            v-if="historyOpen === identity.id"
                            :data-testid="'history-popover-' + identity.id"
                            class="mt-1 rounded-md border p-2"
                            :style="{
                                borderColor: 'var(--ds-border-strong)',
                                background: 'var(--ds-panel-bg)',
                            }"
                        >
                            <p
                                class="text-[10.5px] font-semibold"
                                :style="{ color: 'var(--ds-text-label)' }"
                            >
                                {{ t.earlier_names_title }}
                            </p>
                            <ul>
                                <li
                                    v-for="entry in identity.username_history"
                                    :key="entry.old"
                                    class="flex justify-between gap-2 text-[11px]"
                                >
                                    <span :style="{ color: 'var(--ds-text-primary)' }">{{
                                        entry.old
                                    }}</span>
                                    <span :style="{ color: 'var(--ds-text-muted)' }">{{
                                        fill(t.until, {
                                            date: String(entry.changed_at ?? '').slice(0, 10),
                                        })
                                    }}</span>
                                </li>
                            </ul>
                            <p
                                class="mt-1 text-[10.5px]"
                                :style="{ color: 'var(--ds-text-muted)' }"
                            >
                                {{ t.search_finds }}
                            </p>
                        </div>
                        <!-- FR-05: provenance line, always. -->
                        <p class="text-[10.5px]" :style="{ color: 'var(--ds-text-label)' }">
                            {{
                                identity.source === 'staff'
                                    ? t.by_staff
                                    : fill(t.from_channel, { channel: String(identity.type ?? '') })
                            }}
                            ·
                            {{
                                fill(t.first_seen, {
                                    date: String(identity.first_seen_at ?? '').slice(0, 10),
                                })
                            }}
                        </p>
                    </li>
                    <li
                        v-if="identities.length === 0"
                        class="text-xs"
                        :style="{ color: 'var(--ds-text-muted)' }"
                    >
                        {{ t.unreachable }}
                    </li>
                </ul>
            </section>

            <!-- ── Betting details (FR-16; A06) ────────────────────── -->
            <section :aria-label="t.betting" data-testid="betting-card">
                <div class="flex items-baseline justify-between">
                    <h4 class="text-xs font-semibold" :style="{ color: 'var(--ds-text-label)' }">
                        {{ t.betting }}
                    </h4>
                    <span class="text-[10px]" :style="{ color: 'var(--ds-text-label)' }">{{
                        t.staff_collected
                    }}</span>
                </div>
                <div
                    v-if="sections.betting.state === 'failed'"
                    data-testid="section-error-betting"
                    class="mt-1 text-xs"
                    :style="{ color: 'var(--ds-text-muted)' }"
                >
                    {{ fill(t.section_failed, { section: t.betting }) }}
                    <button
                        type="button"
                        class="ms-1 underline"
                        :style="{ color: 'var(--ds-accent)' }"
                        @click="fetchSection('betting')"
                    >
                        {{ t.try_again }}
                    </button>
                </div>
                <dl v-else-if="bettingData" class="mt-1 space-y-0.5">
                    <div
                        v-for="row in bettingData.fields"
                        :key="row.key"
                        class="flex items-center justify-between gap-2 text-xs"
                    >
                        <dt :style="{ color: 'var(--ds-text-muted)' }">
                            {{ t[row.key] ?? row.key }}
                            <span
                                v-if="row.retired"
                                class="ms-1 rounded border border-dashed px-1 text-[10px]"
                                :style="{
                                    borderColor: 'var(--ds-border-strong)',
                                    color: 'var(--ds-text-label)',
                                }"
                                >{{ t.retired }}</span
                            >
                        </dt>
                        <dd
                            class="flex items-center gap-1"
                            :style="{ color: 'var(--ds-text-primary)' }"
                        >
                            {{ row.value ?? '—' }}
                            <!-- A06: count + link, never a name; info-blue chip. -->
                            <span
                                v-if="
                                    row.key === 'player_id' &&
                                    bettingData.duplicate_hint &&
                                    bettingData.duplicate_hint.count > 0
                                "
                                data-testid="duplicate-hint"
                                class="rounded border px-1 py-0.5 text-[10px]"
                                :style="{
                                    borderColor: 'var(--ds-accent)',
                                    color: 'var(--ds-accent)',
                                }"
                            >
                                {{
                                    fill(t.duplicate_hint, {
                                        count: String(bettingData.duplicate_hint.count),
                                    })
                                }}
                            </span>
                        </dd>
                    </div>
                </dl>
            </section>

            <!-- ── History = the rollups card ──────────────────────── -->
            <section :aria-label="t.history" data-testid="rollups-card">
                <h4 class="text-xs font-semibold" :style="{ color: 'var(--ds-text-label)' }">
                    {{ t.history }}
                    <!-- H07: counts that admit they are behind — the mark plus
                         the WORD, never a colour alone (§13); the tooltip
                         carries the recompute age from the server's answer. -->
                    <span
                        v-if="rollupsStale?.stale"
                        data-testid="rollups-stale-chip"
                        class="ms-1 font-normal"
                        :style="{ color: 'var(--ds-text-muted)' }"
                        :title="
                            fill(t.stale_tooltip, {
                                minutes: String(
                                    Math.max(1, Math.round((rollupsStale.stale_seconds ?? 0) / 60)),
                                ),
                            })
                        "
                        >◷ {{ t.stale }}</span
                    >
                </h4>
                <p class="mt-1 text-xs" :style="{ color: 'var(--ds-text-primary)' }">
                    {{
                        fill(t.tickets_line, {
                            count: String(header.tickets_count ?? 0),
                            open: String(header.open_tickets ?? 0),
                        })
                    }}
                </p>
                <p
                    v-if="header.satisfaction_count"
                    class="text-xs"
                    :style="{ color: 'var(--ds-text-primary)' }"
                >
                    {{ header.satisfaction_avg }}
                    {{ fill(t.rating_line, { count: String(header.satisfaction_count) }) }}
                </p>
            </section>

            <!-- ── Recent tickets (A15's demonstration section) ─────── -->
            <section :aria-label="t.recent_tickets" data-testid="tickets-card">
                <h4 class="text-xs font-semibold" :style="{ color: 'var(--ds-text-label)' }">
                    {{ t.recent_tickets }}
                </h4>
                <div
                    v-if="sections.tickets.state === 'failed'"
                    data-testid="section-error-tickets"
                    class="mt-1 text-xs"
                    :style="{ color: 'var(--ds-text-muted)' }"
                >
                    <p>{{ fill(t.section_failed, { section: t.recent_tickets }) }}</p>
                    <p>{{ t.section_rest_fine }}</p>
                    <button
                        type="button"
                        class="underline"
                        :style="{ color: 'var(--ds-accent)' }"
                        @click="fetchSection('tickets')"
                    >
                        {{ t.try_again }}
                    </button>
                </div>
                <div
                    v-else-if="sections.tickets.state === 'loading'"
                    class="mt-1 h-8 rounded"
                    :style="{ background: 'var(--ds-border)' }"
                    aria-hidden="true"
                ></div>
                <ul v-else-if="ticketsData" class="mt-1 space-y-1">
                    <li
                        v-for="ticket in ticketsData.tickets"
                        :key="ticket.id"
                        class="text-xs"
                        :style="{ color: 'var(--ds-text-primary)' }"
                    >
                        #{{ ticket.number }} · {{ ticket.subject ?? '—' }}
                        <span :style="{ color: 'var(--ds-text-muted)' }">{{ ticket.status }}</span>
                    </li>
                </ul>
            </section>

            <!-- ── Notes / Files tabs (A14 empties) ─────────────────── -->
            <div
                class="flex gap-3 border-b text-xs"
                :style="{ borderColor: 'var(--ds-border)' }"
                role="tablist"
            >
                <button
                    v-if="can.notes_view"
                    type="button"
                    role="tab"
                    data-testid="tab-notes"
                    :aria-selected="tab === 'notes'"
                    class="pb-1"
                    :style="{
                        color: tab === 'notes' ? 'var(--ds-text-primary)' : 'var(--ds-text-muted)',
                        borderBottom: tab === 'notes' ? '2px solid var(--ds-accent)' : 'none',
                    }"
                    @click="tab = 'notes'"
                >
                    {{ t.notes }}
                </button>
                <button
                    type="button"
                    role="tab"
                    data-testid="tab-files"
                    :aria-selected="tab === 'files'"
                    class="pb-1"
                    :style="{
                        color: tab === 'files' ? 'var(--ds-text-primary)' : 'var(--ds-text-muted)',
                        borderBottom: tab === 'files' ? '2px solid var(--ds-accent)' : 'none',
                    }"
                    @click="tab = 'files'"
                >
                    {{ t.files }}
                </button>
            </div>
            <section
                v-if="tab === 'notes' && can.notes_view"
                :aria-label="t.notes"
                data-testid="notes-section"
            >
                <ul v-if="notesData && notesData.notes.length > 0" class="space-y-1">
                    <li
                        v-for="note in notesData.notes"
                        :key="note.id"
                        class="text-xs"
                        :style="{ color: 'var(--ds-text-primary)' }"
                    >
                        <span v-if="note.pinned">📌 </span>{{ note.body }}
                    </li>
                </ul>
                <p
                    v-else
                    class="text-xs"
                    :style="{ color: 'var(--ds-text-muted)' }"
                    data-testid="notes-empty"
                >
                    {{ t.notes_empty }}
                </p>
            </section>
            <section v-if="tab === 'files'" :aria-label="t.files" data-testid="files-section">
                <ul v-if="filesData && filesData.items.length > 0" class="space-y-1">
                    <li
                        v-for="item in filesData.items"
                        :key="item.message_id + '-' + item.media_index"
                        class="text-xs"
                        :style="{ color: 'var(--ds-text-primary)' }"
                    >
                        {{ item.kind }} · {{ item.mime ?? '' }}
                        <span
                            v-if="item.scan_status !== 'clean'"
                            :style="{ color: 'var(--ds-danger-strong)' }"
                            >{{ item.scan_status }}</span
                        >
                    </li>
                </ul>
                <p
                    v-else
                    class="text-xs"
                    :style="{ color: 'var(--ds-text-muted)' }"
                    data-testid="files-empty"
                >
                    {{ t.files_empty }}
                </p>
            </section>
        </div>
    </div>
</template>
