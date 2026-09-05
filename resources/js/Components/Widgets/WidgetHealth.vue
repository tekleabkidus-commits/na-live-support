<script setup lang="ts">
// B3-S08 J07–J08: version + rollout, the 7-day counters, blocked origins, the two diagnoses with the copyable fix,
// invalid signatures, the test-mode switch (BR-07), the publish history with "Roll back to this" (J10).
import { ref } from 'vue';
import { door, tr, type Copy } from './shared';

type Health = {
    days: number;
    counters: {
        loads: number;
        opens: number;
        messages: number;
        errors: number;
        error_rate: number;
        invalid_signatures: number;
        blocked_origin_attempts: number;
    };
    blocked_origins: { origin: string; attempts?: number; count?: number }[];
    diagnoses: { csp_blocked: { count: number }; double_install: { count: number } };
    bundle: { version: string; channel: string; rollout_pct: number; source: string };
    config_version: number | null;
    test_mode: boolean;
};
type Version = {
    id: string;
    version: number;
    status: string;
    note: string | null;
    published_by: string;
    published_at: string;
};
const props = defineProps<{
    copy: Copy;
    widgetId: string;
    health: Health;
    history: Version[];
    frameSrc: string;
    canConfigure: boolean;
}>();
const emit = defineEmits<{ (e: 'rolled-back'): void; (e: 'test-mode', on: boolean): void }>();
const t = props.copy;
const testMode = ref(props.health.test_mode);
const busy = ref<string | null>(null);
const copied = ref(false);
const fix = 'frame-src ' + props.frameSrc + ';';
async function toggleTestMode(): Promise<void> {
    const r = await door('PATCH', '/admin/widgets/api/' + props.widgetId, {
        test_mode: testMode.value,
    });
    if (r.ok) emit('test-mode', testMode.value);
}
async function rollback(v: Version): Promise<void> {
    busy.value = v.id;
    const r = await door('POST', '/admin/widgets/api/' + props.widgetId + '/config/rollback', {
        version_id: v.id,
    });
    busy.value = null;
    if (r.ok) emit('rolled-back');
}
async function copyFix(): Promise<void> {
    try {
        await navigator.clipboard.writeText(fix);
        copied.value = true;
    } catch {
        copied.value = false;
    }
}
function pct(n: number): string {
    return (n * 100).toFixed(1) + '%';
}
</script>

<template>
    <div class="wd-stack" data-testid="wd-health">
        <p class="wd-readonly" data-testid="wd-health-version">
            <strong>{{ t.health_version }}</strong> {{ health.bundle.version.slice(0, 12) }} ·
            {{ health.bundle.rollout_pct }}% {{ t.rollout }}
            <span class="wd-muted">· {{ health.bundle.channel }}</span>
        </p>
        <div class="wd-stats" data-testid="wd-health-counters">
            <div class="wd-stat">
                <b>{{ health.counters.loads.toLocaleString() }}</b
                ><span>{{ tr(t.loads, { days: health.days }) }}</span>
            </div>
            <div class="wd-stat">
                <b>{{ health.counters.opens.toLocaleString() }}</b
                ><span>{{ t.opens }}</span>
            </div>
            <div class="wd-stat">
                <b>{{ health.counters.messages.toLocaleString() }}</b
                ><span>{{ t.conversations }}</span>
            </div>
            <div class="wd-stat">
                <b>{{ pct(health.counters.error_rate) }}</b
                ><span>{{ t.error_rate }}</span>
            </div>
        </div>

        <section class="wd-field" data-testid="wd-blocked">
            <h3>
                {{
                    health.blocked_origins.length
                        ? tr(t.blocked_title, { count: health.blocked_origins.length })
                        : t.blocked_none
                }}
            </h3>
            <ul v-if="health.blocked_origins.length" class="wd-chips">
                <li v-for="b in health.blocked_origins" :key="b.origin" class="wd-chip">
                    {{ b.origin.replace('https://', '') }} ·
                    {{ tr(t.attempts, { count: b.attempts ?? b.count ?? 0 }) }}
                </li>
            </ul>
            <p v-if="health.blocked_origins.length" class="wd-muted">{{ t.blocked_note }}</p>
        </section>

        <section
            v-if="health.diagnoses.csp_blocked.count > 0"
            class="wd-card wd-diag"
            data-testid="wd-diag-csp"
        >
            <h3>{{ tr(t.csp_blocked_title, { count: health.diagnoses.csp_blocked.count }) }}</h3>
            <p class="wd-muted">{{ t.csp_blocked_note }}</p>
            <pre class="wd-code">{{ fix }}</pre>
            <button type="button" class="wd-btn" @click="copyFix">
                {{ copied ? t.copied : t.copy_fix }}
            </button>
        </section>
        <section
            v-if="health.diagnoses.double_install.count > 0"
            class="wd-card wd-diag"
            data-testid="wd-diag-double"
        >
            <h3>{{ t.double_title }}</h3>
            <p class="wd-muted">{{ t.double_note }}</p>
        </section>

        <p class="wd-readonly" data-testid="wd-invalid-signatures">
            <strong>{{ t.invalid_signatures }}</strong> {{ health.counters.invalid_signatures }}
        </p>
        <label class="wd-switch" data-testid="wd-test-mode">
            <span>{{ t.test_mode }}</span>
            <input
                v-model="testMode"
                type="checkbox"
                role="switch"
                :disabled="!canConfigure"
                @change="toggleTestMode"
            />
        </label>

        <section class="wd-field" data-testid="wd-history">
            <h3>{{ t.history }}</h3>
            <ol class="wd-history">
                <li v-for="v in history" :key="v.id" class="wd-history-row" :data-status="v.status">
                    <div>
                        <b>{{
                            v.note && !v.note.startsWith('rollback:')
                                ? v.note
                                : tr(t.version, { version: v.version })
                        }}</b>
                        <span class="wd-muted"
                            >· {{ tr(t.version, { version: v.version }) }} ·
                            {{ v.published_at.slice(0, 16).replace('T', ' ') }} ·
                            {{ t['history_' + v.status] ?? v.status }}</span
                        >
                    </div>
                    <button
                        v-if="v.status !== 'live' && canConfigure"
                        type="button"
                        class="wd-btn"
                        :disabled="busy === v.id"
                        :data-testid="'wd-rollback-' + v.version"
                        @click="rollback(v)"
                    >
                        {{ t.rollback }}
                    </button>
                </li>
            </ol>
            <p class="wd-muted">{{ t.history_note }}</p>
        </section>
    </div>
</template>
