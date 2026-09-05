<script setup lang="ts">
// Doc 13 §4.4 FR-19/FR-20 — B3-S08 v3 J01–J10 at full fidelity: ONE draft object edited across five tabs,
// validated through the SAME door publish() runs (the inline refusal is the server's, ratio + suggestion),
// previewed in the panel bundle itself, published as a version with one-click rollback.
import { computed, onMounted, ref, watch } from 'vue';
import BackofficeShell from '../../../Components/Backoffice/BackofficeShell.vue';
import WidgetAppearance from '../../../Components/Widgets/WidgetAppearance.vue';
import { draft } from '../../../Components/Widgets/draft';
import WidgetBehavior from '../../../Components/Widgets/WidgetBehavior.vue';
import WidgetContent from '../../../Components/Widgets/WidgetContent.vue';
import WidgetHealth from '../../../Components/Widgets/WidgetHealth.vue';
import WidgetInstall from '../../../Components/Widgets/WidgetInstall.vue';
import {
    clone,
    door,
    normalizeConfig,
    tr,
    type Copy,
    type Refusal,
    type WidgetConfig,
} from '../../../Components/Widgets/shared';

type WidgetProps = {
    id: string;
    name: string;
    key_prefix: string;
    bundle_channel: string;
    test_mode: boolean;
    live_version: number | null;
    live_version_id: string | null;
    allowed_origins: string[];
    has_secret: boolean;
    secret_rotated_at: string | null;
    overlap_hours: number;
};
const props = defineProps<{
    copy: Copy;
    can: Record<string, boolean>;
    widget: WidgetProps;
    config: WidgetConfig;
    defaults: WidgetConfig;
    fonts: string[];
    icons: string[];
    voice_flag: boolean;
    chatbot: { enabled: boolean; href: string };
    forms: { id: string; name: string }[];
    departments: { id: string; name: string }[];
    history: {
        id: string;
        version: number;
        status: string;
        note: string | null;
        published_by: string;
        published_at: string;
    }[];
    health: InstanceType<typeof WidgetHealth>['$props']['health'];
    install: {
        snippet: string | null;
        key_shown_once: boolean;
        csp: string;
        frame_src: string;
        key_prefix: string;
        script_src: string;
    };
    languages: string[];
}>();
const t = props.copy;
const s = (k: string): string => t[k] ?? '';
const canConfigure = computed(() => props.can['webchat.widgets.configure'] === true);
type TabKey = 'appearance' | 'content' | 'behavior' | 'install' | 'health';
const tabs: TabKey[] = ['appearance', 'content', 'behavior', 'install', 'health'];
const tab = ref<TabKey>(
    (new URLSearchParams(location.search).get('tab') as TabKey | null) ?? 'appearance',
);
// the baseline is the live config in the SAME normalised shape the draft takes (PHP's `[]` for an empty map)
const baseline = normalizeConfig(clone(props.config));
draft.value = clone(baseline);
const dirty = computed(() => JSON.stringify(draft.value) !== JSON.stringify(baseline));
const refusal = ref<Refusal | null>(null);
const warnings = ref<string[]>([]);
const note = ref('');
const publishing = ref(false);
const flash = ref<string | null>(null);
const hasSecret = ref(props.widget.has_secret);
const language = computed(() => draft.value.content.default_language ?? props.languages[0] ?? 'en');
let validateTimer: number | null = null;

// The preview frames receive the draft on every change (same origin, postMessage) — EC-11 in the preview too.
function pushPreview(): void {
    for (const frame of document.querySelectorAll<HTMLIFrameElement>('iframe[data-preview]')) {
        frame.contentWindow?.postMessage(
            {
                type: 'preview-config',
                data: { config: clone(draft.value), theme: frame.dataset.preview },
            },
            location.origin,
        ); // a plain object: a reactive proxy cannot be structured-cloned
    }
}
async function validate(): Promise<void> {
    const r = await door('POST', '/admin/widgets/api/' + props.widget.id + '/config/validate', {
        config: draft.value,
    });
    if (r.ok) {
        refusal.value = null;
        warnings.value = (r.json.warnings as string[] | undefined) ?? [];
    } else {
        refusal.value = r.json as unknown as Refusal;
    }
}
function changed(): void {
    pushPreview();
    if (validateTimer !== null) window.clearTimeout(validateTimer);
    validateTimer = window.setTimeout(() => void validate(), 250);
}
function applySuggestion(hex: string): void {
    draft.value.appearance.brand = { accent: hex };
    changed();
}
function discard(): void {
    draft.value = clone(baseline);
    refusal.value = null;
    changed();
}
async function publish(): Promise<void> {
    publishing.value = true;
    flash.value = null;
    const r = await door('POST', '/admin/widgets/api/' + props.widget.id + '/config/publish', {
        config: draft.value,
        note: note.value || null,
    });
    publishing.value = false;
    if (!r.ok) {
        refusal.value = r.json as unknown as Refusal;
        return;
    }
    flash.value = s('published');
    window.setTimeout(() => window.location.reload(), 600);
}
function reload(): void {
    window.location.reload();
}
onMounted(() => {
    window.addEventListener('message', (e: MessageEvent) => {
        // a preview frame that just booted asks for the current draft
        if (
            e.origin === location.origin &&
            (e.data as { type?: string } | null)?.type === 'preview-ready'
        )
            pushPreview();
    });
    void validate();
});
watch(tab, (v) => history.replaceState(null, '', '?tab=' + v));
</script>

<template>
    <BackofficeShell
        :t="t"
        active="widgets"
        :title="widget.name || widget.key_prefix"
        :crumb="[
            { label: s('back'), href: '/admin/widgets' },
            { label: widget.name || widget.key_prefix },
        ]"
        width="full"
    >
        <div class="wd-editor" data-testid="widgets-editor">
            <header class="wd-editor-head">
                <div>
                    <h2 class="wd-editor-title">
                        {{ widget.name || widget.key_prefix }}
                        <span class="wd-muted">{{ widget.key_prefix }}</span>
                    </h2>
                    <p class="wd-muted">
                        <span v-if="widget.allowed_origins.length"
                            >{{ t.live_on }}
                            {{ widget.allowed_origins[0]?.replace('https://', '') }}</span
                        >
                        <span v-if="widget.live_version !== null">
                            · {{ tr(t.version, { version: widget.live_version }) }}</span
                        >
                    </p>
                </div>
                <div class="wd-row wd-publish" data-testid="wd-publish-bar">
                    <span class="wd-status" :data-dirty="dirty" data-testid="wd-dirty"
                        >● {{ dirty ? t.unpublished_changes : t.no_changes }}</span
                    >
                    <template v-if="canConfigure">
                        <input
                            v-if="dirty"
                            v-model="note"
                            type="text"
                            maxlength="200"
                            :placeholder="s('publish_note')"
                            :aria-label="s('publish_note')"
                            data-testid="wd-publish-note"
                        />
                        <button
                            type="button"
                            class="wd-btn"
                            :disabled="!dirty"
                            data-testid="wd-discard"
                            @click="discard"
                        >
                            {{ t.discard }}
                        </button>
                        <button
                            type="button"
                            class="wd-btn wd-btn-primary"
                            :disabled="!dirty || publishing || refusal !== null"
                            data-testid="wd-publish"
                            @click="publish"
                        >
                            {{ publishing ? t.publishing : t.publish }}
                        </button>
                    </template>
                </div>
            </header>
            <p v-if="flash" class="wd-flash" role="status" data-testid="wd-flash">{{ flash }}</p>
            <p
                v-if="refusal && refusal.refusal_code !== 'contrast'"
                class="wd-error"
                role="alert"
                data-testid="wd-refusal"
            >
                {{
                    tr(t.invalid_fields, {
                        fields:
                            Object.keys(refusal.errors ?? {}).join(', ') || refusal.refusal_code,
                    })
                }}
            </p>

            <div class="wd-tabs" role="tablist" :aria-label="widget.name">
                <button
                    v-for="k in tabs"
                    :id="'wd-tab-' + k"
                    :key="k"
                    type="button"
                    role="tab"
                    :aria-selected="tab === k"
                    :aria-controls="'wd-panel-' + k"
                    :class="{ on: tab === k }"
                    :data-testid="'wd-tab-' + k"
                    @click="tab = k"
                >
                    {{ t['tab_' + k] }}
                </button>
            </div>

            <section
                v-show="tab === 'appearance'"
                id="wd-panel-appearance"
                role="tabpanel"
                aria-labelledby="wd-tab-appearance"
            >
                <WidgetAppearance
                    :copy="t"
                    :refusal="refusal"
                    :fonts="fonts"
                    :icons="icons"
                    :widget-id="widget.id"
                    :language="language"
                    @change="changed"
                    @apply="applySuggestion"
                />
            </section>
            <section
                v-show="tab === 'content'"
                id="wd-panel-content"
                role="tabpanel"
                aria-labelledby="wd-tab-content"
            >
                <WidgetContent
                    :copy="t"
                    :warnings="warnings"
                    :forms="forms"
                    :languages="languages"
                    agent-identity="first_name"
                    @change="changed"
                />
            </section>
            <section
                v-show="tab === 'behavior'"
                id="wd-panel-behavior"
                role="tabpanel"
                aria-labelledby="wd-tab-behavior"
            >
                <WidgetBehavior
                    :copy="t"
                    :chatbot="chatbot"
                    :voice-flag="voice_flag"
                    :cooldown-hours="24"
                    :departments="departments"
                    :languages="languages"
                    @change="changed"
                />
            </section>
            <section
                v-show="tab === 'install'"
                id="wd-panel-install"
                role="tabpanel"
                aria-labelledby="wd-tab-install"
            >
                <WidgetInstall
                    :copy="t"
                    :widget-id="widget.id"
                    :install="install"
                    :origins="widget.allowed_origins"
                    :has-secret="hasSecret"
                    :overlap-hours="widget.overlap_hours"
                    :can-configure="canConfigure"
                    @secret="hasSecret = true"
                />
            </section>
            <section
                v-show="tab === 'health'"
                id="wd-panel-health"
                role="tabpanel"
                aria-labelledby="wd-tab-health"
            >
                <WidgetHealth
                    :copy="t"
                    :widget-id="widget.id"
                    :health="health"
                    :history="history"
                    :frame-src="install.frame_src"
                    :can-configure="canConfigure"
                    @rolled-back="reload"
                />
            </section>
        </div>
    </BackofficeShell>
</template>

<style>
.wd-editor {
    display: grid;
    gap: 12px;
    font-size: 12.5px;
    color: var(--ds-text-primary);
    min-width: 0;
    max-width: 100%;
    overflow-x: hidden;
}
.wd-editor > *,
.wd-two > *,
.wd-stack > *,
.wd-field > * {
    min-width: 0;
}
.wd-editor-head {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    align-items: flex-start;
    flex-wrap: wrap;
}
.wd-editor-title {
    margin: 0;
    font-size: 15px;
}
.wd-publish input {
    min-height: 36px;
    padding: 6px 10px;
    border: 1px solid var(--ds-border);
    border-radius: 8px;
    background: var(--ds-panel-bg);
    color: var(--ds-text-primary);
    font: inherit;
}
.wd-status {
    color: var(--ds-text-secondary);
}
.wd-status[data-dirty='true'] {
    color: var(--ds-warning-text, var(--ds-text-primary));
    font-weight: 600;
}
.wd-flash {
    margin: 0;
    color: var(--ds-success-text, var(--ds-text-primary));
}
.wd-tabs {
    display: flex;
    gap: 2px;
    border-bottom: 1px solid var(--ds-border);
    overflow-x: auto;
    max-width: 100%;
}
.wd-tabs button {
    flex: none;
    white-space: nowrap;
    min-height: 36px;
    padding: 0 12px;
    border: 0;
    border-bottom: 2px solid transparent;
    background: transparent;
    color: var(--ds-text-secondary);
    font: inherit;
    font-weight: 600;
    cursor: pointer;
}
.wd-tabs button.on {
    color: var(--ds-text-primary);
    border-bottom-color: var(--ds-accent);
}
.wd-tabs button:focus-visible {
    outline: 3px solid var(--ds-focus-ring);
    outline-offset: -3px;
}
.wd-two {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1.2fr);
    gap: 20px;
}
.wd-stack {
    display: grid;
    gap: 14px;
    align-content: start;
}
.wd-stack h3,
.wd-preview h3 {
    margin: 0;
    font-size: 12.5px;
}
fieldset.wd-field {
    border: 0;
    padding: 0;
    margin: 0;
}
.wd-field legend,
.wd-field > span:first-child {
    font-weight: 600;
    color: var(--ds-text-label);
    margin-bottom: 4px;
    display: block;
}
.wd-inline {
    display: inline-grid;
}
.wd-swatch {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    border: 1px solid var(--ds-border-strong);
    background: var(--ds-panel-bg);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    font-size: 16px;
}
.wd-swatch.on {
    outline: 2px solid var(--ds-accent);
    outline-offset: 2px;
}
.wd-swatch-dot {
    width: 18px;
    height: 18px;
    border-radius: 50%;
    display: inline-block;
    border: 1px solid var(--ds-border);
}
.wd-refusal {
    border: 1px solid var(--ds-danger-border, var(--ds-border-strong));
    background: var(--ds-danger-bg, var(--ds-inset-bg));
    border-radius: 10px;
    padding: 12px;
    display: grid;
    gap: 6px;
}
.wd-refusal p {
    margin: 0;
}
.wd-refusal-title {
    font-weight: 600;
}
.wd-btn-apply {
    min-height: 36px;
    justify-self: start;
}
.wd-readonly {
    margin: 0;
    padding: 8px 10px;
    background: var(--ds-inset-bg);
    border-radius: 8px;
}
.wd-warn {
    margin: 0;
    padding: 8px 10px;
    border-radius: 8px;
    background: var(--ds-warning-bg, var(--ds-inset-bg));
    color: var(--ds-warning-text, var(--ds-text-primary));
}
.wd-seg {
    display: inline-flex;
    border: 1px solid var(--ds-border-strong);
    border-radius: 8px;
    overflow: hidden;
}
.wd-seg button {
    min-height: 32px;
    padding: 0 10px;
    border: 0;
    background: var(--ds-panel-bg);
    color: var(--ds-text-secondary);
    font: inherit;
    font-weight: 600;
    cursor: pointer;
}
.wd-seg button.on {
    background: var(--ds-accent);
    color: var(--ds-accent-contrast);
}
.wd-check,
.wd-switch {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    min-height: 36px;
}
.wd-switch {
    justify-content: space-between;
    width: 100%;
    max-width: 520px;
}
.wd-switches {
    display: grid;
    gap: 6px;
}
.wd-shortcuts,
.wd-rules,
.wd-history {
    list-style: none;
    margin: 0;
    padding: 0;
    display: grid;
    gap: 6px;
}
.wd-shortcut {
    display: grid;
    grid-template-columns: auto minmax(0, 1.4fr) auto minmax(0, 1fr) auto auto auto;
    gap: 6px;
    align-items: center;
}
.wd-shortcut input,
.wd-shortcut select,
.wd-rule input,
.wd-rule select {
    min-height: 36px;
    padding: 4px 8px;
    border: 1px solid var(--ds-border);
    border-radius: 8px;
    background: var(--ds-panel-bg);
    color: var(--ds-text-primary);
    font: inherit;
    min-width: 0;
}
.wd-grip {
    color: var(--ds-text-muted);
}
.wd-icon {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    border: 1px solid var(--ds-border);
    background: var(--ds-panel-bg);
    color: var(--ds-text-primary);
    cursor: pointer;
}
.wd-rule {
    display: grid;
    gap: 6px;
    padding: 8px;
    border: 1px solid var(--ds-border);
    border-radius: 10px;
}
.wd-chips {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}
.wd-chip {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 8px;
    border-radius: 999px;
    background: var(--ds-inset-bg);
}
.wd-chip .wd-icon {
    width: 28px;
    height: 28px;
}
.wd-stats {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 8px;
}
.wd-stat {
    padding: 10px 12px;
    border: 1px solid var(--ds-border);
    border-radius: 10px;
    display: grid;
}
.wd-stat b {
    font-size: 18px;
}
.wd-stat span {
    color: var(--ds-text-secondary);
}
.wd-diag {
    display: grid;
    gap: 6px;
}
.wd-history-row {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    align-items: center;
    padding: 8px 0;
    border-bottom: 1px solid var(--ds-border);
}
.wd-preview {
    display: grid;
    gap: 10px;
    align-content: start;
}
.wd-preview-head {
    justify-content: space-between;
}
.wd-preview-frames {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
}
.wd-preview-frame {
    margin: 0;
    display: grid;
    gap: 4px;
}
.wd-preview-frame iframe {
    width: 100%;
    border: 1px solid var(--ds-border);
    border-radius: 14px;
    background: var(--ds-page-bg);
    height: 560px;
}
.wd-preview[data-frame='mobile'] .wd-preview-frame iframe {
    max-width: 375px;
    height: 640px;
    justify-self: center;
}
.wd-preview-frame figcaption {
    color: var(--ds-text-secondary);
    text-align: center;
}
@media (max-width: 1023px) {
    .wd-two {
        grid-template-columns: 1fr;
    }
    .wd-preview-frames {
        grid-template-columns: 1fr;
    }
    .wd-stats {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}
@media (max-width: 640px) {
    .wd-btn,
    .wd-btn-apply,
    .wd-icon,
    .wd-swatch,
    .wd-tabs button {
        min-height: 44px;
    }
    .wd-icon,
    .wd-swatch {
        width: 44px;
    }
    .wd-shortcut {
        grid-template-columns: 1fr 1fr;
    }
}
</style>
