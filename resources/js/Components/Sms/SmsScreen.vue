<script setup lang="ts">
// Doc 14 (S14 step 13) — the SMS pages' shared chassis: BackofficeShell with
// the flat nav strings lifted out of the nested sms.screens copy, plus the
// pages' ONE global style block (sms-* classes, tokens only — B1-DS density).
import { computed } from 'vue';
import BackofficeShell from '../Backoffice/BackofficeShell.vue';
import { shared, type ScreensCopy } from './screens';

const props = defineProps<{
    copy: ScreensCopy;
    active: 'sms' | 'sms-settings';
    title: string;
    readNote?: string;
}>();

const t = computed<Record<string, string>>(() => ({
    nav_backoffice: shared(props.copy, 'nav_backoffice'),
    nav_organization: shared(props.copy, 'nav_organization'),
    nav_people: shared(props.copy, 'nav_people'),
    nav_channels: shared(props.copy, 'nav_channels'),
    nav_kb: shared(props.copy, 'nav_kb'),
    nav_workflow: shared(props.copy, 'nav_workflow'),
    nav_widgets: shared(props.copy, 'nav_widgets'),
    nav_sms_channel: shared(props.copy, 'nav_sms_channel'),
    nav_sms_settings: shared(props.copy, 'nav_sms_settings'),
}));
</script>

<template>
    <BackofficeShell :t="t" :active="active" :title="title" width="full" :read-note="readNote">
        <slot />
    </BackofficeShell>
</template>

<style>
/* B1-DS desktop density, 12.5 px type — tokens only; sms-* is this batch's prefix. */
.sms-page {
    display: grid;
    gap: 12px;
    font-size: 12.5px;
    color: var(--ds-text-primary);
}
.sms-card {
    background: var(--ds-panel-bg);
    border: 1px solid var(--ds-border);
    border-radius: 10px;
    padding: 14px;
}
.sms-muted {
    color: var(--ds-text-secondary);
    margin: 0;
}
.sms-row {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    align-items: center;
}
.sms-field {
    display: grid;
    gap: 4px;
}
.sms-field > span {
    font-weight: 600;
    color: var(--ds-text-label);
}
.sms-field input,
.sms-field select,
.sms-field textarea {
    min-height: 36px;
    padding: 6px 10px;
    border: 1px solid var(--ds-border);
    border-radius: 8px;
    background: var(--ds-panel-bg);
    color: var(--ds-text-primary);
    font: inherit;
}
.sms-btn {
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
.sms-btn-primary {
    background: var(--ds-accent);
    border-color: var(--ds-accent);
    color: var(--ds-accent-contrast);
}
.sms-btn[disabled] {
    opacity: 0.55;
    cursor: not-allowed;
}
.sms-field input:focus-visible,
.sms-field select:focus-visible,
.sms-field textarea:focus-visible,
.sms-btn:focus-visible {
    outline: 3px solid var(--ds-focus-ring);
    outline-offset: 1px;
}
.sms-pill {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 1px 8px;
    border-radius: 999px;
    border: 1px solid var(--ds-border);
    font-weight: 600;
    white-space: nowrap;
}
.sms-pill.ok {
    color: var(--ds-success-text);
    background: var(--ds-success-bg);
    border-color: transparent;
}
.sms-pill.warn {
    color: var(--ds-warn-text);
    background: var(--ds-warn-bg);
    border-color: transparent;
}
.sms-pill.bad {
    color: var(--ds-danger-text);
    background: var(--ds-danger-bg);
    border-color: transparent;
}
.sms-table {
    width: 100%;
    border-collapse: collapse;
}
.sms-table th,
.sms-table td {
    text-align: start;
    padding: 8px 10px;
    border-block-end: 1px solid var(--ds-border);
    vertical-align: top;
}
.sms-table th {
    color: var(--ds-text-label);
    font-weight: 600;
}
.sms-error {
    color: var(--ds-danger-text);
    margin: 0;
}
.sms-ok {
    color: var(--ds-success-text);
    margin: 0;
}
.sms-grid-cards {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 12px;
}
.sms-dl {
    display: grid;
    grid-template-columns: max-content 1fr;
    gap: 4px 12px;
    margin: 0;
}
.sms-dl dt {
    color: var(--ds-text-label);
    font-weight: 600;
}
.sms-dl dd {
    margin: 0;
}
.sms-dot {
    display: inline-block;
    inline-size: 10px;
    block-size: 10px;
    border-radius: 999px;
    border: 1px solid var(--ds-border-strong);
}
.sms-dot.full {
    background: var(--ds-success-text);
    border-color: transparent;
}
@media (max-width: 480px) {
    .sms-table th:nth-child(n + 4),
    .sms-table td:nth-child(n + 4) {
        display: none;
    }
}
</style>
