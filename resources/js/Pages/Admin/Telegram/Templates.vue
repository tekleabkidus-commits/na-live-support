<script setup lang="ts">
// B6-S05 — Bot messages & commands (FR-25/FR-28; rows E07–E11). The shipped
// wording is always visible beside the operator's version (edit = comparison);
// the preview is a real message bubble; the five commands are FIXED in v1 and
// the screen says so; /language offers only complete languages, shown as a
// readiness checklist (E10). <1024px is read-only behind the explicit notice.
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps<{
    bot: { username: string };
    requiredKeys: string[];
    templates: Record<string, Record<string, { body: string; edited: boolean }>>;
    offeredLanguages: string[];
    commands: string[];
    variables: string[];
    t: Record<string, string>;
}>();

const lang = ref<'en' | 'am'>('en');
const selectedKey = ref(props.requiredKeys[0] ?? 'greeting');
const publishOpen = ref(false);

const wide = ref(true);
let mq: MediaQueryList | null = null;
const onMq = (e: MediaQueryListEvent | MediaQueryList): void => {
    wide.value = e.matches;
};
onMounted(() => {
    mq = window.matchMedia('(min-width: 1024px)');
    onMq(mq);
    mq.addEventListener('change', onMq);
});
onBeforeUnmount(() => mq?.removeEventListener('change', onMq));
const readonly = computed(() => !wide.value);

const fill = (template: string, values: Record<string, string | number>): string =>
    Object.entries(values).reduce((s, [k, v]) => s.replaceAll(`:${k}`, String(v)), template);

const current = computed(() => props.templates[lang.value]?.[selectedKey.value] ?? null);
const shipped = computed(() => props.templates.en?.[selectedKey.value] ?? null);
const missing = computed(() => current.value === null);

const editorText = ref('');
const syncEditor = (): void => {
    editorText.value = current.value?.body ?? '';
};
onMounted(syncEditor);
const select = (key: string): void => {
    selectedKey.value = key;
    syncEditor();
};
const switchLang = (l: 'en' | 'am'): void => {
    lang.value = l;
    syncEditor();
};

// E08 — unknown-variable detection: anything in braces beyond the real set.
const unknownVars = computed(() => {
    const found = [...editorText.value.matchAll(/\{\{\s*(\w+)\s*\}\}/g)]
        .map((m) => m[1])
        .filter((v): v is string => typeof v === 'string');
    return [...new Set(found.filter((v) => !props.variables.includes(v)))];
});

// The bubble preview with sample values (drawn: variables filled).
const preview = computed(() =>
    editorText.value
        .replaceAll(/\{\{\s*first_name\s*\}\}/g, props.t.sample_first_name ?? '')
        .replaceAll(/\{\{\s*queue_wait\s*\}\}/g, props.t.sample_queue_wait ?? ''),
);

const insert = (v: string): void => {
    if (!readonly.value) editorText.value += `{{${v}}}`;
};

const missingCount = (l: string): number =>
    props.requiredKeys.filter((k) => !props.templates[l]?.[k]).length;

const editedCount = computed(
    () =>
        Object.values(props.templates)
            .flatMap((set) => Object.values(set))
            .filter((x) => x.edited).length,
);

const languageName = (l: string): string => (l === 'am' ? 'አማርኛ' : 'English');
</script>

<template>
    <div
        class="min-h-screen"
        :style="{ background: 'var(--ds-page-bg)', color: 'var(--ds-text-primary)' }"
    >
        <div class="mx-auto max-w-[1180px] px-4 py-6 sm:px-6">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="text-xs" :style="{ color: 'var(--ds-text-muted)' }">
                        @{{ bot.username }}
                    </p>
                    <h1 class="text-lg font-semibold">{{ t.title }}</h1>
                </div>
                <div v-if="!readonly" class="flex items-center gap-2">
                    <button
                        type="button"
                        class="min-h-[44px] rounded-lg border px-4 text-sm font-medium"
                        :style="{
                            borderColor: 'var(--ds-border-strong)',
                            color: 'var(--ds-text-secondary)',
                        }"
                    >
                        {{ t.send_test }}
                    </button>
                    <button
                        type="button"
                        data-action="publish-templates"
                        class="min-h-[44px] rounded-lg px-4 text-sm font-semibold"
                        :style="{
                            background: 'var(--ds-accent)',
                            color: 'var(--ds-accent-contrast)',
                        }"
                        @click="publishOpen = true"
                    >
                        {{ t.publish_changes }}
                    </button>
                </div>
            </div>

            <!-- read-only notice below 1024 (the E13/J19 pattern, owner ruling) -->
            <p
                v-if="readonly"
                data-frame="E07-readonly"
                class="mt-3 rounded-lg border px-3 py-2 text-xs"
                :style="{
                    borderColor: 'var(--ds-border-strong)',
                    background: 'var(--ds-inset-bg)',
                    color: 'var(--ds-text-muted)',
                }"
            >
                <span class="font-medium" :style="{ color: 'var(--ds-text-primary)' }">{{
                    t.readonly_notice
                }}</span>
                {{ t.readonly_body }}
            </p>

            <!-- language tabs (approved on B6-S04) -->
            <div class="mt-4 flex items-center gap-2" role="tablist">
                <button
                    v-for="l in ['en', 'am'] as const"
                    :key="l"
                    role="tab"
                    type="button"
                    :aria-selected="lang === l"
                    class="min-h-[44px] rounded-lg border px-4 text-sm font-medium"
                    :style="
                        lang === l
                            ? {
                                  background: 'var(--ds-accent)',
                                  color: 'var(--ds-accent-contrast)',
                                  borderColor: 'var(--ds-accent)',
                              }
                            : {
                                  borderColor: 'var(--ds-border-strong)',
                                  color: 'var(--ds-text-secondary)',
                              }
                    "
                    @click="switchLang(l)"
                >
                    {{ languageName(l) }}
                    <span
                        class="ml-1 rounded-full px-2 py-0.5 text-[10px] font-semibold"
                        :style="
                            missingCount(l) > 0
                                ? { background: 'var(--ds-warn-bg)', color: 'var(--ds-warn-text)' }
                                : {
                                      background: 'var(--ds-inset-bg)',
                                      color: 'var(--ds-text-muted)',
                                  }
                        "
                        >{{ requiredKeys.length - missingCount(l) }}/{{ requiredKeys.length }}</span
                    >
                </button>
            </div>

            <div
                class="mt-4 grid gap-5"
                :class="wide ? 'grid-cols-[250px_1fr_292px]' : 'grid-cols-1'"
            >
                <!-- E07 — the six messages, by job -->
                <section
                    data-frame="E07"
                    class="rounded-xl border p-3"
                    :style="{ borderColor: 'var(--ds-border)', background: 'var(--ds-panel-bg)' }"
                >
                    <h2
                        class="text-xs font-semibold"
                        :style="{ color: 'var(--ds-text-secondary)' }"
                    >
                        {{ t.list_title }}
                    </h2>
                    <ul class="mt-2 space-y-1">
                        <li v-for="key in requiredKeys" :key="key">
                            <button
                                type="button"
                                :data-template="key"
                                class="w-full rounded-lg border px-3 py-2 text-start"
                                :style="
                                    selectedKey === key
                                        ? {
                                              borderColor: 'var(--ds-accent)',
                                              borderWidth: '2px',
                                              background: 'var(--ds-panel-soft)',
                                          }
                                        : {
                                              borderColor: 'var(--ds-border)',
                                              background: 'var(--ds-panel-bg)',
                                          }
                                "
                                @click="select(key)"
                            >
                                <span class="flex items-center justify-between gap-2">
                                    <span class="text-xs font-medium">{{ t[`job_${key}`] }}</span>
                                    <span
                                        v-if="templates[lang]?.[key]?.edited"
                                        class="rounded-full px-2 py-0.5 text-[10px]"
                                        :style="{
                                            background: 'var(--ds-inset-bg)',
                                            color: 'var(--ds-text-secondary)',
                                        }"
                                        >{{ t.edited_badge }}</span
                                    >
                                    <span
                                        v-else-if="!templates.am?.[key]"
                                        class="rounded-full px-2 py-0.5 text-[10px] font-semibold"
                                        :style="{
                                            background: 'var(--ds-warn-bg)',
                                            color: 'var(--ds-warn-text)',
                                        }"
                                        >{{
                                            fill(t.missing_badge ?? '', { language: 'አማርኛ' })
                                        }}</span
                                    >
                                </span>
                                <span
                                    class="mt-0.5 block font-mono text-[10px]"
                                    :style="{ color: 'var(--ds-text-faint)' }"
                                    >{{ key }}</span
                                >
                            </button>
                        </li>
                    </ul>
                </section>

                <!-- the editor (E07/E08/E11) -->
                <section
                    class="rounded-xl border p-4"
                    :style="{ borderColor: 'var(--ds-border)', background: 'var(--ds-panel-bg)' }"
                >
                    <h2 class="text-sm font-semibold">{{ t[`job_${selectedKey}`] }}</h2>
                    <p class="font-mono text-[10px]" :style="{ color: 'var(--ds-text-faint)' }">
                        {{ selectedKey }} · {{ lang }}
                    </p>

                    <!-- E11 — the missing-translation state -->
                    <div
                        v-if="missing"
                        data-frame="E11"
                        role="status"
                        class="mt-3 rounded-lg border px-3 py-2 text-xs"
                        :style="{
                            borderColor: 'var(--ds-warn-text)',
                            background: 'var(--ds-warn-bg)',
                            color: 'var(--ds-warn-text)',
                        }"
                    >
                        <strong>{{
                            fill(t.missing_title ?? '', { language: languageName(lang) })
                        }}</strong>
                        {{ t.missing_body }}
                    </div>

                    <label
                        class="mt-3 block text-xs font-medium"
                        for="template-editor"
                        :style="{ color: 'var(--ds-text-secondary)' }"
                        >{{ t.your_wording }}</label
                    >
                    <textarea
                        id="template-editor"
                        v-model="editorText"
                        rows="4"
                        :disabled="readonly"
                        :placeholder="missing ? t.write_here : undefined"
                        class="mt-1 w-full rounded-lg border px-3 py-2 text-sm"
                        :style="{
                            borderColor: 'var(--ds-border-strong)',
                            borderStyle: missing && !editorText ? 'dashed' : 'solid',
                            background: readonly ? 'var(--ds-inset-bg)' : 'var(--ds-panel-soft)',
                            color: 'var(--ds-text-primary)',
                        }"
                    ></textarea>

                    <!-- E08 — variable insertion + count -->
                    <div class="mt-2 flex flex-wrap items-center gap-2 text-xs">
                        <span :style="{ color: 'var(--ds-text-muted)' }">{{ t.insert_label }}</span>
                        <button
                            v-for="v in variables"
                            :key="v"
                            type="button"
                            :disabled="readonly"
                            class="rounded-full border px-2.5 py-1 font-mono text-[11px]"
                            :style="{
                                borderColor: 'var(--ds-border-strong)',
                                color: 'var(--ds-text-secondary)',
                            }"
                            @click="insert(v)"
                            v-text="'{{' + v + '}}'"
                        ></button>
                        <span class="ms-auto" :style="{ color: 'var(--ds-text-faint)' }">{{
                            fill(t.characters ?? '', { count: editorText.length })
                        }}</span>
                    </div>

                    <!-- E08 — unknown-variable error -->
                    <div
                        v-if="unknownVars.length"
                        data-frame="E08-error"
                        role="alert"
                        class="mt-3 rounded-lg border px-3 py-2 text-xs"
                        :style="{
                            borderColor: 'var(--ds-danger-border)',
                            background: 'var(--ds-danger-bg)',
                            color: 'var(--ds-danger-text)',
                        }"
                    >
                        <strong>{{ t.unknown_var_title }}</strong> {{ t.unknown_var_body }}
                        <button
                            v-if="!readonly"
                            type="button"
                            class="mt-1 block rounded-lg border px-2.5 py-1 font-medium"
                            :style="{
                                borderColor: 'var(--ds-danger-border)',
                                color: 'var(--ds-danger-text)',
                            }"
                        >
                            {{ t.use_instead }}
                        </button>
                    </div>

                    <!-- shipped wording beside the operator's (the screen's first rule) -->
                    <div
                        class="mt-4 rounded-lg border p-3"
                        :style="{
                            borderColor: 'var(--ds-border)',
                            background: 'var(--ds-inset-bg)',
                        }"
                    >
                        <div class="flex items-center justify-between">
                            <span
                                class="text-[10px] font-semibold uppercase tracking-wide"
                                :style="{ color: 'var(--ds-text-muted)' }"
                                >{{ t.shipped_wording }}</span
                            >
                            <button
                                v-if="!readonly"
                                type="button"
                                class="min-h-[44px] rounded-lg border px-3 text-xs font-medium"
                                :style="{
                                    borderColor: 'var(--ds-border-strong)',
                                    color: 'var(--ds-text-secondary)',
                                }"
                                @click="editorText = shipped?.body ?? ''"
                            >
                                {{ missing ? t.copy_as_start : t.reset_to_this }}
                            </button>
                        </div>
                        <p class="mt-1 text-xs" :style="{ color: 'var(--ds-text-secondary)' }">
                            {{ shipped?.body }}
                        </p>
                        <p
                            v-if="!templates[lang]?.[selectedKey]?.edited && !missing"
                            class="mt-1 text-[11px]"
                            :style="{ color: 'var(--ds-text-faint)' }"
                        >
                            {{ t.never_customised }}
                        </p>
                    </div>

                    <!-- the real bubble preview -->
                    <h3
                        class="mt-4 text-xs font-semibold"
                        :style="{ color: 'var(--ds-text-secondary)' }"
                    >
                        {{ t.how_it_arrives }}
                    </h3>
                    <div
                        data-frame="E07-preview"
                        class="mt-1 rounded-lg border p-3"
                        :style="{
                            borderColor: 'var(--ds-border)',
                            background: 'var(--ds-inset-bg)',
                        }"
                    >
                        <div
                            class="max-w-[85%] rounded-xl rounded-bl-sm border px-3 py-2 text-xs leading-5"
                            :style="{
                                borderColor: 'var(--ds-border)',
                                background: 'var(--ds-panel-bg)',
                                color: 'var(--ds-text-primary)',
                            }"
                        >
                            {{ preview }}
                        </div>
                        <p class="mt-1 text-[10px]" :style="{ color: 'var(--ds-text-faint)' }">
                            @{{ bot.username }}
                        </p>
                    </div>
                    <p class="mt-1 text-[11px]" :style="{ color: 'var(--ds-text-faint)' }">
                        {{ t.preview_note }}
                    </p>
                    <p
                        v-if="missing && editorText"
                        class="mt-1 text-[11px]"
                        :style="{ color: 'var(--ds-warn-text)' }"
                    >
                        {{ t.proposed_note }}
                    </p>
                </section>

                <!-- sidebar: variables + propagation -->
                <div class="space-y-4">
                    <section
                        class="rounded-xl border p-4"
                        :style="{
                            borderColor: 'var(--ds-border)',
                            background: 'var(--ds-panel-bg)',
                        }"
                    >
                        <h2 class="text-xs font-semibold">{{ t.variables_title }}</h2>
                        <dl class="mt-2 space-y-2 text-xs">
                            <div>
                                <dt class="font-mono text-[11px]" v-text="'{{first_name}}'"></dt>
                                <dd :style="{ color: 'var(--ds-text-muted)' }">
                                    {{ t.var_first_name }}
                                </dd>
                            </div>
                            <div>
                                <dt class="font-mono text-[11px]" v-text="'{{queue_wait}}'"></dt>
                                <dd :style="{ color: 'var(--ds-text-muted)' }">
                                    {{ t.var_queue_wait }}
                                </dd>
                            </div>
                        </dl>
                        <p class="mt-2 text-[11px]" :style="{ color: 'var(--ds-text-faint)' }">
                            {{ t.variables_note }}
                        </p>
                    </section>
                    <section
                        class="rounded-xl border p-4"
                        :style="{
                            borderColor: 'var(--ds-border)',
                            background: 'var(--ds-panel-soft)',
                        }"
                    >
                        <h2 class="text-xs font-semibold">{{ t.propagation_title }}</h2>
                        <p class="mt-1 text-[11px]" :style="{ color: 'var(--ds-text-muted)' }">
                            {{ t.propagation_body }}
                        </p>
                    </section>
                </div>
            </div>

            <!-- §C — E09 commands + E10 /language gate -->
            <div class="mt-6 grid gap-5" :class="wide ? 'grid-cols-2' : 'grid-cols-1'">
                <section
                    data-frame="E09"
                    class="rounded-xl border p-4"
                    :style="{ borderColor: 'var(--ds-border)', background: 'var(--ds-panel-bg)' }"
                >
                    <h2 class="text-sm font-semibold">{{ t.commands_title }}</h2>
                    <!-- §13 (the S18 lesson): scrollable regions stay keyboard-reachable. -->
                    <div
                        class="mt-2 overflow-x-auto"
                        tabindex="0"
                        role="region"
                        :aria-label="t.commands_title"
                    >
                        <table class="w-full text-xs">
                            <thead>
                                <tr
                                    class="text-start text-[10px] uppercase tracking-wide"
                                    :style="{ color: 'var(--ds-text-muted)' }"
                                >
                                    <th class="py-1.5 text-start">{{ t.col_command }}</th>
                                    <th class="py-1.5 text-start">{{ t.col_desc_en }}</th>
                                    <th class="py-1.5 text-start">{{ t.col_desc_am }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y" :style="{ borderColor: 'var(--ds-border)' }">
                                <tr v-for="c in commands" :key="c">
                                    <td class="py-2 font-mono">/{{ c }}</td>
                                    <td class="py-2">{{ t[`cmd_${c}`] }}</td>
                                    <td class="py-2">{{ t[`cmd_${c}_am`] ?? t[`cmd_${c}`] }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div
                        class="mt-3 rounded-lg border p-3 text-xs"
                        :style="{
                            borderColor: 'var(--ds-border)',
                            background: 'var(--ds-inset-bg)',
                        }"
                    >
                        <p class="font-medium">{{ t.fixed_title }}</p>
                        <p class="mt-0.5" :style="{ color: 'var(--ds-text-muted)' }">
                            {{ t.fixed_body }}
                        </p>
                    </div>
                </section>

                <section
                    data-frame="E10"
                    class="rounded-xl border p-4"
                    :style="{ borderColor: 'var(--ds-border)', background: 'var(--ds-panel-bg)' }"
                >
                    <h2 class="text-sm font-semibold">{{ t.gate_title }}</h2>
                    <ul class="mt-2 space-y-2">
                        <li
                            v-for="l in ['en', 'am']"
                            :key="`gate-${l}`"
                            class="flex items-center justify-between rounded-lg border px-3 py-2 text-xs"
                            :style="{ borderColor: 'var(--ds-border)' }"
                        >
                            <span>
                                <span class="font-medium">{{ languageName(l) }}</span>
                                <span class="ms-2" :style="{ color: 'var(--ds-text-muted)' }"
                                    >{{ requiredKeys.length - missingCount(l) }}/{{
                                        requiredKeys.length
                                    }}</span
                                >
                            </span>
                            <span
                                :data-offered="offeredLanguages.includes(l)"
                                class="rounded-full px-2.5 py-1 text-[10px] font-semibold"
                                :style="
                                    offeredLanguages.includes(l)
                                        ? {
                                              background: 'var(--ds-success-bg)',
                                              color: 'var(--ds-success-text)',
                                          }
                                        : {
                                              background: 'var(--ds-warn-bg)',
                                              color: 'var(--ds-warn-text)',
                                          }
                                "
                                >{{
                                    offeredLanguages.includes(l) ? `✓ ${t.offered}` : t.not_offered
                                }}</span
                            >
                        </li>
                    </ul>
                    <p class="mt-2 text-[11px]" :style="{ color: 'var(--ds-text-muted)' }">
                        {{ t.gate_note }}
                    </p>
                    <a
                        v-if="!readonly"
                        href="#"
                        class="mt-1 inline-block text-xs font-medium underline"
                        :style="{ color: 'var(--ds-text-secondary)' }"
                        >{{ t.fill_gaps }}</a
                    >
                </section>
            </div>

            <!-- publish confirmation (§B) -->
            <div
                v-if="publishOpen"
                role="dialog"
                aria-modal="true"
                :aria-label="fill(t.publish_title ?? '', { count: editedCount })"
                class="fixed inset-0 z-40 flex items-center justify-center p-4"
                style="background: rgba(0, 0, 0, 0.4)"
            >
                <div
                    class="w-full max-w-md rounded-xl border p-5"
                    :style="{ borderColor: 'var(--ds-border)', background: 'var(--ds-panel-bg)' }"
                >
                    <h2 class="text-base font-semibold">
                        {{ fill(t.publish_title ?? '', { count: editedCount }) }}
                    </h2>
                    <p
                        class="mt-2 rounded-lg border p-3 text-xs"
                        :style="{
                            borderColor: 'var(--ds-border)',
                            background: 'var(--ds-inset-bg)',
                            color: 'var(--ds-text-muted)',
                        }"
                    >
                        {{ t.publish_note }}
                    </p>
                    <div class="mt-4 flex justify-end gap-2">
                        <button
                            type="button"
                            class="min-h-[44px] rounded-lg px-4 text-sm"
                            :style="{ color: 'var(--ds-text-secondary)' }"
                            @click="publishOpen = false"
                        >
                            {{ t.keep_editing ?? '' }}
                        </button>
                        <button
                            type="button"
                            class="min-h-[44px] rounded-lg px-4 text-sm font-semibold"
                            :style="{
                                background: 'var(--ds-accent)',
                                color: 'var(--ds-accent-contrast)',
                            }"
                            @click="publishOpen = false"
                        >
                            {{ t.publish_changes }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
