<script setup lang="ts">
/**
 * B4-S07 H05 — asking for the archive, then building → ready. The contents
 * list states what the archive holds (profile with provenance, the tickets
 * index, the files manifest with blocked media LISTED not included), the
 * watermark sentence stands beside the button, and the ready state hands a
 * signed link that dies on its own (SEC-04 — the server signs, the client
 * only polls the re-issue door).
 */
import { onMounted, ref } from 'vue';

const props = defineProps<{
    customerId: string;
    t: Record<string, string>;
}>();
const emit = defineEmits<{ close: [] }>();

const customerName = ref('');

const fill = (template: string | undefined, vars: Record<string, string>): string => {
    let out = template ?? '';
    for (const [key, value] of Object.entries(vars)) out = out.replaceAll(':' + key, value);
    return out;
};
function xsrf(): string {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    return match?.[1] !== undefined ? decodeURIComponent(match[1]) : '';
}

const ticketsCount = ref<number | null>(null);
const filesCount = ref<number | null>(null);
const phase = ref<'ask' | 'building' | 'ready'>('ask');
const url = ref('');
const exportId = ref('');
let poll: ReturnType<typeof setInterval> | null = null;

onMounted(async () => {
    // The counts the contents list names — read from the panel's own doors.
    const panel = await fetch(`/api/staff/customers/${props.customerId}/panel`, {
        headers: { Accept: 'application/json' },
    });
    if (panel.ok) {
        const data = (await panel.json()) as {
            header?: { tickets_count?: number; display_name?: string | null };
        };
        ticketsCount.value = data.header?.tickets_count ?? 0;
        customerName.value = data.header?.display_name ?? '';
    }
    const files = await fetch(`/api/staff/customers/${props.customerId}/panel/files`, {
        headers: { Accept: 'application/json' },
    });
    if (files.ok) {
        const data = (await files.json()) as { files?: unknown[] };
        filesCount.value = data.files?.length ?? 0;
    }
});

async function start(): Promise<void> {
    const response = await fetch(`/api/staff/customers/${props.customerId}/export`, {
        method: 'POST',
        headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() },
    });
    if (!response.ok) return;
    const body = (await response.json()) as { export_id: string };
    exportId.value = body.export_id;
    phase.value = 'building';
    poll = setInterval(() => void check(), 2000);
}

async function check(): Promise<void> {
    const response = await fetch(
        `/api/staff/customers/${props.customerId}/export/${exportId.value}`,
        { headers: { Accept: 'application/json' } },
    );
    if (!response.ok) return; // 404 = not ready yet (FS-05: partials never sign)
    const body = (await response.json()) as { url: string };
    url.value = body.url;
    phase.value = 'ready';
    if (poll !== null) clearInterval(poll);
}

function close(): void {
    if (poll !== null) clearInterval(poll);
    emit('close');
}
</script>

<template>
    <div
        role="dialog"
        aria-modal="true"
        :aria-label="fill(t.export_title, { name: customerName })"
        class="fixed inset-0 z-40 flex items-center justify-center p-4"
        data-testid="export-modal"
    >
        <div
            class="absolute inset-0"
            :style="{ background: 'var(--ds-overlay)' }"
            @click="close"
        ></div>
        <div
            class="relative w-full max-w-md rounded border p-4"
            :style="{
                borderColor: 'var(--ds-border-strong)',
                background: 'var(--ds-surface)',
                color: 'var(--ds-text-primary)',
            }"
        >
            <h2 class="text-base font-semibold">
                {{ fill(t.export_title, { name: customerName }) }}
            </h2>

            <template v-if="phase === 'ask'">
                <h3 class="mt-3 text-xs font-medium" :style="{ color: 'var(--ds-text-label)' }">
                    {{ t.export_contains }}
                </h3>
                <ul class="mt-1 list-disc ps-5 text-sm" data-testid="export-contents">
                    <li>{{ t.export_profile_line }}</li>
                    <li>{{ fill(t.export_tickets_line, { count: String(ticketsCount ?? 0) }) }}</li>
                    <li>{{ fill(t.export_files_line, { count: String(filesCount ?? 0) }) }}</li>
                </ul>
                <p class="mt-3 text-xs" :style="{ color: 'var(--ds-text-muted)' }">
                    {{ t.export_watermark_note }}
                </p>
                <p class="mt-1 text-xs" :style="{ color: 'var(--ds-text-muted)' }">
                    {{ t.export_async }}
                </p>
                <div class="mt-4 flex justify-end gap-2">
                    <button
                        type="button"
                        class="rounded border px-3 py-1.5 text-sm"
                        :style="{ borderColor: 'var(--ds-border-strong)' }"
                        data-testid="export-cancel"
                        @click="close"
                    >
                        {{ t.cancel }}
                    </button>
                    <button
                        type="button"
                        class="rounded border px-3 py-1.5 text-sm"
                        :style="{ borderColor: 'var(--ds-border-strong)' }"
                        data-testid="export-start"
                        @click="start"
                    >
                        {{ t.export_start }}
                    </button>
                </div>
            </template>

            <template v-else-if="phase === 'building'">
                <p class="mt-3 text-sm" role="status" data-testid="export-building">
                    {{ t.export_building }}
                    <span class="text-xs" :style="{ color: 'var(--ds-text-muted)' }">{{
                        t.export_eta
                    }}</span>
                </p>
                <p class="mt-1 text-xs" :style="{ color: 'var(--ds-text-muted)' }">
                    {{ t.export_building_hint }}
                </p>
            </template>

            <template v-else>
                <p class="mt-3 text-sm" role="status" data-testid="export-ready">
                    ✓
                    {{
                        fill(t.export_ready, {
                            file: `${customerName.toLowerCase().replaceAll(' ', '-')}.zip`,
                        })
                    }}
                </p>
                <p class="mt-1 text-xs" :style="{ color: 'var(--ds-text-muted)' }">
                    {{ t.export_link_ttl }}
                </p>
                <a
                    :href="url"
                    class="mt-2 inline-block rounded border px-3 py-1.5 text-sm underline"
                    :style="{ borderColor: 'var(--ds-border-strong)', color: 'var(--ds-accent)' }"
                    data-testid="export-download"
                    >{{ t.download }}</a
                >
            </template>
        </div>
    </div>
</template>
