<script setup lang="ts">
// R-22's class-2 fill (S07 step 14) — the tag rename/merge surface, composed
// ONLY from existing design-system patterns per the resolved ruling:
//  - the per-key settings-row contract (B6-X01 J03–J08: label + description
//    left, typed control right, consequence stated BEFORE save, provenance
//    after) carried by the B6-S11 shell idiom;
//  - the confirmation component weights (B1-DS addendum B5): the verb on the
//    primary — never OK, never Confirm; consequence in nouns; the heaviest
//    weight (irreversible merge) takes a TYPED confirmation and a red primary;
//  - the J19 viewport law (read-only under 1024);
//  - B2-S06's inline refusal (role=alert, in place, never a browser dialog).
// Nothing here invents styling; every color is a --ds-* token.
import { computed, ref } from 'vue';

type TagRow = { name: string; count: number };

const props = defineProps<{
    t: Record<string, string>;
    tags: TagRow[];
}>();

const rows = ref<TagRow[]>(props.tags);
const readonly = ref(window.innerWidth < 1024); // J19: load-time viewport law

const from = ref<string | null>(null);
const to = ref('');
const phrase = ref('');
const busy = ref(false);
const refusal = ref('');
const resultCount = ref<number | null>(null);

const label = (key: string, replacements: Record<string, string>): string => {
    let value = props.t[key] ?? '';
    for (const [token, replacement] of Object.entries(replacements)) {
        value = value.replaceAll(':' + token, replacement);
    }
    return value;
};

const fromRow = computed(() => rows.value.find((row) => row.name === from.value) ?? null);
const targetRow = computed(
    () => rows.value.find((row) => row.name === to.value.trim() && row.name !== from.value) ?? null,
);
const isMerge = computed(() => targetRow.value !== null);
const confirmBlocked = computed(
    () =>
        fromRow.value === null ||
        to.value.trim() === '' ||
        to.value.trim() === from.value ||
        (isMerge.value && phrase.value !== to.value.trim()),
);

function pick(name: string): void {
    from.value = name;
    to.value = '';
    phrase.value = '';
    refusal.value = '';
    resultCount.value = null;
}

function cancel(): void {
    from.value = null;
    to.value = '';
    phrase.value = '';
    refusal.value = '';
}

function xsrf(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return match?.[1] ? decodeURIComponent(match[1]) : '';
}

async function execute(): Promise<void> {
    if (busy.value || confirmBlocked.value || from.value === null) return;
    busy.value = true;
    refusal.value = '';
    try {
        const response = await fetch('/admin/tags/rewrite', {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': xsrf(),
            },
            body: JSON.stringify({ from: from.value, to: to.value.trim() }),
        });
        if (response.ok) {
            const body = (await response.json()) as {
                tickets_rewritten: number;
                tags: TagRow[];
            };
            resultCount.value = body.tickets_rewritten;
            rows.value = body.tags;
            from.value = null;
            to.value = '';
            phrase.value = '';
            return;
        }
        const body = (await response.json().catch(() => null)) as { detail?: string } | null;
        refusal.value = body?.detail ?? props.t.refused ?? '';
    } catch {
        refusal.value = props.t.refused ?? '';
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div
        class="min-h-screen"
        :style="{ background: 'var(--ds-page-bg)', color: 'var(--ds-text-primary)' }"
    >
        <div class="mx-auto max-w-[760px] px-4 py-6 sm:px-6">
            <h1 class="text-lg font-semibold">{{ t.title }}</h1>

            <!-- J19 viewport notice — read-only on small screens -->
            <p
                v-if="readonly"
                data-frame="J19-readonly"
                class="mt-3 rounded-lg border px-3 py-2 text-xs"
                :style="{
                    borderColor: 'var(--ds-border-strong)',
                    background: 'var(--ds-inset-bg)',
                    color: 'var(--ds-text-muted)',
                }"
            >
                {{ t.readonly_notice }}
            </p>

            <!-- The result strip (J04 provenance flavor): count first — the
                 count IS the record (T-07's own words). -->
            <p
                v-if="resultCount !== null"
                role="status"
                data-testid="rewrite-result"
                data-frame="J06-result"
                class="mt-3 rounded-lg border px-3 py-2 text-xs"
                :style="{
                    borderColor: 'var(--ds-border)',
                    background: 'var(--ds-panel-soft)',
                    color: 'var(--ds-text-primary)',
                }"
            >
                {{ label('result', { count: String(resultCount) }) }}
                <span :style="{ color: 'var(--ds-text-muted)' }">{{ t.result_audit }}</span>
            </p>

            <!-- The inventory (settings-row list: label + description left,
                 the action control right). -->
            <section
                data-frame="J03-inventory"
                class="mt-6 rounded-xl border p-5"
                :style="{ borderColor: 'var(--ds-border)', background: 'var(--ds-panel-bg)' }"
            >
                <h2 class="text-sm font-semibold">{{ t.inventory_title }}</h2>
                <p class="mt-0.5 text-xs" :style="{ color: 'var(--ds-text-muted)' }">
                    {{ t.inventory_help }}
                </p>

                <p
                    v-if="rows.length === 0"
                    data-testid="inventory-empty"
                    class="mt-4 text-sm"
                    :style="{ color: 'var(--ds-text-muted)' }"
                >
                    {{ t.inventory_empty }}
                </p>

                <ul v-else class="mt-3">
                    <li
                        v-for="row in rows"
                        :key="row.name"
                        :data-tag-row="row.name"
                        class="flex items-center justify-between gap-4 border-t py-2.5"
                        :style="{ borderColor: 'var(--ds-border-soft)' }"
                    >
                        <div class="min-w-0">
                            <p class="text-sm font-medium">{{ row.name }}</p>
                            <p class="text-xs" :style="{ color: 'var(--ds-text-muted)' }">
                                {{ label('ticket_count', { count: String(row.count) }) }}
                            </p>
                        </div>
                        <button
                            v-if="!readonly"
                            type="button"
                            data-action="rename"
                            :data-tag="row.name"
                            class="min-h-[44px] rounded-lg border px-4 text-sm font-medium"
                            :style="{
                                borderColor: 'var(--ds-border-strong)',
                                color: 'var(--ds-text-secondary)',
                            }"
                            @click="pick(row.name)"
                        >
                            {{ t.rename_action }}
                        </button>
                    </li>
                </ul>

                <p
                    v-if="rows.length === 200"
                    class="mt-2 text-[11px]"
                    :style="{ color: 'var(--ds-text-faint)' }"
                >
                    {{ t.inventory_cap }}
                </p>
            </section>

            <!-- The rewrite row (J03–J08): typed control, consequence BEFORE
                 save, the confirmation weights on the primary. -->
            <section
                v-if="from !== null && !readonly"
                data-frame="J08-rewrite"
                data-testid="rewrite-card"
                class="mt-6 rounded-xl border p-5"
                :style="{ borderColor: 'var(--ds-border)', background: 'var(--ds-panel-bg)' }"
            >
                <h2 class="text-sm font-semibold">{{ t.rewrite_title }}</h2>

                <div class="mt-3 flex items-start justify-between gap-4">
                    <div class="min-w-0">
                        <span class="block text-sm font-medium">{{ t.from_label }}</span>
                        <p class="mt-0.5 text-xs" :style="{ color: 'var(--ds-text-muted)' }">
                            {{ from }}
                        </p>
                    </div>
                    <div class="w-56">
                        <label class="block text-sm font-medium" for="tag-to">{{
                            t.to_label
                        }}</label>
                        <input
                            id="tag-to"
                            v-model="to"
                            type="text"
                            maxlength="48"
                            data-testid="tag-to"
                            class="mt-1 w-full rounded-lg border px-3 py-2 text-sm"
                            :style="{
                                borderColor: 'var(--ds-border-strong)',
                                background: 'var(--ds-panel-soft)',
                                color: 'var(--ds-text-primary)',
                            }"
                        />
                        <p class="mt-1 text-[11px]" :style="{ color: 'var(--ds-text-faint)' }">
                            {{ t.to_help }}
                        </p>
                    </div>
                </div>

                <!-- J08: the consequence in nouns, BEFORE save. -->
                <p
                    v-if="fromRow !== null && to.trim() !== '' && to.trim() !== from"
                    data-testid="rewrite-consequence"
                    class="mt-3 rounded-lg border px-3 py-2 text-xs"
                    :style="{
                        borderColor: 'var(--ds-border-soft)',
                        background: 'var(--ds-inset-bg)',
                        color: 'var(--ds-text-secondary)',
                    }"
                >
                    {{
                        label('consequence', {
                            from: from ?? '',
                            to: to.trim(),
                            count: String(fromRow.count),
                        })
                    }}
                </p>

                <!-- The heaviest confirmation weight: an irreversible MERGE
                     takes the typed phrase (the target tag itself). -->
                <div v-if="isMerge && targetRow !== null" class="mt-3">
                    <p
                        data-testid="merge-notice"
                        class="rounded-lg border px-3 py-2 text-xs"
                        :style="{
                            borderColor: 'var(--ds-warn-strong, var(--ds-border-strong))',
                            color: 'var(--ds-warn-strong, var(--ds-text-primary))',
                        }"
                    >
                        {{
                            label('merge_notice', {
                                to: to.trim(),
                                count: String(targetRow.count),
                            })
                        }}
                    </p>
                    <label
                        class="mt-2 block text-xs"
                        :style="{ color: 'var(--ds-text-secondary)' }"
                    >
                        {{ label('merge_phrase_label', { to: to.trim() }) }}
                        <input
                            v-model="phrase"
                            type="text"
                            data-testid="merge-phrase"
                            class="ms-1 w-40 rounded-lg border px-2 py-1 text-xs"
                            :style="{ borderColor: 'var(--ds-border-strong)' }"
                        />
                    </label>
                </div>

                <!-- B2-S06's inline refusal — in place, never a dialog. -->
                <p
                    v-if="refusal !== ''"
                    role="alert"
                    data-testid="rewrite-refusal"
                    class="mt-3 rounded-lg border px-3 py-2 text-xs"
                    :style="{
                        borderColor: 'var(--ds-danger-strong)',
                        color: 'var(--ds-danger-strong)',
                    }"
                >
                    {{ refusal }}
                </p>

                <div class="mt-4 flex justify-end gap-2">
                    <button
                        type="button"
                        data-testid="rewrite-cancel"
                        class="min-h-[44px] rounded-lg border px-4 text-sm"
                        :style="{ borderColor: 'var(--ds-border)' }"
                        @click="cancel"
                    >
                        {{ t.cancel }}
                    </button>
                    <button
                        type="button"
                        data-testid="rewrite-confirm"
                        class="min-h-[44px] rounded-lg px-4 text-sm font-semibold"
                        :style="{
                            background: isMerge ? 'var(--ds-danger-strong)' : 'var(--ds-text-ink)',
                            color: 'var(--ds-page-bg)',
                            opacity: confirmBlocked || busy ? 0.4 : 1,
                        }"
                        :disabled="confirmBlocked || busy"
                        @click="void execute()"
                    >
                        {{ busy ? t.working : isMerge ? t.merge_verb : t.rename_verb }}
                    </button>
                </div>
            </section>
        </div>
    </div>
</template>
