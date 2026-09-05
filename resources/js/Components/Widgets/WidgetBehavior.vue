<script setup lang="ts">
// B3-S08 J05: chatbot read-only + link, the proactive triggers builder, the cooldown ceiling (a setting),
// business-hours source, file upload, sound, CSAT with its policy note, and the voice row — ABSENT unless the flag is on.
import { draft } from './draft';
import type { Copy } from './shared';

const props = defineProps<{
    copy: Copy;
    chatbot: { enabled: boolean; href: string };
    voiceFlag: boolean;
    cooldownHours: number;
    departments: { id: string; name: string }[];
    languages: string[];
}>();
const emit = defineEmits<{ (e: 'change'): void }>();
const t = props.copy;
const s = (k: string): string => t[k] ?? '';
function addRule(): void {
    draft.value.behavior.proactive.push({
        id: 'rule-' + Date.now().toString(36),
        condition: { kind: 'url', match: '/' },
        action: { kind: 'message', message: { en: '', am: '' } },
    });
    emit('change');
}
function removeRule(i: number): void {
    draft.value.behavior.proactive.splice(i, 1);
    emit('change');
}
</script>

<template>
    <div class="wd-stack" data-testid="wd-behavior">
        <div class="wd-field">
            <span>{{ t.chatbot }} · {{ chatbot.enabled ? t.chatbot_on : t.chatbot_off }}</span>
            <p class="wd-readonly" data-testid="wd-chatbot">
                {{ t.chatbot_note }} <a :href="chatbot.href">{{ t.chatbot_link }} ↗</a>
            </p>
        </div>

        <fieldset class="wd-field">
            <legend>{{ t.proactive }}</legend>
            <ul class="wd-rules" data-testid="wd-proactive">
                <li v-for="(r, i) in draft.behavior.proactive" :key="r.id" class="wd-rule">
                    <div class="wd-row">
                        <select
                            v-model="r.condition.kind"
                            :aria-label="s('proactive')"
                            @change="emit('change')"
                        >
                            <option value="url">{{ t.proactive_kind_url }}</option>
                            <option value="time">{{ t.proactive_kind_time }}</option>
                            <option value="exit">{{ t.proactive_kind_exit }}</option>
                            <option value="capture">{{ t.proactive_kind_capture }}</option>
                        </select>
                        <input
                            v-if="r.condition.kind !== 'time'"
                            v-model="r.condition.match"
                            type="text"
                            :aria-label="s('proactive_match')"
                            :placeholder="s('proactive_match')"
                            @input="emit('change')"
                        />
                        <input
                            v-if="r.condition.kind === 'time'"
                            v-model.number="r.condition.seconds"
                            type="number"
                            min="5"
                            max="600"
                            :aria-label="s('proactive_seconds')"
                            @change="emit('change')"
                        />
                        <label v-if="r.condition.kind === 'exit'" class="wd-check"
                            ><input
                                v-model="r.condition.desktop_only"
                                type="checkbox"
                                @change="emit('change')"
                            />
                            {{ t.proactive_desktop_only }}</label
                        >
                        <button
                            type="button"
                            class="wd-icon"
                            :aria-label="s('remove')"
                            @click="removeRule(i)"
                        >
                            ✕
                        </button>
                    </div>
                    <div class="wd-row">
                        <span class="wd-muted">→ {{ t.proactive_message }}</span>
                        <input
                            v-for="l in languages"
                            :key="l"
                            v-model="r.action.message[l]"
                            type="text"
                            maxlength="300"
                            :aria-label="s('proactive_message') + ' ' + (t['lang_' + l] ?? l)"
                            :placeholder="t['lang_' + l] ?? l"
                            @input="emit('change')"
                        />
                    </div>
                </li>
            </ul>
            <button
                v-if="draft.behavior.proactive.length < 10"
                type="button"
                class="wd-btn"
                data-testid="wd-proactive-add"
                @click="addRule"
            >
                + {{ t.proactive_add }}
            </button>
            <p class="wd-readonly">
                <strong>{{ t.cooldown }}</strong> {{ cooldownHours }} {{ t.hours }}
                <span class="wd-muted">— {{ t.cooldown_note }}</span>
            </p>
        </fieldset>

        <label class="wd-field">
            <span>{{ t.hours_source }}</span>
            <select
                v-model="draft.behavior.hours_source"
                data-testid="wd-hours-source"
                @change="emit('change')"
            >
                <option value="tenant">{{ t.hours_tenant }}</option>
                <option v-for="d in departments" :key="d.id" :value="'department:' + d.id">
                    {{ t.hours_department }} {{ d.name }}
                </option>
            </select>
        </label>

        <div class="wd-switches">
            <label class="wd-switch"
                ><span>{{ t.file_upload }}</span
                ><input
                    v-model="draft.behavior.file_upload"
                    type="checkbox"
                    role="switch"
                    data-testid="wd-file-upload"
                    @change="emit('change')"
            /></label>
            <label class="wd-switch"
                ><span>{{ t.sound }}</span
                ><input
                    :checked="draft.behavior.sound !== false"
                    type="checkbox"
                    role="switch"
                    data-testid="wd-sound"
                    @change="
                        draft.behavior.sound = ($event.target as HTMLInputElement).checked;
                        emit('change');
                    "
            /></label>
            <label class="wd-switch"
                ><span>{{ t.csat }}</span
                ><input
                    v-model="draft.behavior.csat"
                    type="checkbox"
                    role="switch"
                    data-testid="wd-csat"
                    @change="emit('change')"
            /></label>
            <p class="wd-muted">{{ t.csat_note }}</p>
            <p v-if="!voiceFlag" class="wd-readonly" data-testid="wd-voice-absent">
                <strong>{{ t.voice }}</strong> <span class="wd-muted">{{ t.voice_flag_off }}</span>
            </p>
            <label v-else class="wd-switch" data-testid="wd-voice"
                ><span>{{ t.voice }}</span
                ><input
                    type="checkbox"
                    role="switch"
                    :checked="draft.behavior.file_upload"
                    disabled
            /></label>
        </div>
    </div>
</template>
