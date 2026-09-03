<script setup lang="ts">
// Doc 09 FR-18 Persona — SESSION-F1 [B8]: the frozen B8-S04-personas-v1 anatomy.
// Settings › AI › Persona in the B6-S11 shell; two columns (editor · live preview
// against the 5 canned conversations); tone as a WORD value ("Friendly +1");
// the disclosure lock (X3: wording editable, presence platform law); language
// tabs with the §14 gate that names what is missing; Publish stays locked with
// its reason ON the button until every canned conversation is previewed for
// the pending draft (FR-18), then the confirm names the three facts (instant,
// versioned, reversible); versions with one-click rollback (FR-04).
import { computed, ref } from 'vue';
import AiSettingsShell from '../../../Components/Ai/AiSettingsShell.vue';
import { adminCall } from './adminApi';

type Preview = {
    state: string;
    results?: {
        dialog: string;
        language: string;
        action: string;
        text: string | null;
        reason: string | null;
    }[];
};
type Turn = { role: string; content: string };
const props = defineProps<{
    t: Record<string, string>;
    published: {
        id: string;
        version: number;
        payload: Record<string, unknown>;
        published_at: string | null;
    } | null;
    history: {
        id: string;
        key: string;
        version: number;
        status: string;
        created_at: string;
        published_at?: string | null;
        published_by_name?: string | null;
    }[];
    disclosure: Record<string, string>;
    languages: string[];
    dialogs: { name: string; language: string; turns: Turn[] }[];
    gates: Record<string, string[]>;
    setup: {
        kb_published: boolean;
        persona_published: boolean;
        categories_ai_usable: boolean;
        kb_url: string;
        categories_url: string;
    } | null;
}>();

function fill(template: string | undefined, vars: Record<string, string | number>): string {
    let out = template ?? '';
    for (const [k, v] of Object.entries(vars)) out = out.replaceAll(':' + k, String(v));
    return out;
}
const payload = props.published?.payload ?? {};
const name = ref(String(payload.name ?? ''));
const avatar = ref(String(payload.avatar_ref ?? 'spark'));
const greeting = ref<Record<string, string>>(
    Object.fromEntries(
        props.languages.map((l) => [
            l,
            String((payload.greeting as Record<string, string> | undefined)?.[l] ?? ''),
        ]),
    ),
);
const tone = ref({
    formality: Number((payload.tone as { formality?: number } | undefined)?.formality ?? 50),
    detail: Number((payload.tone as { detail?: number } | undefined)?.detail ?? 50),
});
const activeLang = ref(props.languages[0] ?? 'en');
const draftId = ref<string | null>(null);
const previewedDraft = ref<string | null>(null);
const preview = ref<Preview | null>(null);
const dialogIndex = ref(0);
const confirming = ref(false);
const message = ref('');
const busy = ref(false);
const dirty = ref(false);

const nextVersion = computed(() => (props.published?.version ?? 0) + 1);
const gated = computed(() => (props.gates[activeLang.value] ?? []).length > 0);
const previewReady = computed(
    () =>
        draftId.value !== null &&
        previewedDraft.value === draftId.value &&
        preview.value?.state === 'ready',
);
const canPublish = computed(() => previewReady.value && !busy.value);
const dialog = computed(() => props.dialogs[dialogIndex.value] ?? null);
const dialogResult = computed(
    () => preview.value?.results?.find((r) => r.dialog === dialog.value?.name) ?? null,
);
const chipText = computed(() => {
    if (previewReady.value)
        return fill(props.t.chip_previewed, {
            n: props.dialogs.length,
            langs: props.languages.map((l) => props.t['lang_' + l] ?? l).join(' + '),
        });
    if (dirty.value || draftId.value) return props.t.chip_draft ?? '';
    return props.published
        ? fill(props.t.chip_published, { version: props.published.version })
        : (props.t.chip_unpublished ?? '');
});
function toneWord(v: number, low: string, high: string): string {
    const step = Math.round((v - 50) / 25); // −2 … +2
    if (step === 0) return fill(props.t.tone_balanced, { n: 0 });
    const label = step > 0 ? high : low;
    return `${label} ${step > 0 ? '+' : '−'}${Math.abs(step)}`;
}
const formalityWord = computed(() =>
    toneWord(tone.value.formality, props.t.tone_formal ?? '', props.t.tone_friendly ?? ''),
);
const detailWord = computed(() =>
    toneWord(tone.value.detail, props.t.tone_brief ?? '', props.t.tone_detailed ?? ''),
);
const avatarMarks = computed(() => [
    { key: 'spark', glyph: '✦' },
    { key: 'am', glyph: name.value.match(/[ሀ-፿]/)?.[0] ?? 'ሰ' },
    { key: 'en', glyph: (name.value.match(/[A-Za-z]/)?.[0] ?? 'S').toUpperCase() },
]);
function touch(): void {
    dirty.value = true;
    previewedDraft.value = null;
    preview.value = null;
}
function initials(): string {
    return name.value.trim() || (props.t.persona_fallback_name ?? '');
}

async function saveDraft(): Promise<string | null> {
    busy.value = true;
    message.value = '';
    try {
        // the door's greeting rules are `sometimes|string` — an empty textarea is OMITTED, never sent as '' (→ null → refused)
        const filledGreetings = Object.fromEntries(
            Object.entries(greeting.value).filter(([, v]) => v.trim() !== ''),
        );
        const r = await adminCall<{ version_id: string }>('/admin/ai/persona/draft', {
            name: name.value,
            avatar_ref: avatar.value,
            greeting: filledGreetings,
            tone: tone.value,
        });
        draftId.value = r.version_id;
        dirty.value = false;
        preview.value = null;
        previewedDraft.value = null;
        message.value = props.t.draft_saved ?? '';
        return r.version_id;
    } catch (e) {
        message.value = (e as Error).message;
        return null;
    } finally {
        busy.value = false;
    }
}
async function runPreview(): Promise<void> {
    let id = draftId.value;
    if (!id || dirty.value) id = await saveDraft();
    if (!id) return;
    busy.value = true;
    try {
        const r = await adminCall<{ preview_id: string }>(`/admin/ai/persona/${id}/preview`, {});
        preview.value = { state: 'pending' };
        for (let i = 0; i < 40; i++) {
            await new Promise((resolve) => setTimeout(resolve, 1500));
            const p = await adminCall<Preview>(
                `/admin/ai/persona/preview/${r.preview_id}`,
                undefined,
                'GET',
            );
            if (p.state === 'ready') {
                preview.value = p;
                previewedDraft.value = id;
                break;
            }
        }
        if (preview.value?.state !== 'ready') message.value = props.t.preview_timeout ?? '';
    } catch (e) {
        message.value = (e as Error).message;
    } finally {
        busy.value = false;
    }
}
async function publish(): Promise<void> {
    if (!canPublish.value || !draftId.value) return;
    busy.value = true;
    try {
        await adminCall(`/admin/ai/versions/${draftId.value}/publish`, {});
        message.value = props.t.published ?? '';
        window.location.reload();
    } catch (e) {
        message.value = (e as Error).message; // e.g. the FR-19a refusal, verbatim
        confirming.value = false;
    } finally {
        busy.value = false;
    }
}
async function rollback(id: string): Promise<void> {
    busy.value = true;
    try {
        await adminCall(`/admin/ai/versions/${id}/rollback`, {});
        window.location.reload();
    } catch (e) {
        message.value = (e as Error).message;
    } finally {
        busy.value = false;
    }
}
function dateOf(row: { published_at?: string | null; created_at: string }): string {
    return (row.published_at ?? row.created_at).slice(0, 16).replace('T', ' ');
}
const gateBodyText = computed(() =>
    fill(props.t.gate_body, {
        missing: (props.gates[activeLang.value] ?? [])
            .map((m) => props.t['gate_' + m.toLowerCase()] ?? m)
            .join(', '),
        lang: props.t['lang_' + activeLang.value] ?? activeLang.value,
    }),
);
</script>

<template>
    <AiSettingsShell :t="t" :page="t.persona_page" :setup="setup">
        <template #chip>
            <span class="ai-vchip" data-testid="persona-chip">{{ chipText }}</span>
        </template>

        <p
            v-if="message"
            role="status"
            data-testid="persona-message"
            class="mx-4 mt-3 rounded border px-2 py-1 text-xs"
            :style="{ borderColor: 'var(--ds-border-strong)' }"
        >
            {{ message }}
        </p>

        <div class="ps-body">
            <!-- ── the editor column ── -->
            <div class="ps-edcol">
                <div class="ai-field">
                    <label class="ai-lab" for="persona-name">{{ t.name }}</label>
                    <input
                        id="persona-name"
                        v-model="name"
                        data-testid="persona-name"
                        class="ai-in"
                        @input="touch"
                    />
                </div>

                <div class="ai-field">
                    <span id="persona-avatar-label" class="ai-lab">{{ t.avatar }}</span>
                    <div class="ps-avrow" role="radiogroup" aria-labelledby="persona-avatar-label">
                        <button
                            v-for="m in avatarMarks"
                            :key="m.key"
                            type="button"
                            role="radio"
                            :aria-checked="avatar === m.key"
                            :aria-label="t['avatar_' + m.key]"
                            class="ps-av"
                            :class="{
                                'ps-av-alt': m.key !== 'spark',
                                'ps-av-sel': avatar === m.key,
                            }"
                            :data-testid="'persona-avatar-' + m.key"
                            @click="
                                avatar = m.key;
                                touch();
                            "
                        >
                            {{ m.glyph }}
                        </button>
                        <span class="ps-avnote">{{ t.avatar_note }}</span>
                    </div>
                </div>

                <div class="ps-langs" role="tablist" :aria-label="t.languages">
                    <button
                        v-for="l in languages"
                        :key="l"
                        type="button"
                        role="tab"
                        :aria-selected="activeLang === l"
                        class="ps-ltab"
                        :class="{ on: activeLang === l }"
                        :data-testid="'persona-lang-' + l"
                        @click="activeLang = l"
                    >
                        {{ t['lang_' + l] ?? l }}
                        <span v-if="(gates[l] ?? []).length" class="ps-gate">{{ t.gated }}</span>
                    </button>
                </div>

                <!-- D05: the §14 gate names what is missing and links to where each lives; drafting stays open -->
                <div
                    v-if="gated"
                    class="ai-lockrow ai-lockrow-warn ps-gatebox"
                    role="status"
                    data-testid="persona-gate"
                >
                    <span aria-hidden="true">⚠</span>
                    <span>
                        <b>{{
                            fill(t.gate_title, { lang: t['lang_' + activeLang] ?? activeLang })
                        }}</b>
                        {{ gateBodyText }}
                    </span>
                </div>
                <div v-if="gated" class="ps-gateacts">
                    <a href="/admin/ai/guardrails" class="ai-btn">{{ t.gate_go_rg }}</a>
                    <a href="/admin/ai/guardrails" class="ai-btn">{{ t.gate_go_disclosure }}</a>
                </div>

                <div
                    v-for="l in languages"
                    v-show="activeLang === l"
                    :key="'g' + l"
                    role="tabpanel"
                >
                    <div class="ai-field">
                        <label class="ai-lab" :for="'persona-greeting-' + l"
                            >{{ t.greeting }} — {{ t['lang_' + l] ?? l }}</label
                        >
                        <textarea
                            :id="'persona-greeting-' + l"
                            v-model="greeting[l]"
                            :data-testid="'persona-greeting-' + l"
                            rows="3"
                            class="ai-ta"
                            @input="touch"
                        />
                    </div>
                    <div class="ai-field">
                        <label class="ai-lab" :for="'persona-disclosure-' + l"
                            >{{ t.disclosure_wording }} — {{ t['lang_' + l] ?? l }}</label
                        >
                        <input
                            :id="'persona-disclosure-' + l"
                            class="ai-in ps-wide"
                            :value="disclosure[l]"
                            readonly
                            :data-testid="'persona-disclosure-' + l"
                        />
                        <div
                            class="ai-lockrow mt-1.5"
                            :data-testid="'persona-disclosure-lock-' + l"
                        >
                            <span aria-hidden="true">🔒</span>
                            <span
                                ><b>{{ t.lock_title }}</b> {{ t.lock_body }}</span
                            >
                        </div>
                    </div>
                </div>

                <div class="ai-field">
                    <span class="ai-lab">{{ t.tone }}</span>
                    <div class="ps-slider">
                        <div class="ps-sl-lab" aria-hidden="true">
                            <span>{{ t.tone_formal }}</span
                            ><span>{{ t.tone_friendly }}</span>
                        </div>
                        <input
                            v-model.number="tone.formality"
                            type="range"
                            min="0"
                            max="100"
                            step="25"
                            class="ps-range"
                            data-testid="tone-formality"
                            :aria-label="t.tone_axis_formality"
                            :aria-valuetext="formalityWord"
                            @input="touch"
                        />
                        <div class="ps-sl-val" data-testid="tone-formality-word">
                            {{ formalityWord }}
                        </div>
                    </div>
                    <div class="ps-slider mt-3">
                        <div class="ps-sl-lab" aria-hidden="true">
                            <span>{{ t.tone_brief }}</span
                            ><span>{{ t.tone_detailed }}</span>
                        </div>
                        <input
                            v-model.number="tone.detail"
                            type="range"
                            min="0"
                            max="100"
                            step="25"
                            class="ps-range"
                            data-testid="tone-detail"
                            :aria-label="t.tone_axis_detail"
                            :aria-valuetext="detailWord"
                            @input="touch"
                        />
                        <div class="ps-sl-val" data-testid="tone-detail-word">{{ detailWord }}</div>
                    </div>
                </div>
            </div>

            <!-- ── the live preview column (D02) ── -->
            <div class="ps-pvcol">
                <div class="ps-pv-h">
                    <span class="ai-lab" style="margin: 0"
                        >{{ t.preview_title
                        }}<template v-if="dialog">
                            — {{ t['lang_' + dialog.language] ?? dialog.language }}</template
                        ></span
                    >
                    <label class="ps-selbox"
                        ><span class="sr-only">{{ t.dialog }}</span>
                        <select
                            v-model.number="dialogIndex"
                            data-testid="persona-dialog"
                            class="ps-select"
                        >
                            <option v-for="(d, i) in dialogs" :key="d.name" :value="i">
                                {{ fill(t.conversation_n_of, { n: i + 1, total: dialogs.length }) }}
                                · {{ t['dialog_' + d.name] ?? d.name }}
                            </option>
                        </select></label
                    >
                </div>
                <div
                    class="ps-chat"
                    data-testid="persona-preview-results"
                    :aria-label="t.preview_title"
                    role="log"
                >
                    <template v-if="dialog">
                        <div v-for="(turn, i) in dialog.turns" :key="i" class="ps-cb">
                            <div class="ps-bx">{{ turn.content }}</div>
                        </div>
                    </template>
                    <div class="ps-bb">
                        <div class="ps-bx ps-bx-ai">
                            <div class="ps-ail">✦ {{ initials() }} · {{ t.preview_tag }}</div>
                            <div
                                v-if="preview?.state === 'pending'"
                                class="ps-bt"
                                data-testid="persona-preview-pending"
                            >
                                {{ t.preview_running }}
                            </div>
                            <div
                                v-else-if="dialogResult"
                                class="ps-bt whitespace-pre-wrap"
                                data-testid="persona-preview-text"
                            >
                                <template v-if="dialogResult.text">{{
                                    dialogResult.text
                                }}</template>
                                <template v-else
                                    >{{ dialogResult.action
                                    }}<span v-if="dialogResult.reason">
                                        ({{ dialogResult.reason }})</span
                                    ></template
                                >
                            </div>
                            <div v-else class="ps-bt" :style="{ color: 'var(--ds-text-muted)' }">
                                {{ t.preview_placeholder }}
                            </div>
                        </div>
                    </div>
                </div>
                <p class="ps-pvnote">{{ t.preview_note }}</p>
            </div>
        </div>

        <!-- ── the publish bar (D03) ── -->
        <div class="ps-pubbar">
            <span class="ai-vchip">{{ chipText }}</span>
            <div class="ps-pubacts">
                <button
                    type="button"
                    data-testid="persona-save"
                    class="ai-btn"
                    :disabled="busy"
                    @click="saveDraft"
                >
                    {{ t.save_draft }}
                </button>
                <button
                    type="button"
                    data-testid="persona-preview"
                    class="ai-btn"
                    :disabled="busy"
                    @click="runPreview"
                >
                    {{ previewReady ? t.preview_again : t.preview }}
                </button>
                <button
                    type="button"
                    data-testid="persona-publish"
                    class="ai-btn ai-btn-pri"
                    :disabled="!canPublish"
                    @click="confirming = true"
                >
                    {{
                        canPublish
                            ? fill(t.publish_v, { version: nextVersion })
                            : fill(t.publish_locked, { version: nextVersion })
                    }}
                </button>
            </div>
        </div>

        <!-- D03: versions + the one-click rollback -->
        <section class="ps-versions" :aria-label="t.history">
            <span class="ai-lab">{{ t.history }}</span>
            <div
                v-for="h in history"
                :key="h.id"
                class="ai-vrow"
                :data-testid="'persona-version-' + h.version"
            >
                <div>
                    <b>v{{ h.version }}</b>
                    <span v-if="h.status === 'published'" class="ai-pill-live">{{ t.live }}</span>
                    <span v-else class="ai-who ms-1">{{
                        t['status_' + h.status] ?? h.status
                    }}</span>
                    <div class="ai-who">
                        {{
                            fill(t.version_who, {
                                date: dateOf(h),
                                actor: h.published_by_name ?? '—',
                            })
                        }}
                    </div>
                </div>
                <button v-if="h.status === 'published'" type="button" class="ai-btn" disabled>
                    {{ t.current }}
                </button>
                <button
                    v-else-if="h.status === 'superseded'"
                    type="button"
                    class="ai-btn"
                    :data-testid="'rollback-' + h.version"
                    :disabled="busy"
                    @click="rollback(h.id)"
                >
                    {{ fill(t.rollback_to, { version: h.version }) }}
                </button>
            </div>
            <p v-if="!history.length" class="ai-who">{{ t.no_versions }}</p>
        </section>

        <!-- the publish confirm: instant, versioned, reversible -->
        <div v-if="confirming" class="ai-modal-backdrop" @click.self="confirming = false">
            <div
                class="ai-modal"
                role="dialog"
                aria-modal="true"
                :aria-label="fill(t.publish_confirm_title, { version: nextVersion })"
                data-testid="persona-publish-dialog"
            >
                <h2>{{ fill(t.publish_confirm_title, { version: nextVersion }) }}</h2>
                <p>
                    {{
                        fill(t.publish_confirm_body, {
                            version: nextVersion,
                            prev: published?.version ?? 0,
                        })
                    }}
                </p>
                <div class="ai-modal-acts">
                    <button type="button" class="ai-btn" @click="confirming = false">
                        {{ t.cancel }}
                    </button>
                    <button
                        type="button"
                        class="ai-btn ai-btn-pri"
                        data-testid="persona-publish-confirm"
                        :disabled="busy"
                        @click="publish"
                    >
                        {{ t.publish_now }}
                    </button>
                </div>
            </div>
        </div>
    </AiSettingsShell>
</template>

<style scoped>
.ps-body {
    display: grid;
    grid-template-columns: 1fr 380px;
}
.ps-edcol {
    padding: 16px;
    border-inline-end: 1px solid var(--ds-border);
}
.ps-pvcol {
    padding: 16px;
    background: var(--ds-panel-soft);
}
.ps-wide {
    max-width: 520px;
}
.ps-avrow {
    display: flex;
    gap: 8px;
    align-items: center;
    flex-wrap: wrap;
}
.ps-av {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 15px;
    font-weight: 700;
    background: var(--ds-ai-bg);
    color: var(--ds-ai-fg);
    border: 1px solid var(--ds-ai-border);
}
.ps-av-alt {
    background: var(--ds-inset-bg);
    color: var(--ds-text-secondary);
    border-color: var(--ds-border-strong);
}
.ps-av-sel {
    outline: 2px solid var(--ds-ai-edge);
    outline-offset: 2px;
}
.ps-avnote {
    font-size: 10.5px;
    color: var(--ds-text-muted);
}
.ps-langs {
    display: flex;
    gap: 2px;
    border-bottom: 1px solid var(--ds-border);
    margin: 4px 0 14px;
    flex-wrap: wrap;
}
.ps-ltab {
    font-size: 11px;
    font-weight: 600;
    padding: 7px 12px;
    color: var(--ds-text-muted);
    border: 0;
    border-bottom: 2px solid transparent;
    background: transparent;
    display: inline-flex;
    gap: 6px;
    align-items: center;
}
.ps-ltab.on {
    color: var(--ds-text-primary);
    border-bottom-color: var(--ds-text-primary);
}
.ps-gate {
    font-size: 9px;
    font-weight: 700;
    background: var(--ds-warn-bg);
    color: var(--ds-warn-text);
    border: 1px solid var(--ds-warn-border);
    border-radius: 4px;
    padding: 1px 5px;
}
.ps-gatebox {
    margin-bottom: 10px;
}
.ps-gateacts {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    margin-bottom: 14px;
}
.ps-slider {
    max-width: 360px;
    margin-top: 4px;
}
.ps-sl-lab {
    display: flex;
    justify-content: space-between;
    font-size: 10.5px;
    color: var(--ds-text-muted);
    margin-bottom: 4px;
}
.ps-range {
    width: 100%;
    accent-color: var(--ds-ai-edge);
}
.ps-sl-val {
    font-size: 10.5px;
    font-weight: 600;
    color: var(--ds-text-secondary);
    margin-top: 5px;
}
.ps-pv-h {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 8px;
    margin-bottom: 10px;
    flex-wrap: wrap;
}
.ps-selbox {
    display: inline-flex;
}
.ps-select {
    font-size: 11px;
    font-weight: 600;
    border: 1px solid var(--ds-border-strong);
    background: var(--ds-panel-bg);
    color: var(--ds-text-primary);
    border-radius: 6px;
    padding: 5px 9px;
    max-width: 100%;
}
.ps-chat {
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.ps-cb {
    align-self: flex-end;
    max-width: 86%;
}
.ps-cb .ps-bx {
    background: var(--ds-inset-bg);
    border: 1px solid var(--ds-border-strong);
    border-radius: 10px 10px 3px 10px;
    padding: 7px 10px;
    font-size: 12px;
    line-height: 1.5;
    color: var(--ds-text-primary);
}
.ps-bb {
    align-self: flex-start;
    max-width: 86%;
}
.ps-bx-ai {
    background: var(--ds-ai-bg);
    border: 1px solid var(--ds-ai-border);
    border-inline-start: 3px solid var(--ds-ai-edge);
    border-radius: 10px 10px 10px 3px;
    padding: 7px 10px;
}
.ps-ail {
    font-size: 9px;
    font-weight: 700;
    color: var(--ds-ai-fg);
    letter-spacing: 0.05em;
    text-transform: uppercase;
    margin-bottom: 2px;
}
.ps-bt {
    font-size: 12px;
    line-height: 1.5;
    color: var(--ds-text-primary);
}
.ps-pvnote {
    font-size: 10.5px;
    color: var(--ds-text-muted);
    margin: 8px 0 0;
}
.ps-pubbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    padding: 12px 16px;
    border-top: 1px solid var(--ds-border);
    flex-wrap: wrap;
}
.ps-pubacts {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}
.ps-versions {
    padding: 12px 16px 16px;
    border-top: 1px solid var(--ds-border);
}
@media (max-width: 1023px) {
    .ps-body {
        grid-template-columns: 1fr;
    }
    .ps-edcol {
        border-inline-end: 0;
        border-bottom: 1px solid var(--ds-border);
    }
}
</style>
