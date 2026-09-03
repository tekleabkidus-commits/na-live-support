<script setup lang="ts">
// Doc 09 FR-18/US-07/AC-08 Scope & kill switches — SESSION-F1 [B8]: the frozen
// B8-S07-scope-killswitches-v2 anatomy. Two rooms on one floor: Scope (G01 — the
// KB categories the chatbot may answer from with the internal LOCK row; the
// endpoints running the chatbot) and the kill-switch GRID (G02/G03 — rows are
// surfaces, columns are endpoints, every cell one 64×32 role=switch with its
// word: steady "On", red "OFF since {time} by {actor}"; every row states its own
// off-consequence), the confirm with the BLAST RADIUS in the endpoint's own
// daily numbers (G04), the page banner while any switch is off, the audit strip
// (G06). The flip is the audited domain door, unchanged; the grid re-renders
// from its answer.
import { computed, ref } from 'vue';
import AiSettingsShell from '../../../Components/Ai/AiSettingsShell.vue';
import { adminCall } from './adminApi';

type Row = {
    id: string;
    surface: string;
    channel: string | null;
    endpoint_ref: string | null;
    is_off: boolean;
    reason: string | null;
    flipped_at: string | null;
    flipped_by_name: string | null;
};
type Endpoint = {
    ref: string;
    label: string;
    channel: string;
    chatbot_enabled?: boolean;
    conversations_per_day?: number;
};
const props = defineProps<{
    t: Record<string, string>;
    grid: Row[];
    surfaces: string[];
    endpoints: Endpoint[];
    scope?: {
        categories: {
            id: string;
            name: string;
            ai_usable: boolean;
            internal: boolean;
            published: number;
            languages: string[];
        }[];
    } | null;
    audit?: { surface: string; scope: string; off: boolean; at: string; actor: string }[];
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
const grid = ref<Row[]>(props.grid);
const confirming = ref<{
    surface: string;
    channel: string | null;
    endpoint_ref: string | null;
    off: boolean;
    label: string;
    perDay: number | null;
} | null>(null);
const reason = ref('');
const message = ref('');
const busy = ref(false);

// Columns: the whole-channel scope first (Telegram — every bot), then each endpoint; the web widget arrives with Doc 13.
const columns = computed(() => {
    const cols: {
        key: string;
        channel: string | null;
        endpoint_ref: string | null;
        label: string;
        perDay: number | null;
        available: boolean;
    }[] = [
        {
            key: 'all',
            channel: null,
            endpoint_ref: null,
            label: props.t.col_all ?? '',
            perDay: null,
            available: true,
        },
        {
            key: 'telegram',
            channel: 'telegram',
            endpoint_ref: null,
            label: props.t.col_telegram ?? '',
            perDay: null,
            available: true,
        },
    ];
    for (const e of props.endpoints.filter((x) => x.ref !== 'default')) {
        cols.push({
            key: e.ref,
            channel: e.channel,
            endpoint_ref: e.ref,
            label: e.label,
            perDay: e.conversations_per_day ?? null,
            available: true,
        });
    }
    cols.push({
        key: 'web',
        channel: 'web',
        endpoint_ref: null,
        label: props.t.col_web ?? '',
        perDay: null,
        available: false,
    });
    return cols;
});
function state(
    surface: string,
    channel: string | null,
    endpoint_ref: string | null,
): Row | undefined {
    return grid.value.find(
        (r) => r.surface === surface && r.channel === channel && r.endpoint_ref === endpoint_ref,
    );
}
const offRows = computed(() => grid.value.filter((r) => r.is_off));
function scopeLabel(r: { channel: string | null; endpoint_ref: string | null }): string {
    if (r.endpoint_ref)
        return props.endpoints.find((e) => e.ref === r.endpoint_ref)?.label ?? r.endpoint_ref;
    if (r.channel) return props.t['col_' + r.channel] ?? r.channel;
    return props.t.col_all ?? '';
}
function hhmm(iso: string | null | undefined): string {
    if (!iso) return '';
    const d = new Date(iso);
    return Number.isNaN(d.getTime())
        ? iso.slice(11, 16)
        : `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
}
function ask(
    surface: string,
    col: {
        channel: string | null;
        endpoint_ref: string | null;
        label: string;
        perDay: number | null;
    },
): void {
    const current = state(surface, col.channel, col.endpoint_ref);
    confirming.value = {
        surface,
        channel: col.channel,
        endpoint_ref: col.endpoint_ref,
        off: !(current?.is_off ?? false),
        label: col.label,
        perDay: col.perDay,
    };
    reason.value = '';
}
async function confirmFlip(): Promise<void> {
    if (!confirming.value) return;
    busy.value = true;
    try {
        const r = await adminCall<{ grid: Row[] }>('/admin/ai/kill-switches/flip', {
            surface: confirming.value.surface,
            channel: confirming.value.channel,
            endpoint_ref: confirming.value.endpoint_ref,
            off: confirming.value.off,
            reason: reason.value || null,
            confirm: true,
        });
        grid.value = r.grid;
        message.value = '';
    } catch (e) {
        message.value = (e as Error).message;
    } finally {
        busy.value = false;
        confirming.value = null;
        reason.value = '';
    }
}
const others = (c: { surface: string; label: string }): string =>
    props.surfaces
        .filter((s) => s !== c.surface)
        .map((s) => props.t['surface_' + s] ?? s)
        .join(', ');
</script>

<template>
    <AiSettingsShell :t="t" :page="t.killswitches_page" :setup="setup">
        <template #chip>
            <span class="ai-vchip" data-testid="ks-chip">{{
                offRows.length ? fill(t.chip_off, { n: offRows.length }) : t.chip_all_on
            }}</span>
        </template>

        <p
            v-if="message"
            role="status"
            class="mx-4 mt-3 rounded border px-2 py-1 text-xs"
            :style="{ borderColor: 'var(--ds-border-strong)' }"
        >
            {{ message }}
        </p>

        <!-- G03/G05: the page banner while any switch is off — since {time} by {actor}, the consequence, the way back -->
        <div
            v-for="r in offRows"
            :key="r.id"
            class="ai-banner ai-banner-off ks-banner"
            role="status"
            data-testid="ks-off-banner"
        >
            <span aria-hidden="true">⛔</span>
            <span
                >{{
                    fill(t.off_banner, {
                        surface: t['surface_' + r.surface] ?? r.surface,
                        scope: scopeLabel(r),
                        time: hhmm(r.flipped_at),
                        actor: r.flipped_by_name ?? '—',
                    })
                }}
                {{ t['off_consequence_' + r.surface] }}</span
            >
            <button
                type="button"
                class="ai-btn ks-banner-btn"
                :data-testid="'ks-banner-on-' + r.surface"
                @click="
                    ask(r.surface, {
                        channel: r.channel,
                        endpoint_ref: r.endpoint_ref,
                        label: scopeLabel(r),
                        perDay: null,
                    })
                "
            >
                {{ t.turn_back_on }}
            </button>
        </div>

        <!-- ── G02/G03: the grid — surfaces × endpoints, one switch per cell ── -->
        <section class="ks-sec" :aria-label="t.grid_title" data-testid="killswitch-grid">
            <table class="ks-grid">
                <thead>
                    <tr>
                        <th>{{ t.col_surface }}</th>
                        <th v-for="c in columns" :key="c.key">{{ c.label }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="s in surfaces" :key="s">
                        <td>
                            <div class="ks-surf">{{ t['surface_' + s] }}</div>
                            <div class="ks-surfd">{{ t['surface_desc_' + s] }}</div>
                        </td>
                        <td v-for="c in columns" :key="c.key">
                            <span v-if="!c.available" class="ks-na">{{ t.web_na }}</span>
                            <span v-else class="ks-ksw">
                                <button
                                    type="button"
                                    role="switch"
                                    :aria-checked="
                                        !(state(s, c.channel, c.endpoint_ref)?.is_off ?? false)
                                    "
                                    :aria-label="(t['surface_' + s] ?? s) + ' · ' + c.label"
                                    :data-testid="
                                        'switch-' +
                                        s +
                                        '-' +
                                        (c.channel ?? 'all') +
                                        '-' +
                                        (c.endpoint_ref ?? 'all')
                                    "
                                    class="ks-sw"
                                    :class="{
                                        on: !(state(s, c.channel, c.endpoint_ref)?.is_off ?? false),
                                        off: state(s, c.channel, c.endpoint_ref)?.is_off,
                                    }"
                                    :disabled="busy"
                                    @click="ask(s, c)"
                                >
                                    <span class="ks-track" aria-hidden="true"><i></i></span>
                                </button>
                                <span
                                    v-if="state(s, c.channel, c.endpoint_ref)?.is_off"
                                    class="ks-swl ks-swl-off"
                                    >{{ t.off_label }}</span
                                >
                                <span v-else class="ks-swl">{{ t.on_word }}</span>
                                <span
                                    v-if="state(s, c.channel, c.endpoint_ref)?.is_off"
                                    class="ks-offsince"
                                    :data-testid="'since-' + s"
                                >
                                    {{
                                        fill(t.off_since, {
                                            time: hhmm(
                                                state(s, c.channel, c.endpoint_ref)?.flipped_at,
                                            ),
                                            actor:
                                                state(s, c.channel, c.endpoint_ref)
                                                    ?.flipped_by_name ?? '—',
                                        })
                                    }}
                                </span>
                            </span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>

        <!-- ── G01: Scope — what the AI may use, and where it runs ── -->
        <section class="ks-sec" :aria-label="t.scope_page" data-testid="ks-scope">
            <span class="ai-lab">{{ t.scope_categories }}</span>
            <div
                v-for="c in scope?.categories ?? []"
                :key="c.id"
                class="ks-cat"
                :data-testid="'ks-cat-' + c.id"
            >
                <div>
                    <div class="ks-cn">
                        {{ c.name }} <span v-if="c.internal" class="ai-seed">{{ t.internal }}</span>
                    </div>
                    <div class="ks-cs">
                        <template v-if="c.internal">{{ t.internal_lock_note }}</template>
                        <template v-else>{{
                            fill(t.cat_stats, {
                                n: c.published,
                                langs: c.languages.join(' + ') || '—',
                            })
                        }}</template>
                    </div>
                </div>
                <span class="ks-chk">
                    <template v-if="c.internal">🔒</template>
                    <template v-else
                        ><span class="ks-box" :class="{ on: c.ai_usable }" aria-hidden="true">{{
                            c.ai_usable ? '✓' : ''
                        }}</span>
                        {{ c.ai_usable ? t.ai_usable : t.off_word }}</template
                    >
                </span>
            </div>
            <p v-if="!scope?.categories?.length" class="ks-cs">{{ t.scope_no_categories }}</p>
            <p class="ks-cs ks-scope-note">{{ t.scope_categories_note }}</p>

            <span class="ai-lab ks-lab-gap">{{ t.scope_endpoints }}</span>
            <div
                v-for="e in endpoints.filter((x) => x.ref !== 'default')"
                :key="e.ref"
                class="ks-cat"
                :data-testid="'ks-endpoint-' + e.ref"
            >
                <div>
                    <div class="ks-cn">
                        {{ e.label }} — {{ t['col_' + e.channel] ?? e.channel }}
                    </div>
                    <div class="ks-cs">
                        {{ fill(t.endpoint_stats, { n: e.conversations_per_day ?? 0 }) }}
                    </div>
                </div>
                <span class="ks-chk"
                    ><span class="ks-box" :class="{ on: e.chatbot_enabled }" aria-hidden="true">{{
                        e.chatbot_enabled ? '✓' : ''
                    }}</span>
                    {{ e.chatbot_enabled ? t.chatbot_on : t.chatbot_off }}</span
                >
            </div>
            <div class="ks-cat">
                <div>
                    <div class="ks-cn">{{ t.col_web }}</div>
                    <div class="ks-cs">{{ t.web_scope_note }}</div>
                </div>
                <span class="ks-chk"><span class="ks-na">—</span></span>
            </div>
            <p class="ks-cs ks-scope-note">
                {{ t.scope_endpoints_note }}
                <a href="/admin/ai/thresholds" class="ks-link">{{ t.thresholds_page }}</a>
            </p>
        </section>

        <!-- ── G06: the audit strip — the recent switch activity, on the page ── -->
        <section class="ks-sec" :aria-label="t.audit_title" data-testid="ks-audit">
            <div class="ks-audit-h">
                <b>{{ t.audit_title }}</b>
                <span class="ai-vchip">{{ t.audit_class }}</span>
            </div>
            <div v-for="(a, i) in audit ?? []" :key="i" class="ks-arow">
                <span class="ks-aw"
                    >{{ t['surface_' + a.surface] ?? a.surface }} · {{ a.scope }} →
                    {{ a.off ? t.off_label : t.on_label }}</span
                >
                <span class="ks-at">{{ a.at }} · {{ a.actor }}</span>
            </div>
            <p v-if="!(audit ?? []).length" class="ks-cs">{{ t.audit_empty }}</p>
            <p class="ks-cs ks-scope-note">{{ t.audit_note }}</p>
        </section>

        <!-- ── G04: the confirmation with the blast radius in the endpoint's own numbers ── -->
        <div v-if="confirming" class="ai-modal-backdrop" @click.self="confirming = null">
            <div
                class="ai-modal"
                role="dialog"
                aria-modal="true"
                :aria-label="confirming.off ? t.confirm_off : t.confirm_on"
                data-testid="killswitch-confirm"
            >
                <h2>
                    {{
                        fill(confirming.off ? t.confirm_off_title : t.confirm_on_title, {
                            surface: t['surface_' + confirming.surface] ?? confirming.surface,
                            scope: confirming.label,
                        })
                    }}
                </h2>
                <div v-if="confirming.off" class="ks-blast" data-testid="ks-blast">
                    <b>{{ t.blast_label }}</b>
                    {{
                        fill(t['blast_' + confirming.surface] ?? t.blast_generic, {
                            surface: t['surface_' + confirming.surface] ?? confirming.surface,
                            scope: confirming.label,
                            per_day:
                                confirming.perDay === null
                                    ? (t.per_day_unknown ?? '')
                                    : fill(t.per_day, { n: confirming.perDay }),
                            others: others({
                                surface: confirming.surface,
                                label: confirming.label,
                            }),
                        })
                    }}
                </div>
                <p>{{ confirming.off ? t.confirm_off_body : t.confirm_on_body }}</p>
                <label class="block text-xs">
                    <span class="ai-lab">{{ t.reason }}</span>
                    <input v-model="reason" data-testid="killswitch-reason" class="ai-in" />
                </label>
                <div class="ai-modal-acts mt-3">
                    <button type="button" class="ai-btn" @click="confirming = null">
                        {{ t.cancel }}
                    </button>
                    <button
                        type="button"
                        data-testid="killswitch-confirm-yes"
                        class="ai-btn"
                        :class="confirming.off ? 'ai-btn-red' : 'ai-btn-pri'"
                        :disabled="busy"
                        @click="confirmFlip"
                    >
                        {{
                            confirming.off
                                ? fill(t.turn_off_verb, {
                                      surface:
                                          t['surface_' + confirming.surface] ?? confirming.surface,
                                      scope: confirming.label,
                                  })
                                : t.turn_on_verb
                        }}
                    </button>
                </div>
            </div>
        </div>
    </AiSettingsShell>
</template>

<style scoped>
.ks-sec {
    padding: 12px 16px 16px;
    border-bottom: 1px solid var(--ds-border);
}
.ks-banner {
    margin: 12px 16px 0;
}
.ks-banner-btn {
    margin-inline-start: auto;
}
.ks-grid {
    border-collapse: collapse;
    width: 100%;
}
.ks-grid th {
    text-align: start;
    font-size: 10px;
    font-weight: 600;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--ds-text-label);
    padding: 8px 12px;
    border-bottom: 1px solid var(--ds-border-strong);
}
.ks-grid td {
    padding: 12px;
    border-bottom: 1px solid var(--ds-border);
    vertical-align: top;
}
.ks-surf {
    font-size: 12.5px;
    font-weight: 600;
    color: var(--ds-text-primary);
}
.ks-surfd {
    font-size: 10.5px;
    color: var(--ds-text-muted);
    margin-top: 2px;
    max-width: 190px;
    line-height: 1.45;
}
.ks-ksw {
    display: inline-flex;
    flex-direction: column;
    gap: 5px;
    min-width: 128px;
}
/* the drawn 64×32 track inside a 64×44 hit box — "the switch already clears 44 px with its padding" (§G) */
.ks-sw {
    width: 64px;
    height: 44px;
    padding: 6px 0;
    background: transparent;
    border: 0;
    cursor: pointer;
    display: inline-block;
}
.ks-track {
    display: block;
    width: 64px;
    height: 32px;
    border-radius: 16px;
    position: relative;
    border: 1px solid transparent;
}
.ks-sw.on .ks-track {
    background: var(--ds-success-text);
}
.ks-sw.off .ks-track {
    background: var(--ds-danger-strong);
}
.ks-track i {
    position: absolute;
    top: 2px;
    width: 26px;
    height: 26px;
    border-radius: 50%;
    background: var(--ds-panel-bg);
}
.ks-sw.on .ks-track i {
    inset-inline-end: 2px;
}
.ks-sw.off .ks-track i {
    inset-inline-start: 2px;
}
.ks-sw[disabled] {
    opacity: 0.7;
}
.ks-swl {
    font-size: 10.5px;
    font-weight: 700;
    color: var(--ds-text-primary);
}
.ks-swl-off {
    color: var(--ds-danger-text);
}
.ks-offsince {
    font-size: 10px;
    line-height: 1.45;
    color: var(--ds-danger-text);
    font-weight: 600;
    max-width: 150px;
}
.ks-na {
    font-size: 10.5px;
    color: var(--ds-text-muted);
}
.ks-cat {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    padding: 9px 2px;
    border-top: 1px solid var(--ds-border);
    font-size: 12.5px;
}
.ks-cat:first-of-type {
    border-top: 0;
}
.ks-cn {
    color: var(--ds-text-primary);
    font-weight: 500;
}
.ks-cs {
    font-size: 10.5px;
    color: var(--ds-text-muted);
    line-height: 1.5;
}
.ks-scope-note {
    margin: 8px 0 0;
}
.ks-lab-gap {
    margin-top: 16px;
}
.ks-chk {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    font-size: 11px;
    font-weight: 600;
    color: var(--ds-text-secondary);
    flex-shrink: 0;
}
.ks-box {
    width: 16px;
    height: 16px;
    border-radius: 4px;
    border: 1.5px solid var(--ds-border-strong);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 11px;
}
.ks-box.on {
    background: var(--ds-accent);
    border-color: var(--ds-accent);
    color: var(--ds-accent-contrast);
}
.ks-link {
    color: var(--ds-text-primary);
    text-decoration: underline;
}
.ks-audit-h {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 8px;
    padding: 4px 0 6px;
    font-size: 12px;
}
.ks-arow {
    display: flex;
    justify-content: space-between;
    gap: 10px;
    padding: 8px 2px;
    border-top: 1px solid var(--ds-border);
    font-size: 11.5px;
}
.ks-aw {
    color: var(--ds-text-primary);
    font-weight: 500;
}
.ks-at {
    color: var(--ds-text-muted);
    font-size: 10.5px;
    white-space: nowrap;
}
.ks-blast {
    background: var(--ds-danger-bg);
    border: 1px solid var(--ds-danger-surface-border);
    border-radius: 8px;
    padding: 9px 11px;
    font-size: 11.5px;
    line-height: 1.55;
    color: var(--ds-danger-text);
    margin-bottom: 12px;
}
@media (max-width: 639px) {
    /* §G: the grid stacks by surface; the switches stay full-size */
    .ks-grid thead {
        display: none;
    }
    .ks-grid tr {
        display: block;
        border-bottom: 1px solid var(--ds-border);
        padding: 8px 0;
    }
    .ks-grid td {
        display: block;
        border: 0;
        padding: 6px 2px;
    }
    .ks-ksw {
        flex-direction: row;
        align-items: center;
        gap: 10px;
        min-width: 0;
    }
    .ks-arow {
        flex-direction: column;
        gap: 2px;
    }
    .ks-at {
        white-space: normal;
    }
}
</style>
