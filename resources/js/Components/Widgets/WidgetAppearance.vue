<script setup lang="ts">
// B3-S08 J01–J03: brand colour with the INLINE contrast refusal (ratio + the suggested shade + "Use …"),
// position, theme, offsets, launcher icon, the curated font, and the live preview (both themes, both frames).
import { computed, ref } from 'vue';
import { draft } from './draft';
import { tr, type Copy, type Refusal } from './shared';

const props = defineProps<{
    copy: Copy;
    refusal: Refusal | null;
    fonts: string[];
    icons: string[];
    widgetId: string;
    language: string;
}>();
const emit = defineEmits<{ (e: 'change'): void; (e: 'apply', hex: string): void }>();
const t = props.copy;
const s = (k: string): string => t[k] ?? '';
const presets = ['#22211d', '#0a7a5a', '#1d4fd7'];
const custom = ref(draft.value.appearance.brand?.accent ?? '');
const frame = ref<'mobile' | 'desktop'>('desktop');
const contrast = computed(() =>
    props.refusal?.refusal_code === 'contrast' ? props.refusal : null,
);
function pick(hex: string | null): void {
    draft.value.appearance.brand = hex ? { accent: hex } : null;
    custom.value = hex ?? '';
    emit('change');
}
function onCustom(): void {
    const v = custom.value.trim();
    if (/^#[0-9a-fA-F]{6}$/.test(v)) pick(v.toLowerCase());
    else if (v === '') pick(null);
}
const glyph: Record<string, string> = { chat: '💬', sparkle: '✻', question: '?' };
</script>

<template>
    <div class="wd-two" data-testid="wd-appearance">
        <div class="wd-stack">
            <fieldset class="wd-field">
                <legend>{{ t.brand }}</legend>
                <div class="wd-row" role="radiogroup" :aria-label="s('brand')">
                    <button
                        type="button"
                        class="wd-swatch"
                        :class="{ on: draft.appearance.brand === null }"
                        :aria-pressed="draft.appearance.brand === null"
                        :aria-label="s('brand_default')"
                        data-testid="wd-brand-default"
                        @click="pick(null)"
                    >
                        <span class="wd-swatch-dot" style="background: var(--ds-accent)"></span>
                    </button>
                    <button
                        v-for="hex in presets"
                        :key="hex"
                        type="button"
                        class="wd-swatch"
                        :class="{ on: draft.appearance.brand?.accent === hex }"
                        :aria-pressed="draft.appearance.brand?.accent === hex"
                        :aria-label="hex"
                        :data-hex="hex"
                        @click="pick(hex)"
                    >
                        <span class="wd-swatch-dot" :style="{ background: hex }"></span>
                    </button>
                    <label class="wd-field wd-inline">
                        <span>{{ t.brand_custom }}</span>
                        <input
                            v-model="custom"
                            type="text"
                            maxlength="7"
                            spellcheck="false"
                            data-testid="wd-brand-custom"
                            @change="onCustom"
                            @blur="onCustom"
                        />
                    </label>
                </div>
            </fieldset>
            <div v-if="contrast" class="wd-refusal" role="alert" data-testid="wd-contrast-refusal">
                <p class="wd-refusal-title">
                    {{
                        tr(t.contrast_refused, {
                            accent: contrast.accent ?? '',
                            on: contrast.on ?? '',
                            ratio: contrast.ratio ?? 0,
                        })
                    }}
                </p>
                <p>
                    {{
                        tr(t.contrast_why, {
                            suggestion: contrast.suggestion ?? '',
                            suggestion_ratio: contrast.suggestion_ratio ?? 0,
                        })
                    }}
                </p>
                <p v-if="contrast.surface === 'dark'" class="wd-muted">{{ t.contrast_dark }}</p>
                <button
                    type="button"
                    class="wd-btn wd-btn-apply"
                    data-testid="wd-contrast-apply"
                    @click="emit('apply', contrast.suggestion ?? '')"
                >
                    <span class="wd-swatch-dot" :style="{ background: contrast.suggestion }"></span>
                    {{ tr(t.contrast_use, { suggestion: contrast.suggestion ?? '' }) }}
                </button>
            </div>

            <label class="wd-field">
                <span>{{ t.position }}</span>
                <select
                    v-model="draft.appearance.position"
                    data-testid="wd-position"
                    @change="emit('change')"
                >
                    <option value="bottom_right">{{ t.pos_bottom_right }}</option>
                    <option value="bottom_left">{{ t.pos_bottom_left }}</option>
                </select>
            </label>
            <label class="wd-field">
                <span>{{ t.theme }}</span>
                <select
                    v-model="draft.appearance.theme"
                    data-testid="wd-theme"
                    @change="emit('change')"
                >
                    <option value="auto">{{ t.theme_auto }}</option>
                    <option value="light">{{ t.theme_light }}</option>
                    <option value="dark">{{ t.theme_dark }}</option>
                </select>
            </label>
            <div class="wd-row">
                <label class="wd-field">
                    <span>{{ t.offset_side }}</span>
                    <input
                        v-model.number="draft.appearance.offset.side"
                        type="number"
                        min="0"
                        max="200"
                        data-testid="wd-offset-side"
                        @change="emit('change')"
                    />
                </label>
                <label class="wd-field">
                    <span>{{ t.offset_bottom }}</span>
                    <input
                        v-model.number="draft.appearance.offset.bottom"
                        type="number"
                        min="0"
                        max="200"
                        data-testid="wd-offset-bottom"
                        @change="emit('change')"
                    />
                </label>
            </div>
            <fieldset class="wd-field">
                <legend>{{ t.icon }}</legend>
                <div class="wd-row" role="radiogroup" :aria-label="s('icon')">
                    <button
                        v-for="icon in icons.filter((i) => i !== 'upload')"
                        :key="icon"
                        type="button"
                        class="wd-swatch"
                        :class="{ on: draft.appearance.launcher_icon === icon }"
                        :aria-pressed="draft.appearance.launcher_icon === icon"
                        :aria-label="icon"
                        :data-icon="icon"
                        @click="
                            draft.appearance.launcher_icon = icon;
                            emit('change');
                        "
                    >
                        {{ glyph[icon] ?? icon }}
                    </button>
                    <span class="wd-muted">{{ t.icon_upload }} — {{ t.icon_upload_note }}</span>
                </div>
            </fieldset>
            <label class="wd-field">
                <span>{{ t.font }}</span>
                <select
                    v-model="draft.appearance.font"
                    data-testid="wd-font"
                    @change="emit('change')"
                >
                    <option v-for="f in fonts" :key="f" :value="f">
                        {{ t['font_' + f] ?? f }}
                    </option>
                </select>
                <span class="wd-muted">{{ t.font_note }}</span>
            </label>
        </div>

        <section class="wd-preview" data-testid="wd-preview" :data-frame="frame">
            <div class="wd-row wd-preview-head">
                <h3>{{ t.preview }}</h3>
                <div class="wd-seg" role="group" :aria-label="s('preview')">
                    <button
                        type="button"
                        :class="{ on: frame === 'mobile' }"
                        :aria-pressed="frame === 'mobile'"
                        data-testid="wd-preview-mobile"
                        @click="frame = 'mobile'"
                    >
                        {{ t.preview_mobile }}
                    </button>
                    <button
                        type="button"
                        :class="{ on: frame === 'desktop' }"
                        :aria-pressed="frame === 'desktop'"
                        data-testid="wd-preview-desktop"
                        @click="frame = 'desktop'"
                    >
                        {{ t.preview_desktop }}
                    </button>
                </div>
            </div>
            <div class="wd-preview-frames">
                <figure
                    v-for="theme in ['light', 'dark']"
                    :key="theme"
                    class="wd-preview-frame"
                    :data-preview-theme="theme"
                >
                    <iframe
                        :src="
                            '/admin/widgets/' +
                            widgetId +
                            '/preview?theme=' +
                            theme +
                            '&language=' +
                            language +
                            '&frame=panel'
                        "
                        :title="theme === 'light' ? s('preview_light') : s('preview_dark')"
                        :data-testid="'wd-preview-' + theme"
                        :data-preview="theme"
                        loading="lazy"
                    ></iframe>
                    <figcaption>
                        {{ theme === 'light' ? t.preview_light : t.preview_dark }}
                    </figcaption>
                </figure>
            </div>
            <p class="wd-muted">{{ t.preview_note }}</p>
        </section>
    </div>
</template>
