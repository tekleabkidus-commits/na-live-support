<script setup lang="ts">
// Doc 13 §4.4 FR-19 — B3-S08 J09: the widgets list (1440 + the 375 "admin on their phone": the list,
// the version and the status badge first; the editors behind). Creating a widget shows the key ONCE.
import { computed, ref } from 'vue';
import BackofficeShell from '../../../Components/Backoffice/BackofficeShell.vue';
import { door, tr, type Copy } from '../../../Components/Widgets/shared';

type Row = {
    id: string;
    name: string;
    key_prefix: string;
    hosts: string[];
    live_version: number | null;
    test_mode: boolean;
    bundle_channel: string;
};
const props = defineProps<{
    copy: Copy;
    can: Record<string, boolean>;
    widgets: Row[];
    bundle_version: string;
}>();
const t = props.copy;
const s = (k: string): string => t[k] ?? '';
const canConfigure = computed(() => props.can['webchat.widgets.configure'] === true);
const creating = ref(false);
const name = ref('');
const domain = ref('');
const error = ref<string | null>(null);
const created = ref<{ id: string; snippet: string; key: string } | null>(null);
const copied = ref(false);

async function create(): Promise<void> {
    error.value = null;
    const r = await door('POST', '/admin/widgets/api', {
        name: name.value,
        allowed_origins: domain.value.trim() ? [normalize(domain.value)] : [],
    });
    if (!r.ok) {
        error.value =
            typeof r.json.detail === 'string'
                ? tr(t.domain_refused, { entry: String(r.json.entry ?? domain.value) })
                : s('error');
        return;
    }
    const install = r.json.install as { snippet: string } | undefined;
    created.value = {
        id: String(r.json.id),
        snippet: install?.snippet ?? '',
        key: String(r.json.key_plaintext ?? ''),
    };
}
function normalize(d: string): string {
    const v = d.trim().toLowerCase();
    return v.startsWith('*.') || v.startsWith('https://') ? v : 'https://' + v;
}
async function copySnippet(): Promise<void> {
    try {
        await navigator.clipboard.writeText(created.value?.snippet ?? '');
        copied.value = true;
    } catch {
        copied.value = false;
    }
}
function status(row: Row): { key: string; kind: 'live' | 'unpublished' | 'test' } {
    if (row.test_mode) return { key: s('status_test'), kind: 'test' };
    return row.live_version === null
        ? { key: s('status_unpublished'), kind: 'unpublished' }
        : { key: s('status_live'), kind: 'live' };
}
</script>

<template>
    <BackofficeShell :t="t" active="widgets" :title="s('list_title')" width="full">
        <div class="wd-index" data-testid="widgets-index">
            <div class="wd-toolbar">
                <p class="wd-muted">{{ bundle_version }}</p>
                <button
                    v-if="canConfigure && !creating"
                    type="button"
                    class="wd-btn wd-btn-primary"
                    data-testid="widgets-new"
                    @click="creating = true"
                >
                    {{ t.new }}
                </button>
            </div>

            <form
                v-if="creating && !created"
                class="wd-card wd-create"
                data-testid="widgets-create"
                @submit.prevent="create"
            >
                <label class="wd-field">
                    <span>{{ t.name }}</span>
                    <input
                        v-model="name"
                        type="text"
                        maxlength="80"
                        required
                        :placeholder="s('name_placeholder')"
                        data-testid="widgets-create-name"
                    />
                </label>
                <label class="wd-field">
                    <span>{{ t.first_domain }}</span>
                    <input
                        v-model="domain"
                        type="text"
                        :placeholder="s('first_domain_placeholder')"
                        data-testid="widgets-create-domain"
                    />
                </label>
                <p v-if="error" class="wd-error" role="alert">{{ error }}</p>
                <div class="wd-row">
                    <button
                        type="submit"
                        class="wd-btn wd-btn-primary"
                        data-testid="widgets-create-submit"
                    >
                        {{ t.create }}
                    </button>
                    <button type="button" class="wd-btn" @click="creating = false">
                        {{ t.discard }}
                    </button>
                </div>
            </form>

            <section
                v-if="created"
                class="wd-card wd-created"
                data-testid="widgets-created"
                aria-live="polite"
            >
                <h2>{{ t.created_title }}</h2>
                <p class="wd-muted">{{ t.created_note }}</p>
                <pre class="wd-code" data-testid="widgets-created-snippet">{{
                    created.snippet
                }}</pre>
                <div class="wd-row">
                    <button type="button" class="wd-btn" @click="copySnippet">
                        {{ copied ? t.copied : t.copy }}
                    </button>
                    <a
                        class="wd-btn wd-btn-primary"
                        :href="'/admin/widgets/' + created.id + '/edit'"
                        data-testid="widgets-created-open"
                        >{{ t.open_designer }}</a
                    >
                </div>
            </section>

            <p v-if="widgets.length === 0 && !created" class="wd-empty" data-testid="widgets-empty">
                {{ t.empty }}
            </p>
            <ul v-else class="wd-list" data-testid="widgets-list">
                <li
                    v-for="row in widgets"
                    :key="row.id"
                    class="wd-card wd-item"
                    :data-widget="row.id"
                >
                    <div class="wd-item-main">
                        <a class="wd-item-name" :href="'/admin/widgets/' + row.id + '/edit'">{{
                            row.name || row.key_prefix
                        }}</a>
                        <span class="wd-muted">{{ row.hosts[0] ?? '—' }}</span>
                    </div>
                    <div class="wd-item-meta">
                        <span class="wd-muted">{{
                            row.live_version === null
                                ? '—'
                                : tr(t.version, { version: row.live_version })
                        }}</span>
                        <span class="wd-badge" :data-kind="status(row).kind"
                            >● {{ status(row).key }}</span
                        >
                    </div>
                </li>
            </ul>
        </div>
    </BackofficeShell>
</template>

<style>
/* B1-DS desktop density, 12.5 px type — tokens only. */
.wd-index {
    display: grid;
    gap: 12px;
    font-size: 12.5px;
    color: var(--ds-text-primary);
}
.wd-toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
}
.wd-muted {
    color: var(--ds-text-secondary);
    margin: 0;
}
.wd-card {
    background: var(--ds-panel-bg);
    border: 1px solid var(--ds-border);
    border-radius: 10px;
    padding: 14px;
}
.wd-create {
    display: grid;
    gap: 10px;
    max-width: 520px;
}
.wd-field {
    display: grid;
    gap: 4px;
}
.wd-field > span {
    font-weight: 600;
    color: var(--ds-text-label);
}
.wd-field input,
.wd-field select,
.wd-field textarea {
    min-height: 36px;
    padding: 6px 10px;
    border: 1px solid var(--ds-border);
    border-radius: 8px;
    background: var(--ds-panel-bg);
    color: var(--ds-text-primary);
    font: inherit;
}
.wd-field input:focus-visible,
.wd-field select:focus-visible,
.wd-field textarea:focus-visible,
.wd-btn:focus-visible {
    outline: 3px solid var(--ds-focus-ring);
    outline-offset: 1px;
}
.wd-row {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    align-items: center;
}
.wd-btn {
    min-height: 36px;
    padding: 0 12px;
    border-radius: 8px;
    border: 1px solid var(--ds-border-strong);
    background: var(--ds-panel-bg);
    color: var(--ds-text-primary);
    font: inherit;
    font-weight: 600;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    text-decoration: none;
}
.wd-btn-primary {
    background: var(--ds-accent);
    color: var(--ds-accent-contrast);
    border-color: var(--ds-accent);
}
.wd-btn:disabled {
    opacity: 0.5;
    cursor: default;
}
.wd-error {
    color: var(--ds-danger-text, var(--ds-text-primary));
    background: var(--ds-danger-bg, var(--ds-inset-bg));
    border-radius: 8px;
    padding: 8px 10px;
    margin: 0;
}
.wd-code {
    background: var(--ds-inset-bg);
    border-radius: 8px;
    padding: 10px 12px;
    overflow-x: auto;
    margin: 0;
    font-size: 12px;
}
.wd-empty {
    color: var(--ds-text-secondary);
}
.wd-list {
    list-style: none;
    margin: 0;
    padding: 0;
    display: grid;
    gap: 8px;
}
.wd-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
}
.wd-item-main {
    display: grid;
    gap: 2px;
    min-width: 0;
}
.wd-item-name {
    font-weight: 600;
    color: var(--ds-text-primary);
    text-decoration: none;
}
.wd-item-meta {
    display: flex;
    gap: 12px;
    align-items: center;
    flex: none;
}
.wd-badge {
    border-radius: 999px;
    padding: 2px 8px;
    background: var(--ds-inset-bg);
    font-size: 11.5px;
    white-space: nowrap;
}
.wd-badge[data-kind='unpublished'] {
    color: var(--ds-text-secondary);
}
@media (max-width: 640px) {
    .wd-btn {
        min-height: 44px;
    }
    .wd-item {
        flex-direction: column;
        align-items: stretch;
    }
    .wd-item-meta {
        justify-content: space-between;
    }
}
</style>
