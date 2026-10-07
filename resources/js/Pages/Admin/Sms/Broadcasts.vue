<script setup lang="ts">
// Doc 14 §14 / FR-17 / FR-18 (S14 step 13, [B14-batch]) — broadcasts: the list;
// the builder (template pin → audience with the LIVE count → schedule → the
// cost preview "≈ N msgs · ~S segments · ~cost" answered by the SAME query the
// chunker runs (the preview door) → the guards summary → the typed confirm at
// ≥1,000); the progress drawer (the Doc 06 pattern) with counters, cost and
// cancel; completion. BR-01: no text field exists anywhere here — a broadcast
// is a template pin plus filters. Launch is A-64's HIGH pair (route-gated).
import { computed, ref } from 'vue';
import SmsScreen from '../../../Components/Sms/SmsScreen.vue';
import { door, section, shared, tr, type ScreensCopy } from '../../../Components/Sms/screens';

type Broadcast = {
    id: string;
    name: string;
    state: string;
    template_id: string;
    pinned_version_id: string | null;
    audience_filter: Record<string, unknown>;
    scheduled_at: string | null;
    total: number;
    sent: number;
    skipped: number;
    failed: number;
    cost_accrued: string;
    completed_at: string | null;
};
type LangCell = {
    template_id: string | null;
    active: boolean;
    live_version: number | null;
    complete: boolean;
};
type EventRow = { event: string; sendable: boolean; languages: Record<string, LangCell> };

const props = defineProps<{
    copy: ScreensCopy;
    can: Record<string, boolean>;
    doors: { broadcasts: Broadcast[] };
    templates: { events: EventRow[]; languages: string[] };
    cost_cards: { gateway: string; per_segment: string; currency: string }[];
}>();

const t = section(props.copy, 'broadcasts');
const s = (k: string): string => t[k] ?? '';
const canLaunch = computed(
    () => props.can['sms.gateways.manage'] === true && props.can['sms.send'] === true,
);

const list = ref<Broadcast[]>(props.doors.broadcasts);
const error = ref<string | null>(null);
const notice = ref<string | null>(null);
const building = ref(false);
const name = ref('');
const language = ref('');
const templateId = ref('');
const draft = ref<Broadcast | null>(null);
const preview = ref<{
    audience_count: number;
    typed_confirmation_required: boolean;
    template_live_version_id_if_newer: string | null;
} | null>(null);
const typed = ref('');
const open = ref<Broadcast | null>(null);

// the pickable templates: published service announcements, per language
const announcements = computed<{ id: string; language: string }[]>(() => {
    const row = props.templates.events.find((e) => e.event === 'service_announcement');
    if (!row) return [];
    const out: { id: string; language: string }[] = [];
    for (const [lang, cell] of Object.entries(row.languages)) {
        if (cell.template_id && cell.live_version !== null)
            out.push({ id: cell.template_id, language: lang });
    }
    return out;
});

const estimate = computed(() => {
    if (!preview.value) return null;
    const n = preview.value.audience_count;
    const card = props.cost_cards[0];
    const segments = n; // ~1 segment per announcement; the accrual at send is the figure of record
    return {
        n,
        segments,
        cost: card ? (n * Number(card.per_segment)).toFixed(4) : '—',
        currency: card?.currency ?? '',
    };
});

async function refresh(): Promise<void> {
    const r = await door('GET', '/admin/sms/api/broadcasts');
    if (r.ok) list.value = (r.json as unknown as { broadcasts: Broadcast[] }).broadcasts;
}
async function createDraft(): Promise<void> {
    error.value = null;
    const filter: Record<string, unknown> = {};
    if (language.value !== '') filter['language'] = language.value;
    const r = await door('POST', '/admin/sms/api/broadcasts', {
        name: name.value,
        template_id: templateId.value,
        audience_filter: filter,
    });
    if (!r.ok) {
        error.value = String(r.json.detail ?? r.status);
        return;
    }
    draft.value = r.json as unknown as Broadcast;
    await loadPreview();
    await refresh();
}
async function loadPreview(): Promise<void> {
    if (!draft.value) return;
    const r = await door('GET', '/admin/sms/api/broadcasts/' + draft.value.id + '/preview');
    if (r.ok) preview.value = r.json as unknown as typeof preview.value;
}
async function launch(): Promise<void> {
    if (!draft.value) return;
    error.value = notice.value = null;
    const r = await door('POST', '/admin/sms/api/broadcasts/' + draft.value.id + '/launch', {
        typed_confirmation: preview.value?.typed_confirmation_required ? typed.value : null,
    });
    if (!r.ok) {
        error.value = String(r.json.detail ?? r.status);
        return;
    }
    notice.value = s('launched');
    building.value = false;
    draft.value = null;
    await refresh();
}
async function cancelBroadcast(b: Broadcast): Promise<void> {
    error.value = null;
    const r = await door('POST', '/admin/sms/api/broadcasts/' + b.id + '/cancel');
    if (!r.ok) {
        error.value = String(r.json.detail ?? r.status);
        return;
    }
    notice.value = s('canceled_line');
    await refresh();
    if (open.value?.id === b.id) open.value = list.value.find((x) => x.id === b.id) ?? null;
}
function stateLabel(state: string): string {
    return s('state_' + state) || state;
}
</script>

<template>
    <SmsScreen :copy="copy" active="sms" :title="s('title')" :read-note="s('read_note')">
        <div class="sms-page" data-testid="sms-broadcasts">
            <p v-if="error" class="sms-error" role="alert" data-testid="broadcasts-error">
                {{ tr(shared(copy, 'refused_line'), { detail: error }) }}
            </p>
            <p v-if="notice" class="sms-ok" role="status" data-testid="broadcasts-notice">
                {{ notice }}
            </p>

            <div class="sms-row">
                <button
                    v-if="!building"
                    type="button"
                    class="sms-btn sms-btn-primary"
                    data-testid="broadcast-new"
                    @click="building = true"
                >
                    {{ s('new') }}
                </button>
            </div>

            <!-- the builder -->
            <div v-if="building" class="sms-card sms-page" data-testid="broadcast-builder">
                <!-- step: template pin -->
                <h2>{{ s('step_template') }}</h2>
                <p class="sms-muted">{{ s('step_template_note') }}</p>
                <div class="sms-row">
                    <label class="sms-field">
                        <span>{{ s('name') }}</span>
                        <input v-model="name" type="text" required data-testid="builder-name" />
                    </label>
                    <label class="sms-field">
                        <span>{{ s('step_template') }}</span>
                        <select v-model="templateId" data-testid="builder-template">
                            <option v-for="a in announcements" :key="a.id" :value="a.id">
                                {{ shared(copy, 'event_service_announcement') }} · {{ a.language }}
                            </option>
                        </select>
                    </label>
                </div>

                <!-- step: audience -->
                <h2>{{ s('step_audience') }}</h2>
                <label class="sms-field">
                    <span>{{ s('audience_language') }}</span>
                    <select v-model="language" data-testid="builder-language">
                        <option value="">—</option>
                        <option v-for="lang in templates.languages" :key="lang" :value="lang">
                            {{ lang }}
                        </option>
                    </select>
                </label>
                <button
                    v-if="!draft"
                    type="button"
                    class="sms-btn sms-btn-primary"
                    :disabled="name === '' || templateId === ''"
                    data-testid="builder-draft"
                    @click="createDraft"
                >
                    {{ s('build_preview') }}
                </button>

                <template v-if="draft && preview">
                    <p role="status" data-testid="builder-count">
                        {{ tr(s('audience_count'), { n: preview.audience_count }) }}
                    </p>
                    <p
                        v-if="preview.template_live_version_id_if_newer"
                        class="sms-pill warn"
                        data-testid="builder-pin-notice"
                    >
                        {{ s('pin_notice') }}
                    </p>

                    <!-- step: cost preview, the SAME query -->
                    <h2>{{ s('step_cost') }}</h2>
                    <p v-if="estimate" data-testid="builder-cost">
                        {{
                            tr(s('cost_preview'), {
                                n: estimate.n,
                                segments: estimate.segments,
                                cost: estimate.cost,
                                currency: estimate.currency,
                            })
                        }}
                    </p>
                    <p class="sms-muted">{{ s('cost_note') }}</p>

                    <!-- the guards summary -->
                    <h2>{{ s('guards_title') }}</h2>
                    <p class="sms-muted" data-testid="builder-guards">{{ s('guards_summary') }}</p>

                    <!-- typed confirm at >= 1,000 -->
                    <label
                        v-if="preview.typed_confirmation_required"
                        class="sms-field"
                        data-testid="builder-typed"
                    >
                        <span>{{ tr(s('typed_confirm'), { n: preview.audience_count }) }}</span>
                        <input v-model="typed" type="text" data-testid="builder-typed-input" />
                    </label>

                    <div class="sms-row">
                        <button
                            type="button"
                            class="sms-btn sms-btn-primary"
                            :disabled="!canLaunch"
                            data-testid="builder-launch"
                            @click="launch"
                        >
                            {{ s('launch') }}
                        </button>
                        <button type="button" class="sms-btn" @click="building = false">
                            {{ shared(copy, 'cancel') }}
                        </button>
                    </div>
                </template>
            </div>

            <!-- the list -->
            <div class="sms-card" data-testid="broadcast-list">
                <p v-if="list.length === 0" class="sms-muted">{{ s('empty') }}</p>
                <table v-else class="sms-table">
                    <tbody>
                        <tr v-for="b in list" :key="b.id" :data-broadcast="b.name">
                            <th scope="row">{{ b.name }}</th>
                            <td>
                                <span class="sms-pill" :data-state="b.state">{{
                                    stateLabel(b.state)
                                }}</span>
                            </td>
                            <td>
                                <button
                                    type="button"
                                    class="sms-btn"
                                    :data-testid="`broadcast-open-${b.name}`"
                                    @click="open = b"
                                >
                                    {{ s('progress_title') }}
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- the progress drawer / completion -->
            <div
                v-if="open"
                class="sms-card sms-page"
                role="dialog"
                :aria-label="open.name"
                data-testid="broadcast-progress"
            >
                <h2>
                    {{ open.completed_at ? s('completion_title') : s('progress_title') }} —
                    {{ open.name }}
                </h2>
                <p data-testid="progress-counts">
                    {{
                        tr(s('progress_counts'), {
                            sent: open.sent,
                            skipped: open.skipped,
                            failed: open.failed,
                            total: open.total,
                        })
                    }}
                </p>
                <p class="sms-muted" data-testid="progress-cost">
                    {{
                        tr(s('progress_cost'), {
                            amount: open.cost_accrued,
                            currency: cost_cards[0]?.currency ?? '',
                        })
                    }}
                </p>
                <div class="sms-row">
                    <button
                        v-if="['scheduled', 'sending', 'paused_quota'].includes(open.state)"
                        type="button"
                        class="sms-btn"
                        data-testid="progress-cancel"
                        @click="cancelBroadcast(open)"
                    >
                        {{ s('cancel_broadcast') }}
                    </button>
                    <button type="button" class="sms-btn" @click="open = null">
                        {{ shared(copy, 'close') }}
                    </button>
                </div>
            </div>
        </div>
    </SmsScreen>
</template>
