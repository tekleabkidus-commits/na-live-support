<script setup lang="ts">
// B6-S04 — Menu designer (FR-27; rows E01–E06, E12, E13, §K). Builder +
// dual preview (button render / numbered business text — neither is "the
// real one"); per-language tabs with completeness badges; versioned publish
// naming affected bots; E13: <1024px is READ-ONLY behind the explicit
// notice (owner ruling, Doc 05 §9 holds). §K: every drag has a keyboard
// equivalent — Space picks up, arrows move, Esc restores, live region
// announces position. Tokens only, both colourways.
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

type MenuButton = { label: string; label_am: string | null; target_key: string };
type MenuRow = { row: number; buttons: MenuButton[] };

const props = defineProps<{
    bot: { username: string };
    definition: MenuRow[];
    versions: { version: number; live: boolean; at: string }[];
    targets: { key: string; type: string; ref: string | null }[];
    buttonsUsed: number;
    amComplete: number;
    amTotal: number;
    t: Record<string, string>;
}>();

const rows = ref<MenuRow[]>(JSON.parse(JSON.stringify(props.definition)));
const lang = ref<'en' | 'am'>('en');
const selected = ref<{ row: number; idx: number } | null>(
    props.definition.length ? { row: 0, idx: 0 } : null,
);
const publishOpen = ref(false);
const historyOpen = ref(false);
const announcement = ref('');

// E13 — the ≥1024 rule (Doc 05 §9; owner ruling: 375 is read-only).
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

const totalButtons = computed(() => rows.value.reduce((n, r) => n + r.buttons.length, 0));
const fullRows = computed(() => rows.value.filter((r) => r.buttons.length >= 3).map((r) => r.row));
const missingAm = computed(
    () => rows.value.flatMap((r) => r.buttons).filter((b) => !b.label_am).length,
);

const targetType = (key: string): string => {
    const target = props.targets.find((x) => x.key === key);
    const map: Record<string, string> = {
        kb_flow: props.t.target_kb_flow ?? '',
        form: props.t.target_form ?? '',
        ai_chat: props.t.target_ai_chat ?? '',
        human_handoff: props.t.target_human_handoff ?? '',
        url: props.t.target_url ?? '',
    };
    return target ? (map[target.type] ?? target.type) : '';
};

const label = (b: MenuButton): string => (lang.value === 'am' && b.label_am ? b.label_am : b.label);
const untranslated = (b: MenuButton): boolean => lang.value === 'am' && !b.label_am;

const selectedButton = computed(() =>
    selected.value ? (rows.value[selected.value.row]?.buttons[selected.value.idx] ?? null) : null,
);

const flatButtons = computed(() => rows.value.flatMap((r) => r.buttons.map((b) => label(b))));

// §14/C4: numbered menus respect locale digits — the number formats through
// the MENU language's own locale (am-ET renders Western digits by Ethiopian
// standard usage; a future locale with native digits inherits them here
// without a code change).
const localeDigit = (n: number): string =>
    new Intl.NumberFormat(lang.value === 'am' ? 'am-ET' : 'en').format(n);

// ── §K keyboard reorder (row E12) ─────────────────────────────────────────
const grabbed = ref<{ row: number; idx: number } | null>(null);

const fill = (template: string, values: Record<string, string | number>): string =>
    Object.entries(values).reduce((s, [k, v]) => s.replaceAll(`:${k}`, String(v)), template);

function announcePosition(key: string, row: number, idx: number): void {
    const b = rows.value[row]?.buttons[idx];
    if (!b) return;
    announcement.value = fill(props.t[key] ?? '', {
        label: label(b),
        row: row + 1,
        position: idx + 1,
        count: rows.value[row]?.buttons.length ?? 0,
    });
}

let restore: MenuRow[] | null = null;

function toggleGrab(row: number, idx: number): void {
    if (readonly.value) return;
    if (grabbed.value && grabbed.value.row === row && grabbed.value.idx === idx) {
        grabbed.value = null;
        restore = null;
        announcePosition('sr_dropped', row, idx);
        return;
    }
    grabbed.value = { row, idx };
    restore = JSON.parse(JSON.stringify(rows.value));
    announcePosition('sr_grabbed', row, idx);
}

function moveGrabbed(dRow: number, dIdx: number): void {
    if (!grabbed.value) return;
    const { row, idx } = grabbed.value;
    const src = rows.value[row];
    if (!src) return;
    const targetRow = row + dRow;
    if (dRow !== 0) {
        const dst = rows.value[targetRow];
        if (!dst || dst.buttons.length >= 3) return; // FR-27: rows of ≤3
        const [b] = src.buttons.splice(idx, 1);
        if (!b) return;
        const at = Math.min(idx, dst.buttons.length);
        dst.buttons.splice(at, 0, b);
        grabbed.value = { row: targetRow, idx: at };
        announcePosition('sr_moved', targetRow, at);
        return;
    }
    const to = idx + dIdx;
    if (to < 0 || to >= src.buttons.length) return;
    const [b] = src.buttons.splice(idx, 1);
    if (!b) return;
    src.buttons.splice(to, 0, b);
    grabbed.value = { row, idx: to };
    announcePosition('sr_moved', row, to);
}

function cancelGrab(): void {
    if (!grabbed.value || !restore) return;
    const b = rows.value[grabbed.value.row]?.buttons[grabbed.value.idx];
    rows.value = restore;
    restore = null;
    grabbed.value = null;
    announcement.value = fill(props.t.sr_cancelled ?? '', { label: b ? label(b) : '' });
}

function onGripKey(e: KeyboardEvent, row: number, idx: number): void {
    if (e.key === ' ' || e.key === 'Space') {
        e.preventDefault();
        toggleGrab(row, idx);
    } else if (grabbed.value) {
        if (e.key === 'ArrowUp') {
            e.preventDefault();
            moveGrabbed(-1, 0);
        } else if (e.key === 'ArrowDown') {
            e.preventDefault();
            moveGrabbed(1, 0);
        } else if (e.key === 'ArrowLeft') {
            e.preventDefault();
            moveGrabbed(0, -1);
        } else if (e.key === 'ArrowRight') {
            e.preventDefault();
            moveGrabbed(0, 1);
        } else if (e.key === 'Escape') {
            e.preventDefault();
            cancelGrab();
        }
    }
}

function moveRow(index: number, delta: number): void {
    if (readonly.value) return;
    const to = index + delta;
    if (to < 0 || to >= rows.value.length) return;
    const [r] = rows.value.splice(index, 1);
    if (!r) return;
    rows.value.splice(to, 0, r);
}
</script>

<template>
    <div
        class="min-h-screen"
        :style="{ background: 'var(--ds-page-bg)', color: 'var(--ds-text-primary)' }"
    >
        <div class="mx-auto max-w-[1180px] px-4 py-6 sm:px-6">
            <!-- §13: reorder announcements -->
            <div aria-live="polite" class="sr-only" data-testid="reorder-live">
                {{ announcement }}
            </div>

            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="text-xs" :style="{ color: 'var(--ds-text-muted)' }">
                        @{{ bot.username }}
                    </p>
                    <h1 class="text-lg font-semibold">{{ t.title }}</h1>
                </div>
                <div v-if="!readonly" class="flex items-center gap-2">
                    <span
                        class="rounded-full border px-3 py-1 text-xs"
                        :style="{
                            borderColor: 'var(--ds-border)',
                            background: 'var(--ds-inset-bg)',
                            color: 'var(--ds-text-secondary)',
                        }"
                        >{{ t.draft_unsaved }}</span
                    >
                    <button
                        type="button"
                        class="min-h-[44px] rounded-lg border px-4 text-sm font-medium"
                        :style="{
                            borderColor: 'var(--ds-border-strong)',
                            color: 'var(--ds-text-secondary)',
                        }"
                        @click="historyOpen = !historyOpen"
                    >
                        {{ t.version_history }}
                    </button>
                    <button
                        type="button"
                        data-action="publish"
                        class="min-h-[44px] rounded-lg px-4 text-sm font-semibold"
                        :style="{
                            background: 'var(--ds-accent)',
                            color: 'var(--ds-accent-contrast)',
                        }"
                        @click="publishOpen = true"
                    >
                        {{ t.publish }}
                    </button>
                </div>
            </div>

            <!-- E13 — read-only notice below 1024 (owner ruling) -->
            <p
                v-if="readonly"
                data-frame="E13"
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

            <!-- E04 — language tabs with completeness badges -->
            <div data-frame="E04" class="mt-4 flex items-center gap-2" role="tablist">
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
                    @click="lang = l"
                >
                    {{ l === 'en' ? 'English' : 'አማርኛ' }}
                    <span
                        class="ms-1 rounded-full px-2 py-0.5 text-[10px] font-semibold"
                        :style="
                            (l === 'am' ? amComplete < amTotal : false)
                                ? { background: 'var(--ds-warn-bg)', color: 'var(--ds-warn-text)' }
                                : {
                                      background: 'var(--ds-inset-bg)',
                                      color: 'var(--ds-text-muted)',
                                  }
                        "
                        >{{
                            l === 'am' ? `${amComplete}/${amTotal}` : `${amTotal}/${amTotal}`
                        }}</span
                    >
                </button>
                <span class="ms-auto text-xs" :style="{ color: 'var(--ds-text-muted)' }">{{
                    fill(t.buttons_used ?? '', { used: totalButtons })
                }}</span>
            </div>
            <p
                v-if="lang === 'am' && missingAm > 0"
                class="mt-2 text-xs"
                :style="{ color: 'var(--ds-warn-text)' }"
            >
                {{ fill(t.am_fallback_note ?? '', { count: missingAm }) }}
            </p>

            <!-- E01 limits-reached banners (drawn states) -->
            <div
                v-if="totalButtons >= 12"
                role="status"
                class="mt-3 rounded-lg border px-3 py-2 text-xs"
                :style="{
                    borderColor: 'var(--ds-warn-text)',
                    background: 'var(--ds-warn-bg)',
                    color: 'var(--ds-warn-text)',
                }"
            >
                <strong>{{ t.limit_buttons_title }}</strong> {{ t.limit_buttons_body }}
            </div>
            <div
                v-for="fullRow in fullRows"
                :key="`full-${fullRow}`"
                role="status"
                class="mt-2 rounded-lg border px-3 py-2 text-xs"
                :style="{
                    borderColor: 'var(--ds-warn-text)',
                    background: 'var(--ds-warn-bg)',
                    color: 'var(--ds-warn-text)',
                }"
            >
                <strong>{{ fill(t.limit_row_title ?? '', { row: fullRow }) }}</strong>
                {{ fill(t.limit_row_body ?? '', { row: fullRow, next: fullRow + 1 }) }}
            </div>

            <div class="mt-4 grid gap-5" :class="wide ? 'grid-cols-[1.25fr_1fr]' : 'grid-cols-1'">
                <!-- E01 — the builder -->
                <section
                    v-if="rows.length"
                    data-frame="E01"
                    class="rounded-xl border p-4"
                    :style="{ borderColor: 'var(--ds-border)', background: 'var(--ds-panel-bg)' }"
                >
                    <div class="flex items-center justify-between">
                        <div>
                            <h2 class="text-sm font-semibold">{{ t.buttons_title }}</h2>
                            <p class="text-[11px]" :style="{ color: 'var(--ds-text-muted)' }">
                                {{ t.rows_hint }}
                            </p>
                        </div>
                        <button
                            v-if="!readonly"
                            type="button"
                            :disabled="totalButtons >= 12"
                            class="min-h-[44px] rounded-lg border px-3 text-xs font-medium disabled:opacity-50"
                            :style="{
                                borderColor: 'var(--ds-border-strong)',
                                color: 'var(--ds-text-secondary)',
                            }"
                        >
                            {{ t.add_row }}
                        </button>
                    </div>

                    <div
                        v-for="(row, ri) in rows"
                        :key="`row-${ri}`"
                        class="mt-3 rounded-lg border p-3"
                        :style="{
                            borderColor: 'var(--ds-border)',
                            background: 'var(--ds-panel-soft)',
                        }"
                    >
                        <div class="flex items-center justify-between">
                            <span
                                class="text-[10px] font-semibold uppercase tracking-wide"
                                :style="{ color: 'var(--ds-text-muted)' }"
                                >{{ fill(t.row_label ?? '', { row: ri + 1 }) }}</span
                            >
                            <!-- E12 — move buttons beside every row -->
                            <span v-if="!readonly" class="flex gap-1">
                                <button
                                    type="button"
                                    :aria-label="fill(t.move_row_up ?? '', { row: ri + 1 })"
                                    :disabled="ri === 0"
                                    class="min-h-[44px] min-w-[44px] rounded-lg border text-sm disabled:opacity-40"
                                    :style="{
                                        borderColor: 'var(--ds-border-strong)',
                                        color: 'var(--ds-text-secondary)',
                                    }"
                                    @click="moveRow(ri, -1)"
                                >
                                    ↑
                                </button>
                                <button
                                    type="button"
                                    :aria-label="fill(t.move_row_down ?? '', { row: ri + 1 })"
                                    :disabled="ri === rows.length - 1"
                                    class="min-h-[44px] min-w-[44px] rounded-lg border text-sm disabled:opacity-40"
                                    :style="{
                                        borderColor: 'var(--ds-border-strong)',
                                        color: 'var(--ds-text-secondary)',
                                    }"
                                    @click="moveRow(ri, 1)"
                                >
                                    ↓
                                </button>
                            </span>
                        </div>

                        <div
                            class="mt-2 grid gap-2"
                            :style="{
                                gridTemplateColumns: `repeat(${Math.max(row.buttons.length, 1)}, minmax(0, 1fr))`,
                            }"
                        >
                            <!-- E02 target subtext; E04 untranslated treatment; §K grip -->
                            <button
                                v-for="(b, bi) in row.buttons"
                                :key="`b-${ri}-${bi}`"
                                type="button"
                                :data-grip="`${ri}-${bi}`"
                                class="rounded-lg border p-2 text-start"
                                :style="{
                                    borderColor:
                                        selected && selected.row === ri && selected.idx === bi
                                            ? 'var(--ds-accent)'
                                            : untranslated(b)
                                              ? 'var(--ds-warn-text)'
                                              : 'var(--ds-border-strong)',
                                    borderWidth:
                                        selected && selected.row === ri && selected.idx === bi
                                            ? '2px'
                                            : '1px',
                                    borderStyle: untranslated(b) ? 'dashed' : 'solid',
                                    background: untranslated(b)
                                        ? 'var(--ds-warn-bg)'
                                        : 'var(--ds-panel-bg)',
                                    outline:
                                        grabbed && grabbed.row === ri && grabbed.idx === bi
                                            ? '2px solid var(--ds-focus-ring)'
                                            : undefined,
                                    outlineOffset: '3px',
                                }"
                                @click="selected = { row: ri, idx: bi }"
                                @keydown="(e) => onGripKey(e, ri, bi)"
                            >
                                <span class="flex items-center gap-1.5 text-sm font-medium">
                                    <span aria-hidden="true" class="cursor-grab select-none"
                                        >⠿</span
                                    >
                                    {{ untranslated(b) ? b.label : label(b) }}
                                    <span
                                        v-if="grabbed && grabbed.row === ri && grabbed.idx === bi"
                                        class="rounded-full px-1.5 text-[10px] font-semibold"
                                        :style="{
                                            background: 'var(--ds-accent)',
                                            color: 'var(--ds-accent-contrast)',
                                        }"
                                        >{{ t.moving_badge }}</span
                                    >
                                </span>
                                <span
                                    class="mt-0.5 block text-[11px]"
                                    :style="{
                                        color: untranslated(b)
                                            ? 'var(--ds-warn-text)'
                                            : 'var(--ds-text-muted)',
                                    }"
                                    >{{
                                        untranslated(b)
                                            ? t.untranslated_badge
                                            : targetType(b.target_key)
                                    }}</span
                                >
                            </button>
                        </div>
                    </div>

                    <!-- selected-button panel (E02) -->
                    <div
                        v-if="selectedButton"
                        class="mt-4 rounded-lg border p-3"
                        :style="{
                            borderColor: 'var(--ds-border)',
                            background: 'var(--ds-panel-bg)',
                        }"
                    >
                        <h3
                            class="text-xs font-semibold"
                            :style="{ color: 'var(--ds-text-secondary)' }"
                        >
                            {{ t.selected_button }} · {{ label(selectedButton) }}
                        </h3>
                        <div class="mt-2 grid grid-cols-2 gap-3 text-xs">
                            <div>
                                <span :style="{ color: 'var(--ds-text-muted)' }">{{
                                    t.label_en
                                }}</span>
                                <p class="font-medium">{{ selectedButton.label }}</p>
                            </div>
                            <div>
                                <span :style="{ color: 'var(--ds-text-muted)' }">{{
                                    t.label_am
                                }}</span>
                                <p class="font-medium">{{ selectedButton.label_am ?? '—' }}</p>
                            </div>
                        </div>
                        <p class="mt-2 text-[11px]" :style="{ color: 'var(--ds-text-muted)' }">
                            {{ t.what_it_does }}
                        </p>
                        <div class="mt-1 flex flex-wrap gap-1.5">
                            <span
                                v-for="type in [
                                    'kb_flow',
                                    'form',
                                    'ai_chat',
                                    'human_handoff',
                                    'url',
                                ]"
                                :key="type"
                                class="rounded-full border px-2.5 py-1 text-[11px] font-medium"
                                :style="
                                    targetType(selectedButton.target_key) === t[`target_${type}`]
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
                                >{{ t[`target_${type}`] }}</span
                            >
                        </div>
                    </div>

                    <!-- §K key map (drawn) -->
                    <dl
                        v-if="!readonly"
                        class="mt-3 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-[11px]"
                        :style="{ color: 'var(--ds-text-muted)' }"
                    >
                        <dt class="font-mono">Space</dt>
                        <dd>{{ t.key_pickup }}</dd>
                        <dt class="font-mono">← → ↑ ↓</dt>
                        <dd>{{ t.key_move }}</dd>
                        <dt class="font-mono">Esc</dt>
                        <dd>{{ t.key_cancel }}</dd>
                    </dl>
                    <p
                        v-if="!readonly"
                        class="mt-1 text-[11px]"
                        :style="{ color: 'var(--ds-text-faint)' }"
                    >
                        {{ t.drag_note }}
                    </p>
                </section>

                <!-- E06 — empty menu -->
                <section
                    v-else
                    data-frame="E06"
                    class="rounded-xl border p-6 text-center"
                    :style="{ borderColor: 'var(--ds-border)', background: 'var(--ds-panel-bg)' }"
                >
                    <h2 class="text-sm font-semibold">{{ t.empty_title }}</h2>
                    <p class="mt-1 text-xs" :style="{ color: 'var(--ds-text-muted)' }">
                        {{ t.empty_body }}
                    </p>
                    <div v-if="!readonly" class="mt-3 flex justify-center gap-2">
                        <button
                            type="button"
                            class="min-h-[44px] rounded-lg px-4 text-sm font-semibold"
                            :style="{
                                background: 'var(--ds-accent)',
                                color: 'var(--ds-accent-contrast)',
                            }"
                        >
                            {{ t.empty_add }}
                        </button>
                        <button
                            type="button"
                            class="min-h-[44px] rounded-lg border px-4 text-sm font-medium"
                            :style="{
                                borderColor: 'var(--ds-border-strong)',
                                color: 'var(--ds-text-secondary)',
                            }"
                        >
                            {{ t.empty_suggestions }}
                        </button>
                    </div>
                </section>

                <!-- E03 — the dual preview, each frame labelled for SR -->
                <div class="space-y-4">
                    <section
                        data-frame="E03-buttons"
                        role="group"
                        :aria-label="t.preview_button_title"
                        class="rounded-xl border p-4"
                        :style="{
                            borderColor: 'var(--ds-border)',
                            background: 'var(--ds-panel-bg)',
                        }"
                    >
                        <h2 class="text-sm font-semibold">{{ t.preview_button_title }}</h2>
                        <p class="text-[11px]" :style="{ color: 'var(--ds-text-muted)' }">
                            {{ t.preview_button_sub }}
                        </p>
                        <div
                            class="mt-3 rounded-lg border p-3"
                            :style="{
                                borderColor: 'var(--ds-border)',
                                background: 'var(--ds-inset-bg)',
                            }"
                        >
                            <div
                                v-for="(row, ri) in rows"
                                :key="`pv-${ri}`"
                                class="mt-1.5 grid gap-1.5 first:mt-0"
                                :style="{
                                    gridTemplateColumns: `repeat(${Math.max(row.buttons.length, 1)}, minmax(0, 1fr))`,
                                }"
                            >
                                <span
                                    v-for="(b, bi) in row.buttons"
                                    :key="`pvb-${ri}-${bi}`"
                                    class="truncate rounded-md border px-2 py-1.5 text-center text-[11px] font-medium"
                                    :style="{
                                        borderColor: 'var(--ds-border-strong)',
                                        background: 'var(--ds-panel-bg)',
                                        color: 'var(--ds-text-primary)',
                                    }"
                                    >{{ untranslated(b) ? b.label : label(b) }}</span
                                >
                            </div>
                        </div>
                    </section>

                    <section
                        data-frame="E03-text"
                        role="group"
                        :aria-label="t.preview_text_title"
                        class="rounded-xl border p-4"
                        :style="{
                            borderColor: 'var(--ds-border)',
                            background: 'var(--ds-panel-bg)',
                        }"
                    >
                        <h2 class="text-sm font-semibold">{{ t.preview_text_title }}</h2>
                        <p class="text-[11px]" :style="{ color: 'var(--ds-text-muted)' }">
                            {{ t.preview_text_sub }}
                        </p>
                        <div
                            class="mt-3 rounded-lg border p-3 text-xs leading-5"
                            :style="{
                                borderColor: 'var(--ds-border)',
                                background: 'var(--ds-inset-bg)',
                                color: 'var(--ds-text-primary)',
                            }"
                        >
                            <p v-for="(l, i) in flatButtons" :key="`line-${i}`">
                                {{ localeDigit(i + 1) }}. {{ l }}
                            </p>
                            <p class="mt-1" :style="{ color: 'var(--ds-text-secondary)' }">
                                {{ t.preview_text_footer }}
                            </p>
                        </div>
                        <p class="mt-2 text-[11px]" :style="{ color: 'var(--ds-text-faint)' }">
                            {{ t.preview_text_note }}
                        </p>
                    </section>

                    <!-- E05 — version history -->
                    <section
                        v-if="historyOpen || readonly"
                        data-frame="E05-history"
                        class="rounded-xl border p-4"
                        :style="{
                            borderColor: 'var(--ds-border)',
                            background: 'var(--ds-panel-bg)',
                        }"
                    >
                        <h2 class="text-sm font-semibold">{{ t.version_history }}</h2>
                        <ul class="mt-2 divide-y" :style="{ borderColor: 'var(--ds-border)' }">
                            <li
                                v-for="v in versions"
                                :key="v.version"
                                class="flex items-center justify-between py-2 text-xs"
                            >
                                <span class="font-medium"
                                    >v{{ v.version }}
                                    <span v-if="v.live" :style="{ color: 'var(--ds-text-muted)' }"
                                        >· {{ t.live_now }}</span
                                    ></span
                                >
                                <span
                                    v-if="v.live"
                                    class="rounded-full px-2 py-0.5 text-[10px] font-semibold"
                                    :style="{
                                        background: 'var(--ds-success-bg)',
                                        color: 'var(--ds-success-text)',
                                    }"
                                    >● {{ t.published_pill }}</span
                                >
                                <button
                                    v-else-if="!readonly"
                                    type="button"
                                    class="min-h-[44px] rounded-lg border px-3 text-xs font-medium"
                                    :style="{
                                        borderColor: 'var(--ds-border-strong)',
                                        color: 'var(--ds-text-secondary)',
                                    }"
                                >
                                    {{ t.restore }}
                                </button>
                            </li>
                        </ul>
                        <p
                            v-if="readonly"
                            class="mt-2 text-[11px]"
                            :style="{ color: 'var(--ds-text-faint)' }"
                        >
                            {{ t.readonly_versions_note }}
                        </p>
                    </section>
                </div>
            </div>

            <!-- E05 — publish confirmation naming affected bots -->
            <div
                v-if="publishOpen"
                data-frame="E05-publish"
                role="dialog"
                aria-modal="true"
                :aria-label="t.publish_title"
                class="fixed inset-0 z-40 flex items-center justify-center p-4"
                style="background: rgba(0, 0, 0, 0.4)"
            >
                <div
                    class="w-full max-w-md rounded-xl border p-5"
                    :style="{ borderColor: 'var(--ds-border)', background: 'var(--ds-panel-bg)' }"
                >
                    <h2 class="text-base font-semibold">{{ t.publish_title }}</h2>
                    <p class="mt-1 text-xs" :style="{ color: 'var(--ds-text-muted)' }">
                        {{ t.publish_sub }}
                    </p>
                    <div
                        class="mt-3 rounded-lg border p-3 text-xs"
                        :style="{
                            borderColor: 'var(--ds-border)',
                            background: 'var(--ds-inset-bg)',
                        }"
                    >
                        <p class="font-medium">{{ t.publish_bots_title }}</p>
                        <p
                            class="mt-1 font-mono text-[11px]"
                            :style="{ color: 'var(--ds-text-secondary)' }"
                        >
                            @{{ bot.username }}
                        </p>
                    </div>
                    <p
                        v-if="missingAm > 0"
                        role="status"
                        class="mt-3 rounded-lg border px-3 py-2 text-xs"
                        :style="{
                            borderColor: 'var(--ds-warn-text)',
                            background: 'var(--ds-warn-bg)',
                            color: 'var(--ds-warn-text)',
                        }"
                    >
                        {{
                            fill(t.publish_incomplete ?? '', {
                                complete: amComplete,
                                total: amTotal,
                            })
                        }}
                    </p>
                    <div class="mt-4 flex justify-end gap-2">
                        <button
                            type="button"
                            class="min-h-[44px] rounded-lg px-4 text-sm"
                            :style="{ color: 'var(--ds-text-secondary)' }"
                            @click="publishOpen = false"
                        >
                            {{ t.keep_editing }}
                        </button>
                        <button
                            type="button"
                            data-action="publish-confirm"
                            class="min-h-[44px] rounded-lg px-4 text-sm font-semibold"
                            :style="{
                                background: 'var(--ds-accent)',
                                color: 'var(--ds-accent-contrast)',
                            }"
                            @click="publishOpen = false"
                        >
                            {{ t.publish_confirm }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
