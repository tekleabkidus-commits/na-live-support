<script setup lang="ts">
// Doc 12 §8 — the Backoffice → Workflow hub (B9-S13 M01, SESSION-F1 [B12]): the seven group cards
// with their live sub-lines, the R-04 paused banner (from the flag, D01), and the "what is real on
// this chrome" legend (Organization a link out, People / Channels drawn, Knowledge base P8's entry,
// the seven Workflow rows on every Doc 12 page). Read-only counts; doors untouched.
import { computed } from 'vue';
import WorkflowShell from '../../Components/Workflow/WorkflowShell.vue';
import { tr } from '../../Components/Workflow/workflowApi';

const props = defineProps<{
    copy: Record<string, unknown>;
    can: Record<string, boolean>;
    paused: boolean;
    counts: {
        rules: number;
        policies: number;
        forms: number;
        macros: number;
        rules_active?: number;
        policies_default_calendar?: number;
        calendars?: number;
        timezone?: string;
        macros_personal?: number;
        macros_team?: number;
        macros_shared?: number;
        fields_ticket?: number;
        fields_customer?: number;
        fields_retired?: number;
        forms_seeded?: number;
        forms_draft?: number;
        csat_cooldown?: number;
        csat_window?: number;
    };
}>();
// eslint-disable-next-line @typescript-eslint/no-explicit-any
const c = props.copy as any;
const groups = ['rules', 'sla', 'hours', 'macros', 'fields', 'forms', 'csat'] as const;
const navKey: Record<(typeof groups)[number], string> = {
    rules: 'automation',
    sla: 'sla',
    hours: 'hours',
    macros: 'macros',
    fields: 'fields',
    forms: 'forms',
    csat: 'csat',
};
const n = props.counts;
const subLine = computed<Record<(typeof groups)[number], string>>(() => ({
    rules: tr(c.hub.sub_rules, { total: n.rules, active: n.rules_active ?? 0 }),
    sla: tr(c.hub.sub_sla, { n: n.policies_default_calendar ?? 0 }),
    hours: tr(c.hub.sub_hours, { n: n.calendars ?? 0, tz: n.timezone ?? '' }),
    macros: tr(c.hub.sub_macros, {
        personal: n.macros_personal ?? 0,
        team: n.macros_team ?? 0,
        shared: n.macros_shared ?? 0,
    }),
    fields: tr(c.hub.sub_fields, {
        ticket: n.fields_ticket ?? 0,
        customer: n.fields_customer ?? 0,
        retired: n.fields_retired ?? 0,
    }),
    forms: tr(c.hub.sub_forms, { seeded: n.forms_seeded ?? 0, draft: n.forms_draft ?? 0 }),
    csat: tr(c.hub.sub_csat, { cooldown: n.csat_cooldown ?? 0, window: n.csat_window ?? 0 }),
}));
const bigNumber = computed<Record<(typeof groups)[number], string>>(() => ({
    rules: String(n.rules),
    sla: String(n.policies),
    hours: String(n.calendars ?? 0),
    macros: String(n.macros),
    fields: String((n.fields_ticket ?? 0) + (n.fields_customer ?? 0)),
    forms: String(n.forms),
    csat: `${n.csat_cooldown ?? 0} h`,
}));
</script>

<template>
    <WorkflowShell
        :copy="props.copy"
        active="hub"
        :title="c.hub.title"
        :summary="tr(c.hub.summary, props.counts)"
    >
        <template #banner>
            <p v-if="paused" role="status" data-testid="wf-paused" class="bo-banner danger">
                <b>{{ c.rules.paused_title }}</b> {{ c.rules.paused_body }}
                {{ c.rules.paused_platform }}
            </p>
        </template>
        <div class="hb-cards">
            <a
                v-for="key in groups"
                :key="key"
                :href="`/admin/workflow/${key}`"
                :data-testid="`wf-hub-${key}`"
                class="bo-card hb-card"
            >
                <span class="big">{{ bigNumber[key] }}</span>
                <span class="nm">{{ c.nav[navKey[key]] }}</span>
                <span class="bo-hint">{{ subLine[key] }}</span>
            </a>
        </div>
        <section class="bo-card hb-legend" data-testid="wf-hub-legend">
            <h4>{{ c.hub.legend_title }}</h4>
            <dl class="bo-kv2">
                <dt>
                    <span class="bo-chip ok">{{ c.hub.real }}</span>
                </dt>
                <dd>{{ c.hub.legend_org }}</dd>
                <dt>
                    <span class="bo-chip">{{ c.hub.drawn }}</span>
                </dt>
                <dd>{{ c.hub.legend_people }}</dd>
                <dt>
                    <span class="bo-chip ok">{{ c.hub.real }}</span>
                </dt>
                <dd>{{ c.hub.legend_kb }}</dd>
                <dt>
                    <span class="bo-chip ok">{{ c.hub.real }}</span>
                </dt>
                <dd>{{ c.hub.legend_workflow }}</dd>
            </dl>
            <p class="bo-hint">{{ c.hub.legend_palette }}</p>
        </section>
    </WorkflowShell>
</template>

<style scoped>
.hb-cards {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: 12px;
}
.hb-card {
    display: grid;
    gap: 2px;
    padding: 14px 16px;
    text-decoration: none;
    color: inherit;
    min-height: 96px;
}
.hb-card:hover,
.hb-card:focus-visible {
    box-shadow: inset 0 0 0 2px var(--ds-accent);
    outline: none;
}
.hb-card .big {
    font-size: 22px;
    font-weight: 700;
    font-variant-numeric: tabular-nums;
}
.hb-card .nm {
    font-weight: 600;
}
.hb-legend {
    margin-block-start: 16px;
    padding: 12px 14px;
    font-size: 12.5px;
}
.hb-legend h4 {
    margin: 0 0 6px;
    font-size: 12.5px;
}
.bo-banner.danger {
    border-color: var(--ds-danger-border);
    background: var(--ds-danger-bg);
    color: var(--ds-danger-text);
}
</style>
