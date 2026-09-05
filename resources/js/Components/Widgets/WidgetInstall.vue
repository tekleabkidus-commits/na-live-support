<script setup lang="ts">
// B3-S08 J06: the snippet (complete at creation; afterwards the shown-once line — D13-D06 / P-WIDGET-KEY), the two-directive
// CSP block, the allowed-domains chips (the Doc 02 matcher refuses by name), the signed-identity secret with its overlap.
import { ref } from 'vue';
import { door, tr, type Copy } from './shared';

const props = defineProps<{
    copy: Copy;
    widgetId: string;
    install: {
        snippet: string | null;
        key_shown_once: boolean;
        csp: string;
        frame_src: string;
        key_prefix: string;
    };
    origins: string[];
    hasSecret: boolean;
    overlapHours: number;
    canConfigure: boolean;
}>();
const emit = defineEmits<{ (e: 'origins', list: string[]): void; (e: 'secret'): void }>();
const t = props.copy;
const s = (k: string): string => t[k] ?? '';
const domains = ref<string[]>([...props.origins]);
const adding = ref('');
const error = ref<string | null>(null);
const secret = ref<string | null>(null);
const copied = ref<string | null>(null);

async function copyText(kind: string, text: string): Promise<void> {
    try {
        await navigator.clipboard.writeText(text);
        copied.value = kind;
    } catch {
        copied.value = null;
    }
}
function normalize(d: string): string {
    const v = d.trim().toLowerCase();
    return v.startsWith('*.') || v.startsWith('https://') ? v : 'https://' + v;
}
async function save(list: string[]): Promise<void> {
    error.value = null;
    const r = await door('PUT', '/admin/widgets/api/' + props.widgetId + '/allowlist', {
        allowed_origins: list,
    });
    if (!r.ok) {
        error.value = tr(t.domain_refused, { entry: String(r.json.entry ?? '') });
        return;
    }
    domains.value = (r.json.allowed_origins as string[] | undefined) ?? list;
    emit('origins', domains.value);
}
async function add(): Promise<void> {
    if (!adding.value.trim()) return;
    const next = [...domains.value, normalize(adding.value)];
    await save(next);
    if (!error.value) adding.value = '';
}
async function remove(entry: string): Promise<void> {
    await save(domains.value.filter((d) => d !== entry));
}
async function mintSecret(): Promise<void> {
    const r = await door(
        'POST',
        '/admin/widgets/api/' + props.widgetId + (props.hasSecret ? '/secret/rotate' : '/secret'),
    );
    if (r.ok) {
        secret.value = String(r.json.secret_plaintext ?? '');
        emit('secret');
    }
}
</script>

<template>
    <div class="wd-stack" data-testid="wd-install">
        <section class="wd-field">
            <h3>{{ t.snippet }}</h3>
            <template v-if="install.snippet">
                <pre class="wd-code" data-testid="wd-snippet">{{ install.snippet }}</pre>
                <div class="wd-row">
                    <button
                        type="button"
                        class="wd-btn"
                        @click="copyText('snippet', install.snippet)"
                    >
                        {{ copied === 'snippet' ? t.copied : t.copy }}
                    </button>
                </div>
            </template>
            <p v-else class="wd-readonly" data-testid="wd-snippet-shown-once">
                {{ tr(t.snippet_shown_once, { prefix: install.key_prefix }) }}
            </p>
            <p class="wd-muted">{{ t.snippet_note }}</p>
        </section>

        <section class="wd-field">
            <h3>{{ t.csp }}</h3>
            <pre class="wd-code" data-testid="wd-csp">{{ install.csp }}</pre>
            <div class="wd-row">
                <button type="button" class="wd-btn" @click="copyText('csp', install.csp)">
                    {{ copied === 'csp' ? t.copied : t.copy }}
                </button>
            </div>
            <p class="wd-muted">{{ t.csp_note }}</p>
            <p class="wd-muted">{{ t.csp_ours }}</p>
        </section>

        <section class="wd-field">
            <h3>{{ t.domains }}</h3>
            <ul class="wd-chips" data-testid="wd-domains">
                <li v-for="d in domains" :key="d" class="wd-chip">
                    <span>{{ d.replace('https://', '') }}</span>
                    <button
                        v-if="canConfigure"
                        type="button"
                        class="wd-icon"
                        :aria-label="s('remove') + ' ' + d"
                        @click="remove(d)"
                    >
                        ×
                    </button>
                </li>
            </ul>
            <form v-if="canConfigure" class="wd-row" @submit.prevent="add">
                <input
                    v-model="adding"
                    type="text"
                    :placeholder="s('domain_placeholder')"
                    :aria-label="s('domain_add')"
                    data-testid="wd-domain-input"
                />
                <button type="submit" class="wd-btn" data-testid="wd-domain-add">
                    + {{ t.domain_add }}
                </button>
            </form>
            <p v-if="error" class="wd-error" role="alert" data-testid="wd-domain-refused">
                {{ error }}
            </p>
            <p class="wd-muted">{{ t.domains_note }}</p>
        </section>

        <section class="wd-field">
            <h3>{{ t.secret }}</h3>
            <div v-if="secret" class="wd-created" data-testid="wd-secret-shown" aria-live="polite">
                <p>
                    <strong>{{ t.secret_shown }}</strong>
                </p>
                <pre class="wd-code">{{ secret }}</pre>
                <button type="button" class="wd-btn" @click="copyText('secret', secret)">
                    {{ copied === 'secret' ? t.copied : t.copy }}
                </button>
            </div>
            <p v-else-if="hasSecret" class="wd-readonly" data-testid="wd-secret-masked">
                ••••••••••••••••••••••••
            </p>
            <p v-else class="wd-muted" data-testid="wd-secret-none">{{ t.secret_none }}</p>
            <div class="wd-row">
                <button
                    v-if="canConfigure"
                    type="button"
                    class="wd-btn"
                    data-testid="wd-secret-mint"
                    @click="mintSecret"
                >
                    {{ hasSecret ? t.secret_rotate : t.secret_create }}
                </button>
            </div>
            <p class="wd-muted">{{ tr(t.secret_note, { hours: overlapHours }) }}</p>
        </section>
    </div>
</template>
