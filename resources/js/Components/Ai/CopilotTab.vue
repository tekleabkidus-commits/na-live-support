<script setup lang="ts">
// Doc 09 FR-14 — the Copilot tab (fills Doc 07's ContextPanel #copilot slot).
// SESSION-F1 [B8]: brought to the frozen B8-S01-copilot-panel-v2 anatomy —
// reading order Summary → Classification → Suggested reply → Relevant KB →
// Similar resolved → Macros; every section a labelled region with its own
// header row (label + freshness stamp) and its own skeleton; the draft card is
// the drawn AI-bubble anatomy (ai-bg + ai-border + 3 px ai-edge + the "✦ AI
// draft" text label); confidence = five segments AND "82% · High"; the
// guardrail strip rides WITH the card; the honest failures (low confidence /
// locked / degraded) REPLACE the card; Insert is ABSENT without the key (never
// greyed); after Discard the region RESTS ("Suggest", budget unchanged).
// §13: role=status announcements; Insert returns focus to the composer (the
// parent's hook). §8: 10 s → degraded (awaitCard). BR-01/X1: nothing here sends.
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { awaitCard, copilotApi, type CopilotCard, type CopilotPanel } from './copilotApi';

const props = defineProps<{
    conversationId: string | null;
    canInsert: boolean;
    t: Record<string, string>;
    // The composer hook: (text, kind, artifactId) → the parent puts text in the composer.
    insert: (text: string, kind: string, artifactId: string) => void;
}>();

type PanelPlus = CopilotPanel & { summary_updated_at?: string | null };
const panel = ref<PanelPlus | null>(null);
const card = ref<CopilotCard | null>(null);
const busy = ref(false);
const failed = ref(false);
const staleAt = ref<string | null>(null); // a refresh that failed — the summary shown is from this stamp
const discarded = ref(false); // the resting state after Discard (A04, v2)
const degradedSince = ref<string | null>(null);
const regenerations = ref<{ used: number; limit: number } | null>(null);
const cooldownLeft = ref(0);
const announcement = ref('');
let refreshTimer: number | null = null;
let cooldownTimer: number | null = null;

function fill(template: string | undefined, vars: Record<string, string>): string {
    let out = template ?? '';
    for (const [k, v] of Object.entries(vars)) out = out.replaceAll(':' + k, v);
    return out;
}
function hhmm(iso: string | null | undefined): string {
    if (!iso) return '';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return iso.slice(11, 16);
    return `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
}
function nowHhmm(): string {
    return hhmm(new Date().toISOString());
}
function mmss(s: number): string {
    return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;
}

const band = (c: number): 'high' | 'medium' | 'low' =>
    c >= 0.8 ? 'high' : c >= 0.5 ? 'medium' : 'low';
const confidencePct = computed(() =>
    typeof card.value?.confidence === 'number' ? Math.round(card.value.confidence * 100) : null,
);
const confidenceLabel = computed(() => {
    const c = card.value?.confidence;
    if (typeof c !== 'number') return '';
    return fill(props.t['confidence_' + band(c)], { pct: String(Math.round(c * 100)) });
});
const confidenceAria = computed(() => {
    const c = card.value?.confidence;
    if (typeof c !== 'number') return '';
    return fill(props.t.confidence_aria, {
        pct: String(Math.round(c * 100)),
        band: props.t['band_' + band(c)] ?? band(c),
    });
});
const segments = computed(() => {
    const c = card.value?.confidence;
    return typeof c === 'number' ? Math.max(0, Math.min(5, Math.round(c * 5))) : 0;
});
const regenLeft = computed(() => {
    const r = card.value?.regenerations ?? regenerations.value;
    return r ? Math.max(0, r.limit - r.used) : null;
});
const replyStamp = computed(() => {
    if (card.value?.state === 'pending') return props.t.generating ?? '';
    return regenLeft.value === null
        ? ''
        : fill(props.t.regen_left, { left: String(regenLeft.value) });
});
const summaryStamp = computed(() => {
    const p = panel.value;
    if (!p) return '';
    if (p.summary_state === 'refreshing') return props.t.summary_refreshing ?? '';
    if (staleAt.value) return fill(props.t.summary_stale, { time: hhmm(staleAt.value) });
    if (p.summary_state === 'empty') return props.t.summary_empty ?? '';
    const at = p.summary_updated_at ? hhmm(p.summary_updated_at) : nowHhmm();
    return fill(props.t.summary_stamp, { time: at });
});
const chipRows = computed(() => {
    const c = panel.value?.chips;
    const rows: { key: string; value: string }[] = [];
    if (!c) return rows;
    if (c.intent) rows.push({ key: 'intent', value: c.intent });
    if (typeof c.sentiment === 'number')
        rows.push({
            key: 'sentiment',
            value: fill(props.t['sentiment_' + sentimentWord(c.sentiment)], {
                n: signed(c.sentiment),
            }),
        });
    if (typeof c.urgency === 'number')
        rows.push({
            key: 'urgency',
            value: fill(props.t['urgency_' + urgencyWord(c.urgency)], { n: String(c.urgency) }),
        });
    return rows;
});
const chipsPartial = computed(() => {
    const c = panel.value?.chips;
    return !!c && (typeof c.sentiment !== 'number' || typeof c.urgency !== 'number');
});
function signed(n: number): string {
    return n > 0 ? `+${n}` : n < 0 ? `−${Math.abs(n)}` : '0';
}
function sentimentWord(n: number): string {
    return n < 0 ? 'negative' : n > 0 ? 'positive' : 'neutral';
}
function urgencyWord(n: number): string {
    return n >= 2 ? 'high' : n === 1 ? 'medium' : 'low';
}
const noticeText = (key: string): string =>
    props.t['notice.' + key.replace('ai.copilot.notice.', '')] ?? key;
const kbItems = computed(() => card.value?.kb ?? panel.value?.kb ?? []);
const macroItems = computed(
    () => (panel.value?.macros?.items ?? []) as { name?: string; body?: string; text?: string }[],
);

async function loadPanel(): Promise<void> {
    if (!props.conversationId) return;
    try {
        const next = (await copilotApi.panel(props.conversationId)) as PanelPlus;
        // A02: mid-refresh keeps the old text visible — never a blank swap.
        if (next.summary_state === 'refreshing' && panel.value?.summary && !next.summary) {
            next.summary = panel.value.summary;
        }
        panel.value = next;
        failed.value = false;
        staleAt.value = null;
        if (next.summary_state === 'refreshing' && refreshTimer === null) {
            refreshTimer = window.setTimeout(() => {
                refreshTimer = null;
                void loadPanel();
            }, 3000);
        }
    } catch {
        if (panel.value?.summary) {
            // a failed refresh says STALE with its stamp (A02) — the old summary stays
            staleAt.value = panel.value.summary_updated_at ?? new Date().toISOString();
        } else {
            failed.value = true;
        }
    }
}

function startCooldown(seconds: number): void {
    cooldownLeft.value = Math.max(0, Math.ceil(seconds));
    if (cooldownTimer !== null) window.clearInterval(cooldownTimer);
    cooldownTimer = window.setInterval(() => {
        cooldownLeft.value = Math.max(0, cooldownLeft.value - 1);
        if (cooldownLeft.value === 0 && cooldownTimer !== null) {
            window.clearInterval(cooldownTimer);
            cooldownTimer = null;
        }
    }, 1000);
}

async function run(): Promise<void> {
    if (!props.conversationId || busy.value || cooldownLeft.value > 0) return;
    busy.value = true;
    discarded.value = false;
    const previous = card.value;
    card.value = { state: 'pending' };
    try {
        const { request_id } = await copilotApi.draft(props.conversationId);
        const next = (await awaitCard(props.conversationId, request_id)) as CopilotCard & {
            retry_after_seconds?: number;
        };
        if (next.regenerations) regenerations.value = next.regenerations;
        if (next.state === 'cooldown') {
            // A06: the previous draft stays; Regenerate counts down in place.
            card.value = previous && previous.state !== 'pending' ? previous : null;
            startCooldown(next.retry_after_seconds ?? 5);
            announcement.value = props.t.state_cooldown ?? '';
        } else {
            card.value = next;
            if (next.state === 'degraded') degradedSince.value = new Date().toISOString();
            announcement.value =
                next.state === 'artifact' && next.text
                    ? next.text
                    : (props.t['state_' + next.state] ?? '');
        }
    } catch {
        card.value = { state: 'degraded', notices: ['ai.copilot.notice.degraded'] };
        degradedSince.value = new Date().toISOString();
    } finally {
        busy.value = false;
    }
}

async function onInsert(): Promise<void> {
    const c = card.value;
    if (!c?.artifact_id || !c.text || !props.canInsert) return;
    try {
        const inserted = await copilotApi.insert(c.artifact_id);
        props.insert(inserted.text, inserted.kind, inserted.artifact_id);
        card.value = null;
    } catch {
        announcement.value = props.t.insert_failed ?? '';
    }
}

async function onDiscard(): Promise<void> {
    const c = card.value;
    if (c?.artifact_id) {
        try {
            await copilotApi.discard(c.artifact_id);
        } catch {
            /* the row is the agent's own; a failed discard is retried by the next action */
        }
    }
    card.value = null;
    discarded.value = true; // the resting state — nothing regenerates on its own
    announcement.value = props.t.resting ?? '';
}

async function insertKbLink(item: {
    article_ref: string;
    url?: string;
    title?: string;
    version_id?: string;
}): Promise<void> {
    // FR-14 "insert-as-link" (S10 packet P3): the agent's ACT — Doc 10's door records it
    // (kb.article.inserted_by_agent, on INSERT never on render) and returns the line to
    // insert; a legacy ref with no article behind it still inserts the bare ref.
    if (!item.url || !props.conversationId) {
        props.insert('📄 ' + item.article_ref, 'kb_link', '');
        return;
    }
    try {
        const response = await fetch('/admin/kb/insert-link', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': decodeURIComponent(
                    document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '',
                ),
            },
            body: JSON.stringify({
                article_id: item.article_ref,
                version_id: item.version_id,
                conversation_id: props.conversationId,
            }),
        });
        const data = (await response.json()) as { text?: string };
        props.insert(
            data.text ?? '📄 ' + (item.title ?? item.article_ref) + ' ' + item.url,
            'kb_link',
            '',
        );
    } catch {
        props.insert('📄 ' + (item.title ?? item.article_ref) + ' ' + item.url, 'kb_link', '');
    }
}

function useMacro(item: { name?: string; body?: string; text?: string }): void {
    const text = item.body ?? item.text ?? '';
    if (text) props.insert(text, 'macro', '');
}

watch(
    () => props.conversationId,
    () => {
        card.value = null;
        panel.value = null;
        staleAt.value = null;
        discarded.value = false;
        regenerations.value = null;
        cooldownLeft.value = 0;
        void loadPanel();
    },
    { immediate: true },
);

onBeforeUnmount(() => {
    if (refreshTimer !== null) window.clearTimeout(refreshTimer);
    if (cooldownTimer !== null) window.clearInterval(cooldownTimer);
});

defineExpose({ loadPanel });
</script>

<template>
    <div class="cp text-start" data-testid="copilot-tab">
        <p class="sr-only" role="status" aria-live="polite">{{ announcement }}</p>

        <p v-if="!conversationId" class="cp-empty">{{ t.empty }}</p>
        <p v-else-if="failed" class="cp-empty">{{ t.panel_failed }}</p>

        <!-- §B: per-section skeletons — the shape never jumps; each source fills alone -->
        <template v-else-if="!panel">
            <section
                v-for="key in ['summary', 'classification', 'reply', 'kb', 'similar', 'macros']"
                :key="key"
                class="cp-region"
                role="region"
                :aria-label="t['region_' + key]"
                :data-testid="'copilot-skeleton-' + key"
            >
                <div class="cp-rh">
                    <span class="cp-lab">{{ t['region_' + key] }}</span>
                </div>
                <div class="cp-sk" :class="key === 'reply' ? 'cp-sk-tall' : 'cp-sk-w80'"></div>
                <div v-if="key === 'summary'" class="cp-sk cp-sk-w60 mt-1.5"></div>
            </section>
        </template>

        <template v-else>
            <!-- A02: thread summary with its freshness stamp -->
            <section
                class="cp-region"
                role="region"
                :aria-label="t.region_summary"
                data-testid="copilot-summary"
            >
                <div class="cp-rh">
                    <span class="cp-lab">{{ t.region_summary }}</span>
                    <span
                        class="cp-stamp"
                        :class="{ 'cp-stamp-warn': staleAt }"
                        data-testid="copilot-summary-stamp"
                        >{{ summaryStamp }}</span
                    >
                </div>
                <p v-if="panel.summary" class="cp-sum">{{ panel.summary }}</p>
                <p v-else class="cp-empty">{{ t.summary_empty }}</p>
                <div v-if="staleAt" class="cp-acts">
                    <button
                        type="button"
                        class="cp-btn"
                        data-testid="copilot-retry-refresh"
                        @click="loadPanel"
                    >
                        {{ t.retry_refresh }}
                    </button>
                </div>
            </section>

            <!-- A03: chips — AI accent + TEXT, never colour alone; absent chips say so -->
            <section
                class="cp-region"
                role="region"
                :aria-label="t.region_classification"
                data-testid="copilot-chips"
            >
                <div class="cp-rh">
                    <span class="cp-lab">{{ t.region_classification }}</span>
                </div>
                <div v-if="chipRows.length" class="cp-chips">
                    <span
                        v-for="chip in chipRows"
                        :key="chip.key"
                        class="cp-chip"
                        :data-testid="'copilot-chip-' + chip.key"
                        >✦ <b>{{ t['chip_' + chip.key] }}</b>
                        <span class="cp-cv">{{ chip.value }}</span></span
                    >
                </div>
                <p v-if="chipsPartial || !chipRows.length" class="cp-empty">
                    {{ chipRows.length ? t.chips_partial : t.chips_empty }}
                </p>
            </section>

            <!-- A04–A09 / A14: the suggested reply — the card, or the honest failure that REPLACES it -->
            <section
                class="cp-region"
                role="region"
                :aria-label="card?.state === 'degraded' ? t.region_status : t.region_reply"
                data-testid="copilot-card"
            >
                <div class="cp-rh">
                    <span class="cp-lab">{{
                        card?.state === 'degraded' ? t.region_status : t.region_reply
                    }}</span>
                    <span
                        v-if="card?.state !== 'degraded' && replyStamp"
                        class="cp-stamp"
                        data-testid="copilot-regen-stamp"
                        >{{ replyStamp }}</span
                    >
                </div>
                <div role="status" aria-live="polite">
                    <!-- idle / resting -->
                    <template v-if="!card">
                        <p
                            class="cp-empty"
                            :data-testid="discarded ? 'copilot-resting' : 'copilot-idle'"
                        >
                            {{ discarded ? t.resting : t.card_idle }}
                        </p>
                    </template>
                    <!-- streaming (polled): the drawn card with a static caret; Insert waits -->
                    <template v-else-if="card.state === 'pending'">
                        <div class="cp-aicard" data-testid="card-pending">
                            <div class="cp-ailabel">
                                <span>{{ t.ai_draft }}</span>
                            </div>
                            <p class="cp-aitext cp-caret">{{ t.state_pending }}</p>
                        </div>
                    </template>
                    <!-- the draft -->
                    <template v-else-if="card.state === 'artifact'">
                        <div class="cp-aicard">
                            <div class="cp-ailabel">
                                <span>{{ t.ai_draft }}</span>
                            </div>
                            <p data-testid="card-text" class="cp-aitext whitespace-pre-wrap">
                                {{ card.text }}
                            </p>
                            <span v-for="c in card.citations ?? []" :key="c" class="cp-cite"
                                >¶ {{ c }}</span
                            >
                            <div
                                v-if="confidenceLabel"
                                class="cp-conf"
                                :aria-label="confidenceAria"
                            >
                                <span class="cp-segs" aria-hidden="true"
                                    ><span
                                        v-for="i in 5"
                                        :key="i"
                                        class="cp-seg"
                                        :class="{ 'cp-seg-f': i <= segments }"
                                    ></span
                                ></span>
                                <span class="cp-conftx" data-testid="card-confidence">{{
                                    confidenceLabel
                                }}</span>
                            </div>
                        </div>
                        <!-- A09: the guardrail strip rides WITH the card -->
                        <div
                            v-for="n in card.notices ?? []"
                            :key="n"
                            class="cp-guard"
                            data-testid="copilot-guard"
                        >
                            <span aria-hidden="true">⚠</span><span>{{ noticeText(n) }}</span>
                        </div>
                    </template>
                    <!-- A08: low confidence — the banner REPLACES the draft -->
                    <template v-else-if="card.state === 'low_confidence'">
                        <div class="cp-banner cp-banner-lowc" data-testid="card-banner">
                            {{ t.lowc_title }}
                            <small>{{
                                confidencePct === null
                                    ? t.lowc_sub_nopct
                                    : fill(t.lowc_sub, { pct: String(confidencePct) })
                            }}</small>
                        </div>
                    </template>
                    <!-- A07: locked after ×3 — no fourth try, the telemetry consequence in words -->
                    <template v-else-if="card.state === 'locked'">
                        <div class="cp-banner cp-banner-lowc" data-testid="card-banner">
                            {{ t.locked_title }}
                            <small>{{ t.locked_sub }}</small>
                        </div>
                    </template>
                    <!-- A14: degraded — the labelled degrade, specific about what still works -->
                    <template v-else-if="card.state === 'degraded'">
                        <div class="cp-banner cp-banner-deg" data-testid="card-banner">
                            {{ t.degraded_title }}
                            <small>{{ fill(t.degraded_sub, { time: hhmm(degradedSince) }) }}</small>
                        </div>
                    </template>
                    <!-- refused / busy -->
                    <template v-else>
                        <div class="cp-banner cp-banner-deg" data-testid="card-banner">
                            <strong>{{ t['state_' + card.state] }}</strong>
                            <small v-for="n in card.notices ?? []" :key="n">{{
                                noticeText(n)
                            }}</small>
                        </div>
                    </template>
                </div>

                <div class="cp-acts">
                    <!-- A05: Insert is ABSENT without the key — never a grey door -->
                    <button
                        v-if="
                            canInsert &&
                            card &&
                            (card.state === 'artifact' || card.state === 'pending')
                        "
                        type="button"
                        data-testid="copilot-insert"
                        class="cp-btn cp-btn-pri"
                        :disabled="card.state === 'pending'"
                        @click="onInsert"
                    >
                        {{ t.insert }}
                    </button>
                    <button v-if="card?.state === 'pending'" type="button" class="cp-btn" disabled>
                        {{ t.stop }}
                    </button>
                    <button
                        v-if="
                            card?.state !== 'pending' &&
                            card?.state !== 'locked' &&
                            card?.state !== 'degraded'
                        "
                        type="button"
                        data-testid="copilot-regenerate"
                        class="cp-btn"
                        :class="{ 'cp-btn-pri': !card }"
                        :disabled="busy || cooldownLeft > 0"
                        @click="run"
                    >
                        <template v-if="!card">✦ {{ t.suggest }}</template>
                        <template v-else-if="cooldownLeft > 0">{{
                            fill(t.regen_in, { time: mmss(cooldownLeft) })
                        }}</template>
                        <template v-else>{{ t.regenerate }}</template>
                    </button>
                    <button
                        v-if="
                            card &&
                            card.state !== 'pending' &&
                            card.state !== 'locked' &&
                            card.state !== 'degraded' &&
                            card.state !== 'low_confidence'
                        "
                        type="button"
                        data-testid="copilot-discard"
                        class="cp-btn"
                        @click="onDiscard"
                    >
                        {{ t.discard }}
                    </button>
                </div>
                <p v-if="card?.state === 'artifact'" class="cp-hint">{{ t.hint_insert }}</p>
            </section>

            <!-- A10: relevant KB, top 3, insert-as-link -->
            <section
                class="cp-region"
                role="region"
                :aria-label="t.region_kb"
                data-testid="copilot-kb"
            >
                <div class="cp-rh">
                    <span class="cp-lab">{{ t.region_kb }}</span>
                </div>
                <template v-if="kbItems.length">
                    <div v-for="item in kbItems" :key="item.article_ref" class="cp-li">
                        <span class="cp-t"
                            ><span v-if="item.title">{{ item.title }}</span
                            ><span v-else class="font-mono text-[10px]">{{
                                item.article_ref
                            }}</span></span
                        >
                        <button type="button" class="cp-ins" @click="insertKbLink(item)">
                            {{ t.insert_link }}
                        </button>
                    </div>
                </template>
                <p v-else class="cp-empty">{{ t.kb_empty }}</p>
            </section>

            <!-- A11: similar resolved, scope-checked — out-of-scope rows are ABSENT -->
            <section
                class="cp-region"
                role="region"
                :aria-label="t.region_similar"
                data-testid="copilot-similar"
            >
                <div class="cp-rh">
                    <span class="cp-lab">{{ t.region_similar }}</span>
                </div>
                <template v-if="panel.similar_tickets.length">
                    <div v-for="s in panel.similar_tickets" :key="s.ticket_id" class="cp-li">
                        <div>
                            <div class="cp-t">
                                <span class="tabular-nums">#{{ s.number ?? '—' }}</span> ·
                                {{ s.subject ?? '' }}
                            </div>
                            <div class="cp-s">{{ s.summary }}</div>
                        </div>
                    </div>
                </template>
                <p v-else class="cp-empty">{{ t.similar_empty }}</p>
            </section>

            <!-- A12: macro suggestions (Doc 12's seam) -->
            <section
                class="cp-region"
                role="region"
                :aria-label="t.region_macros"
                data-testid="copilot-macros"
            >
                <div class="cp-rh">
                    <span class="cp-lab">{{ t.region_macros }}</span>
                </div>
                <template v-if="macroItems.length">
                    <div v-for="(m, i) in macroItems" :key="i" class="cp-li">
                        <span class="cp-t">{{ m.name ?? m.text }}</span>
                        <button type="button" class="cp-ins" @click="useMacro(m)">
                            {{ t.macro_use }}
                        </button>
                    </div>
                </template>
                <p v-else class="cp-empty">{{ t.macros_empty }}</p>
            </section>
        </template>
    </div>
</template>

<style scoped>
/* B8-S01 anatomy — tokens only (design-tokens.css), the frame's measures verbatim. */
.cp {
    display: flex;
    flex-direction: column;
    gap: 12px;
    font-size: 12.5px;
}
.cp-region {
    border: 1px solid var(--ds-border);
    border-radius: 8px;
    background: var(--ds-panel-bg);
    padding: 10px;
}
.cp-rh {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 8px;
    margin-bottom: 7px;
}
.cp-lab {
    font-size: 10px;
    font-weight: 600;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--ds-text-label);
}
.cp-stamp {
    font-size: 10.5px;
    color: var(--ds-text-muted);
    text-align: end;
}
.cp-stamp-warn {
    color: var(--ds-warn-text);
}
.cp-sum {
    font-size: 12.5px;
    line-height: 1.55;
    color: var(--ds-text-primary);
    margin: 0;
}
.cp-empty {
    font-size: 11.5px;
    color: var(--ds-text-muted);
    padding: 6px 2px;
    margin: 0;
}
.cp-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}
.cp-chip {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    background: var(--ds-ai-bg);
    border: 1px solid var(--ds-ai-border);
    border-inline-start: 3px solid var(--ds-ai-edge);
    color: var(--ds-ai-fg);
    font-size: 11px;
    font-weight: 700;
    padding: 3px 8px;
    border-radius: 4px;
}
.cp-cv {
    color: var(--ds-text-primary);
    font-weight: 600;
}
.cp-aicard {
    background: var(--ds-ai-bg);
    border: 1px solid var(--ds-ai-border);
    border-inline-start: 3px solid var(--ds-ai-edge);
    border-radius: 8px;
    padding: 10px 11px;
}
.cp-ailabel {
    font-size: 9.5px;
    font-weight: 700;
    color: var(--ds-ai-fg);
    letter-spacing: 0.05em;
    text-transform: uppercase;
    margin-bottom: 5px;
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    gap: 8px;
}
.cp-aitext {
    font-size: 12.5px;
    line-height: 1.55;
    color: var(--ds-text-primary);
    margin: 0;
}
.cp-caret::after {
    content: '▍';
    color: var(--ds-ai-fg);
    font-size: 12px;
    animation: none;
}
.cp-cite {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 10.5px;
    font-weight: 600;
    color: var(--ds-ai-fg);
    background: var(--ds-panel-bg);
    border: 1px solid var(--ds-ai-border);
    border-radius: 4px;
    padding: 1px 6px;
    margin-top: 7px;
    margin-inline-end: 4px;
}
.cp-conf {
    display: flex;
    align-items: center;
    gap: 7px;
    margin-top: 9px;
}
.cp-segs {
    display: flex;
    gap: 3px;
}
.cp-seg {
    width: 16px;
    height: 4px;
    border-radius: 2px;
    background: var(--ds-border);
}
.cp-seg-f {
    background: var(--ds-ai-fg);
}
.cp-conftx {
    font-size: 10.5px;
    font-weight: 600;
    color: var(--ds-text-secondary);
}
.cp-guard {
    display: flex;
    gap: 7px;
    align-items: flex-start;
    background: var(--ds-warn-bg);
    border: 1px solid var(--ds-warn-border);
    color: var(--ds-warn-text);
    border-radius: 6px;
    padding: 7px 9px;
    font-size: 11px;
    font-weight: 600;
    line-height: 1.45;
    margin-top: 8px;
}
.cp-banner {
    border-radius: 8px;
    padding: 10px 11px;
    font-size: 11.5px;
    line-height: 1.5;
    font-weight: 600;
}
.cp-banner small {
    display: block;
    font-weight: 400;
    margin-top: 4px;
    color: inherit;
    opacity: 0.9;
}
.cp-banner-lowc {
    background: var(--ds-warn-bg);
    border: 1px solid var(--ds-warn-border);
    color: var(--ds-warn-text);
}
.cp-banner-deg {
    background: var(--ds-inset-bg);
    border: 1px solid var(--ds-border-strong);
    color: var(--ds-text-secondary);
}
.cp-acts {
    display: flex;
    gap: 8px;
    margin-top: 10px;
    flex-wrap: wrap;
}
.cp-acts:empty {
    display: none;
}
.cp-btn {
    font-size: 11px;
    font-weight: 600;
    border-radius: 6px;
    padding: 6px 12px;
    border: 1px solid var(--ds-border-strong);
    background: var(--ds-panel-bg);
    color: var(--ds-text-primary);
}
.cp-btn-pri {
    background: var(--ds-accent);
    color: var(--ds-accent-contrast);
    border-color: var(--ds-accent);
}
.cp-btn[disabled] {
    opacity: 0.55;
}
.cp-hint {
    font-size: 10.5px;
    color: var(--ds-text-muted);
    margin: 7px 0 0;
}
.cp-li {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 8px;
    padding: 7px 2px;
    border-top: 1px solid var(--ds-border);
    font-size: 12px;
}
.cp-li:first-of-type {
    border-top: 0;
}
.cp-t {
    color: var(--ds-text-primary);
    font-weight: 500;
}
.cp-s {
    color: var(--ds-text-muted);
    font-size: 10.5px;
}
.cp-ins {
    font-size: 10.5px;
    font-weight: 600;
    color: var(--ds-ai-fg);
    border: 1px solid var(--ds-ai-border);
    background: var(--ds-panel-bg);
    border-radius: 4px;
    padding: 2px 7px;
    white-space: nowrap;
}
.cp-sk {
    border-radius: 4px;
    background: var(--ds-inset-bg);
    height: 10px;
}
.cp-sk-w80 {
    width: 80%;
}
.cp-sk-w60 {
    width: 60%;
}
.cp-sk-tall {
    height: 34px;
    width: 100%;
}
/* §13 at 375: every control row clears 44 px (the frame's `.m .btn` / `.m .ins`) */
@media (max-width: 639px) {
    .cp-btn {
        padding: 12px 16px;
        min-height: 44px;
    }
    .cp-ins {
        padding: 10px 12px;
        min-height: 44px;
    }
}
</style>
