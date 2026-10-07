<script setup lang="ts">
// SESSION-F1 [B9] (frozen B9-S01…S04, the B5-S09 J01 chrome): the Backoffice shell the KB
// pages share — 52 px top bar + 200 px nav (Organization a real link; People & roles and
// Channels drawn dim, Docs 03/05 own them; Knowledge base its own row; Workflow the link to
// Doc 12's hub) — the WorkflowShell idiom generalised for a second document. Tokens only;
// logical properties (RTL-safe); no animation. Under 640 px the nav folds away and the
// page states its READ posture (Doc 10 §9): nothing greyed, nothing blank.
defineProps<{
    t: Record<string, string>;
    active: 'kb' | 'workflow' | 'organization' | 'widgets' | 'sms' | 'sms-settings';
    title: string;
    crumb?: { label: string; href?: string }[];
    readNote?: string;
    width?: 'kb' | 'full';
}>();
</script>

<template>
    <div class="bo-shell" data-testid="backoffice-shell">
        <header class="bo-top">
            <span class="bo-logo">{{ t.nav_backoffice }}</span>
            <h1 class="bo-top-title">{{ title }}</h1>
        </header>
        <div class="bo-body">
            <nav :aria-label="t.nav_backoffice" class="bo-nav">
                <p class="bo-nav-h">{{ t.nav_backoffice }}</p>
                <a
                    href="/admin/organization"
                    class="bo-nav-a"
                    :class="{ on: active === 'organization' }"
                    >{{ t.nav_organization }}</a
                >
                <span class="bo-nav-a dim">{{ t.nav_people }}</span>
                <span class="bo-nav-a dim">{{ t.nav_channels }}</span>
                <a
                    href="/admin/kb"
                    class="bo-nav-a"
                    :class="{ on: active === 'kb' }"
                    :aria-current="active === 'kb' ? 'page' : undefined"
                    data-testid="bo-nav-kb"
                    >{{ t.nav_kb }}</a
                >
                <a
                    href="/admin/workflow"
                    class="bo-nav-a"
                    :class="{ on: active === 'workflow' }"
                    :aria-current="active === 'workflow' && !$slots['nav-sub'] ? 'page' : undefined"
                    data-testid="bo-nav-workflow"
                    >{{ t.nav_workflow }}</a
                >
                <!-- Doc 13 §4.4 (S13 step 11): the Widget Designer — a real row ("an unlinked screen is a worse deviation") -->
                <a
                    href="/admin/widgets"
                    class="bo-nav-a"
                    :class="{ on: active === 'widgets' }"
                    :aria-current="active === 'widgets' ? 'page' : undefined"
                    data-testid="bo-nav-widgets"
                    >{{ t.nav_widgets }}</a
                >
                <!-- Doc 14 (S14 step 13, P-NAV-SMS): the two frozen slots B6-S01/B6-S06 draw — "Channels · ✉ SMS" and "Settings · SMS" ("an unlinked screen is the worse deviation") -->
                <a
                    href="/admin/sms"
                    class="bo-nav-a"
                    :class="{ on: active === 'sms' }"
                    :aria-current="active === 'sms' ? 'page' : undefined"
                    data-testid="bo-nav-sms"
                    >{{ t.nav_sms_channel }}</a
                >
                <a
                    href="/admin/sms/settings"
                    class="bo-nav-a"
                    :class="{ on: active === 'sms-settings' }"
                    :aria-current="active === 'sms-settings' ? 'page' : undefined"
                    data-testid="bo-nav-sms-settings"
                    >{{ t.nav_sms_settings }}</a
                >
                <!-- the Workflow group's sub-rows (Doc 12's pages render them; B9-S05 §E chrome) -->
                <div v-if="$slots['nav-sub']" class="bo-nav-sub"><slot name="nav-sub" /></div>
            </nav>
            <main class="bo-main" :class="{ full: width === 'full' }">
                <p v-if="readNote" class="bo-readnote" data-testid="bo-readnote">{{ readNote }}</p>
                <div class="bo-ph">
                    <div class="bo-ph-l">
                        <p v-if="crumb && crumb.length" class="bo-crumb" data-testid="bo-crumb">
                            <template v-for="(c, i) in crumb" :key="i">
                                <a v-if="c.href" :href="c.href">{{ c.label }}</a
                                ><b v-else>{{ c.label }}</b>
                                <span v-if="i < crumb.length - 1" aria-hidden="true"> › </span>
                            </template>
                        </p>
                        <h2 class="bo-h2">{{ title }}</h2>
                        <div class="bo-sub"><slot name="sub" /></div>
                    </div>
                    <div class="bo-acts"><slot name="actions" /></div>
                </div>
                <slot name="banner" />
                <slot />
            </main>
        </div>
    </div>
</template>

<style>
/* ── the KB house sheet (B9-S01…S04 anatomy on B1-DS v2 tokens; shared by the KB pages) ── */
.bo-shell {
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    background: var(--ds-page-bg);
    color: var(--ds-text-primary);
    font-size: 12.5px;
}
.bo-top {
    height: 52px;
    flex: none;
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 0 16px;
    background: var(--ds-panel-bg);
    border-block-end: 1px solid var(--ds-border);
}
.bo-logo {
    border: 1px dashed var(--ds-border-strong);
    color: var(--ds-text-muted);
    border-radius: 6px;
    padding: 4px 10px;
    font-size: 11.5px;
    font-weight: 600;
}
.bo-top-title {
    font-size: 13px;
    font-weight: 600;
    margin: 0;
}
.bo-body {
    display: flex;
    flex: 1;
    min-height: 0;
}
.bo-nav {
    width: 200px;
    flex: none;
    border-inline-end: 1px solid var(--ds-border);
    padding: 8px;
    background: var(--ds-page-bg);
}
.bo-nav-h {
    font-size: 10.5px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.07em;
    color: var(--ds-text-label);
    padding: 0 8px 8px;
    margin: 0;
}
.bo-nav-a {
    display: block;
    padding: 6px 8px;
    border-radius: 6px;
    font-size: 12.5px;
    color: var(--ds-text-primary);
    text-decoration: none;
}
.bo-nav-a.on {
    background: var(--ds-accent);
    color: var(--ds-accent-contrast);
    font-weight: 600;
}
.bo-nav-a.dim {
    color: var(--ds-text-muted);
}
.bo-nav-sub {
    margin-inline-start: 10px;
    border-inline-start: 1px solid var(--ds-border);
    padding-inline-start: 6px;
    margin-top: 2px;
}
.bo-nav-sub .bo-nav-a {
    font-size: 12px;
    padding: 5px 8px;
}
.bo-main {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
}
.bo-ph {
    padding: 14px 18px 0;
    display: flex;
    align-items: flex-start;
    gap: 12px;
    flex-wrap: wrap;
}
.bo-ph-l {
    min-width: 0;
    flex: 1;
}
.bo-crumb {
    font-size: 11.5px;
    color: var(--ds-text-muted);
    margin: 0 0 4px;
}
.bo-crumb a {
    color: var(--ds-text-muted);
}
.bo-crumb b {
    color: var(--ds-text-primary);
    font-weight: 500;
}
.bo-h2 {
    font-size: 20px;
    font-weight: 600;
    letter-spacing: -0.01em;
    margin: 0;
    line-height: 1.3;
    overflow-wrap: anywhere;
}
.bo-sub {
    font-size: 12px;
    color: var(--ds-text-muted);
    margin-top: 2px;
    display: flex;
    gap: 8px;
    align-items: center;
    flex-wrap: wrap;
}
.bo-acts {
    margin-inline-start: auto;
    display: flex;
    gap: 8px;
    align-items: center;
    flex-wrap: wrap;
    justify-content: flex-end;
}
.bo-btn {
    height: 36px;
    padding: 0 12px;
    border-radius: 8px;
    border: 1px solid var(--ds-border);
    background: var(--ds-panel-bg);
    color: var(--ds-text-primary);
    font-size: 12px;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    white-space: nowrap;
    cursor: pointer;
    text-decoration: none;
}
.bo-btn.p {
    background: var(--ds-accent);
    color: var(--ds-accent-contrast);
    border-color: var(--ds-accent);
}
.bo-btn.sm {
    height: 28px;
    padding: 0 10px;
    font-size: 11.5px;
}
.bo-btn.danger {
    background: var(--ds-danger-text);
    color: var(--ds-accent-contrast);
    border-color: var(--ds-danger-text);
}
.bo-btn:disabled {
    cursor: default;
}
.bo-stamp {
    font-size: 11.5px;
    color: var(--ds-text-muted);
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-variant-numeric: tabular-nums;
}
.bo-pill {
    display: inline-flex;
    align-items: center;
    height: 20px;
    padding: 0 8px;
    border-radius: 999px;
    font-size: 10.5px;
    font-weight: 600;
    letter-spacing: 0.02em;
    white-space: nowrap;
}
.bo-pill.draft {
    background: var(--ds-status-closed-bg);
    color: var(--ds-status-closed-fg);
}
.bo-pill.in_review {
    background: var(--ds-status-pending-internal-bg);
    color: var(--ds-status-pending-internal-fg);
}
.bo-pill.published {
    background: var(--ds-status-open-bg);
    color: var(--ds-status-open-fg);
}
.bo-pill.archived {
    background: var(--ds-status-spam-bg);
    color: var(--ds-status-spam-fg);
}
.bo-tag {
    display: inline-flex;
    align-items: center;
    height: 18px;
    padding: 0 6px;
    border-radius: 4px;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.07em;
    text-transform: uppercase;
    border: 1px solid var(--ds-warn-border);
    background: var(--ds-warn-bg);
    color: var(--ds-warn-text);
    white-space: nowrap;
}
.bo-tag.ai {
    border-color: transparent;
    background: var(--ds-ai-bg);
    color: var(--ds-ai-fg);
    text-transform: none;
    letter-spacing: 0;
    font-weight: 600;
}
.bo-dots {
    display: inline-flex;
    gap: 8px;
    font-size: 11.5px;
    font-variant-numeric: tabular-nums;
    flex-wrap: wrap;
}
.bo-dots > span {
    display: inline-flex;
    align-items: center;
    gap: 3px;
}
.bo-dots i {
    font-style: normal;
    font-size: 12px;
    line-height: 1;
}
.d-cur {
    color: var(--ds-success-text);
}
.d-out {
    color: var(--ds-warn-text);
}
.d-mis {
    color: var(--ds-text-muted);
}
.bo-chip {
    display: inline-flex;
    align-items: center;
    height: 20px;
    padding: 0 8px;
    border-radius: 999px;
    font-size: 10.5px;
    font-weight: 600;
    border: 1px solid var(--ds-border-strong);
    color: var(--ds-text-secondary);
    white-space: nowrap;
}
.bo-chip.warn {
    border-color: var(--ds-warn-border);
    background: var(--ds-warn-bg);
    color: var(--ds-warn-text);
}
.bo-chip.ok {
    border-color: transparent;
    background: var(--ds-success-bg);
    color: var(--ds-success-text);
}
.bo-chip.ai {
    border-color: transparent;
    background: var(--ds-ai-bg);
    color: var(--ds-ai-fg);
}
.bo-chip.cur {
    background: var(--ds-accent);
    color: var(--ds-accent-contrast);
    border-color: var(--ds-accent);
}
.bo-chip.seed {
    border-style: dashed;
}
.bo-link {
    color: var(--ds-text-primary);
    text-decoration: underline;
    text-underline-offset: 2px;
    font-size: 11.5px;
    background: none;
    border: 0;
    padding: 0;
    cursor: pointer;
    font-family: inherit;
}
.bo-mono {
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    font-size: 11.5px;
}
.bo-pane {
    background: var(--ds-panel-bg);
    border: 1px solid var(--ds-border);
    border-radius: 8px;
    min-height: 560px;
    display: flex;
    flex-direction: column;
    min-width: 0;
}
.bo-pane .pt {
    padding: 10px 12px;
    border-block-end: 1px solid var(--ds-border-soft);
    font-size: 10.5px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.07em;
    color: var(--ds-text-label);
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.bo-pane .pt .n {
    margin-inline-start: auto;
    font-weight: 500;
    letter-spacing: 0;
    text-transform: none;
    font-size: 11px;
    color: var(--ds-text-muted);
}
.bo-sk {
    background: linear-gradient(90deg, var(--ds-inset-bg), var(--ds-row-hover), var(--ds-inset-bg));
    border-radius: 4px;
    height: 12px;
    display: block;
}
.bo-sk.w60 {
    width: 60%;
}
.bo-sk.w40 {
    width: 40%;
}
.bo-sk.w80 {
    width: 80%;
}
.bo-sk.h20 {
    height: 20px;
    width: 72px;
    border-radius: 999px;
}
.bo-empty {
    padding: 28px 20px;
    text-align: center;
    color: var(--ds-text-muted);
    font-size: 12.5px;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 10px;
    margin: auto;
}
.bo-empty b {
    color: var(--ds-text-primary);
    font-size: 13px;
}
.bo-empty .q {
    font-family: ui-monospace, monospace;
    background: var(--ds-inset-bg);
    padding: 1px 6px;
    border-radius: 4px;
    color: var(--ds-text-primary);
}
.bo-check {
    text-align: start;
    background: var(--ds-panel-soft);
    border: 1px solid var(--ds-border);
    border-radius: 8px;
    padding: 10px 14px;
    font-size: 12px;
    color: var(--ds-text-primary);
    width: 100%;
    max-width: 520px;
    list-style: none;
    margin: 0;
}
.bo-check li {
    display: flex;
    gap: 8px;
    align-items: center;
    min-height: 32px;
}
.bo-box {
    width: 16px;
    height: 16px;
    border: 1.5px solid var(--ds-border-strong);
    border-radius: 4px;
    display: inline-grid;
    place-items: center;
    font-size: 10px;
    flex: none;
}
.bo-box.done {
    background: var(--ds-success-bg);
    border-color: var(--ds-success-text);
    color: var(--ds-success-text);
}
.bo-note {
    margin: 12px 18px 0;
    padding: 8px 12px;
    border: 1px solid var(--ds-border);
    background: var(--ds-panel-soft);
    border-radius: 8px;
    font-size: 11.5px;
    color: var(--ds-text-secondary);
}
.bo-card {
    background: var(--ds-panel-bg);
    border: 1px solid var(--ds-border);
    border-radius: 8px;
}
.bo-card .ct {
    padding: 10px 12px;
    border-block-end: 1px solid var(--ds-border-soft);
    font-size: 10.5px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.07em;
    color: var(--ds-text-label);
    display: flex;
    align-items: center;
    gap: 8px;
}
.bo-card .ct .n {
    margin-inline-start: auto;
    text-transform: none;
    letter-spacing: 0;
    font-weight: 500;
    font-size: 11px;
    color: var(--ds-text-muted);
}
.bo-card .cb {
    padding: 10px 12px;
    font-size: 12px;
    display: grid;
    gap: 8px;
}
.bo-kv2 {
    display: grid;
    grid-template-columns: 92px 1fr;
    gap: 6px 10px;
    font-size: 11.5px;
    margin: 0;
}
.bo-kv2 dt {
    color: var(--ds-text-label);
    font-weight: 600;
    text-transform: uppercase;
    font-size: 10px;
    letter-spacing: 0.07em;
}
.bo-kv2 dd {
    margin: 0;
}
.bo-rail {
    display: grid;
    gap: 12px;
    align-self: start;
    min-width: 0;
}
.bo-banner {
    display: flex;
    gap: 10px;
    align-items: flex-start;
    padding: 10px 14px;
    font-size: 12px;
    border: 1px solid var(--ds-border);
    border-radius: 8px;
    background: var(--ds-panel-soft);
    color: var(--ds-text-primary);
    margin: 12px 18px 0;
    flex-wrap: wrap;
}
.bo-banner.int {
    background: var(--ds-warn-bg);
    border-color: var(--ds-warn-border);
}
.bo-banner.int b {
    color: var(--ds-warn-text);
    letter-spacing: 0.07em;
    font-size: 10.5px;
    text-transform: uppercase;
}
.bo-banner.danger {
    background: var(--ds-danger-bg);
    border-color: var(--ds-danger-border);
}
.bo-banner.ai {
    background: var(--ds-ai-bg);
    border-color: var(--ds-ai-border);
}
.bo-banner .acts {
    margin-inline-start: auto;
    display: flex;
    gap: 6px;
    flex: none;
}
.bo-readnote {
    margin: 10px 14px 0;
    padding: 8px 12px;
    border: 1px solid var(--ds-border);
    background: var(--ds-inset-bg);
    border-radius: 8px;
    font-size: 11.5px;
    color: var(--ds-text-secondary);
}
.bo-tabs {
    display: flex;
    gap: 4px;
    padding: 10px 14px 0;
}
.bo-tabs a {
    flex: 1;
    text-align: center;
    padding: 8px 6px;
    border-radius: 6px;
    font-size: 11.5px;
    font-weight: 600;
    color: var(--ds-text-muted);
    border: 1px solid var(--ds-border);
    text-decoration: none;
    min-height: 44px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}
.bo-tabs a.on {
    background: var(--ds-accent);
    color: var(--ds-accent-contrast);
    border-color: var(--ds-accent);
}
.bo-sel {
    height: 28px;
    border: 1px solid var(--ds-border);
    border-radius: 6px;
    background: var(--ds-inset-bg);
    padding: 0 8px;
    font-size: 11.5px;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: var(--ds-text-primary);
    font-family: inherit;
}
.bo-sel.on {
    border-color: var(--ds-accent);
    font-weight: 600;
}
.bo-search2 {
    height: 28px;
    flex: 1;
    min-width: 180px;
    border: 1px solid var(--ds-border);
    border-radius: 6px;
    background: var(--ds-inset-bg);
    padding: 0 8px;
    font-size: 11.5px;
    display: flex;
    align-items: center;
    gap: 6px;
    color: var(--ds-text-muted);
}
.bo-search2 input {
    flex: 1;
    min-width: 0;
    border: 0;
    background: transparent;
    color: var(--ds-text-primary);
    font-size: 11.5px;
    font-family: inherit;
    outline: none;
}
.bo-fld label,
.bo-fld .lab {
    display: block;
    font-size: 11.5px;
    font-weight: 600;
    color: var(--ds-text-secondary);
    margin-bottom: 5px;
}
.bo-in {
    min-height: 38px;
    width: 100%;
    border: 1px solid var(--ds-border-strong);
    border-radius: 6px;
    padding: 9px 11px;
    font-size: 13px;
    background: var(--ds-panel-bg);
    color: var(--ds-text-primary);
    font-family: inherit;
    box-sizing: border-box;
}
.bo-in.big {
    font-size: 18px;
    font-weight: 600;
    letter-spacing: -0.01em;
    line-height: 1.3;
    min-height: 44px;
}
.bo-in.ta {
    min-height: 64px;
    resize: vertical;
}
.bo-in.bad {
    border-color: var(--ds-danger-text);
}
.bo-hint {
    font-size: 11px;
    color: var(--ds-text-muted);
    margin-top: 4px;
}
.bo-tip {
    position: relative;
    display: inline-block;
}
.bo-tip .tt {
    position: absolute;
    top: calc(100% + 6px);
    inset-inline-start: 0;
    background: var(--ds-accent);
    color: var(--ds-accent-contrast);
    font-size: 11px;
    padding: 6px 8px;
    border-radius: 6px;
    white-space: nowrap;
    z-index: 2;
}
.bo-scrim {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.28);
    display: grid;
    place-items: center;
    padding: 24px;
    z-index: 20;
}
.bo-dlg {
    width: 560px;
    max-width: 100%;
    background: var(--ds-panel-bg);
    border: 1px solid var(--ds-border-strong);
    border-radius: 12px;
    overflow: hidden;
    color: var(--ds-text-primary);
    max-height: 90vh;
    overflow-y: auto;
}
.bo-dlg .dt {
    padding: 14px 18px;
    border-block-end: 1px solid var(--ds-border-soft);
    font-size: 15px;
    font-weight: 600;
    letter-spacing: -0.01em;
    margin: 0;
}
.bo-dlg .db {
    padding: 12px 18px;
    display: grid;
    gap: 10px;
    font-size: 12.5px;
}
.bo-dlg .df {
    padding: 12px 18px;
    border-block-start: 1px solid var(--ds-border-soft);
    display: flex;
    gap: 8px;
    justify-content: flex-end;
    align-items: center;
    flex-wrap: wrap;
}
.bo-row2 {
    display: grid;
    grid-template-columns: 150px 1fr;
    gap: 6px 12px;
    align-items: start;
    margin: 0;
}
.bo-row2 dt {
    font-size: 10.5px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.07em;
    color: var(--ds-text-label);
    padding-top: 2px;
}
.bo-row2 dd {
    margin: 0;
}
.bo-warnbox {
    border: 1px solid var(--ds-warn-border);
    background: var(--ds-warn-bg);
    border-radius: 6px;
    padding: 8px 10px;
}
.bo-refuse {
    border: 1px solid var(--ds-danger-border);
    background: var(--ds-danger-bg);
    border-radius: 6px;
    padding: 8px 10px;
    color: var(--ds-text-primary);
}
.bo-refuse b {
    color: var(--ds-danger-text);
}
.bo-absent {
    border: 1px dashed var(--ds-border-strong);
    border-radius: 8px;
    padding: 8px 10px;
    font-size: 11.5px;
    color: var(--ds-text-secondary);
}
.sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
}
@media (max-width: 639px) {
    .bo-nav {
        display: none;
    }
    .bo-top {
        height: 48px;
        padding: 0 12px;
    }
    .bo-ph {
        padding: 12px 14px 0;
    }
    .bo-h2 {
        font-size: 18px;
    }
    .bo-btn {
        min-height: 44px;
    }
    .bo-pane {
        min-height: 0;
    }
}
</style>
