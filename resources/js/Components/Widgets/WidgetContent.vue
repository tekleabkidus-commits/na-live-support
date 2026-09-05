<script setup lang="ts">
// B3-S08 J04: welcome per language (the am-incomplete gate), the shortcuts builder, agent identity (a setting,
// read-only here), the pre-chat / offline form pickers, the tab toggles with the Messages-only note.
import { ref } from 'vue';
import { draft } from './draft';
import type { Copy } from './shared';

const props = defineProps<{
    copy: Copy;
    warnings: string[];
    forms: { id: string; name: string }[];
    languages: string[];
    agentIdentity: string;
}>();
const emit = defineEmits<{ (e: 'change'): void }>();
const t = props.copy;
const s = (k: string): string => t[k] ?? '';
const lang = ref(props.languages[0] ?? 'en');
function addShortcut(): void {
    draft.value.content.shortcuts.push({ kind: 'chat', label: { en: '', am: '' }, target: '' });
    emit('change');
}
function move(i: number, d: number): void {
    const list = draft.value.content.shortcuts;
    const j = i + d;
    if (j < 0 || j >= list.length) return;
    [list[i], list[j]] = [list[j]!, list[i]!];
    emit('change');
}
function remove(i: number): void {
    draft.value.content.shortcuts.splice(i, 1);
    emit('change');
}
</script>

<template>
    <div class="wd-stack" data-testid="wd-content">
        <fieldset class="wd-field">
            <legend>{{ t.welcome }}</legend>
            <div class="wd-seg" role="tablist" :aria-label="s('welcome')">
                <button
                    v-for="l in languages"
                    :key="l"
                    type="button"
                    role="tab"
                    :aria-selected="lang === l"
                    :class="{ on: lang === l }"
                    :data-testid="'wd-welcome-lang-' + l"
                    @click="lang = l"
                >
                    {{ t['lang_' + l] ?? l }}
                </button>
            </div>
            <textarea
                v-model="draft.content.welcome[lang]"
                rows="2"
                maxlength="300"
                :data-testid="'wd-welcome-' + lang"
                @input="emit('change')"
            ></textarea>
            <p
                v-if="warnings.includes('am_incomplete')"
                class="wd-warn"
                data-testid="wd-am-incomplete"
            >
                <strong>{{ t.am_incomplete }}</strong> {{ t.am_incomplete_note }}
            </p>
        </fieldset>

        <fieldset class="wd-field">
            <legend>{{ t.shortcuts }}</legend>
            <ol class="wd-shortcuts" data-testid="wd-shortcuts">
                <li v-for="(sc, i) in draft.content.shortcuts" :key="i" class="wd-shortcut">
                    <span class="wd-grip" aria-hidden="true">⠿</span>
                    <input
                        v-model="sc.label[lang]"
                        type="text"
                        maxlength="80"
                        :aria-label="s('shortcut_label')"
                        :data-testid="'wd-shortcut-label-' + i"
                        @input="emit('change')"
                    />
                    <select
                        v-model="sc.kind"
                        :aria-label="s('shortcut_kind_article')"
                        @change="emit('change')"
                    >
                        <option value="article">{{ t.shortcut_kind_article }}</option>
                        <option value="form">{{ t.shortcut_kind_form }}</option>
                        <option value="chat">{{ t.shortcut_kind_chat }}</option>
                        <option value="url">{{ t.shortcut_kind_url }}</option>
                    </select>
                    <input
                        v-model="sc.target"
                        :data-testid="'wd-shortcut-target-' + i"
                        type="text"
                        maxlength="500"
                        :aria-label="s('shortcut_target')"
                        :placeholder="s('shortcut_target')"
                        @input="emit('change')"
                    />
                    <button
                        type="button"
                        class="wd-icon"
                        :aria-label="s('move_up')"
                        @click="move(i, -1)"
                    >
                        ↑
                    </button>
                    <button
                        type="button"
                        class="wd-icon"
                        :aria-label="s('move_down')"
                        @click="move(i, 1)"
                    >
                        ↓
                    </button>
                    <button
                        type="button"
                        class="wd-icon"
                        :aria-label="s('remove')"
                        @click="remove(i)"
                    >
                        ✕
                    </button>
                </li>
            </ol>
            <button
                v-if="draft.content.shortcuts.length < 8"
                type="button"
                class="wd-btn"
                data-testid="wd-shortcut-add"
                @click="addShortcut"
            >
                + {{ t.shortcut_add }}
            </button>
        </fieldset>

        <div class="wd-field">
            <span>{{ t.agents_as }}</span>
            <p class="wd-readonly" data-testid="wd-agent-identity">
                {{ t['identity_' + agentIdentity] ?? agentIdentity }}
                <span class="wd-muted">— {{ t.agents_as_note }}</span>
            </p>
        </div>

        <div class="wd-row">
            <label class="wd-field">
                <span>{{ t.pre_chat }}</span>
                <select
                    v-model="draft.content.pre_chat_mode"
                    data-testid="wd-prechat-mode"
                    @change="emit('change')"
                >
                    <option value="off">{{ t.pre_chat_off }}</option>
                    <option value="optional">{{ t.pre_chat_optional }}</option>
                    <option value="required">{{ t.pre_chat_required }}</option>
                </select>
            </label>
            <label v-if="draft.content.pre_chat_mode !== 'off'" class="wd-field">
                <span>{{ t.pre_chat }}</span>
                <select
                    v-model="draft.content.pre_chat_form_id"
                    data-testid="wd-prechat-form"
                    @change="emit('change')"
                >
                    <option :value="null">{{ t.form_none }}</option>
                    <option v-for="f in forms" :key="f.id" :value="f.id">{{ f.name }}</option>
                </select>
            </label>
            <label class="wd-field">
                <span>{{ t.offline_form }}</span>
                <select
                    v-model="draft.content.offline_form_id"
                    data-testid="wd-offline-form"
                    @change="emit('change')"
                >
                    <option :value="null">{{ t.form_none }}</option>
                    <option v-for="f in forms" :key="f.id" :value="f.id">{{ f.name }}</option>
                </select>
            </label>
        </div>

        <fieldset class="wd-field">
            <legend>{{ t.tabs }}</legend>
            <div class="wd-row">
                <label class="wd-check"
                    ><input
                        v-model="draft.content.tabs.home"
                        type="checkbox"
                        data-testid="wd-tab-home"
                        @change="emit('change')"
                    />
                    {{ t.tab_home }}</label
                >
                <label class="wd-check"
                    ><input type="checkbox" checked disabled /> {{ t.tab_messages }}</label
                >
                <label class="wd-check"
                    ><input
                        v-model="draft.content.tabs.help"
                        type="checkbox"
                        data-testid="wd-tab-help"
                        @change="emit('change')"
                    />
                    {{ t.tab_help }}</label
                >
            </div>
            <span class="wd-muted">{{ t.tabs_note }}</span>
        </fieldset>
    </div>
</template>
