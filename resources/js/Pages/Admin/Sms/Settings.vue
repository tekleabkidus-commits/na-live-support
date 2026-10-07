<script setup lang="ts">
// Doc 14 §8 / FR-16 / FR-19 (S14 step 13, [B14-batch]) — SMS settings: the
// setup checklist FIRST ("SMS is off until a gateway exists"), quiet hours in
// the TENANT timezone with explicit labels, the per-event toggles (off means
// the SMS never exists), and the quotas/caps (platform-set, read-only here).
// Writes ride the ONE settings door (settings.edit + tenant.setting_changed).
import { computed, reactive, ref } from 'vue';
import SmsScreen from '../../../Components/Sms/SmsScreen.vue';
import { door, section, shared, tr, type ScreensCopy } from '../../../Components/Sms/screens';

const props = defineProps<{
    copy: ScreensCopy;
    can: Record<string, boolean>;
    quiet: { start: string; end: string; tz: string };
    events: Record<string, boolean>;
    quota: { segments_used: number; segments_limit: number };
    broadcast_cap: number;
    checklist: {
        gateway: boolean;
        route: boolean;
        templates: boolean;
        incomplete_events: string[];
    };
}>();

const t = section(props.copy, 'settings');
const s = (k: string): string => t[k] ?? '';
const ev = (key: string): string => shared(props.copy, 'event_' + key) || key;
const canEdit = computed(() => props.can['settings.edit'] === true);

const start = ref(props.quiet.start);
const end = ref(props.quiet.end);
const toggles = reactive<Record<string, boolean>>({ ...props.events });
const error = ref<string | null>(null);
const notice = ref<string | null>(null);

async function saveQuiet(): Promise<void> {
    error.value = notice.value = null;
    const r = await door('POST', '/admin/sms/settings', {
        quiet_hours: { start: start.value, end: end.value },
    });
    if (!r.ok) {
        error.value = String(r.json.detail ?? r.status);
        return;
    }
    notice.value = shared(props.copy, 'saved');
}
async function toggle(event: string): Promise<void> {
    error.value = notice.value = null;
    toggles[event] = !toggles[event];
    const r = await door('POST', '/admin/sms/settings', { events: { [event]: toggles[event] } });
    if (!r.ok) {
        toggles[event] = !toggles[event];
        error.value = String(r.json.detail ?? r.status);
        return;
    }
    notice.value = shared(props.copy, 'saved');
}
</script>

<template>
    <SmsScreen :copy="copy" active="sms-settings" :title="s('title')" :read-note="s('read_note')">
        <div class="sms-page" data-testid="sms-settings">
            <p v-if="error" class="sms-error" role="alert" data-testid="settings-error">
                {{ tr(shared(copy, 'refused_line'), { detail: error }) }}
            </p>
            <p v-if="notice" class="sms-ok" role="status" data-testid="settings-notice">
                {{ notice }}
            </p>

            <!-- the setup checklist -->
            <div class="sms-card" data-testid="setup-checklist">
                <h2>{{ s('checklist_title') }}</h2>
                <p v-if="!checklist.gateway" class="sms-pill warn" data-testid="checklist-off">
                    {{ s('checklist_off') }}
                </p>
                <ul style="list-style: none; margin: 0; padding: 0; display: grid; gap: 6px">
                    <li class="sms-row" data-testid="checklist-gateway">
                        <span class="sms-pill" :class="checklist.gateway ? 'ok' : 'bad'">{{
                            checklist.gateway ? '✓' : '✗'
                        }}</span>
                        <span>{{ s('checklist_gateway') }}</span>
                    </li>
                    <li class="sms-row" data-testid="checklist-route">
                        <span class="sms-pill" :class="checklist.route ? 'ok' : 'bad'">{{
                            checklist.route ? '✓' : '✗'
                        }}</span>
                        <span>{{ s('checklist_route') }}</span>
                    </li>
                    <li class="sms-row" data-testid="checklist-templates">
                        <span class="sms-pill" :class="checklist.templates ? 'ok' : 'warn'">{{
                            checklist.templates ? '✓' : '✗'
                        }}</span>
                        <span>{{ s('checklist_templates') }}</span>
                        <span v-if="checklist.incomplete_events.length" class="sms-muted">
                            — {{ checklist.incomplete_events.map(ev).join(' · ') }}</span
                        >
                    </li>
                </ul>
            </div>

            <!-- quiet hours in the tenant timezone -->
            <div class="sms-card sms-page" data-testid="quiet-hours">
                <h2>{{ s('quiet_title') }}</h2>
                <p class="sms-muted">{{ s('quiet_note') }}</p>
                <div class="sms-row">
                    <label class="sms-field">
                        <span>{{ tr(s('quiet_start'), { tz: quiet.tz }) }}</span>
                        <input
                            v-model="start"
                            type="time"
                            :disabled="!canEdit"
                            data-testid="quiet-start"
                        />
                    </label>
                    <label class="sms-field">
                        <span>{{ tr(s('quiet_end'), { tz: quiet.tz }) }}</span>
                        <input
                            v-model="end"
                            type="time"
                            :disabled="!canEdit"
                            data-testid="quiet-end"
                        />
                    </label>
                    <button
                        type="button"
                        class="sms-btn sms-btn-primary"
                        :disabled="!canEdit"
                        data-testid="quiet-save"
                        @click="saveQuiet"
                    >
                        {{ s('quiet_save') }}
                    </button>
                </div>
            </div>

            <!-- per-event toggles -->
            <div class="sms-card" data-testid="event-toggles">
                <h2>{{ s('events_title') }}</h2>
                <p class="sms-muted">{{ s('events_note') }}</p>
                <ul style="list-style: none; margin: 0; padding: 0; display: grid; gap: 6px">
                    <li
                        v-for="(enabled, event) in toggles"
                        :key="event"
                        class="sms-row"
                        :data-event-toggle="event"
                    >
                        <label class="sms-row">
                            <input
                                type="checkbox"
                                :checked="enabled"
                                :disabled="!canEdit"
                                :aria-label="`${ev(String(event))}`"
                                :data-testid="`toggle-${event}`"
                                @change="toggle(String(event))"
                            />
                            <span>{{ ev(String(event)) }}</span>
                            <span class="sms-pill" :class="enabled ? 'ok' : ''">{{
                                enabled ? shared(copy, 'active') : shared(copy, 'inactive')
                            }}</span>
                        </label>
                    </li>
                </ul>
            </div>

            <!-- quotas & caps (platform-set) -->
            <div class="sms-card" data-testid="quotas">
                <h2>{{ s('quota_title') }}</h2>
                <dl class="sms-dl">
                    <dt>{{ s('quota_segments') }}</dt>
                    <dd data-testid="quota-segments">
                        {{
                            tr(s('quota_used'), {
                                used: quota.segments_used,
                                limit: quota.segments_limit,
                            })
                        }}
                        <span class="sms-muted"> · {{ s('platform_set') }}</span>
                    </dd>
                    <dt>{{ s('broadcast_cap') }}</dt>
                    <dd data-testid="quota-broadcasts">
                        {{ broadcast_cap }} <span class="sms-muted">· {{ s('platform_set') }}</span>
                    </dd>
                </dl>
            </div>
        </div>
    </SmsScreen>
</template>
