// The Designer edits ONE draft config object shared by its five tabs (B3-S08: "the same config object that publishes").
// A module-level ref rather than a prop the tabs mutate: the Editor seeds it, every tab reads and writes it, the
// preview frames receive it, publish() sends it.
import { ref } from 'vue';
import type { WidgetConfig } from './shared';

export const draft = ref<WidgetConfig>({
    appearance: {
        position: 'bottom_right',
        offset: { side: 16, bottom: 20 },
        theme: 'auto',
        font: 'inter_noto_ethiopic',
        launcher_icon: 'chat',
        brand: null,
    },
    content: {
        welcome: {},
        shortcuts: [],
        tabs: { home: true, help: true },
        default_language: null,
        pre_chat_form_id: null,
        pre_chat_mode: 'off',
        offline_form_id: null,
    },
    behavior: {
        hours_source: 'tenant',
        file_upload: true,
        sound: null,
        csat: true,
        proactive: [],
        context_allowlist: [],
    },
});
