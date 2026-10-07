<script setup lang="ts">
// Doc 14 §8 / FR-06…FR-11 (S14 step 13, [B14-batch]) — the gateways screen
// (desktop-wide): cards with health pills, driver badge, throughput cap and
// today's sent figure; the detail with WRITE-ONLY credentials ("saved — enter
// a new value to replace"), the rate card (reports.view-gated by the door),
// DLR callback + rotate, circuit state + reset; the routing table with move
// buttons and overlap warnings; the test-a-number resolver announcing the
// chain. Every mutation rides the step-3/4 JSON doors.
import { computed, ref } from 'vue';
import SmsScreen from '../../../Components/Sms/SmsScreen.vue';
import { door, section, shared, tr, type ScreensCopy } from '../../../Components/Sms/screens';

type Gateway = {
    id: string;
    name: string;
    driver: string;
    config: Record<string, unknown>;
    has_credential: boolean;
    has_dlr_credentials: boolean;
    sender_id: string | null;
    health: string;
    circuit_state: string;
    active: boolean;
    is_fixture: boolean;
    throughput_per_second: number | null;
};
type RouteRow = {
    id: string;
    country_prefix: string;
    position: number;
    primary_gateway_id: string;
    fallback_gateway_ids: string[];
    active: boolean;
};

const props = defineProps<{
    copy: ScreensCopy;
    can: Record<string, boolean>;
    doors: { gateways: Gateway[]; routes: RouteRow[]; overlaps: { a: string; b: string }[] };
    today: Record<string, number>;
}>();

const t = section(props.copy, 'gateways');
const s = (k: string): string => t[k] ?? '';
const canManage = computed(() => props.can['sms.gateways.manage'] === true);

const gateways = ref<Gateway[]>(props.doors.gateways);
const routes = ref<RouteRow[]>(props.doors.routes);
const overlaps = ref(props.doors.overlaps);
const open = ref<Gateway | null>(null);
const error = ref<string | null>(null);
const notice = ref<string | null>(null);
const credential = ref('');
const resolvePhone = ref('');
const resolved = ref<string | null>(null);

function gatewayName(id: string): string {
    return gateways.value.find((g) => g.id === id)?.name ?? id;
}
function healthKind(h: string): 'ok' | 'warn' | 'bad' {
    return h === 'healthy' ? 'ok' : h === 'degraded' ? 'warn' : 'bad';
}
async function refresh(): Promise<void> {
    const r = await door('GET', '/admin/sms/api/gateways');
    if (r.ok) {
        const j = r.json as unknown as {
            gateways: Gateway[];
            routes: RouteRow[];
            overlaps: { a: string; b: string }[];
        };
        gateways.value = j.gateways;
        routes.value = j.routes;
        overlaps.value = j.overlaps;
        if (open.value) open.value = j.gateways.find((g) => g.id === open.value?.id) ?? null;
    }
}
async function replaceCredential(): Promise<void> {
    if (!open.value) return;
    error.value = notice.value = null;
    const r = await door('POST', '/admin/sms/api/gateways/' + open.value.id + '/credential', {
        credential: credential.value,
    });
    if (!r.ok) {
        error.value = String(r.json.detail ?? r.status);
        return;
    }
    credential.value = '';
    notice.value = s('credential_saved');
    await refresh();
}
async function rotateDlr(): Promise<void> {
    if (!open.value) return;
    error.value = notice.value = null;
    const r = await door('POST', '/admin/sms/api/gateways/' + open.value.id + '/dlr/rotate');
    if (!r.ok) {
        error.value = String(r.json.detail ?? r.status);
        return;
    }
    notice.value = s('dlr_rotated');
}
const circuitReason = ref('');
async function resetCircuit(): Promise<void> {
    if (!open.value) return;
    error.value = notice.value = null;
    const r = await door('POST', '/admin/sms/api/gateways/' + open.value.id + '/circuit/reset', {
        reason: circuitReason.value,
    });
    if (!r.ok) {
        error.value = String(r.json.detail ?? r.status);
        return;
    }
    circuitReason.value = '';
    await refresh();
}
async function move(route: RouteRow, direction: -1 | 1): Promise<void> {
    const ordered = [...routes.value].sort((a, b) => a.position - b.position).map((r) => r.id);
    const i = ordered.indexOf(route.id);
    const j = i + direction;
    if (j < 0 || j >= ordered.length) return;
    const other = ordered[j];
    if (other === undefined) return;
    ordered[j] = route.id;
    ordered[i] = other;
    error.value = null;
    const r = await door('POST', '/admin/sms/api/routes/reorder', { ordered_ids: ordered });
    if (!r.ok) error.value = String(r.json.detail ?? r.status);
    await refresh();
}
async function deactivate(route: RouteRow): Promise<void> {
    error.value = null;
    const r = await door('POST', '/admin/sms/api/routes/' + route.id + '/deactivate');
    if (!r.ok) error.value = String(r.json.detail ?? r.status);
    await refresh();
}
async function resolveNumber(): Promise<void> {
    error.value = null;
    resolved.value = null;
    const r = await door('POST', '/admin/sms/api/gateways/resolve', { phone: resolvePhone.value });
    if (!r.ok) {
        error.value = String(r.json.detail ?? r.status);
        return;
    }
    const chain =
        (r.json.chain as { gateway_id: string; gateway_name: string | null }[] | undefined) ?? [];
    resolved.value =
        r.json.no_route === true || chain.length === 0
            ? s('resolver_none')
            : tr(s('resolver_chain'), {
                  phone: resolvePhone.value,
                  chain: chain
                      .map((hop) => hop.gateway_name ?? gatewayName(hop.gateway_id))
                      .join(' → '),
              });
}
const dlrCallback = computed<string>(() => {
    const dlr = open.value?.config['dlr'];
    if (typeof dlr === 'object' && dlr !== null && 'callback_url' in dlr)
        return String((dlr as Record<string, unknown>).callback_url ?? '');
    return '';
});
</script>

<template>
    <SmsScreen :copy="copy" active="sms" :title="s('title')" :read-note="s('read_note')">
        <div class="sms-page" data-testid="sms-gateways">
            <p v-if="error" class="sms-error" role="alert" data-testid="sms-gateways-error">
                {{ tr(shared(copy, 'refused_line'), { detail: error }) }}
            </p>

            <!-- the cards -->
            <div class="sms-grid-cards" data-testid="gateway-cards">
                <button
                    v-for="g in gateways"
                    :key="g.id"
                    type="button"
                    class="sms-card sms-page"
                    :data-testid="`gateway-card-${g.name}`"
                    @click="open = g"
                >
                    <span class="sms-row">
                        <strong>{{ g.name }}</strong>
                        <span
                            class="sms-pill"
                            :class="healthKind(g.health)"
                            :data-health="g.health"
                            >{{ s('health_' + g.health) || g.health }}</span
                        >
                        <span class="sms-pill">{{
                            g.driver === 'smpp_sidecar' ? s('driver_smpp') : s('driver_http')
                        }}</span>
                    </span>
                    <span v-if="g.throughput_per_second" class="sms-muted">{{
                        tr(s('throughput'), { n: g.throughput_per_second * 60 })
                    }}</span>
                    <span class="sms-muted">{{
                        tr(s('sent_today'), { n: today[g.id] ?? 0 })
                    }}</span>
                </button>
            </div>

            <!-- the detail -->
            <div v-if="open" class="sms-card sms-page" data-testid="gateway-detail">
                <h2>{{ tr(s('detail_title'), { name: open.name }) }}</h2>
                <dl class="sms-dl">
                    <dt>
                        {{
                            s('circuit_' + open.circuit_state.replace('-', '_')) ||
                            open.circuit_state
                        }}
                    </dt>
                    <dd>
                        <span v-if="canManage && open.circuit_state !== 'closed'" class="sms-row">
                            <label class="sms-field">
                                <span>{{ s('reset_reason') }}</span>
                                <input
                                    v-model="circuitReason"
                                    type="text"
                                    data-testid="circuit-reason"
                                />
                            </label>
                            <button
                                type="button"
                                class="sms-btn"
                                data-testid="circuit-reset"
                                @click="resetCircuit"
                            >
                                {{ s('reset_circuit') }}
                            </button>
                        </span>
                    </dd>
                    <dt>{{ s('sender_rules') }}</dt>
                    <dd>{{ open.sender_id ?? '—' }}</dd>
                </dl>

                <!-- write-only credential -->
                <div class="sms-row" data-testid="credential-row">
                    <label class="sms-field">
                        <span>{{ s('credential') }}</span>
                        <input
                            v-model="credential"
                            type="password"
                            autocomplete="off"
                            data-testid="credential-input"
                        />
                    </label>
                    <button
                        type="button"
                        class="sms-btn"
                        :disabled="!canManage || credential === ''"
                        data-testid="credential-replace"
                        @click="replaceCredential"
                    >
                        {{ s('credential_replace') }}
                    </button>
                </div>
                <p v-if="open.has_credential" class="sms-muted" data-testid="credential-saved">
                    {{ s('credential_saved') }}
                </p>

                <!-- DLR -->
                <div class="sms-card">
                    <h3>{{ s('dlr_title') }}</h3>
                    <dl class="sms-dl">
                        <dt>{{ s('dlr_callback') }}</dt>
                        <dd data-testid="dlr-callback">{{ dlrCallback || '—' }}</dd>
                    </dl>
                    <button
                        type="button"
                        class="sms-btn"
                        :disabled="!canManage"
                        data-testid="dlr-rotate"
                        @click="rotateDlr"
                    >
                        {{ s('dlr_rotate') }}
                    </button>
                </div>
                <p v-if="notice" class="sms-ok" role="status" data-testid="gateway-notice">
                    {{ notice }}
                </p>
            </div>

            <!-- routing -->
            <div class="sms-card sms-page" data-testid="routing-table">
                <h2>{{ s('routing_title') }}</h2>
                <p class="sms-muted">{{ s('routing_note') }}</p>
                <p
                    v-for="o in overlaps"
                    :key="o.a + o.b"
                    class="sms-pill warn"
                    data-testid="route-overlap"
                >
                    {{ tr(s('overlap_warning'), { a: o.a, b: o.b }) }}
                </p>
                <table class="sms-table">
                    <thead>
                        <tr>
                            <th scope="col">{{ s('route_prefix') }}</th>
                            <th scope="col">{{ s('route_gateway') }}</th>
                            <th scope="col">{{ s('route_fallback') }}</th>
                            <th scope="col"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="r in [...routes].sort((a, b) => a.position - b.position)"
                            :key="r.id"
                            :data-route="r.country_prefix"
                        >
                            <th scope="row">{{ r.country_prefix }}</th>
                            <td>{{ gatewayName(r.primary_gateway_id) }}</td>
                            <td>{{ r.fallback_gateway_ids.map(gatewayName).join(', ') || '—' }}</td>
                            <td>
                                <span v-if="canManage && r.active" class="sms-row">
                                    <button
                                        type="button"
                                        class="sms-btn"
                                        :aria-label="`${s('route_move_up')} ${r.country_prefix}`"
                                        @click="move(r, -1)"
                                    >
                                        ↑
                                    </button>
                                    <button
                                        type="button"
                                        class="sms-btn"
                                        :aria-label="`${s('route_move_down')} ${r.country_prefix}`"
                                        @click="move(r, 1)"
                                    >
                                        ↓
                                    </button>
                                    <button type="button" class="sms-btn" @click="deactivate(r)">
                                        {{ s('route_deactivate') }}
                                    </button>
                                </span>
                                <span v-else-if="!r.active" class="sms-muted">{{
                                    shared(copy, 'inactive')
                                }}</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- the test-a-number resolver -->
            <div class="sms-card" data-testid="resolver">
                <h2>{{ s('resolver_title') }}</h2>
                <div class="sms-row">
                    <label class="sms-field">
                        <span>{{ s('resolver_phone') }}</span>
                        <input
                            v-model="resolvePhone"
                            type="text"
                            inputmode="tel"
                            data-testid="resolver-phone"
                        />
                    </label>
                    <button
                        type="button"
                        class="sms-btn"
                        data-testid="resolver-run"
                        @click="resolveNumber"
                    >
                        {{ s('resolver_run') }}
                    </button>
                </div>
                <p v-if="resolved" class="sms-ok" role="status" data-testid="resolver-result">
                    {{ resolved }}
                </p>
            </div>
        </div>
    </SmsScreen>
</template>
