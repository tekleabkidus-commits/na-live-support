<script setup lang="ts">
// Doc 09 FR-02/FR-04/T-01/NFR-04/NFR-06 Providers & status — SESSION-F1 [B8]:
// the frozen B8-S08-provider-config-status-v1 anatomy on its OWN screen (ruling
// Q1b — B6-S06 untouched, no cross-link). One chain per task tier read left to
// right the way a request travels, every node carrying its breaker state in
// WORDS with the dot second; the degradation ladder as live status (tripped →
// failover, all-exhausted → straight-to-human, recovery announced); the model
// map whose Change is a versioned config publish through the existing routing
// door; config & prompt versions with the honesty layer as a verifiable HASH
// (code, not config); the daily token quota with the 80% mark and the hatched
// 5% reserve. Credentials appear as vault references only (SEC-01).
import { computed, ref } from 'vue';
import AiSettingsShell from '../../../Components/Ai/AiSettingsShell.vue';
import { adminCall } from './adminApi';

type Hop = { provider: string; model: string };
const TIERS = ['fast', 'strong', 'embeddings'] as const;
type Tier = (typeof TIERS)[number];
const props = defineProps<{
    t: Record<string, string>;
    tiers: Record<Tier, Hop[]>;
    providers: string[];
    breakers: Record<string, { open: boolean; health: string | null }>;
    credentials: { provider: string; ref: string }[];
    versions: {
        kind: string;
        version: number | null;
        published_at: string | null;
        published_by_name: string | null;
        rollback_to: number | null;
        rollback_id: string | null;
        own_screen: string | null;
    }[];
    honesty_hash: string;
    quota: {
        used: number;
        limit: number;
        pct: number;
        reserve_permille: number;
        resets_at: string;
    };
    published: { version: number } | null;
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
const message = ref('');
const busy = ref(false);
const editing = ref<Tier | null>(null);
const draft = ref<Record<Tier, Hop[]>>({
    fast: [...props.tiers.fast],
    strong: [...props.tiers.strong],
    embeddings: [...props.tiers.embeddings],
});

function nodeState(tier: Tier, index: number): 'serving' | 'standby' | 'tripped' {
    const chain = props.tiers[tier] ?? [];
    const open = (h: Hop): boolean => props.breakers[h.provider]?.open ?? false;
    if (open(chain[index] as Hop)) return 'tripped';
    const firstClosed = chain.findIndex((h) => !open(h));
    return index === firstClosed ? 'serving' : 'standby';
}
const trippedCount = computed(() => Object.values(props.breakers).filter((b) => b.open).length);
const ladder = computed(() => {
    const out: { tier: Tier; kind: 'failover' | 'exhausted'; primary: string; fallback: string }[] =
        [];
    for (const tier of TIERS) {
        const chain = props.tiers[tier] ?? [];
        if (!chain.length) continue;
        const open = chain.map((h) => props.breakers[h.provider]?.open ?? false);
        if (open.every(Boolean))
            out.push({ tier, kind: 'exhausted', primary: chain[0]?.provider ?? '', fallback: '' });
        else if (open[0])
            out.push({
                tier,
                kind: 'failover',
                primary: chain[0]?.provider ?? '',
                fallback: chain[open.findIndex((o) => !o)]?.provider ?? '',
            });
    }
    return out;
});
const quotaState = computed<'under' | 'warn' | 'exhausted'>(() =>
    props.quota.pct >= 100 - props.quota.reserve_permille / 10
        ? 'exhausted'
        : props.quota.pct >= 80
          ? 'warn'
          : 'under',
);
const fmt = (n: number): string => n.toLocaleString();
const reservePct = computed(() => props.quota.reserve_permille / 10);

function startEdit(tier: Tier): void {
    editing.value = tier;
    draft.value[tier] = props.tiers[tier].map((h) => ({ ...h }));
}
function setHop(tier: Tier, i: number, key: keyof Hop, value: string): void {
    const next = draft.value[tier].map((h) => ({ ...h }));
    next[i] = { ...(next[i] as Hop), [key]: value };
    draft.value[tier] = next;
}
async function publishRouting(): Promise<void> {
    busy.value = true;
    message.value = '';
    try {
        await adminCall('/admin/ai/providers/routing', { tiers: draft.value });
        window.location.reload();
    } catch (e) {
        message.value = (e as Error).message;
    } finally {
        busy.value = false;
    }
}
async function rollback(id: string): Promise<void> {
    busy.value = true;
    try {
        await adminCall(`/admin/ai/versions/${id}/rollback`, {});
        window.location.reload();
    } catch (e) {
        message.value = (e as Error).message;
    } finally {
        busy.value = false;
    }
}
function fallbackText(tier: Tier): string {
    return (
        (props.tiers[tier] ?? [])
            .slice(1)
            .map((h) => h.provider + ' · ' + h.model)
            .join(' → ') || '—'
    );
}
</script>

<template>
    <AiSettingsShell :t="t" :page="t.providers_page" :setup="setup">
        <template #chip>
            <span class="ai-vchip" data-testid="pv-chip">{{
                trippedCount
                    ? fill(t.chip_breakers_tripped, { n: trippedCount })
                    : t.chip_chains_healthy
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

        <!-- ── §B H05: the degradation ladder as live status ── -->
        <section class="pv-sec" :aria-label="t.tier_fast" data-testid="pv-ladder">
            <template v-if="ladder.length">
                <div
                    v-for="l in ladder"
                    :key="l.tier"
                    class="ai-banner"
                    :class="l.kind === 'exhausted' ? 'ai-banner-off' : 'ai-banner-warn'"
                    role="status"
                    :data-testid="'pv-ladder-' + l.kind"
                >
                    <span aria-hidden="true">{{ l.kind === 'exhausted' ? '⛔' : '⚠' }}</span>
                    <span>
                        {{
                            fill(l.kind === 'exhausted' ? t.ladder_exhausted : t.ladder_failover, {
                                tier: t['tier_' + l.tier] ?? l.tier,
                                primary: l.primary,
                                fallback: l.fallback,
                            })
                        }}
                        <small>{{
                            l.kind === 'exhausted' ? t.ladder_exhausted_sub : t.ladder_failover_sub
                        }}</small>
                    </span>
                </div>
            </template>
            <div
                v-else
                class="ai-banner ai-banner-ok"
                role="status"
                data-testid="pv-ladder-healthy"
            >
                ✓ {{ t.ladder_healthy }}
            </div>
        </section>

        <!-- ── §A H01/H03: the chains, one per tier ── -->
        <section class="pv-sec" data-testid="pv-chains">
            <div v-for="tier in TIERS" :key="tier" class="pv-tier" :data-testid="'pv-tier-' + tier">
                <div class="pv-tn">{{ t['tier_' + tier] }}</div>
                <div class="pv-td">{{ t['tier_' + tier + '_desc'] }}</div>
                <div class="pv-chain">
                    <template v-for="(hop, i) in tiers[tier]" :key="i">
                        <span v-if="i > 0" class="pv-arrow" aria-hidden="true">→</span>
                        <div
                            class="pv-node"
                            :class="'pv-node-' + nodeState(tier, i)"
                            :data-testid="'pv-node-' + tier + '-' + i"
                        >
                            <div class="pv-np">
                                <span
                                    class="pv-dot"
                                    :class="'pv-dot-' + nodeState(tier, i)"
                                    aria-hidden="true"
                                ></span
                                >{{ hop.provider }}
                            </div>
                            <div class="pv-nm">{{ hop.model }}</div>
                            <div class="pv-ns" :class="'pv-ns-' + nodeState(tier, i)">
                                {{ t['node_' + nodeState(tier, i)]
                                }}<template v-if="breakers[hop.provider]?.health">
                                    ·
                                    {{
                                        fill(t.node_health, {
                                            status: breakers[hop.provider]?.health ?? '',
                                        })
                                    }}</template
                                >
                            </div>
                        </div>
                    </template>
                </div>
            </div>
            <div class="pv-vaults">
                <span v-for="c in credentials" :key="c.provider" class="pv-vault"
                    >🔐 {{ fill(t.credential, { ref: c.ref }) }}</span
                >
                <span class="pv-note">{{ t.credentials_note }}</span>
            </div>
        </section>

        <!-- ── §C H02: the model map — a versioned config publish through the routing door ── -->
        <section class="pv-sec" :aria-label="t.model_map" data-testid="pv-model-map">
            <span class="ai-lab">{{ t.model_map }}</span>
            <table class="ai-tt">
                <thead>
                    <tr>
                        <th>{{ t.col_tier }}</th>
                        <th>{{ t.col_primary }}</th>
                        <th>{{ t.col_fallback }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="tier in TIERS" :key="tier">
                        <td>{{ t['tier_' + tier] }}</td>
                        <td class="ai-mono">
                            {{
                                tiers[tier][0]
                                    ? tiers[tier][0].provider + ' · ' + tiers[tier][0].model
                                    : '—'
                            }}
                        </td>
                        <td class="ai-mono">{{ fallbackText(tier) }}</td>
                        <td>
                            <button
                                type="button"
                                class="ai-btn"
                                :data-testid="'pv-change-' + tier"
                                @click="startEdit(tier)"
                            >
                                {{ t.change }}
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
            <div v-if="editing" class="pv-edit" :data-testid="'pv-edit-' + editing">
                <div v-for="(hop, i) in draft[editing]" :key="i" class="pv-hop">
                    <label class="text-xs"
                        ><span class="ai-lab">{{ t.provider }}</span>
                        <select
                            :value="hop.provider"
                            class="ai-in pv-sel"
                            @change="
                                setHop(
                                    editing as Tier,
                                    i,
                                    'provider',
                                    ($event.target as HTMLSelectElement).value,
                                )
                            "
                        >
                            <option v-for="p in providers" :key="p" :value="p">{{ p }}</option>
                        </select></label
                    >
                    <label class="text-xs"
                        ><span class="ai-lab">{{ t.model }}</span>
                        <input
                            :value="hop.model"
                            class="ai-in"
                            @input="
                                setHop(
                                    editing as Tier,
                                    i,
                                    'model',
                                    ($event.target as HTMLInputElement).value,
                                )
                            "
                    /></label>
                    <button
                        v-if="draft[editing].length > 1"
                        type="button"
                        class="ai-btn"
                        @click="draft[editing] = draft[editing].filter((_, j) => j !== i)"
                    >
                        {{ t.remove_hop }}
                    </button>
                </div>
                <div class="pv-edit-acts">
                    <button
                        v-if="draft[editing].length < 4"
                        type="button"
                        class="ai-btn"
                        @click="
                            draft[editing] = [
                                ...draft[editing],
                                { provider: providers[0] ?? '', model: '' },
                            ]
                        "
                    >
                        {{ t.add_fallback }}
                    </button>
                    <button type="button" class="ai-btn" @click="editing = null">
                        {{ t.cancel }}
                    </button>
                    <button
                        type="button"
                        class="ai-btn ai-btn-pri"
                        data-testid="pv-publish-routing"
                        :disabled="busy"
                        @click="publishRouting"
                    >
                        {{ t.save_routing }}
                    </button>
                </div>
            </div>
            <p class="pv-note mt-2.5">{{ t.model_map_note }}</p>
        </section>

        <!-- ── §D H04: config & prompt versions; the honesty layer as a hash ── -->
        <section class="pv-sec" :aria-label="t.versions_title" data-testid="pv-versions">
            <span class="ai-lab">{{ t.versions_title }}</span>
            <div
                v-for="v in versions"
                :key="v.kind"
                class="ai-vrow"
                :data-testid="'pv-version-' + v.kind"
            >
                <div>
                    <b
                        >{{ t['kind_' + v.kind] ?? v.kind
                        }}<template v-if="v.version"> v{{ v.version }}</template></b
                    >
                    <span v-if="v.version" class="ai-pill-live">{{ t.live }}</span>
                    <div class="ai-who">
                        <template v-if="v.version">{{
                            fill(t.version_who, {
                                date: (v.published_at ?? '').slice(0, 16).replace('T', ' '),
                                actor: v.published_by_name ?? '—',
                            })
                        }}</template>
                        <template v-else>{{ t.not_published }}</template>
                    </div>
                </div>
                <button
                    v-if="v.rollback_id && v.rollback_to"
                    type="button"
                    class="ai-btn"
                    :data-testid="'pv-rollback-' + v.kind"
                    :disabled="busy"
                    @click="rollback(v.rollback_id)"
                >
                    {{ fill(t.rollback_to, { version: v.rollback_to }) }}
                </button>
                <a v-else-if="v.own_screen && v.version" :href="v.own_screen" class="ai-btn">{{
                    t.rollback_via
                }}</a>
            </div>
            <div class="ai-vrow" data-testid="pv-honesty">
                <div>
                    <b>{{ t.honesty_layer }}</b> <span class="ai-seed">{{ t.code_shipped }}</span>
                    <div class="ai-who">
                        {{
                            fill(t.honesty_who, {
                                hash: honesty_hash.slice(0, 8) + '…' + honesty_hash.slice(-4),
                            })
                        }}
                    </div>
                </div>
                <span class="pv-note">{{ t.no_rollback }}</span>
            </div>
            <div class="ai-banner ai-banner-deg mt-3">⚠ {{ t.versions_note }}</div>
        </section>

        <!-- ── §E H06: the daily token quota — the 80% mark and the hatched 5% reserve, always drawn ── -->
        <section class="pv-sec" :aria-label="t.quota_title" data-testid="pv-quota">
            <span class="ai-lab">{{ t.quota_title }}</span>
            <div class="pv-qbar" role="img" :aria-label="fill(t.quota_aria, { pct: quota.pct })">
                <div
                    class="pv-qfill"
                    :class="'pv-qfill-' + quotaState"
                    :style="{ width: Math.min(100, quota.pct) + '%' }"
                ></div>
                <div class="pv-qmark" aria-hidden="true"></div>
                <div class="pv-qres" :style="{ width: reservePct + '%' }" aria-hidden="true"></div>
            </div>
            <p class="pv-qtx" :data-testid="'pv-quota-' + quotaState">
                <b v-if="quotaState !== 'exhausted'">{{
                    fill(t.quota_under, {
                        used: fmt(quota.used),
                        limit: fmt(quota.limit),
                        pct: quota.pct,
                        resets: quota.resets_at,
                    }).split(' · ')[0]
                }}</b>
                {{
                    quotaState === 'exhausted'
                        ? fill(t.quota_exhausted, {
                              limit: fmt(quota.limit),
                              resets: quota.resets_at,
                          })
                        : quotaState === 'warn'
                          ? fill(t.quota_warn, {
                                used: fmt(quota.used),
                                limit: fmt(quota.limit),
                                pct: quota.pct,
                            })
                                .split(' · ')
                                .slice(1)
                                .join(' · ')
                          : fill(t.quota_under, {
                                used: fmt(quota.used),
                                limit: fmt(quota.limit),
                                pct: quota.pct,
                                resets: quota.resets_at,
                            })
                                .split(' · ')
                                .slice(1)
                                .join(' · ')
                }}
            </p>
        </section>
    </AiSettingsShell>
</template>

<style scoped>
.pv-sec {
    padding: 12px 16px 16px;
    border-bottom: 1px solid var(--ds-border);
}
.pv-tier {
    padding: 10px 0;
    border-top: 1px solid var(--ds-border);
}
.pv-tier:first-of-type {
    border-top: 0;
    padding-top: 0;
}
.pv-tn {
    font-size: 12.5px;
    font-weight: 600;
    color: var(--ds-text-primary);
}
.pv-td {
    font-size: 10.5px;
    color: var(--ds-text-muted);
    margin: 2px 0 8px;
}
.pv-chain {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}
.pv-arrow {
    color: var(--ds-text-muted);
    font-size: 14px;
}
.pv-node {
    border: 1px solid var(--ds-border);
    border-radius: 8px;
    padding: 8px 11px;
    min-width: 180px;
    background: var(--ds-panel-bg);
}
.pv-node-serving {
    border-color: var(--ds-border-strong);
    box-shadow: inset 0 0 0 1px var(--ds-border-strong);
}
.pv-np {
    font-size: 12px;
    font-weight: 600;
    color: var(--ds-text-primary);
    display: flex;
    align-items: center;
    gap: 6px;
}
.pv-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    display: inline-block;
}
.pv-dot-serving {
    background: var(--ds-success-text);
}
.pv-dot-standby {
    background: var(--ds-border-strong);
}
.pv-dot-tripped {
    background: var(--ds-danger-text);
}
.pv-nm {
    font-family: ui-monospace, monospace;
    font-size: 10.5px;
    color: var(--ds-text-muted);
    margin-top: 2px;
}
.pv-ns {
    font-size: 10.5px;
    font-weight: 600;
    margin-top: 3px;
}
.pv-ns-serving {
    color: var(--ds-success-text);
}
.pv-ns-standby {
    color: var(--ds-text-muted);
}
.pv-ns-tripped {
    color: var(--ds-danger-text);
}
.pv-vaults {
    display: flex;
    gap: 10px;
    align-items: center;
    margin-top: 12px;
    flex-wrap: wrap;
}
.pv-vault {
    font-family: ui-monospace, monospace;
    font-size: 10.5px;
    background: var(--ds-panel-soft);
    border: 1px solid var(--ds-border);
    border-radius: 5px;
    padding: 3px 8px;
    color: var(--ds-text-secondary);
}
.pv-note {
    font-size: 10.5px;
    color: var(--ds-text-muted);
    line-height: 1.5;
}
.pv-edit {
    margin-top: 10px;
    border: 1px dashed var(--ds-border-strong);
    border-radius: 8px;
    padding: 10px 12px;
}
.pv-hop {
    display: flex;
    gap: 10px;
    align-items: flex-end;
    flex-wrap: wrap;
    padding: 6px 0;
}
.pv-sel {
    width: auto;
}
.pv-edit-acts {
    display: flex;
    gap: 8px;
    justify-content: flex-end;
    margin-top: 8px;
    flex-wrap: wrap;
}
.pv-qbar {
    position: relative;
    height: 12px;
    border-radius: 6px;
    background: var(--ds-inset-bg);
    overflow: hidden;
    max-width: 640px;
}
.pv-qfill {
    position: absolute;
    inset-inline-start: 0;
    top: 0;
    bottom: 0;
    border-radius: 6px;
    background: var(--ds-success-text);
}
.pv-qfill-warn {
    background: var(--ds-warn-text);
}
.pv-qfill-exhausted {
    background: var(--ds-danger-text);
}
.pv-qmark {
    position: absolute;
    inset-inline-start: 80%;
    top: 0;
    bottom: 0;
    width: 2px;
    background: var(--ds-text-primary);
}
.pv-qres {
    position: absolute;
    inset-inline-end: 0;
    top: 0;
    bottom: 0;
    background: repeating-linear-gradient(
        135deg,
        var(--ds-border-strong) 0 3px,
        transparent 3px 7px
    );
    opacity: 0.8;
}
.pv-qtx {
    font-size: 11.5px;
    line-height: 1.55;
    color: var(--ds-text-secondary);
    margin: 8px 0 0;
    max-width: 640px;
}
@media (max-width: 639px) {
    .pv-chain {
        flex-direction: column;
        align-items: stretch;
    }
    .pv-arrow {
        text-align: center;
    }
    .pv-arrow::before {
        content: '↓';
    }
    .pv-arrow {
        font-size: 0;
    }
    .pv-arrow::before {
        font-size: 12px;
    }
}
</style>
