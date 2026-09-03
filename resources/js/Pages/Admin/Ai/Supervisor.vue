<script setup lang="ts">
// Doc 09 FR-17/FR-20/US-05/NFR-07/SEC-06 Supervisor outputs & the RG review
// queue — SESSION-F1 [B8]: the frozen B8-S10-supervisor-rg-queue-v1 anatomy
// (rulings Q2/Q3): ARTEFACTS a supervisor meets — the anomaly item with its
// baseline in the sentence, the sentiment item that names who holds the thread
// and opens it, the digest (exactly six bullets), the QA pre-score labelled
// advisory at every appearance — and the RG precision review queue in calm
// slate, present ONLY for a viewer holding the RG key: for anyone else this
// page carries no count, no row, no vocabulary that reveals it (J06).
import { computed } from 'vue';
import AiSettingsShell from '../../../Components/Ai/AiSettingsShell.vue';

type FeedItem = {
    kind: 'anomaly' | 'sentiment' | 'digest' | 'paused' | 'resumed';
    at: string;
    title: string;
    body: string;
    conversation_id: string | null;
    holder: string | null;
};
const props = defineProps<{
    t: Record<string, string>;
    feed: FeedItem[];
    digest: { at: string; bullets: string[]; language: string; source: string } | null;
    qa: {
        ticket_id: string;
        ticket_number: number | null;
        score: number | null;
        flags: string[];
        rubric_version: string | number | null;
        at: string;
        agent: string | null;
    }[];
    rg: null | {
        fires: number;
        cleared: number;
        kept: number;
        precision: number | null;
        items: {
            customer_id: string;
            fired_at: string;
            ai_score: number | null;
            cleared_at: string | null;
            clear_reason: string | null;
            conversation_id: string | null;
            customer_url: string;
        }[];
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
const pending = computed(() => props.rg?.items.filter((i) => !i.cleared_at).length ?? 0);
function hhmm(iso: string | null | undefined): string {
    if (!iso) return '';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return iso.slice(11, 16);
    const today = new Date().toDateString() === d.toDateString();
    const time = `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
    return today ? time : `${d.toISOString().slice(5, 10)} ${time}`;
}
</script>

<template>
    <AiSettingsShell
        :t="t"
        :page="t.supervisor_feed"
        :crumb-root="t.supervisor_page"
        :setup="setup"
    >
        <template #chip>
            <span class="ai-vchip">{{ t.live_scans }}</span>
        </template>

        <!-- J06: the page's own nav — the RG row exists ONLY for the RG key (absent, not locked) -->
        <nav class="sv-nav" :aria-label="t.supervisor_page" data-testid="sv-nav">
            <a href="#sv-feed" class="sv-navrow">{{ t.nav_feed }}</a>
            <a href="#sv-qa" class="sv-navrow"
                >{{ t.nav_qa }} <span class="ai-vchip">{{ qa.length }}</span></a
            >
            <a v-if="rg" href="#sv-rg" class="sv-navrow" data-testid="sv-nav-rg"
                >{{ t.nav_rg }}
                <span class="ai-vchip">{{ fill(t.pending_n, { n: pending }) }}</span></a
            >
        </nav>

        <!-- J01/J02/J03: the feed -->
        <section id="sv-feed" class="sv-sec" :aria-label="t.nav_feed" data-testid="sv-feed">
            <span class="ai-lab">{{ t.nav_feed }}</span>
            <div
                v-for="(item, i) in feed"
                :key="i"
                class="sv-item"
                :class="'sv-item-' + item.kind"
                :data-testid="'sv-item-' + item.kind"
            >
                <div class="sv-ih">
                    <span class="sv-it">{{ item.title }}</span>
                    <span class="sv-iw">{{ hhmm(item.at) }} · {{ t['kind_' + item.kind] }}</span>
                </div>
                <p class="sv-ib">{{ item.body }}</p>
                <div v-if="item.conversation_id" class="sv-ia">
                    <a :href="'/inbox/thread/' + item.conversation_id" class="ai-btn ai-btn-pri">{{
                        t.open_thread
                    }}</a>
                </div>
            </div>
            <p v-if="!feed.length" class="ai-who">{{ t.feed_empty }}</p>
        </section>

        <!-- J03: the daily digest — exactly six bullets, delivered here and by email -->
        <section class="sv-sec" :aria-label="t.digest_title" data-testid="sv-digest">
            <div v-if="digest" class="sv-item sv-item-digest">
                <div class="sv-ih">
                    <span class="sv-it"
                        >✦ {{ fill(t.digest_heading, { date: hhmm(digest.at) }) }}</span
                    >
                    <span class="sv-iw"
                        >{{ t['lang_' + digest.language] ?? digest.language }} ·
                        {{
                            digest.source === 'template' ? t.digest_template : t.digest_model
                        }}</span
                    >
                </div>
                <ul class="sv-bullets" data-testid="sv-digest-bullets">
                    <li v-for="(b, i) in digest.bullets" :key="i">{{ b }}</li>
                </ul>
                <p class="ai-who">{{ t.digest_note }}</p>
            </div>
            <p v-else class="ai-who">{{ t.digest_empty }}</p>
        </section>

        <!-- J04: the QA pre-score where a reviewer meets it — advisory forever -->
        <section id="sv-qa" class="sv-sec" :aria-label="t.nav_qa" data-testid="sv-qa">
            <div class="sv-secline">
                <span class="ai-lab" style="margin: 0">{{ t.qa_title }}</span
                ><span class="ai-vchip">{{ fill(t.qa_prioritized, { n: qa.length }) }}</span>
            </div>
            <div
                v-for="q in qa"
                :key="q.ticket_id"
                class="sv-qa"
                :data-testid="'sv-qa-' + q.ticket_id"
            >
                <div class="sv-ql">✦ {{ t.qa_label }}</div>
                <p class="sv-qb">
                    <b
                        >#{{ q.ticket_number ?? '—'
                        }}<template v-if="q.agent">
                            · {{ fill(t.qa_resolved_by, { agent: q.agent }) }}</template
                        ></b
                    >
                    —
                    {{
                        q.score === null
                            ? t.qa_unscored
                            : fill(t.qa_score, { score: q.score, rubric: q.rubric_version ?? '' })
                    }}
                </p>
                <p class="sv-qr">
                    <template v-if="q.flags.length"
                        >{{ t.qa_flags }}: {{ q.flags.join(' · ') }}. </template
                    >{{ t.qa_advisory }}
                </p>
                <div class="sv-ia">
                    <a :href="'/inbox'" class="ai-btn ai-btn-pri">{{ t.qa_start_review }}</a>
                </div>
            </div>
            <p v-if="!qa.length" class="ai-who">{{ t.qa_empty }}</p>
        </section>

        <!-- J05/J06: the RG precision review queue — calm slate; ABSENT without the key -->
        <section v-if="rg" id="sv-rg" class="sv-sec" :aria-label="t.nav_rg" data-testid="sv-rg">
            <div class="sv-secline">
                <span class="ai-lab" style="margin: 0">{{ t.rg_title }}</span>
                <span class="ai-vchip" data-testid="sv-rg-precision">{{
                    fill(t.rg_month, {
                        fires: rg.fires,
                        kept: rg.kept,
                        cleared: rg.cleared,
                        precision: rg.precision === null ? '—' : rg.precision.toFixed(2),
                    })
                }}</span>
            </div>
            <p class="ai-who sv-rgnote">{{ t.rg_note }}</p>
            <div
                v-for="(f, i) in rg.items"
                :key="i"
                class="sv-rg"
                :data-testid="'sv-rg-' + (f.cleared_at ? 'cleared' : 'pending')"
            >
                <div class="sv-rl">
                    <span
                        >{{ f.cleared_at ? t.rg_cleared_label : t.rg_fire_pending }}
                        <span v-if="f.cleared_at" class="sv-pillc">{{
                            t.rg_cleared_pill
                        }}</span></span
                    >
                    <span>{{ hhmm(f.fired_at) }}</span>
                </div>
                <p class="sv-rs">
                    {{
                        fill(t.rg_facts, {
                            score: f.ai_score === null ? '—' : f.ai_score.toFixed(2),
                        })
                    }}
                    <template v-if="f.cleared_at">
                        ·
                        {{
                            fill(t.rg_cleared_facts, {
                                when: hhmm(f.cleared_at),
                                reason: f.clear_reason ?? '—',
                            })
                        }}</template
                    >
                </p>
                <div class="sv-ra">
                    <a
                        v-if="f.conversation_id"
                        :href="'/inbox/thread/' + f.conversation_id"
                        class="ai-btn ai-btn-pri"
                        >{{ t.open_conversation }}</a
                    >
                    <a :href="f.customer_url" class="ai-btn">{{
                        f.cleared_at ? t.rg_view_card : t.rg_review_card
                    }}</a>
                </div>
            </div>
            <p v-if="!rg.items.length" class="ai-who">{{ t.rg_empty }}</p>
        </section>
    </AiSettingsShell>
</template>

<style scoped>
.sv-nav {
    display: flex;
    gap: 14px;
    padding: 10px 16px;
    border-bottom: 1px solid var(--ds-border);
    flex-wrap: wrap;
}
.sv-navrow {
    font-size: 12px;
    font-weight: 600;
    color: var(--ds-text-primary);
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.sv-sec {
    padding: 12px 16px 16px;
    border-bottom: 1px solid var(--ds-border);
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.sv-secline {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.sv-item {
    border: 1px solid var(--ds-border);
    border-radius: 8px;
    padding: 10px 12px;
    background: var(--ds-panel-bg);
    border-inline-start-width: 3px;
}
.sv-item-anomaly {
    border-inline-start-color: var(--ds-warn-text);
}
.sv-item-sentiment {
    border-inline-start-color: var(--ds-danger-text);
}
.sv-item-digest {
    border-inline-start-color: var(--ds-ai-edge);
}
.sv-item-paused {
    border-inline-start-color: var(--ds-warn-text);
}
.sv-item-resumed {
    border-inline-start-color: var(--ds-success-text);
}
.sv-ih {
    display: flex;
    justify-content: space-between;
    gap: 10px;
    align-items: baseline;
    flex-wrap: wrap;
}
.sv-it {
    font-size: 12.5px;
    font-weight: 600;
    color: var(--ds-text-primary);
}
.sv-iw {
    font-size: 10.5px;
    color: var(--ds-text-muted);
}
.sv-ib {
    font-size: 12px;
    line-height: 1.55;
    color: var(--ds-text-secondary);
    margin: 5px 0 0;
}
.sv-ia,
.sv-ra {
    display: flex;
    gap: 8px;
    margin-top: 9px;
    flex-wrap: wrap;
}
.sv-bullets {
    margin: 6px 0 0;
    padding-inline-start: 18px;
    line-height: 1.7;
    font-size: 12px;
    color: var(--ds-text-primary);
}
.sv-qa {
    background: var(--ds-ai-bg);
    border: 1px solid var(--ds-ai-border);
    border-inline-start: 3px solid var(--ds-ai-edge);
    border-radius: 8px;
    padding: 10px 12px;
}
.sv-ql {
    font-size: 9.5px;
    font-weight: 700;
    color: var(--ds-ai-fg);
    letter-spacing: 0.05em;
    text-transform: uppercase;
    margin-bottom: 5px;
}
.sv-qb {
    font-size: 12.5px;
    line-height: 1.5;
    color: var(--ds-text-primary);
    margin: 0;
}
.sv-qr {
    font-size: 11px;
    line-height: 1.5;
    color: var(--ds-text-secondary);
    margin: 5px 0 0;
}
.sv-rgnote {
    margin: -4px 0 0;
}
.sv-rg {
    background: var(--ds-status-closed-bg);
    border: 1px solid var(--ds-border-strong);
    border-radius: 8px;
    padding: 10px 12px;
}
.sv-rl {
    display: flex;
    justify-content: space-between;
    gap: 8px;
    font-size: 10.5px;
    font-weight: 700;
    color: var(--ds-status-closed-fg);
    letter-spacing: 0.04em;
    text-transform: uppercase;
    flex-wrap: wrap;
}
.sv-pillc {
    font-size: 9px;
    border: 1px solid var(--ds-border-strong);
    border-radius: 4px;
    padding: 1px 5px;
    margin-inline-start: 4px;
}
.sv-rs {
    font-size: 11.5px;
    line-height: 1.5;
    color: var(--ds-text-primary);
    margin: 6px 0 0;
}
@media (max-width: 639px) {
    .sv-ra,
    .sv-ia {
        flex-direction: column;
    }
}
</style>
