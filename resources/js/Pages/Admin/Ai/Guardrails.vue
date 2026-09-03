<script setup lang="ts">
// Doc 09 FR-18 Guardrails — SESSION-F1 [B8]: the frozen B8-S06-guardrails-v1 anatomy.
// Blocked topics as rows: the platform-seeded floor wears a lock (no delete path
// exists for it — absent, not disabled), the operator's own rows delete behind a
// confirm that states the consequence; the disclosure block under the same lock
// the persona editor shows (X3); the RG template per language — sent byte for
// byte (X2), previewed in the calm slate it ships in, each language tab carrying
// COMPLETE or its gate; every limit with its consequence beside the control and
// the PII toggle carrying its state in words. Draft → publish through the audited
// door, unchanged.
import { computed, ref } from 'vue';
import AiSettingsShell from '../../../Components/Ai/AiSettingsShell.vue';
import { adminCall } from './adminApi';

const props = defineProps<{
    t: Record<string, string>;
    published: { id: string; version: number; payload: Record<string, unknown> } | null;
    history: {
        id: string;
        version: number;
        status: string;
        created_at: string;
        published_at?: string | null;
        published_by_name?: string | null;
    }[];
    // blocked_topics arrives as the domain's SEEDED map (name → pattern) — the names are the platform floor.
    defaults: {
        disclosure: Record<string, string>;
        rg_template: Record<string, string>;
        blocked_topics: string[] | Record<string, string>;
        deflection?: Record<string, string>;
    };
    setup?: {
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
const LANGS = ['en', 'am'];
const p = props.published?.payload ?? {};
const seededNames: string[] = Array.isArray(props.defaults.blocked_topics)
    ? props.defaults.blocked_topics
    : Object.keys(props.defaults.blocked_topics);
const blocked = ref<string[]>(
    Array.isArray(p.blocked_topics) ? [...(p.blocked_topics as string[])] : [...seededNames],
);
const newTopic = ref('');
const adding = ref(false);
const deleting = ref<string | null>(null);
const disclosure = ref<Record<string, string>>(
    Object.fromEntries(
        LANGS.map((l) => [
            l,
            String((p.disclosure as Record<string, string> | undefined)?.[l] ?? ''),
        ]),
    ),
);
const rgTemplate = ref<Record<string, string>>(
    Object.fromEntries(
        LANGS.map((l) => [
            l,
            String((p.rg_template as Record<string, string> | undefined)?.[l] ?? ''),
        ]),
    ),
);
const rgLang = ref('am');
const maxAi = ref(Number(p.max_ai_messages ?? 8));
const profanity = ref(String(p.profanity_policy ?? 'soften'));
const pii = ref(p.pii_redaction !== false);
const message = ref('');
const draftId = ref<string | null>(null);
const busy = ref(false);
const dirty = ref(false);

const seeded = computed(() => new Set(seededNames));
const nextVersion = computed(() => (props.published?.version ?? 0) + 1);
const chipText = computed(() =>
    dirty.value || draftId.value
        ? (props.t.chip_draft ?? '')
        : props.published
          ? fill(props.t.chip_published, { version: props.published.version })
          : (props.t.chip_unpublished ?? ''),
);
function effectiveRg(l: string): string {
    return (rgTemplate.value[l] ?? '').trim() || props.defaults.rg_template[l] || '';
}
function rgComplete(l: string): boolean {
    return effectiveRg(l).trim() !== '';
}
function touch(): void {
    dirty.value = true;
}
function addTopic(): void {
    const v = newTopic.value.trim();
    if (!v || blocked.value.includes(v)) return;
    blocked.value.push(v);
    newTopic.value = '';
    adding.value = false;
    touch();
}
function removeTopic(topic: string): void {
    if (seeded.value.has(topic)) return; // structurally absent for the platform floor — never reached from the UI
    blocked.value = blocked.value.filter((x) => x !== topic);
    deleting.value = null;
    touch();
}
async function save(): Promise<void> {
    busy.value = true;
    message.value = '';
    try {
        const r = await adminCall<{ version_id: string }>('/admin/ai/guardrails/draft', {
            blocked_topics: blocked.value,
            disclosure: disclosure.value,
            rg_template: rgTemplate.value,
            max_ai_messages: maxAi.value,
            profanity_policy: profanity.value,
            pii_redaction: pii.value,
        });
        draftId.value = r.version_id;
        dirty.value = false;
        message.value = props.t.draft_saved ?? '';
    } catch (e) {
        message.value = (e as Error).message;
    } finally {
        busy.value = false;
    }
}
async function publish(): Promise<void> {
    if (!draftId.value || dirty.value) await save();
    if (!draftId.value) return;
    busy.value = true;
    try {
        await adminCall(`/admin/ai/versions/${draftId.value}/publish`, {});
        window.location.reload();
    } catch (e) {
        message.value = (e as Error).message;
    } finally {
        busy.value = false;
    }
}
const MAX_AI_KEY = 'max_ai_messages'; // the setting's registry key, shown verbatim as the frame draws it
</script>

<template>
    <AiSettingsShell :t="t" :page="t.guardrails_page" :setup="setup">
        <template #chip>
            <span class="ai-vchip" data-testid="guardrails-chip">{{ chipText }}</span>
        </template>

        <p
            v-if="message"
            role="status"
            data-testid="guardrails-message"
            class="mx-4 mt-3 rounded border px-2 py-1 text-xs"
            :style="{ borderColor: 'var(--ds-border-strong)' }"
        >
            {{ message }}
        </p>

        <!-- ── F01/F06: blocked topics — the seeded floor plus the operator's own ── -->
        <section class="gr-sec" :aria-label="t.blocked_topics" data-testid="gr-topics">
            <span class="ai-lab">{{ t.blocked_topics }}</span>
            <div
                v-for="topic in blocked"
                :key="topic"
                class="gr-top"
                :data-testid="'gr-topic-' + (seeded.has(topic) ? 'seeded' : 'custom')"
                :data-topic="topic"
            >
                <div>
                    <div class="gr-tn">
                        <span v-if="seeded.has(topic)" aria-hidden="true">🔒</span>
                        <span>{{ t['topic_' + topic] ?? topic }}</span>
                        <span v-if="seeded.has(topic)" class="ai-seed">{{ t.platform }}</span>
                    </div>
                    <p class="gr-td">
                        <template v-if="seeded.has(topic)">{{
                            t['topic_desc_' + topic] ?? t.topic_desc_generic
                        }}</template>
                        <template v-else>{{ t.topic_custom_desc }}</template>
                    </p>
                </div>
                <div class="gr-acts">
                    <button
                        v-if="!seeded.has(topic)"
                        type="button"
                        class="ai-btn ai-btn-dngr"
                        :data-testid="'gr-delete-' + topic"
                        @click="deleting = topic"
                    >
                        {{ t.delete }}
                    </button>
                    <span v-else class="gr-lockword">{{ t.seeded_no_delete }}</span>
                </div>
            </div>
            <div class="gr-add">
                <template v-if="adding">
                    <label class="sr-only" for="gr-new-topic">{{ t.blocked_topics }}</label>
                    <input
                        id="gr-new-topic"
                        v-model="newTopic"
                        data-testid="blocked-topic-input"
                        class="ai-in"
                        @keydown.enter.prevent="addTopic"
                    />
                    <button
                        type="button"
                        class="ai-btn ai-btn-pri"
                        data-testid="gr-add-confirm"
                        @click="addTopic"
                    >
                        {{ t.add }}
                    </button>
                    <button type="button" class="ai-btn" @click="adding = false">
                        {{ t.cancel }}
                    </button>
                </template>
                <button
                    v-else
                    type="button"
                    class="ai-btn"
                    data-testid="gr-add-topic"
                    @click="adding = true"
                >
                    {{ t.add_topic }}
                </button>
            </div>
        </section>

        <!-- ── F02 / X3: the disclosure block under the permanent lock ── -->
        <section class="gr-sec" :aria-label="t.disclosure_wording" data-testid="gr-disclosure">
            <div v-for="l in LANGS" :key="l" class="ai-field">
                <label class="ai-lab" :for="'gr-disclosure-' + l"
                    >{{ t.disclosure_label }} — {{ t['lang_' + l] ?? l }}</label
                >
                <input
                    :id="'gr-disclosure-' + l"
                    v-model="disclosure[l]"
                    :placeholder="defaults.disclosure[l]"
                    class="ai-in ps-wide"
                    :data-testid="'gr-disclosure-' + l"
                    @input="touch"
                />
            </div>
            <div class="ai-lockrow" data-testid="gr-disclosure-lock">
                <span aria-hidden="true">🔒</span>
                <span
                    ><b>{{ t.disclosure_lock_title }}</b> {{ t.disclosure_lock_body }}</span
                >
            </div>
        </section>

        <!-- ── F03 / X2: the RG response template — byte-fixed, per language, gated ── -->
        <section class="gr-sec" :aria-label="t.rg_template" data-testid="gr-rg">
            <div class="ai-lockrow gr-rglock">
                <span aria-hidden="true">🔒</span>
                <span
                    ><b>{{ t.rg_lock_title }}</b> {{ t.rg_lock_body }}</span
                >
            </div>
            <div class="gr-langs" role="tablist" :aria-label="t.rg_template">
                <button
                    v-for="l in LANGS"
                    :key="l"
                    type="button"
                    role="tab"
                    :aria-selected="rgLang === l"
                    class="gr-ltab"
                    :class="{ on: rgLang === l }"
                    :data-testid="'gr-rg-tab-' + l"
                    @click="rgLang = l"
                >
                    {{ t['lang_' + l] ?? l }}
                    <span v-if="rgComplete(l)" class="gr-okb">{{ t.complete }}</span>
                    <span v-else class="gr-gt">{{ t.missing_gates }}</span>
                </button>
            </div>
            <div v-for="l in LANGS" v-show="rgLang === l" :key="'rg' + l" role="tabpanel">
                <label class="ai-lab" :for="'gr-rg-' + l"
                    >{{ t.template_label }} — {{ t['lang_' + l] ?? l }}
                    <span class="gr-tier">({{ t.destructive_tier }})</span></label
                >
                <textarea
                    :id="'gr-rg-' + l"
                    v-model="rgTemplate[l]"
                    :placeholder="defaults.rg_template[l]"
                    rows="5"
                    class="ai-ta gr-rgta"
                    :data-testid="'gr-rg-' + l"
                    @input="touch"
                />
                <div class="mt-2.5">
                    <span class="ai-lab">{{ t.rg_preview_label }}</span>
                    <div class="gr-rgprev" :data-testid="'gr-rg-preview-' + l">
                        <div class="gr-rl">{{ t.rg_preview_tag }}</div>
                        <div class="gr-rt whitespace-pre-wrap">
                            {{ effectiveRg(l) || t.rg_preview_empty }}
                        </div>
                    </div>
                </div>
                <div
                    v-if="!rgComplete(l)"
                    class="ai-lockrow ai-lockrow-warn mt-2.5"
                    role="status"
                    :data-testid="'gr-rg-gate-' + l"
                >
                    <span aria-hidden="true">⚠</span>
                    <span
                        ><b>{{ fill(t.rg_gate_title, { lang: t['lang_' + l] ?? l }) }}</b>
                        {{ fill(t.rg_gate_body, { lang: t['lang_' + l] ?? l }) }}</span
                    >
                </div>
            </div>
        </section>

        <!-- ── F04/F05: turn cap, profanity, PII — every control with its consequence ── -->
        <section class="gr-sec gr-limits" :aria-label="t.limits" data-testid="gr-limits">
            <div>
                <label class="ai-lab" for="gr-max-ai">{{ MAX_AI_KEY }}</label>
                <div class="gr-ctl">
                    <input
                        id="gr-max-ai"
                        v-model.number="maxAi"
                        type="number"
                        min="1"
                        max="50"
                        class="ai-in gr-num"
                        data-testid="gr-max-ai"
                        @input="touch"
                    />
                    <span class="gr-cons">{{ fill(t.max_ai_consequence, { n: maxAi }) }}</span>
                </div>
            </div>
            <div>
                <label class="ai-lab" for="gr-profanity">{{ t.profanity_policy }}</label>
                <div class="gr-ctl">
                    <select
                        id="gr-profanity"
                        v-model="profanity"
                        class="ai-in gr-sel"
                        data-testid="gr-profanity"
                        @change="touch"
                    >
                        <option value="block">{{ t.profanity_block }}</option>
                        <option value="soften">{{ t.profanity_soften }}</option>
                        <option value="allow">{{ t.profanity_allow }}</option>
                    </select>
                    <span class="gr-cons">{{ t.profanity_consequence }}</span>
                </div>
            </div>
            <div>
                <span id="gr-pii-label" class="ai-lab">{{ t.pii_redaction }}</span>
                <div class="gr-ctl gr-ctl-top">
                    <button
                        type="button"
                        role="switch"
                        :aria-checked="pii"
                        aria-labelledby="gr-pii-label"
                        class="gr-toggle"
                        data-testid="gr-pii"
                        @click="
                            pii = !pii;
                            touch();
                        "
                    >
                        <span class="gr-tg" :class="{ off: !pii }" aria-hidden="true"><i></i></span>
                        <span class="gr-tgl">{{ pii ? t.on_word : t.off_word }}</span>
                    </button>
                    <span class="gr-cons">{{ t.pii_consequence }}</span>
                </div>
            </div>
        </section>

        <div class="gr-pubbar">
            <span class="ai-vchip">{{ chipText }}</span>
            <div class="gr-pubacts">
                <button
                    type="button"
                    class="ai-btn"
                    data-testid="guardrails-save"
                    :disabled="busy"
                    @click="save"
                >
                    {{ t.save_draft }}
                </button>
                <button
                    type="button"
                    class="ai-btn ai-btn-pri"
                    data-testid="guardrails-publish"
                    :disabled="busy || (!dirty && !draftId)"
                    @click="publish"
                >
                    {{ fill(t.publish_v, { version: nextVersion }) }}
                </button>
            </div>
        </div>

        <section class="gr-versions" :aria-label="t.history">
            <span class="ai-lab">{{ t.history }}</span>
            <div v-for="h in history" :key="h.id" class="ai-vrow">
                <div>
                    <b>v{{ h.version }}</b>
                    <span v-if="h.status === 'published'" class="ai-pill-live">{{ t.live }}</span>
                    <span v-else class="ai-who ms-1">{{
                        t['status_' + h.status] ?? h.status
                    }}</span>
                    <div class="ai-who">
                        {{
                            fill(t.version_who, {
                                date: (h.published_at ?? h.created_at)
                                    .slice(0, 16)
                                    .replace('T', ' '),
                                actor: h.published_by_name ?? '—',
                            })
                        }}
                    </div>
                </div>
            </div>
        </section>

        <!-- F06: deleting a custom topic — the confirm states the consequence; seeded rows never reach it -->
        <div v-if="deleting" class="ai-modal-backdrop" @click.self="deleting = null">
            <div
                class="ai-modal"
                role="dialog"
                aria-modal="true"
                :aria-label="fill(t.delete_title, { topic: deleting })"
                data-testid="gr-delete-dialog"
            >
                <h2>{{ fill(t.delete_title, { topic: deleting }) }}</h2>
                <p>{{ t.delete_body }}</p>
                <div class="ai-modal-acts">
                    <button type="button" class="ai-btn" @click="deleting = null">
                        {{ t.cancel }}
                    </button>
                    <button
                        type="button"
                        class="ai-btn ai-btn-red"
                        data-testid="gr-delete-confirm"
                        @click="removeTopic(deleting)"
                    >
                        {{ t.delete_topic }}
                    </button>
                </div>
            </div>
        </div>
    </AiSettingsShell>
</template>

<style scoped>
.gr-sec {
    padding: 12px 16px 16px;
    border-bottom: 1px solid var(--ds-border);
}
.gr-top {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 12px;
    padding: 10px 2px;
    border-top: 1px solid var(--ds-border);
}
.gr-top:first-of-type {
    border-top: 0;
}
.gr-tn {
    font-size: 12.5px;
    font-weight: 600;
    color: var(--ds-text-primary);
    display: flex;
    align-items: center;
    gap: 7px;
    flex-wrap: wrap;
}
.gr-td {
    font-size: 11px;
    color: var(--ds-text-muted);
    margin: 3px 0 0;
    max-width: 520px;
    line-height: 1.5;
}
.gr-acts {
    display: flex;
    gap: 6px;
    flex-shrink: 0;
    align-items: center;
}
.gr-lockword {
    font-size: 10.5px;
    color: var(--ds-text-muted);
}
.gr-add {
    display: flex;
    gap: 8px;
    margin-top: 12px;
    flex-wrap: wrap;
    align-items: center;
}
.ps-wide {
    max-width: 520px;
}
.gr-rglock {
    max-width: none;
    margin-bottom: 12px;
}
.gr-langs {
    display: flex;
    gap: 2px;
    border-bottom: 1px solid var(--ds-border);
    margin: 4px 0 14px;
    flex-wrap: wrap;
}
.gr-ltab {
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
.gr-ltab.on {
    color: var(--ds-text-primary);
    border-bottom-color: var(--ds-text-primary);
}
.gr-okb {
    font-size: 9px;
    font-weight: 700;
    background: var(--ds-success-bg);
    color: var(--ds-success-text);
    border-radius: 4px;
    padding: 1px 5px;
}
.gr-gt {
    font-size: 9px;
    font-weight: 700;
    background: var(--ds-warn-bg);
    color: var(--ds-warn-text);
    border: 1px solid var(--ds-warn-border);
    border-radius: 4px;
    padding: 1px 5px;
}
.gr-tier {
    text-transform: none;
    letter-spacing: 0;
    color: var(--ds-warn-text);
}
.gr-rgta {
    min-height: 96px;
    max-width: none;
}
.gr-rgprev {
    background: var(--ds-status-closed-bg);
    border: 1px solid var(--ds-border-strong);
    border-radius: 8px;
    padding: 10px 12px;
}
.gr-rl {
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--ds-status-closed-fg);
    margin-bottom: 5px;
}
.gr-rt {
    font-size: 12.5px;
    line-height: 1.6;
    color: var(--ds-text-primary);
}
.gr-limits {
    display: flex;
    flex-direction: column;
    gap: 16px;
}
.gr-ctl {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-top: 4px;
    flex-wrap: wrap;
}
.gr-ctl-top {
    align-items: flex-start;
}
.gr-num {
    width: 72px;
    text-align: center;
}
.gr-sel {
    width: auto;
}
.gr-cons {
    font-size: 11.5px;
    color: var(--ds-text-secondary);
    line-height: 1.5;
    max-width: 520px;
}
.gr-toggle {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: transparent;
    border: 0;
    padding: 0;
    min-height: 24px;
}
.gr-tg {
    width: 34px;
    height: 20px;
    border-radius: 10px;
    background: var(--ds-success-text);
    position: relative;
    display: inline-block;
}
.gr-tg.off {
    background: var(--ds-border-strong);
}
.gr-tg i {
    position: absolute;
    top: 2px;
    inset-inline-end: 2px;
    width: 16px;
    height: 16px;
    border-radius: 50%;
    background: var(--ds-panel-bg);
}
.gr-tg.off i {
    inset-inline-end: auto;
    inset-inline-start: 2px;
}
.gr-tgl {
    font-size: 11.5px;
    font-weight: 600;
    color: var(--ds-text-primary);
}
.gr-pubbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    padding: 12px 16px;
    flex-wrap: wrap;
}
.gr-pubacts {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}
.gr-versions {
    padding: 12px 16px 16px;
    border-top: 1px solid var(--ds-border);
}
@media (max-width: 639px) {
    .gr-top {
        flex-direction: column;
        gap: 8px;
    }
    .gr-toggle {
        min-height: 44px;
    }
}
</style>
