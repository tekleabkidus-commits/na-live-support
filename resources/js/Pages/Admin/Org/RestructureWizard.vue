<script setup lang="ts">
// Doc 11 FR-18 (S11 step 13) — the DELETE WIZARDS (B5-S10 J16–J21; tokens only). The page
// renders what the step-10 JSON doors answer — inventory, choices, confirmation, progress all
// come from ONE truth (the doors); the typed confirmation is the SERVER'S phrase (AC-07), and
// a stale phrase surfaces the door's own refusal. §13: a stepped form — the step heading is
// focused on every change and the progress list is a polite live region. 375: steps 1 and 3
// are executable per the ALREADY-FILED v3 deviation (B5-S00-checklist-v3 66-72/253/258,
// B5-S11-closing-audit-v2 L10 — cited, not re-filed); steps 2/4 reuse the same step chrome.
import { computed, onMounted, onUnmounted, ref } from 'vue';

type MoveTarget = { id: string; name: string; department_id: string; department: string };
type Check = { ok: boolean; reason: string | null };
type RefRow = { id: string; name: string };
type Inventory = {
    target: Record<string, unknown>;
    rules: RefRow[];
    bots: { id: string; name: string; tag: string }[];
    sla_policies: RefRow[];
    views: { count: number; items: { id: string; name: string | null; redacted: boolean }[] };
    tickets: { open: number; total: number };
    checks: Record<string, Check>;
    queues?: { id: string; name: string; is_rg: boolean; is_active: boolean }[];
    choices: Record<string, unknown>;
    confirmation: string;
    blocked: string | null;
};
type Progress = { chunk: number; chunk_total: number; moved: number; state: string };

const props = defineProps<{
    can: Record<string, boolean>;
    copy: Record<string, unknown>;
    kind: 'queue' | 'department';
    target: { id: string; name: string; department_id?: string; is_rg?: boolean };
    targets: MoveTarget[];
}>();

// eslint-disable-next-line @typescript-eslint/no-explicit-any
const t = (props.copy as any).wizard;
const step = ref(1);
const inv = ref<Inventory | null>(null);
const loadFailed = ref(false);
const partialAccepted = ref(false);
const targetQueueId = ref('');
const rgQueueId = ref('');
const ruleChoice = ref<Record<string, string>>({}); // rule id -> target queue id | 'off'
const botChoice = ref<Record<string, string>>({});
const slaChoice = ref<Record<string, string>>({});
const typed = ref('');
const postError = ref('');
const staleTo = ref('');
const progress = ref<Progress | null>(null);
const submitted = ref(false);
const heading = ref<HTMLElement | null>(null);
let poller: number | null = null;

const doorBase = computed(() =>
    props.kind === 'queue'
        ? `/org/queues/${props.target.id}/delete`
        : `/org/departments/${props.target.id}/delete`,
);
const wizardName = computed(() => (props.kind === 'queue' ? 'delete_queue' : 'delete_department'));
const failedChecks = computed(() =>
    Object.entries(inv.value?.checks ?? {}).filter(([, c]) => !c.ok),
);
const rgRequired = computed(() => {
    const choice = inv.value?.choices?.replacement_rg_queue_id as
        { required?: boolean } | undefined;
    return choice?.required === true;
});
const needsTarget = computed(() => (inv.value?.tickets.open ?? 0) > 0);
const step2Ready = computed(
    () =>
        (!needsTarget.value || targetQueueId.value !== '') &&
        (!rgRequired.value || rgQueueId.value !== ''),
);
const offRules = computed(() => Object.values(ruleChoice.value).filter((v) => v === 'off').length);
const repointRules = computed(() => (inv.value?.rules.length ?? 0) - offRules.value);
const refCount = computed(
    () =>
        (inv.value?.sla_policies.length ?? 0) +
        (inv.value?.bots.length ?? 0) +
        (inv.value?.views.count ?? 0),
);
const targetName = computed(
    () => props.targets.find((q) => q.id === targetQueueId.value)?.name ?? '',
);

function tr(template: string, replace: Record<string, string | number>): string {
    let out = template ?? '';
    for (const [key, value] of Object.entries(replace))
        out = out.replaceAll(':' + key, String(value));
    return out;
}
function focusHeading(): void {
    requestAnimationFrame(() => heading.value?.focus());
}
function rowCount(section: 'rules' | 'bots' | 'sla_policies'): number {
    if (section === 'sla_policies') return inv.value?.sla_policies.length ?? 0;
    return inv.value?.[section].length ?? 0;
}
async function loadPreview(): Promise<void> {
    loadFailed.value = false;
    try {
        const response = await fetch(doorBase.value, {
            headers: { Accept: 'application/json' },
        });
        if (!response.ok) throw new Error(String(response.status));
        inv.value = (await response.json()) as Inventory;
    } catch {
        loadFailed.value = true;
    }
}
function goTo(next: number): void {
    step.value = next;
    postError.value = '';
    focusHeading();
}
async function execute(): Promise<void> {
    postError.value = '';
    staleTo.value = '';
    const choices: Record<string, unknown> = {
        rules: (inv.value?.rules ?? []).map((rule) => ({
            id: rule.id,
            new_target_queue_id:
                (ruleChoice.value[rule.id] ?? '') === 'off'
                    ? null
                    : ruleChoice.value[rule.id] || targetQueueId.value || null,
        })),
        bots: (inv.value?.bots ?? []).map((bot) => ({
            id: bot.id,
            new_target_queue_id: botChoice.value[bot.id] || targetQueueId.value || null,
        })),
        sla_policies: (inv.value?.sla_policies ?? []).map((policy) => ({
            id: policy.id,
            new_target_queue_id: slaChoice.value[policy.id] || targetQueueId.value || null,
        })),
    };
    if (targetQueueId.value !== '') choices.target_queue_id = targetQueueId.value;
    if (rgQueueId.value !== '') choices.replacement_rg_queue_id = rgQueueId.value;
    const response = await fetch(doorBase.value, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-XSRF-TOKEN': decodeURIComponent(
                document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '',
            ),
        },
        body: JSON.stringify({ confirmation: typed.value, choices }),
    });
    if (response.status === 202) {
        submitted.value = true;
        goTo(4);
        poller = window.setInterval(() => void poll(), 1000);
        return;
    }
    const body = (await response.json()) as {
        refusal_code?: string;
        detail?: string;
    };
    if (body.refusal_code === 'confirmation_stale') {
        await loadPreview();
        staleTo.value = inv.value?.confirmation ?? '';
        typed.value = '';
        return;
    }
    postError.value = body.detail ?? body.refusal_code ?? 'error';
}
async function poll(): Promise<void> {
    try {
        const response = await fetch(
            `/org/restructure/${wizardName.value}/${props.target.id}/progress`,
            { headers: { Accept: 'application/json' } },
        );
        const body = (await response.json()) as Progress | { state: 'unknown' };
        if ('chunk' in body) progress.value = body;
        if (
            'state' in body &&
            body.state !== 'queued' &&
            body.state !== 'running' &&
            poller !== null
        ) {
            window.clearInterval(poller);
            poller = null;
        }
    } catch {
        /* keep polling */
    }
}
onMounted(() => void loadPreview());
onUnmounted(() => {
    if (poller !== null) window.clearInterval(poller);
});
</script>

<template>
    <main
        class="min-h-screen px-4 py-8"
        :style="{
            background: 'var(--ds-page-bg)',
            color: 'var(--ds-text-primary)',
        }"
    >
        <!-- J16: the 760px step card (max-w keeps 375 executable — the v3 deviation, cited) -->
        <div
            class="mx-auto w-full max-w-[760px] overflow-hidden rounded-[10px] border shadow-lg"
            :style="{
                background: 'var(--ds-panel-bg)',
                borderColor: 'var(--ds-border)',
            }"
        >
            <div
                class="border-b px-[18px] py-3.5"
                :style="{ borderColor: 'var(--ds-border-soft)' }"
            >
                <h1
                    ref="heading"
                    tabindex="-1"
                    class="m-0 text-[15px] font-semibold outline-none"
                    data-testid="wizard-title"
                >
                    {{
                        tr(kind === 'queue' ? t.title_queue : t.title_department, {
                            name: target.name,
                        })
                    }}
                </h1>
                <p
                    class="mt-0.5 text-[11.5px]"
                    :style="{ color: 'var(--ds-text-secondary)' }"
                    data-testid="wizard-step-line"
                >
                    {{ tr(t.step_of, { n: step }) }} · {{ t['step' + step + '_label'] }}
                </p>
                <div class="mt-2.5 flex gap-1.5" aria-hidden="true">
                    <span
                        v-for="n in 4"
                        :key="n"
                        class="h-1 flex-1 rounded-full"
                        :style="{
                            background: n <= step ? 'var(--ds-accent)' : 'var(--ds-border)',
                        }"
                    ></span>
                </div>
            </div>

            <div class="flex flex-col gap-3 px-[18px] py-3.5">
                <!-- load failure of the WHOLE preview -->
                <div
                    v-if="loadFailed"
                    class="rounded-lg border p-3"
                    :style="{
                        background: 'var(--ds-danger-bg)',
                        borderColor: 'var(--ds-danger-border)',
                    }"
                    data-testid="wizard-load-failed"
                >
                    <p
                        class="text-[12px] font-semibold"
                        :style="{ color: 'var(--ds-danger-strong)' }"
                    >
                        {{ tr(t.check_failed, { system: t.row_tickets }) }}
                    </p>
                    <button
                        type="button"
                        class="mt-2 rounded-md border px-2.5 py-1.5 text-[11px] font-semibold"
                        :style="{
                            borderColor: 'var(--ds-danger-border)',
                            color: 'var(--ds-danger-strong)',
                        }"
                        @click="void loadPreview()"
                    >
                        {{ t.retry }}
                    </button>
                </div>

                <!-- blocked: the department-default queue can never be deleted from here (EC-10) -->
                <div
                    v-if="inv?.blocked === 'queue_is_department_default'"
                    class="rounded-lg border p-3 text-[12px] leading-relaxed"
                    :style="{
                        background: 'var(--ds-danger-bg)',
                        borderColor: 'var(--ds-danger-border)',
                        color: 'var(--ds-danger-strong)',
                    }"
                    data-testid="wizard-blocked"
                >
                    {{ t.blocked_department_default }}
                </div>

                <!-- STEP 1 (J19): the inventory, one row per system, failures inline -->
                <template v-if="step === 1 && inv && !inv.blocked">
                    <p
                        class="text-[12.5px] leading-relaxed"
                        :style="{ color: 'var(--ds-text-secondary)' }"
                    >
                        {{ t.step1_intro }}
                    </p>
                    <div
                        class="overflow-hidden rounded-lg border"
                        :style="{ borderColor: 'var(--ds-border)' }"
                    >
                        <div
                            class="flex items-center gap-2.5 border-b px-3 py-2.5"
                            :style="{ borderColor: 'var(--ds-border-soft)' }"
                            data-testid="inv-row-tickets"
                        >
                            <span
                                class="w-14 flex-none text-right font-mono text-[15px] font-semibold tabular-nums"
                                >{{ inv.tickets.open }}</span
                            >
                            <span class="min-w-0 flex-1 text-[12.5px]">{{ t.row_tickets }}</span>
                            <span
                                v-if="inv.tickets.open > 0"
                                class="flex-none rounded-full px-2 py-px text-[10.5px] font-semibold"
                                :style="{
                                    background: 'var(--ds-danger-bg)',
                                    color: 'var(--ds-danger-strong)',
                                }"
                                >{{ t.badge_move }}</span
                            >
                        </div>
                        <template
                            v-for="section in ['rules', 'bots', 'sla_policies', 'views'] as const"
                            :key="section"
                        >
                            <div
                                v-if="inv.checks[section]?.ok !== false"
                                class="flex items-center gap-2.5 border-b px-3 py-2.5 last:border-b-0"
                                :style="{ borderColor: 'var(--ds-border-soft)' }"
                                :data-testid="'inv-row-' + section"
                            >
                                <span
                                    class="w-14 flex-none text-right font-mono text-[15px] font-semibold tabular-nums"
                                    >{{
                                        section === 'views' ? inv.views.count : rowCount(section)
                                    }}</span
                                >
                                <span class="min-w-0 flex-1 text-[12.5px]">{{
                                    section === 'rules'
                                        ? t.row_rules
                                        : section === 'bots'
                                          ? t.row_bots
                                          : section === 'sla_policies'
                                            ? t.row_sla
                                            : t.row_views
                                }}</span>
                                <span
                                    v-if="section === 'rules' && rowCount('rules') > 0"
                                    class="flex-none rounded-full px-2 py-px text-[10.5px] font-semibold"
                                    :style="{
                                        background: 'var(--ds-warn-bg)',
                                        color: 'var(--ds-warn-text)',
                                    }"
                                    >{{ t.badge_breaks }}</span
                                >
                            </div>
                            <!-- J19: the failed check row — red, reasoned, retryable -->
                            <div
                                v-else
                                class="flex items-center gap-2.5 border-b px-3 py-2.5 last:border-b-0"
                                :style="{
                                    background: 'var(--ds-danger-bg)',
                                    borderColor: 'var(--ds-danger-border)',
                                }"
                                :data-testid="'inv-failed-' + section"
                            >
                                <span class="min-w-0 flex-1">
                                    <span
                                        class="block text-[12px] font-semibold"
                                        :style="{ color: 'var(--ds-danger-strong)' }"
                                        >{{
                                            tr(t.check_failed, {
                                                system:
                                                    section === 'bots'
                                                        ? t.row_bots
                                                        : section === 'rules'
                                                          ? t.row_rules
                                                          : section === 'sla_policies'
                                                            ? t.row_sla
                                                            : t.row_views,
                                            })
                                        }}</span
                                    >
                                    <span
                                        class="mt-px block text-[11px]"
                                        :style="{ color: 'var(--ds-danger-strong)' }"
                                        >{{ t.check_failed_body }}</span
                                    >
                                </span>
                                <button
                                    type="button"
                                    class="flex-none rounded-md border px-2.5 py-1.5 text-[11px] font-semibold"
                                    :style="{
                                        background: 'var(--ds-panel-bg)',
                                        borderColor: 'var(--ds-danger-border)',
                                        color: 'var(--ds-danger-strong)',
                                    }"
                                    :data-testid="'inv-retry-' + section"
                                    @click="void loadPreview()"
                                >
                                    {{ t.retry }}
                                </button>
                            </div>
                        </template>
                    </div>
                    <!-- J19: the incomplete banner + the deliberate partial-continue -->
                    <div
                        v-if="failedChecks.length > 0"
                        class="rounded-lg border p-3 text-[11.5px] leading-relaxed"
                        :style="{
                            background: 'var(--ds-danger-bg)',
                            borderColor: 'var(--ds-danger-border)',
                            color: 'var(--ds-danger-strong)',
                        }"
                        data-testid="wizard-incomplete"
                    >
                        <strong class="font-semibold">{{ t.incomplete_title }}</strong>
                        {{ t.incomplete_body }}
                        <label class="mt-2 flex items-center gap-2 text-[11.5px] font-semibold">
                            <input
                                v-model="partialAccepted"
                                type="checkbox"
                                data-testid="wizard-partial-accept"
                            />
                            {{ t.continue_partial }}
                        </label>
                    </div>
                    <div class="flex items-center gap-2">
                        <a
                            href="/admin/organization"
                            class="rounded-md border px-3 py-2 text-[12px] font-semibold no-underline"
                            :style="{
                                borderColor: 'var(--ds-border-strong)',
                                color: 'var(--ds-text-primary)',
                            }"
                            >{{ t.cancel }}</a
                        >
                        <button
                            type="button"
                            class="ml-auto rounded-md px-3.5 py-2 text-[12.5px] font-semibold disabled:opacity-50"
                            :style="{
                                background: 'var(--ds-accent)',
                                color: 'var(--ds-accent-contrast)',
                            }"
                            :disabled="failedChecks.length > 0 && !partialAccepted"
                            data-testid="wizard-next-1"
                            @click="goTo(2)"
                        >
                            {{ t.next }}
                        </button>
                    </div>
                </template>

                <!-- STEP 2: a choice for each reference (J16 step 2; J18's RG lock) -->
                <template v-if="step === 2 && inv">
                    <div
                        class="rounded-lg border p-3"
                        :style="{ borderColor: 'var(--ds-border)' }"
                        data-testid="choice-tickets"
                    >
                        <div class="flex items-center gap-2">
                            <span class="text-[12.5px] font-semibold">{{
                                tr(t.choice_tickets, { count: inv.tickets.open })
                            }}</span>
                            <span
                                v-if="needsTarget"
                                class="ml-auto text-[11px]"
                                :style="{ color: 'var(--ds-text-secondary)' }"
                                >{{ t.required }}</span
                            >
                        </div>
                        <div v-if="needsTarget" class="mt-2 flex items-center gap-2">
                            <label
                                class="whitespace-nowrap text-[12px]"
                                for="wizard-target"
                                :style="{ color: 'var(--ds-text-secondary)' }"
                                >{{ t.move_to }}</label
                            >
                            <select
                                id="wizard-target"
                                v-model="targetQueueId"
                                class="min-w-0 flex-1 rounded-[7px] border px-2.5 py-2 text-[12.5px]"
                                :style="{
                                    borderColor: 'var(--ds-accent)',
                                    background: 'var(--ds-panel-bg)',
                                    color: 'var(--ds-text-primary)',
                                }"
                                data-testid="wizard-target-select"
                            >
                                <option value="" disabled>{{ t.choose }}</option>
                                <option v-for="q in targets" :key="q.id" :value="q.id">
                                    {{ q.name }} · {{ q.department }}
                                </option>
                            </select>
                        </div>
                        <p
                            class="mt-1.5 text-[11.5px] leading-relaxed"
                            :style="{ color: 'var(--ds-text-secondary)' }"
                        >
                            {{ t.keep_note }}
                        </p>
                    </div>
                    <div
                        v-if="(inv.rules.length ?? 0) > 0"
                        class="rounded-lg border p-3"
                        :style="{ borderColor: 'var(--ds-border)' }"
                    >
                        <span class="text-[12.5px] font-semibold">{{
                            tr(t.choice_rules, { count: inv.rules.length })
                        }}</span>
                        <div
                            v-for="rule in inv.rules"
                            :key="rule.id"
                            class="mt-1.5 flex items-center gap-2 rounded-[7px] px-2.5 py-2"
                            :style="{ background: 'var(--ds-inset-bg)' }"
                        >
                            <span class="min-w-0 flex-1 truncate text-[12px]">{{ rule.name }}</span>
                            <select
                                v-model="ruleChoice[rule.id]"
                                class="flex-none rounded-md border px-2 py-1 text-[11px]"
                                :style="{
                                    borderColor: 'var(--ds-border-strong)',
                                    background: 'var(--ds-panel-bg)',
                                    color: 'var(--ds-text-primary)',
                                }"
                                :aria-label="rule.name"
                            >
                                <option v-for="q in targets" :key="q.id" :value="q.id">
                                    {{ tr(t.repoint, { name: q.name }) }}
                                </option>
                                <option value="off">{{ t.switch_off }}</option>
                            </select>
                        </div>
                    </div>
                    <!-- J18: the RG lock — a red card, a required choice, a reasoned disabled Next -->
                    <div
                        v-if="rgRequired"
                        class="rounded-[7px] border p-3"
                        :style="{
                            background: 'var(--ds-danger-bg)',
                            borderColor: 'var(--ds-danger-border)',
                        }"
                        data-testid="wizard-rg-card"
                    >
                        <p
                            class="text-[12px] font-semibold"
                            :style="{ color: 'var(--ds-danger-strong)' }"
                        >
                            🔒 {{ t.rg_title }}
                        </p>
                        <p
                            class="mt-1 text-[11.5px] leading-relaxed"
                            :style="{ color: 'var(--ds-danger-strong)' }"
                        >
                            {{ t.rg_body }}
                        </p>
                        <div class="mt-2 flex items-center gap-2">
                            <label
                                class="whitespace-nowrap text-[11.5px]"
                                for="wizard-rg"
                                :style="{ color: 'var(--ds-danger-strong)' }"
                                >{{ t.rg_label }}</label
                            >
                            <select
                                id="wizard-rg"
                                v-model="rgQueueId"
                                class="min-w-0 flex-1 rounded-md border px-2.5 py-1.5 text-[12px]"
                                :style="{
                                    background: 'var(--ds-panel-bg)',
                                    borderColor: 'var(--ds-danger-border)',
                                    color: 'var(--ds-text-primary)',
                                }"
                                data-testid="wizard-rg-select"
                            >
                                <option value="" disabled>{{ t.choose }}</option>
                                <option v-for="q in targets" :key="q.id" :value="q.id">
                                    {{ q.name }} · {{ q.department }}
                                </option>
                            </select>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            class="rounded-md border px-3 py-2 text-[12px] font-semibold"
                            :style="{
                                borderColor: 'var(--ds-border-strong)',
                                color: 'var(--ds-text-primary)',
                            }"
                            @click="goTo(1)"
                        >
                            {{ t.back }}
                        </button>
                        <span
                            v-if="rgRequired && rgQueueId === ''"
                            class="text-[11.5px]"
                            :style="{ color: 'var(--ds-warn-text)' }"
                            data-testid="wizard-rg-blocked"
                            >{{ t.rg_blocked }}</span
                        >
                        <button
                            type="button"
                            class="ml-auto rounded-md px-3.5 py-2 text-[12.5px] font-semibold disabled:opacity-50"
                            :style="{
                                background: 'var(--ds-accent)',
                                color: 'var(--ds-accent-contrast)',
                            }"
                            :disabled="!step2Ready"
                            data-testid="wizard-next-2"
                            @click="goTo(3)"
                        >
                            {{ t.next }}
                        </button>
                    </div>
                </template>

                <!-- STEP 3 (J16): what happens + the typed confirmation (AC-07: the server's phrase) -->
                <template v-if="step === 3 && inv">
                    <p class="text-[12.5px] font-semibold">{{ t.confirm_header }}</p>
                    <div class="flex flex-col gap-1.5" data-testid="wizard-confirm-list">
                        <p
                            v-if="inv.tickets.open > 0"
                            class="m-0 flex gap-2 text-[12.5px] leading-relaxed"
                            :style="{ color: 'var(--ds-text-secondary)' }"
                        >
                            <span aria-hidden="true">→</span>
                            <span>{{
                                tr(t.confirm_tickets, { count: inv.tickets.open, name: targetName })
                            }}</span>
                        </p>
                        <p
                            v-if="repointRules > 0"
                            class="m-0 flex gap-2 text-[12.5px] leading-relaxed"
                            :style="{ color: 'var(--ds-text-secondary)' }"
                        >
                            <span aria-hidden="true">→</span>
                            <span>{{ tr(t.confirm_rules_repoint, { count: repointRules }) }}</span>
                        </p>
                        <p
                            v-if="offRules > 0"
                            class="m-0 flex gap-2 text-[12.5px] leading-relaxed"
                            :style="{ color: 'var(--ds-text-secondary)' }"
                        >
                            <span aria-hidden="true">→</span>
                            <span>{{ tr(t.confirm_rules_off, { count: offRules }) }}</span>
                        </p>
                        <p
                            v-if="refCount > 0"
                            class="m-0 flex gap-2 text-[12.5px] leading-relaxed"
                            :style="{ color: 'var(--ds-text-secondary)' }"
                        >
                            <span aria-hidden="true">→</span>
                            <span>{{ tr(t.confirm_refs, { count: refCount }) }}</span>
                        </p>
                        <p
                            class="m-0 flex gap-2 text-[12.5px] leading-relaxed"
                            :style="{ color: 'var(--ds-text-secondary)' }"
                        >
                            <span aria-hidden="true">→</span>
                            <span>{{
                                kind === 'queue'
                                    ? t.confirm_deleted_queue
                                    : t.confirm_deleted_department
                            }}</span>
                        </p>
                    </div>
                    <div>
                        <label
                            class="block text-[11.5px] font-semibold"
                            for="wizard-typed"
                            :style="{ color: 'var(--ds-text-secondary)' }"
                        >
                            {{ tr(t.type_label, { phrase: inv.confirmation }) }}
                        </label>
                        <input
                            id="wizard-typed"
                            v-model="typed"
                            type="text"
                            class="mt-1 w-full rounded-[7px] border px-2.5 py-2 font-mono text-[12.5px]"
                            :style="{
                                borderColor: 'var(--ds-border-strong)',
                                background: 'var(--ds-panel-bg)',
                                color: 'var(--ds-text-primary)',
                            }"
                            data-testid="wizard-typed"
                        />
                        <p
                            v-if="typed !== '' && typed !== inv.confirmation"
                            class="mt-1 text-[11.5px]"
                            :style="{ color: 'var(--ds-danger-strong)' }"
                            data-testid="wizard-typed-mismatch"
                        >
                            {{ tr(t.type_mismatch, { phrase: inv.confirmation }) }}
                        </p>
                        <p
                            v-if="staleTo !== ''"
                            class="mt-1 text-[11.5px]"
                            :style="{ color: 'var(--ds-warn-text)' }"
                            data-testid="wizard-stale"
                        >
                            {{ tr(t.stale, { phrase: staleTo }) }}
                        </p>
                        <p
                            v-if="postError !== ''"
                            class="mt-1 text-[11.5px]"
                            :style="{ color: 'var(--ds-danger-strong)' }"
                            data-testid="wizard-post-error"
                        >
                            {{ postError }}
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            class="rounded-md border px-3 py-2 text-[12px] font-semibold"
                            :style="{
                                borderColor: 'var(--ds-border-strong)',
                                color: 'var(--ds-text-primary)',
                            }"
                            @click="goTo(2)"
                        >
                            {{ t.back }}
                        </button>
                        <button
                            type="button"
                            class="ml-auto rounded-md px-3.5 py-2 text-[12.5px] font-semibold disabled:opacity-50"
                            :style="{
                                background: 'var(--ds-danger-strong)',
                                color: 'var(--ds-accent-contrast)',
                            }"
                            :disabled="typed !== inv.confirmation"
                            data-testid="wizard-execute"
                            @click="void execute()"
                        >
                            {{ t.execute }}
                        </button>
                    </div>
                </template>

                <!-- STEP 4 (J16): progress from the mover's own row; a polite live region -->
                <template v-if="step === 4">
                    <div
                        class="flex flex-col gap-2"
                        aria-live="polite"
                        data-testid="wizard-progress"
                    >
                        <div class="flex items-center gap-2.5">
                            <span
                                class="flex-none rounded-full px-2 py-px text-[10.5px] font-semibold"
                                :style="
                                    progress?.state === 'done'
                                        ? {
                                              background: 'var(--ds-success-bg)',
                                              color: 'var(--ds-success-text)',
                                          }
                                        : {
                                              background: 'var(--ds-inset-bg)',
                                              border: '1px solid var(--ds-border)',
                                              color: 'var(--ds-text-secondary)',
                                          }
                                "
                                data-testid="wizard-progress-chip"
                                >{{
                                    progress?.state === 'done' ? t.chip_done : t.chip_moving
                                }}</span
                            >
                            <span class="min-w-0 flex-1 text-[12.5px]">{{
                                tr(t.progress_tickets, { name: targetName })
                            }}</span>
                            <span
                                class="flex-none font-mono text-[11px]"
                                :style="{ color: 'var(--ds-text-secondary)' }"
                                >{{ tr(t.chunks, { moved: progress?.moved ?? 0 }) }}</span
                            >
                        </div>
                        <p
                            v-if="
                                progress?.state === 'stopped_operator_gone' ||
                                progress?.state === 'stopped_permission_lost'
                            "
                            class="m-0 rounded-lg border p-2.5 text-[11.5px] leading-relaxed"
                            :style="{
                                background: 'var(--ds-danger-bg)',
                                borderColor: 'var(--ds-danger-border)',
                                color: 'var(--ds-danger-strong)',
                            }"
                            data-testid="wizard-stopped"
                        >
                            {{
                                progress.state === 'stopped_operator_gone'
                                    ? t.stopped_operator_gone
                                    : t.stopped_permission_lost
                            }}
                        </p>
                    </div>
                    <div
                        class="flex items-center gap-2.5 border-t pt-3"
                        :style="{ borderColor: 'var(--ds-border-soft)' }"
                    >
                        <span class="text-[11.5px]" :style="{ color: 'var(--ds-text-muted)' }">{{
                            t.close_note
                        }}</span>
                        <a
                            href="/admin/organization"
                            class="ml-auto rounded-[7px] border px-3.5 py-2 text-[12.5px] font-semibold no-underline"
                            :style="{
                                background: 'var(--ds-accent)',
                                borderColor: 'var(--ds-accent)',
                                color: 'var(--ds-accent-contrast)',
                            }"
                            data-testid="wizard-close"
                            >{{ t.close }}</a
                        >
                    </div>
                </template>
            </div>
        </div>
    </main>
</template>
