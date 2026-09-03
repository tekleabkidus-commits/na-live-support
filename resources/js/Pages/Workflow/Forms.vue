<script setup lang="ts">
// Doc 12 §8 forms list (S12 step 14, redrawn to the frozen B9-S11 record in SESSION-F1 [B12]):
// the forms table — name with the "seeded · editable" tag (FR-23: content, not config), the
// department and shape line, the live version and date, what binds the form (bot menus / rules /
// widget shortcuts), and the new-form row. Doors unchanged.
import { computed, ref } from 'vue';
import WorkflowShell from '../../Components/Workflow/WorkflowShell.vue';
import WorkflowRefusal from '../../Components/Workflow/WorkflowRefusal.vue';
import { post, tr } from '../../Components/Workflow/workflowApi';

type Form = {
    id: string;
    name: string;
    active: boolean;
    current_version: number | null;
    published_at: string | null;
    usage: Record<string, { id: string; name: string }[]>;
    updated_at: string | null;
    seeded?: boolean;
    department_name?: string | null;
    steps?: number;
    fields?: number;
};
const props = defineProps<{
    copy: Record<string, unknown>;
    usage_labels: Record<string, string>;
    can: Record<string, boolean>;
    forms: Form[];
}>();
// eslint-disable-next-line @typescript-eslint/no-explicit-any
const c = props.copy as any;
const refusal = ref('');
const newName = ref('');
const readonly = ref(window.innerWidth < 1024);
const seededCount = computed(() => props.forms.filter((f) => f.seeded).length);
const summaryLine = computed(() => {
    const base = tr(c.forms.summary, { count: props.forms.length });
    return seededCount.value ? `${base} · ${tr(c.forms.n_seeded, { n: seededCount.value })}` : base;
});
async function create(): Promise<void> {
    const result = await post<{ id: string }>('/admin/workflow/forms', { name: newName.value });
    if (!result.ok) {
        refusal.value = tr(c.common.refused, { detail: result.refusal });
        return;
    }
    window.location.href = `/admin/workflow/forms/${result.data.id}`;
}
function usageBits(f: Form): string[] {
    return Object.entries(f.usage).flatMap(([k, list]) =>
        list.map((x) => `${props.usage_labels[k] ?? k} “${x.name}”`),
    );
}
function shapeLine(f: Form): string {
    const parts: string[] = [];
    if (f.department_name) parts.push(f.department_name);
    if (f.steps !== undefined && f.fields !== undefined)
        parts.push(tr(c.forms.shape, { steps: f.steps, fields: f.fields }));
    return parts.join(' · ');
}
function fmtDate(iso: string | null): string {
    if (!iso) return '';
    const d = new Date(iso.replace(' ', 'T'));
    return Number.isNaN(d.getTime())
        ? iso
        : d.toLocaleDateString(undefined, { day: 'numeric', month: 'short' });
}
function versionLine(f: Form): string {
    return f.current_version
        ? `v${f.current_version} · ${fmtDate(f.published_at)}`
        : c.forms.draft_never;
}
</script>

<template>
    <WorkflowShell :copy="c" active="forms" :title="c.forms.title" :summary="summaryLine">
        <template #actions>
            <form v-if="can['forms.edit'] && !readonly" class="fm-new" @submit.prevent="create">
                <input
                    v-model="newName"
                    required
                    data-testid="wf-form-name"
                    class="bo-in"
                    :placeholder="c.common.name"
                    :aria-label="c.common.name"
                />
                <button type="submit" data-testid="wf-form-create" class="bo-btn p">
                    {{ c.forms.new }}
                </button>
            </form>
        </template>
        <WorkflowRefusal :text="refusal" />
        <p v-if="forms.length === 0" class="bo-empty" data-testid="wf-forms-empty">
            {{ c.forms.empty }}
        </p>
        <div v-else class="bo-pane fm-table">
            <div class="fm-h">
                <span>{{ c.forms.col_form }}</span
                ><span>{{ c.forms.col_version }}</span
                ><span>{{ c.forms.usage_banner }}</span>
            </div>
            <ul class="fm-rows">
                <li
                    v-for="f in forms"
                    :key="f.id"
                    :data-form-row="f.id"
                    class="fm-row"
                    :class="{ off: !f.active }"
                >
                    <div class="main">
                        <a :href="`/admin/workflow/forms/${f.id}`" class="name">{{ f.name }}</a>
                        <span v-if="f.seeded" class="bo-chip" data-testid="wf-form-seeded">{{
                            c.forms.seeded_editable
                        }}</span>
                        <span class="bo-hint">{{ shapeLine(f) }}</span>
                    </div>
                    <span class="ver">
                        <span v-if="f.current_version" class="bo-chip ok">{{
                            versionLine(f)
                        }}</span>
                        <span v-else class="bo-chip">{{ versionLine(f) }}</span>
                    </span>
                    <span class="use bo-hint">{{
                        usageBits(f).length ? usageBits(f).join(' · ') : c.forms.usage_none_short
                    }}</span>
                </li>
            </ul>
            <p class="bo-hint fm-foot">{{ c.forms.seeded_law }}</p>
        </div>
    </WorkflowShell>
</template>

<style scoped>
.fm-new {
    display: flex;
    gap: 8px;
    align-items: center;
}
.fm-new .bo-in {
    inline-size: 200px;
}
.fm-h {
    display: grid;
    grid-template-columns: minmax(0, 2fr) 140px minmax(0, 2fr);
    gap: 10px;
    padding: 8px 12px;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--ds-text-label);
    border-block-end: 1px solid var(--ds-border);
}
.fm-rows {
    list-style: none;
    margin: 0;
    padding: 0;
}
.fm-row {
    display: grid;
    grid-template-columns: minmax(0, 2fr) 140px minmax(0, 2fr);
    gap: 10px;
    align-items: center;
    padding: 10px 12px;
    border-block-end: 1px solid var(--ds-border-soft);
    min-height: 44px;
}
.fm-row.off {
    color: var(--ds-text-muted);
}
.fm-row .main {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 6px;
}
.fm-row .name {
    font-weight: 600;
    color: inherit;
    text-decoration: none;
    min-height: 44px;
    display: inline-flex;
    align-items: center;
}
.fm-row .name:hover {
    text-decoration: underline;
}
.fm-row .main .bo-hint {
    flex-basis: 100%;
}
.fm-foot {
    padding: 10px 12px;
}
@media (max-width: 639px) {
    .fm-h {
        display: none;
    }
    .fm-row {
        grid-template-columns: minmax(0, 1fr);
    }
}
</style>
