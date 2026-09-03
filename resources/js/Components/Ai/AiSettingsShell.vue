<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
// SESSION-F1 [B8] — the Settings › AI shell the frozen B8-S04…S10 frames draw
// (B6-S11 law, context only): one panel with the crumb bar "Settings · AI · Page"
// and a right-hand chip slot. The K04 setup checklist (B8-S11 §D) lives here so
// every AI page tells a brand-new tenant what to do before the chatbot can
// answer. Tokens only; RTL-safe (logical properties); no motion.
defineProps<{
    t: Record<string, string>;
    page?: string; // t-map values are string | undefined under noUncheckedIndexedAccess
    extra?: string;
    width?: string;
    crumbRoot?: string; // B8-S09/S10 draw "AI · Decisions" / "Supervisor feed" — not under Settings
    setup?: {
        kb_published: boolean;
        persona_published: boolean;
        categories_ai_usable: boolean;
        kb_url: string;
        categories_url: string;
    } | null;
}>();
</script>

<template>
    <div class="ai-page">
        <div class="ai-shell" :style="width ? { maxWidth: width } : undefined">
            <div class="ai-crumb">
                <template v-if="crumbRoot">
                    <span v-if="crumbRoot !== page">{{ crumbRoot }}</span>
                    <span v-if="crumbRoot !== page" aria-hidden="true">·</span>
                </template>
                <template v-else>
                    <span>{{ t.crumb_settings }}</span>
                    <span aria-hidden="true">·</span>
                    <span>{{ t.crumb_ai }}</span>
                    <span aria-hidden="true">·</span>
                </template>
                <b>{{ page }}</b>
                <template v-if="extra">
                    <span aria-hidden="true">·</span>
                    <span>{{ extra }}</span>
                </template>
                <span class="ai-crumb-right"><slot name="chip" /></span>
            </div>

            <!-- K04 (B8-S11 §D): the empty state is a to-do list with its finished rows showing -->
            <section
                v-if="setup && !setup.kb_published"
                class="ai-setup"
                :aria-label="t.setup_title"
                data-testid="ai-setup-checklist"
            >
                <h2 class="ai-setup-title">{{ t.setup_title }}</h2>
                <p class="ai-setup-lead">{{ t.setup_lead }}</p>
                <div class="ai-ck">
                    <span
                        class="ai-ckb"
                        :class="{ on: setup.persona_published }"
                        aria-hidden="true"
                        >{{ setup.persona_published ? '✓' : '' }}</span
                    >
                    <span v-if="setup.persona_published"
                        >{{ t.setup_persona_done
                        }}<span class="sr-only"> — {{ t.setup_done }}</span></span
                    >
                    <span v-else-if="page === 'persona'"
                        >{{ t.setup_persona_todo }} {{ t.setup_here
                        }}<span class="sr-only"> — {{ t.setup_todo }}</span></span
                    >
                    <span v-else
                        ><Link
                            href="/admin/ai/persona"
                            class="ai-link"
                            data-testid="ai-setup-persona-link"
                            >{{ t.setup_persona_todo }}</Link
                        ><span class="sr-only"> — {{ t.setup_todo }}</span></span
                    >
                </div>
                <div class="ai-ck">
                    <span class="ai-ckb" aria-hidden="true"></span>
                    <span
                        ><b>{{ t.setup_starter_title }}</b> — {{ t.setup_starter_desc }}</span
                    >
                </div>
                <div class="ai-ck">
                    <span class="ai-ckb" aria-hidden="true"></span>
                    <span
                        ><b>{{ t.setup_publish_title }}</b> — {{ t.setup_publish_desc }}</span
                    >
                </div>
                <div class="ai-ck">
                    <span
                        class="ai-ckb"
                        :class="{ on: setup.categories_ai_usable }"
                        aria-hidden="true"
                        >{{ setup.categories_ai_usable ? '✓' : '' }}</span
                    >
                    <span
                        ><b>{{ t.setup_scope_title }}</b> — {{ t.setup_scope_desc }}</span
                    >
                </div>
                <div class="ai-setup-acts">
                    <a :href="setup.kb_url" class="ai-btn ai-btn-pri">{{ t.setup_open_kb }}</a>
                    <a :href="setup.categories_url" class="ai-btn">{{ t.setup_open_categories }}</a>
                </div>
            </section>

            <slot />
        </div>
    </div>
</template>

<style>
/* Shared Settings › AI shell + the B8 house primitives (unscoped on purpose: the AI pages
   compose them; tokens only, the frozen measures verbatim). */
.ai-page {
    min-height: 100vh;
    padding: 24px 16px 60px;
    background: var(--ds-page-bg);
    color: var(--ds-text-primary);
    font-size: 12.5px;
}
.ai-shell {
    max-width: 1180px;
    margin: 0 auto;
    background: var(--ds-panel-bg);
    border: 1px solid var(--ds-border);
    border-radius: 10px;
    overflow: hidden;
}
.ai-crumb {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 10px 16px;
    border-bottom: 1px solid var(--ds-border);
    font-size: 11px;
    color: var(--ds-text-muted);
    flex-wrap: wrap;
}
.ai-crumb b {
    color: var(--ds-text-primary);
    font-weight: 600;
}
.ai-crumb-right {
    margin-inline-start: auto;
    display: inline-flex;
    gap: 8px;
    align-items: center;
    flex-wrap: wrap;
}
.ai-lab {
    font-size: 10px;
    font-weight: 600;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--ds-text-label);
    display: block;
    margin-bottom: 5px;
}
.ai-vchip {
    font-size: 10.5px;
    font-weight: 600;
    background: var(--ds-panel-soft);
    border: 1px solid var(--ds-border);
    color: var(--ds-text-secondary);
    border-radius: 5px;
    padding: 3px 8px;
}
.ai-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    font-size: 11px;
    font-weight: 600;
    border-radius: 6px;
    padding: 7px 13px;
    border: 1px solid var(--ds-border-strong);
    background: var(--ds-panel-bg);
    color: var(--ds-text-primary);
    text-decoration: none;
}
.ai-btn-pri {
    background: var(--ds-accent);
    color: var(--ds-accent-contrast);
    border-color: var(--ds-accent);
}
.ai-btn-red {
    background: var(--ds-danger-strong);
    color: var(--ds-accent-contrast);
    border-color: var(--ds-danger-strong);
}
.ai-btn-dngr {
    border-color: var(--ds-danger-border);
    color: var(--ds-danger-text);
}
.ai-btn-ai {
    background: var(--ds-ai-bg);
    color: var(--ds-ai-fg);
    border-color: var(--ds-ai-border);
}
.ai-btn[disabled] {
    opacity: 0.55;
}
.ai-in {
    border: 1px solid var(--ds-border-strong);
    border-radius: 6px;
    background: var(--ds-panel-bg);
    color: var(--ds-text-primary);
    font-size: 12.5px;
    padding: 7px 10px;
    width: 100%;
    max-width: 360px;
}
.ai-ta {
    border: 1px solid var(--ds-border-strong);
    border-radius: 6px;
    background: var(--ds-panel-bg);
    color: var(--ds-text-primary);
    font-size: 12.5px;
    line-height: 1.55;
    padding: 8px 10px;
    width: 100%;
    max-width: 520px;
    min-height: 58px;
}
.ai-field {
    margin-bottom: 14px;
}
.ai-lockrow {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    background: var(--ds-panel-soft);
    border: 1px solid var(--ds-border);
    border-radius: 6px;
    padding: 8px 10px;
    max-width: 520px;
    font-size: 11px;
    line-height: 1.5;
    color: var(--ds-text-secondary);
}
.ai-lockrow-warn {
    background: var(--ds-warn-bg);
    border-color: var(--ds-warn-border);
    color: var(--ds-warn-text);
    max-width: none;
}
.ai-modal-backdrop {
    position: fixed;
    inset: 0;
    z-index: 50;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px;
    background: rgba(0, 0, 0, 0.45);
}
.ai-modal {
    width: 100%;
    max-width: 420px;
    background: var(--ds-panel-bg);
    border: 1px solid var(--ds-border-strong);
    border-radius: 10px;
    padding: 16px;
    color: var(--ds-text-primary);
}
.ai-modal h2 {
    margin: 0 0 6px;
    font-size: 13.5px;
    font-weight: 600;
}
.ai-modal p {
    margin: 0 0 12px;
    font-size: 12px;
    line-height: 1.55;
    color: var(--ds-text-secondary);
}
.ai-modal-acts {
    display: flex;
    gap: 8px;
    justify-content: flex-end;
    flex-wrap: wrap;
}
.ai-banner {
    border-radius: 8px;
    padding: 9px 11px;
    font-size: 11.5px;
    line-height: 1.5;
    font-weight: 600;
}
.ai-banner small {
    display: block;
    font-weight: 400;
    margin-top: 4px;
    opacity: 0.92;
}
.ai-banner-deg {
    background: var(--ds-inset-bg);
    border: 1px solid var(--ds-border-strong);
    color: var(--ds-text-secondary);
}
.ai-banner-warn {
    background: var(--ds-warn-bg);
    border: 1px solid var(--ds-warn-border);
    color: var(--ds-warn-text);
}
.ai-banner-ok {
    background: var(--ds-success-bg);
    border: 1px solid var(--ds-border);
    color: var(--ds-success-text);
}
.ai-banner-off {
    background: var(--ds-danger-bg);
    border: 1px solid var(--ds-danger-surface-border);
    color: var(--ds-danger-text);
    display: flex;
    gap: 8px;
    align-items: center;
    flex-wrap: wrap;
}
.ai-seed {
    font-size: 9px;
    font-weight: 700;
    letter-spacing: 0.06em;
    background: var(--ds-inset-bg);
    color: var(--ds-text-secondary);
    border: 1px solid var(--ds-border-strong);
    border-radius: 4px;
    padding: 1px 5px;
    vertical-align: middle;
}
.ai-pill-live {
    font-size: 9px;
    font-weight: 700;
    letter-spacing: 0.06em;
    background: var(--ds-success-bg);
    color: var(--ds-success-text);
    border: 1px solid var(--ds-border);
    border-radius: 4px;
    padding: 1px 6px;
    margin-inline-start: 6px;
    vertical-align: middle;
}
.ai-vrow {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    padding: 9px 2px;
    border-top: 1px solid var(--ds-border);
    font-size: 12px;
}
.ai-vrow:first-of-type {
    border-top: 0;
}
.ai-who {
    color: var(--ds-text-muted);
    font-size: 10.5px;
}
.ai-tt {
    border-collapse: collapse;
    font-size: 11.5px;
    width: 100%;
}
.ai-tt th {
    text-align: start;
    font-size: 10px;
    font-weight: 600;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--ds-text-label);
    padding: 6px 10px;
    border-bottom: 1px solid var(--ds-border-strong);
}
.ai-tt td {
    padding: 6px 10px;
    border-bottom: 1px solid var(--ds-border);
    color: var(--ds-text-primary);
}
.ai-tt td.num {
    font-variant-numeric: tabular-nums;
}
.ai-mono {
    font-family: ui-monospace, monospace;
    font-size: 11px;
}
.ai-setup {
    margin: 12px 16px 0;
    padding: 14px 16px;
    border: 1px solid var(--ds-border);
    border-radius: 10px;
    background: var(--ds-panel-bg);
}
.ai-setup-title {
    font-size: 13.5px;
    font-weight: 600;
    margin: 0 0 4px;
}
.ai-setup-lead {
    font-size: 11.5px;
    color: var(--ds-text-secondary);
    line-height: 1.55;
    margin: 0 0 10px;
}
.ai-ck {
    display: flex;
    gap: 9px;
    align-items: flex-start;
    padding: 6px 0;
    border-top: 1px solid var(--ds-border);
    font-size: 12px;
    line-height: 1.5;
}
.ai-ck:first-of-type {
    border-top: 0;
}
.ai-ckb {
    width: 16px;
    height: 16px;
    flex: none;
    border-radius: 4px;
    border: 1px solid var(--ds-border-strong);
    background: var(--ds-panel-bg);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 11px;
    font-weight: 700;
    margin-top: 1px;
}
.ai-ckb.on {
    background: var(--ds-success-bg);
    color: var(--ds-success-text);
    border-color: var(--ds-success-text);
}
.ai-setup-acts {
    display: flex;
    gap: 8px;
    margin-top: 12px;
    flex-wrap: wrap;
}
@media (max-width: 639px) {
    .ai-page {
        padding: 0 0 40px;
    }
    .ai-shell {
        border-radius: 0;
        border-inline-width: 0;
    }
    .ai-btn {
        padding: 12px 16px;
        min-height: 44px;
    }
}
.ai-link {
    color: var(--ds-text-primary);
    text-decoration: underline;
    text-underline-offset: 2px;
    font-weight: 600;
}
</style>
