<script setup lang="ts">
// Doc 14 §9 (S14 step 13, [B14-batch]) — queue & history, PHONE-READABLE:
// the filterable log with each message's lifecycle as a definition row and the
// phone always masked (SEC-02); the held view with the release-now door
// (sms.send, audited). Filters re-enter through the server (the props carry
// the filtered page).
import { ref } from 'vue';
import SmsScreen from '../../../Components/Sms/SmsScreen.vue';
import { door, section, shared, tr, type ScreensCopy } from '../../../Components/Sms/screens';

type Row = {
    id: string;
    state: string;
    event: string;
    phone_masked: string;
    segments: number;
    is_test: boolean;
    queued_at: string | null;
    last_change_at: string | null;
    reason: string | null;
};
type Held = {
    id: string;
    event: string;
    phone_masked: string;
    lane: string;
    queued_at: string;
    hold_reason: string | null;
};

const props = defineProps<{
    copy: ScreensCopy;
    can: Record<string, boolean>;
    rows: Row[];
    held: { held: Held[] };
    filters: { state: string; event: string };
}>();

const t = section(props.copy, 'queue');
const s = (k: string): string => t[k] ?? '';
const ev = (key: string): string => shared(props.copy, 'event_' + key) || key;
const chip = (state: string): string => section(props.copy, 'chips')[state] ?? state;

const heldRows = ref<Held[]>(props.held.held);
const error = ref<string | null>(null);
const notice = ref<string | null>(null);

function applyFilter(kind: 'state' | 'event', value: string): void {
    const next = new URLSearchParams(window.location.search);
    if (value === '') next.delete(kind);
    else next.set(kind, value);
    window.location.search = next.toString();
}
async function releaseNow(row: Held): Promise<void> {
    error.value = notice.value = null;
    const r = await door('POST', '/admin/sms/api/held/' + row.id + '/release');
    if (!r.ok) {
        error.value = String(r.json.detail ?? r.status);
        return;
    }
    heldRows.value = heldRows.value.filter((h) => h.id !== row.id);
    notice.value = s('released');
}

const STATES = [
    'queued',
    'held',
    'sending',
    'sent',
    'delivered',
    'undelivered',
    'expired',
    'skipped',
    'failed',
];
</script>

<template>
    <SmsScreen :copy="copy" active="sms" :title="s('title')" :read-note="s('read_note')">
        <div class="sms-page" data-testid="sms-queue">
            <p v-if="error" class="sms-error" role="alert">
                {{ tr(shared(copy, 'refused_line'), { detail: error }) }}
            </p>
            <p class="sms-muted">{{ shared(copy, 'masked_note') }}</p>

            <!-- filters -->
            <div class="sms-row" data-testid="queue-filters">
                <label class="sms-field">
                    <span>{{ s('filter_state') }}</span>
                    <select
                        :value="filters.state"
                        data-testid="filter-state"
                        @change="applyFilter('state', ($event.target as HTMLSelectElement).value)"
                    >
                        <option value="">{{ s('filter_all') }}</option>
                        <option v-for="st in STATES" :key="st" :value="st">{{ chip(st) }}</option>
                    </select>
                </label>
            </div>

            <!-- the log: lifecycle rows as definition lists -->
            <div class="sms-card" data-testid="queue-log">
                <p v-if="rows.length === 0" class="sms-muted">{{ s('empty') }}</p>
                <ul
                    v-else
                    style="list-style: none; margin: 0; padding: 0; display: grid; gap: 10px"
                >
                    <li v-for="row in rows" :key="row.id" :data-sms-row="row.state">
                        <dl class="sms-dl">
                            <dt>{{ s('col_state') }}</dt>
                            <dd>
                                <span class="sms-pill" :data-state="row.state">{{
                                    chip(row.state)
                                }}</span>
                            </dd>
                            <dt>{{ s('col_event') }}</dt>
                            <dd>{{ ev(row.event) }}</dd>
                            <dt>{{ s('col_phone') }}</dt>
                            <dd>{{ row.phone_masked }}</dd>
                            <dt>{{ s('col_segments') }}</dt>
                            <dd>{{ row.segments }}</dd>
                            <dt>{{ s('col_queued_at') }}</dt>
                            <dd>{{ row.queued_at }}</dd>
                            <dt>{{ s('col_terminal_at') }}</dt>
                            <dd>{{ row.last_change_at }}</dd>
                            <template v-if="row.reason">
                                <dt>{{ s('reason') }}</dt>
                                <dd>{{ row.reason }}</dd>
                            </template>
                        </dl>
                    </li>
                </ul>
            </div>

            <!-- the held view + release-now -->
            <div class="sms-card" data-testid="held-view">
                <h2>{{ s('held_title') }}</h2>
                <p v-if="notice" class="sms-ok" role="status" data-testid="held-notice">
                    {{ notice }}
                </p>
                <p v-if="heldRows.length === 0" class="sms-muted">{{ s('held_empty') }}</p>
                <table v-else class="sms-table">
                    <tbody>
                        <tr v-for="h in heldRows" :key="h.id">
                            <th scope="row">{{ ev(h.event) }}</th>
                            <td>{{ h.phone_masked }}</td>
                            <td class="sms-muted">{{ h.hold_reason ?? '—' }}</td>
                            <td>
                                <button
                                    type="button"
                                    class="sms-btn"
                                    :data-testid="`release-${h.id}`"
                                    @click="releaseNow(h)"
                                >
                                    {{ s('release_now') }}
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </SmsScreen>
</template>
