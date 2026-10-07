<script setup lang="ts">
// Doc 14 §8 / FR-04 / FR-05 / US-03 (S14 step 13, [B14-batch]) — the templates
// screen: the event-grouped list with per-language completeness dots and active
// toggles; the editor (tablet-wide) with the LIVE counter + encoding badge
// ("Now 2 segments, UCS-2" announced via role=status), the variable palette the
// event provides, the cost row per routed gateway, on_missing, the quiet-hours
// policy with the exempt caution, the test-send panel (fixture numbers only
// outside production) and the version history with rollback. Every mutation
// rides the step-3 JSON doors; refusals render the server's own detail inline.
import { computed, ref } from 'vue';
import SmsScreen from '../../../Components/Sms/SmsScreen.vue';
import {
    door,
    section,
    segmentFacts,
    shared,
    tr,
    type ScreensCopy,
} from '../../../Components/Sms/screens';

type LangCell = {
    template_id: string | null;
    active: boolean;
    live_version: number | null;
    complete: boolean;
};
type EventRow = { event: string; sendable: boolean; languages: Record<string, LangCell> };
type HistoryRow = {
    version: number;
    encoding: string;
    segments_estimate: number;
    published_at: string;
    note: string | null;
    live: boolean;
    body: string;
};
type Detail = {
    id: string;
    event: string;
    language: string;
    name: string;
    active: boolean;
    quiet_hours_policy: string;
    on_missing: string;
    fallback_text: string | null;
    live_version: number | null;
    template_body: string | null;
    history?: HistoryRow[];
};

const props = defineProps<{
    copy: ScreensCopy;
    can: Record<string, boolean>;
    doors: { events: EventRow[]; languages: string[]; incomplete: string[] };
    cost_cards: { gateway: string; per_segment: string; currency: string }[];
    variables: Record<string, string[]>;
}>();

const t = section(props.copy, 'templates');
const s = (k: string): string => t[k] ?? '';
const ev = (key: string): string => shared(props.copy, 'event_' + key) || key;
const canEdit = computed(() => props.can['sms.templates.edit'] === true);
const canCreate = computed(() => props.can['sms.templates.create'] === true);

const events = ref<EventRow[]>(props.doors.events);
const open = ref<Detail | null>(null);
const body = ref('');
const error = ref<string | null>(null);
const notice = ref<string | null>(null);
const testPhone = ref('');
const creating = ref<{ event: string; language: string } | null>(null);
const newName = ref('');

const facts = computed(() => segmentFacts(body.value));
const announce = computed(() =>
    tr(s('announce_segments'), {
        segments: facts.value.segments,
        segment_word: facts.value.segments === 1 ? s('segment_one') : s('segment_many'),
        encoding: facts.value.encoding === 'UCS-2' ? s('encoding_ucs2') : s('encoding_gsm'),
    }),
);

async function refresh(): Promise<void> {
    const r = await door('GET', '/admin/sms/api/templates');
    if (r.ok) events.value = (r.json as unknown as { events: EventRow[] }).events;
}
async function openTemplate(id: string): Promise<void> {
    error.value = notice.value = null;
    creating.value = null;
    const r = await door('GET', '/admin/sms/api/templates/' + id);
    if (!r.ok) {
        error.value = String(r.json.detail ?? r.status);
        return;
    }
    open.value = r.json as unknown as Detail;
    body.value = open.value.template_body ?? '';
}
function startCreate(event: string, language: string): void {
    open.value = null;
    error.value = notice.value = null;
    newName.value = '';
    body.value = '';
    creating.value = { event, language };
}
async function create(): Promise<void> {
    if (!creating.value) return;
    error.value = null;
    const r = await door('POST', '/admin/sms/api/templates', {
        event: creating.value.event,
        language: creating.value.language,
        name: newName.value,
        template_body: body.value,
    });
    if (!r.ok) {
        error.value = String(r.json.detail ?? r.status);
        return;
    }
    creating.value = null;
    await refresh();
    await openTemplate(String(r.json.id));
}
async function toggleActive(cell: LangCell): Promise<void> {
    if (!cell.template_id) return;
    const r = await door('PATCH', '/admin/sms/api/templates/' + cell.template_id, {
        active: !cell.active,
    });
    if (!r.ok) error.value = String(r.json.detail ?? r.status);
    await refresh();
}
async function check(): Promise<void> {
    if (!open.value) return;
    error.value = notice.value = null;
    const r = await door('POST', '/admin/sms/api/templates/' + open.value.id + '/validate', {
        template_body: body.value,
    });
    if (!r.ok) {
        error.value = String(r.json.detail ?? r.status);
        return;
    }
    notice.value = announce.value;
}
async function publish(): Promise<void> {
    if (!open.value) return;
    error.value = notice.value = null;
    const r = await door('POST', '/admin/sms/api/templates/' + open.value.id + '/publish', {
        template_body: body.value,
    });
    if (!r.ok) {
        error.value = String(r.json.detail ?? r.status);
        return;
    }
    notice.value = tr(s('published_line'), { version: Number(r.json.version) });
    await refresh();
    await openTemplate(open.value.id);
}
async function saveMeta(patch: Record<string, unknown>): Promise<void> {
    if (!open.value) return;
    error.value = null;
    const r = await door('PATCH', '/admin/sms/api/templates/' + open.value.id, patch);
    if (!r.ok) {
        error.value = String(r.json.detail ?? r.status);
        return;
    }
    open.value = { ...open.value, ...(patch as Partial<Detail>) };
}
async function rollback(toVersion: number): Promise<void> {
    if (!open.value) return;
    error.value = notice.value = null;
    const r = await door('POST', '/admin/sms/api/templates/' + open.value.id + '/rollback', {
        to_version: toVersion,
    });
    if (!r.ok) {
        error.value = String(r.json.detail ?? r.status);
        return;
    }
    notice.value = s('rolled_back');
    await openTemplate(open.value.id);
}
async function testSend(): Promise<void> {
    if (!open.value) return;
    error.value = notice.value = null;
    const r = await door('POST', '/admin/sms/api/templates/' + open.value.id + '/test-send', {
        phone: testPhone.value,
    });
    if (!r.ok) {
        error.value = String(r.json.detail ?? r.status);
        return;
    }
    notice.value = s('test_sent');
}
function insertVariable(name: string): void {
    body.value = body.value + braced(name);
}
// computed in script: a literal {{ }} inside a template interpolation terminates it early
function braced(name: string): string {
    return '{{' + name + '}}';
}
function openCell(cell: LangCell | undefined): void {
    if (cell?.template_id) void openTemplate(cell.template_id);
}
const paletteFor = computed<string[]>(() => {
    const key = open.value?.event ?? creating.value?.event ?? '';
    return props.variables[key] ?? [];
});
</script>

<template>
    <SmsScreen :copy="copy" active="sms" :title="s('title')" :read-note="s('read_note')">
        <div class="sms-page sms-templates" data-testid="sms-templates">
            <p v-if="error" class="sms-error" role="alert" data-testid="sms-templates-error">
                {{ tr(shared(copy, 'refused_line'), { detail: error }) }}
            </p>

            <!-- the event-grouped list: a dot per language, full when published+active -->
            <div class="sms-card">
                <table class="sms-table" data-testid="sms-templates-list">
                    <thead>
                        <tr>
                            <th scope="col">{{ s('event') }}</th>
                            <th v-for="lang in doors.languages" :key="lang" scope="col">
                                {{ lang }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in events" :key="row.event" :data-event="row.event">
                            <th scope="row">{{ ev(row.event) }}</th>
                            <td v-for="lang in doors.languages" :key="lang">
                                <span class="sms-row">
                                    <span
                                        class="sms-dot"
                                        :class="{ full: row.languages[lang]?.complete }"
                                        role="img"
                                        :aria-label="
                                            tr(
                                                s(
                                                    row.languages[lang]?.complete
                                                        ? 'dot_full'
                                                        : 'dot_empty',
                                                ),
                                                { language: lang },
                                            )
                                        "
                                        :data-testid="`dot-${row.event}-${lang}`"
                                    ></span>
                                    <button
                                        v-if="row.languages[lang]?.template_id"
                                        type="button"
                                        class="sms-btn"
                                        :data-testid="`open-${row.event}-${lang}`"
                                        @click="openCell(row.languages[lang])"
                                    >
                                        v{{ row.languages[lang]?.live_version ?? '—' }}
                                    </button>
                                    <button
                                        v-else-if="canCreate"
                                        type="button"
                                        class="sms-btn"
                                        :data-testid="`new-${row.event}-${lang}`"
                                        @click="startCreate(row.event, lang)"
                                    >
                                        {{ s('new_template') }}
                                    </button>
                                    <label
                                        v-if="row.languages[lang]?.template_id && canEdit"
                                        class="sms-row"
                                    >
                                        <input
                                            type="checkbox"
                                            :checked="row.languages[lang]?.active"
                                            :aria-label="`${s('toggle_active')} — ${ev(row.event)} ${lang}`"
                                            @change="
                                                row.languages[lang] &&
                                                toggleActive(row.languages[lang])
                                            "
                                        />
                                        <span>{{ s('toggle_active') }}</span>
                                    </label>
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- the creator (name + first body) -->
            <form
                v-if="creating"
                class="sms-card sms-page"
                data-testid="sms-template-create"
                @submit.prevent="create"
            >
                <h2>
                    {{ s('new_template') }} — {{ ev(creating.event) }} · {{ creating.language }}
                </h2>
                <label class="sms-field">
                    <span>{{ s('name') }}</span>
                    <input
                        v-model="newName"
                        type="text"
                        maxlength="120"
                        required
                        data-testid="create-name"
                    />
                </label>
                <label class="sms-field">
                    <span>{{ s('body') }}</span>
                    <textarea v-model="body" rows="4" required data-testid="create-body"></textarea>
                </label>
                <div class="sms-row">
                    <button type="submit" class="sms-btn sms-btn-primary" data-testid="create-save">
                        {{ s('publish') }}
                    </button>
                    <button type="button" class="sms-btn" @click="creating = null">
                        {{ shared(copy, 'cancel') }}
                    </button>
                </div>
            </form>

            <!-- the editor -->
            <div v-if="open" class="sms-card sms-page" data-testid="sms-template-editor">
                <h2>{{ tr(s('editor_title'), { name: open.name }) }}</h2>
                <label class="sms-field">
                    <span>{{ s('body') }}</span>
                    <textarea
                        v-model="body"
                        rows="5"
                        :disabled="!canEdit"
                        data-testid="editor-body"
                    ></textarea>
                </label>
                <p class="sms-row">
                    <span data-testid="editor-counter">{{
                        tr(s('counter'), {
                            chars: facts.chars,
                            segments: facts.segments,
                            segment_word:
                                facts.segments === 1 ? s('segment_one') : s('segment_many'),
                        })
                    }}</span>
                    <span
                        class="sms-pill"
                        :class="facts.encoding === 'UCS-2' ? 'warn' : 'ok'"
                        data-testid="editor-encoding"
                        >{{
                            facts.encoding === 'UCS-2' ? s('encoding_ucs2') : s('encoding_gsm')
                        }}</span
                    >
                </p>
                <p class="sms-muted" role="status" data-testid="editor-announce">{{ announce }}</p>
                <details>
                    <summary>{{ s('segment_rules') }}</summary>
                </details>

                <div class="sms-row" data-testid="editor-variables">
                    <span>{{ s('variables_title') }}:</span>
                    <button
                        v-for="name in paletteFor"
                        :key="name"
                        type="button"
                        class="sms-btn"
                        :disabled="!canEdit"
                        :aria-label="tr(s('insert_variable'), { name })"
                        @click="insertVariable(name)"
                    >
                        {{ braced(name) }}
                    </button>
                </div>

                <p v-if="cost_cards.length" class="sms-muted" data-testid="editor-cost">
                    <template v-for="(card, i) in cost_cards" :key="card.gateway">
                        <span v-if="i > 0"> · </span>
                        {{
                            tr(s('cost_row'), {
                                cost: card.per_segment,
                                currency: card.currency,
                                gateway: card.gateway,
                            })
                        }}
                    </template>
                </p>
                <p v-else class="sms-muted" data-testid="editor-cost-none">
                    {{ s('cost_row_none') }}
                </p>

                <div class="sms-row">
                    <label class="sms-field">
                        <span>{{ s('on_missing') }}</span>
                        <select
                            :value="open.on_missing"
                            :disabled="!canEdit"
                            data-testid="editor-on-missing"
                            @change="
                                saveMeta({ on_missing: ($event.target as HTMLSelectElement).value })
                            "
                        >
                            <option value="skip_send">{{ s('on_missing_skip') }}</option>
                            <option value="fallback_text">{{ s('on_missing_fallback') }}</option>
                        </select>
                    </label>
                    <label class="sms-field">
                        <span>{{ s('quiet_policy') }}</span>
                        <select
                            :value="open.quiet_hours_policy"
                            :disabled="!canEdit"
                            data-testid="editor-quiet-policy"
                            @change="
                                saveMeta({
                                    quiet_hours_policy: ($event.target as HTMLSelectElement).value,
                                })
                            "
                        >
                            <option value="hold">{{ s('quiet_hold') }}</option>
                            <option value="skip">{{ s('quiet_skip') }}</option>
                            <option value="exempt">{{ s('quiet_exempt') }}</option>
                        </select>
                    </label>
                </div>
                <p
                    v-if="open.quiet_hours_policy === 'exempt'"
                    class="sms-pill warn"
                    data-testid="exempt-caution"
                >
                    {{ s('quiet_exempt_caution') }}
                </p>

                <div class="sms-row">
                    <button
                        type="button"
                        class="sms-btn"
                        :disabled="!canEdit"
                        data-testid="editor-check"
                        @click="check"
                    >
                        {{ s('validate') }}
                    </button>
                    <button
                        type="button"
                        class="sms-btn sms-btn-primary"
                        :disabled="!canEdit"
                        data-testid="editor-publish"
                        @click="publish"
                    >
                        {{ s('publish') }}
                    </button>
                </div>
                <p v-if="notice" class="sms-ok" role="status" data-testid="editor-notice">
                    {{ notice }}
                </p>

                <!-- test send: fixture numbers only outside production (the door enforces it) -->
                <div class="sms-card" data-testid="editor-test-send">
                    <h3>{{ s('test_send_title') }}</h3>
                    <div class="sms-row">
                        <label class="sms-field">
                            <span>{{ s('test_send_phone') }}</span>
                            <input
                                v-model="testPhone"
                                type="text"
                                inputmode="tel"
                                data-testid="test-phone"
                            />
                        </label>
                        <button
                            type="button"
                            class="sms-btn"
                            :disabled="!canEdit"
                            data-testid="test-send"
                            @click="testSend"
                        >
                            {{ s('test_send') }}
                        </button>
                    </div>
                </div>

                <!-- version history + rollback -->
                <div class="sms-card" data-testid="editor-history">
                    <h3>{{ s('history_title') }}</h3>
                    <table class="sms-table">
                        <tbody>
                            <tr
                                v-for="v in open.history ?? []"
                                :key="v.version"
                                :data-version="v.version"
                            >
                                <th scope="row">
                                    {{ tr(s('history_version'), { version: v.version }) }}
                                </th>
                                <td>{{ v.encoding }} · {{ v.segments_estimate }}</td>
                                <td class="sms-muted">{{ v.body }}</td>
                                <td>
                                    <span v-if="v.live" class="sms-pill ok">{{
                                        s('toggle_active')
                                    }}</span>
                                    <button
                                        v-else-if="canEdit"
                                        type="button"
                                        class="sms-btn"
                                        :data-testid="`rollback-${v.version}`"
                                        @click="rollback(v.version)"
                                    >
                                        {{ tr(s('rollback'), { version: v.version }) }}
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </SmsScreen>
</template>
