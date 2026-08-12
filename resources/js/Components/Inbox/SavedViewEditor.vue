<script setup lang="ts">
// FR-06 — the saved-view editor (S07 step 12), drawn in B1-S03: name, the
// filter CHIP set over the closed SavedViewFilters grammar, sort, share-with,
// and the per-viewer count note (Doc 07 EC-04's explanation, verbatim from
// the frame). Wired to the step-4 doors: POST/PATCH /inbox/views.
//
// The Share-with control renders per the frame but DISABLED: the share DOOR
// (views.share, proven at step 4) targets team/department ids, and no org
// directory exists yet to pick from — an honest seam, stated in
// BUILD-STATUS, never a silent drop.
import { reactive, ref, watch } from 'vue';

const props = defineProps<{
    open: boolean;
    t: Record<string, string>;
    canShare: boolean;
    editing: {
        id: string | null; // null = create
        name: string;
        filters: Record<string, unknown>;
        sort: { by?: string; dir?: string };
    } | null;
}>();

const emit = defineEmits<{ (e: 'close'): void; (e: 'saved'): void }>();

// The CLOSED filter grammar (SavedViewFilters::KEYS — kept in lockstep; the
// server rejects anything else with view_filters_invalid).
const ARRAY_KEYS = ['status', 'priority', 'tags'] as const;
const SCALAR_KEYS = [
    'department_id',
    'queue_id',
    'assignee_id',
    'language',
    'channel',
    'created_from',
    'created_to',
    'updated_from',
    'updated_to',
] as const;

const name = ref('');
const filters = reactive<Record<string, unknown>>({});
const sortBy = ref('updated_desc');
const addKey = ref('');
const addValue = ref('');
const error = ref('');

watch(
    () => props.open,
    (isOpen) => {
        if (!isOpen) return;
        error.value = '';
        addKey.value = '';
        addValue.value = '';
        name.value = props.editing?.name ?? '';
        Object.keys(filters).forEach((key) => delete filters[key]);
        Object.assign(filters, props.editing?.filters ?? {});
        sortBy.value =
            props.editing?.sort?.by === 'created_at'
                ? props.editing.sort.dir === 'asc'
                    ? 'created_asc'
                    : 'created_desc'
                : props.editing?.sort?.dir === 'asc'
                  ? 'updated_asc'
                  : 'updated_desc';
    },
);

function chipLabel(key: string, value: unknown): string {
    const label = props.t['filter_' + key] ?? key;
    if (Array.isArray(value)) return `${label}: ${value.join(', ')}`;
    if (typeof value === 'boolean') return label;
    return `${label}: ${String(value)}`;
}

function removeFilter(key: string): void {
    delete filters[key];
}

function addFilter(): void {
    const key = addKey.value;
    if (!key) return;
    if (key === 'has_draft') {
        filters[key] = true;
    } else if ((ARRAY_KEYS as readonly string[]).includes(key)) {
        const value = addValue.value.trim();
        if (!value) return;
        const current = Array.isArray(filters[key]) ? (filters[key] as string[]) : [];
        filters[key] = [...current, value];
    } else {
        const value = addValue.value.trim();
        if (!value) return;
        filters[key] = value;
    }
    addKey.value = '';
    addValue.value = '';
}

function xsrf(): string {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    return match?.[1] ? decodeURIComponent(match[1]) : '';
}

async function save(): Promise<void> {
    error.value = '';
    const sort =
        sortBy.value === 'created_asc'
            ? { by: 'created_at', dir: 'asc' }
            : sortBy.value === 'created_desc'
              ? { by: 'created_at', dir: 'desc' }
              : sortBy.value === 'updated_asc'
                ? { by: 'updated_at', dir: 'asc' }
                : { by: 'updated_at', dir: 'desc' };
    const body = JSON.stringify({ name: name.value, filters, sort });
    const response = await fetch(
        props.editing?.id ? `/inbox/views/${props.editing.id}` : '/inbox/views',
        {
            method: props.editing?.id ? 'PATCH' : 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': xsrf(),
            },
            body,
        },
    );
    if (!response.ok) {
        const problem = (await response.json()) as { title?: string };
        error.value = problem.title ?? props.t.save_failed ?? '';
        return;
    }
    emit('saved');
}
</script>

<template>
    <div
        v-if="open"
        data-testid="saved-view-editor"
        role="dialog"
        aria-modal="true"
        :aria-label="editing?.id ? t.edit_title : t.new_title"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
        @click.self="emit('close')"
    >
        <div
            class="w-full max-w-[420px] rounded-[10px] border p-4"
            :style="{ background: 'var(--ds-panel-bg)', borderColor: 'var(--ds-border)' }"
        >
            <p class="text-sm font-semibold" :style="{ color: 'var(--ds-text-primary)' }">
                {{ editing?.id ? t.edit_title : t.new_title }}
            </p>

            <label class="mt-3 block">
                <span
                    class="mb-1 block text-[11px] font-semibold"
                    :style="{ color: 'var(--ds-text-secondary)' }"
                    >{{ t.name_label }}</span
                >
                <input
                    v-model="name"
                    data-testid="view-editor-name"
                    type="text"
                    maxlength="120"
                    class="w-full rounded-md border bg-transparent px-2.5 py-2 text-[12.5px]"
                    :style="{ borderColor: 'var(--ds-border)', color: 'var(--ds-text-primary)' }"
                />
            </label>

            <div class="mt-2.5">
                <span
                    class="mb-1 block text-[11px] font-semibold"
                    :style="{ color: 'var(--ds-text-secondary)' }"
                    >{{ t.filters_label }}</span
                >
                <div class="flex flex-wrap gap-1.5">
                    <button
                        v-for="(value, key) in filters"
                        :key="key"
                        type="button"
                        :data-filter-chip="key"
                        class="rounded-[5px] px-2 py-1 text-[11px]"
                        :style="{
                            background: 'var(--ds-inset-bg)',
                            color: 'var(--ds-text-primary)',
                        }"
                        :aria-label="
                            (t.remove_filter ?? '').replace(
                                ':filter',
                                chipLabel(String(key), value),
                            )
                        "
                        @click="removeFilter(String(key))"
                    >
                        {{ chipLabel(String(key), value) }} ×
                    </button>
                </div>
                <div class="mt-1.5 flex gap-1.5">
                    <select
                        v-model="addKey"
                        data-testid="view-editor-add-key"
                        class="rounded-[5px] border border-dashed bg-transparent px-2 py-1 text-[11px]"
                        :style="{
                            borderColor: 'var(--ds-border)',
                            color: 'var(--ds-text-secondary)',
                        }"
                        :aria-label="t.add_filter"
                    >
                        <option value="">{{ t.add_filter }}</option>
                        <option
                            v-for="key in [...ARRAY_KEYS, ...SCALAR_KEYS, 'has_draft']"
                            :key="key"
                            :value="key"
                        >
                            {{ t['filter_' + key] ?? key }}
                        </option>
                    </select>
                    <input
                        v-if="addKey && addKey !== 'has_draft'"
                        v-model="addValue"
                        data-testid="view-editor-add-value"
                        type="text"
                        class="min-w-0 flex-1 rounded-[5px] border bg-transparent px-2 py-1 text-[11px]"
                        :style="{
                            borderColor: 'var(--ds-border)',
                            color: 'var(--ds-text-primary)',
                        }"
                        :aria-label="t.filter_value"
                        @keydown.enter.prevent="addFilter"
                    />
                    <button
                        v-if="addKey"
                        type="button"
                        data-testid="view-editor-add-confirm"
                        class="rounded-[5px] border px-2 py-1 text-[11px]"
                        :style="{
                            borderColor: 'var(--ds-border)',
                            color: 'var(--ds-text-primary)',
                        }"
                        @click="addFilter"
                    >
                        +
                    </button>
                </div>
            </div>

            <div class="mt-2.5 grid grid-cols-2 gap-2.5">
                <label class="block">
                    <span
                        class="mb-1 block text-[11px] font-semibold"
                        :style="{ color: 'var(--ds-text-secondary)' }"
                        >{{ t.sort_label }}</span
                    >
                    <select
                        v-model="sortBy"
                        data-testid="view-editor-sort"
                        class="w-full rounded-md border bg-transparent px-2 py-2 text-[12.5px]"
                        :style="{
                            borderColor: 'var(--ds-border)',
                            color: 'var(--ds-text-primary)',
                        }"
                    >
                        <option value="updated_desc">{{ t.sort_updated_desc }}</option>
                        <option value="updated_asc">{{ t.sort_updated_asc }}</option>
                        <option value="created_desc">{{ t.sort_created_desc }}</option>
                        <option value="created_asc">{{ t.sort_created_asc }}</option>
                    </select>
                </label>
                <label v-if="canShare" class="block">
                    <span
                        class="mb-1 block text-[11px] font-semibold"
                        :style="{ color: 'var(--ds-text-secondary)' }"
                        >{{ t.share_label }}</span
                    >
                    <select
                        data-testid="view-editor-share"
                        disabled
                        :title="t.share_pending"
                        class="w-full rounded-md border bg-transparent px-2 py-2 text-[12.5px] opacity-60"
                        :style="{ borderColor: 'var(--ds-border)', color: 'var(--ds-text-muted)' }"
                    >
                        <option>{{ t.share_pending }}</option>
                    </select>
                </label>
            </div>

            <!-- EC-04's per-viewer note, verbatim from the frame. -->
            <p
                class="mt-2.5 rounded-md px-2.5 py-2 text-[11px] leading-relaxed"
                :style="{ background: 'var(--ds-panel-soft)', color: 'var(--ds-text-secondary)' }"
            >
                {{ t.per_viewer_note }}
            </p>

            <p
                v-if="error"
                data-testid="view-editor-error"
                class="mt-2 text-[11px]"
                :style="{ color: 'var(--ds-danger-text)' }"
            >
                {{ error }}
            </p>

            <div class="mt-2.5 flex justify-end gap-2">
                <button
                    type="button"
                    data-testid="view-editor-cancel"
                    class="px-3 py-2 text-xs font-medium"
                    :style="{ color: 'var(--ds-text-secondary)' }"
                    @click="emit('close')"
                >
                    {{ t.cancel }}
                </button>
                <button
                    type="button"
                    data-testid="view-editor-save"
                    class="rounded-md px-3.5 py-2 text-xs font-semibold"
                    :style="{ background: 'var(--ds-accent)', color: 'var(--ds-accent-contrast)' }"
                    @click="save"
                >
                    {{ t.save }}
                </button>
            </div>
        </div>
    </div>
</template>
