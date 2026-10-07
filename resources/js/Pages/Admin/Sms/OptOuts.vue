<script setup lang="ts">
// Doc 14 §8 / FR-14 / N-16 (S14 step 13, [B14-batch]) — the opt-out manager:
// search over the masked list, add-with-reason, restore behind a
// HIGH-VISIBILITY confirm (restoring re-enables every SMS), CSV export
// (customers.export) and import. Opt-out is absolute; nothing here can
// weaken the guard — these are the step-8 doors.
import { computed, ref } from 'vue';
import SmsScreen from '../../../Components/Sms/SmsScreen.vue';
import { door, section, shared, tr, type ScreensCopy } from '../../../Components/Sms/screens';

type Row = {
    id: string;
    phone_masked: string;
    source: string;
    reason: string | null;
    stop_word_matched: string | null;
    restored_at: string | null;
    restore_reason: string | null;
};

const props = defineProps<{
    copy: ScreensCopy;
    can: Record<string, boolean>;
    doors: { optouts: Row[] };
}>();

const t = section(props.copy, 'optouts');
const s = (k: string): string => t[k] ?? '';
const canExport = computed(() => props.can['customers.export'] === true);

const rows = ref<Row[]>(props.doors.optouts);
const search = ref('');
const phone = ref('');
const reason = ref('');
const error = ref<string | null>(null);
const notice = ref<string | null>(null);
const confirming = ref<Row | null>(null);
const restoreReason = ref('');

const visible = computed(() =>
    search.value === ''
        ? rows.value
        : rows.value.filter((r) => r.phone_masked.includes(search.value)),
);

async function refresh(): Promise<void> {
    const r = await door('GET', '/admin/sms/api/optouts');
    if (r.ok) rows.value = (r.json as unknown as { optouts: Row[] }).optouts;
}
async function add(): Promise<void> {
    error.value = notice.value = null;
    const r = await door('POST', '/admin/sms/api/optouts', { phone: phone.value, reason: reason.value });
    if (!r.ok) {
        error.value = String(r.json.detail ?? r.status);
        return;
    }
    phone.value = reason.value = '';
    notice.value = s('added');
    await refresh();
}
async function restore(): Promise<void> {
    if (!confirming.value) return;
    error.value = notice.value = null;
    const r = await door('POST', '/admin/sms/api/optouts/' + confirming.value.id + '/restore', {
        reason: restoreReason.value,
    });
    if (!r.ok) {
        error.value = String(r.json.detail ?? r.status);
        return;
    }
    confirming.value = null;
    restoreReason.value = '';
    notice.value = s('restored');
    await refresh();
}
function sourceLabel(src: string): string {
    return src === 'inbound_stop'
        ? s('source_stop')
        : src === 'import'
          ? s('source_import')
          : s('source_staff');
}
</script>

<template>
    <SmsScreen :copy="copy" active="sms" :title="s('title')" :read-note="s('read_note')">
        <div class="sms-page" data-testid="sms-optouts">
            <p v-if="error" class="sms-error" role="alert" data-testid="optouts-error">
                {{ tr(shared(copy, 'refused_line'), { detail: error }) }}
            </p>
            <p v-if="notice" class="sms-ok" role="status" data-testid="optouts-notice">
                {{ notice }}
            </p>

            <div class="sms-row">
                <label class="sms-field">
                    <span>{{ s('search') }}</span>
                    <input v-model="search" type="search" data-testid="optouts-search" />
                </label>
                <a
                    v-if="canExport"
                    class="sms-btn"
                    href="/admin/sms/api/optouts/export"
                    data-testid="optouts-export"
                    >{{ s('csv_export') }}</a
                >
            </div>

            <!-- add with reason -->
            <form class="sms-card sms-row" data-testid="optouts-add" @submit.prevent="add">
                <label class="sms-field">
                    <span>{{ s('phone') }}</span>
                    <input
                        v-model="phone"
                        type="text"
                        inputmode="tel"
                        required
                        data-testid="add-phone"
                    />
                </label>
                <label class="sms-field">
                    <span>{{ s('reason') }}</span>
                    <input
                        v-model="reason"
                        type="text"
                        required
                        minlength="3"
                        data-testid="add-reason"
                    />
                </label>
                <button type="submit" class="sms-btn sms-btn-primary" data-testid="add-submit">
                    {{ s('add') }}
                </button>
            </form>

            <!-- the list -->
            <div class="sms-card" data-testid="optouts-list">
                <p v-if="visible.length === 0" class="sms-muted">{{ s('empty') }}</p>
                <table v-else class="sms-table">
                    <thead>
                        <tr>
                            <th scope="col">{{ s('phone') }}</th>
                            <th scope="col">{{ s('reason') }}</th>
                            <th scope="col"></th>
                            <th scope="col"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in visible" :key="row.id" :data-optout="row.phone_masked">
                            <th scope="row">{{ row.phone_masked }}</th>
                            <td>
                                {{ row.reason ?? '—' }}
                                <span v-if="row.stop_word_matched" class="sms-muted">
                                    ·
                                    {{ tr(s('stop_word'), { word: row.stop_word_matched }) }}</span
                                >
                            </td>
                            <td>
                                <span class="sms-pill">{{ sourceLabel(row.source) }}</span>
                            </td>
                            <td>
                                <span v-if="row.restored_at" class="sms-muted">{{
                                    s('restored')
                                }}</span>
                                <button
                                    v-else
                                    type="button"
                                    class="sms-btn"
                                    :data-testid="`restore-${row.phone_masked}`"
                                    @click="confirming = row"
                                >
                                    {{ s('restore') }}
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- the HIGH-VISIBILITY restore confirm -->
            <div
                v-if="confirming"
                class="sms-card sms-page"
                role="alertdialog"
                :aria-label="tr(s('restore_title'), { phone: confirming.phone_masked })"
                data-testid="restore-confirm"
            >
                <h2>{{ tr(s('restore_title'), { phone: confirming.phone_masked }) }}</h2>
                <p class="sms-pill warn">{{ s('restore_warning') }}</p>
                <label class="sms-field">
                    <span>{{ s('restore_reason') }}</span>
                    <input
                        v-model="restoreReason"
                        type="text"
                        required
                        minlength="3"
                        data-testid="restore-reason"
                    />
                </label>
                <div class="sms-row">
                    <button
                        type="button"
                        class="sms-btn sms-btn-primary"
                        :disabled="restoreReason.length < 3"
                        data-testid="restore-go"
                        @click="restore"
                    >
                        {{ s('restore_confirm') }}
                    </button>
                    <button
                        type="button"
                        class="sms-btn"
                        data-testid="restore-cancel"
                        @click="confirming = null"
                    >
                        {{ shared(copy, 'cancel') }}
                    </button>
                </div>
            </div>
        </div>
    </SmsScreen>
</template>
