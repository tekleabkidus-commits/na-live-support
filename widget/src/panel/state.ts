import { computed, signal } from '@preact/signals';
import type { ConfigEnvelope, HostFacts, Message, MessagesPage } from './types';

export type Tab = 'home' | 'messages' | 'help';
export type Boot = 'booting' | 'ready' | 'down';

export const frameKind = signal<'launcher' | 'panel'>(
    new URLSearchParams(location.search).get('frame') === 'launcher' ? 'launcher' : 'panel',
);
export const boot = signal<Boot>('booting');
export const open = signal(false);
export const tab = signal<Tab>('home');
export const config = signal<ConfigEnvelope | null>(null);
export const host = signal<HostFacts | null>(null);
export const visitorId = signal<string | null>(null);
export const language = signal<string>('en');
export const messages = signal<Message[]>([]);
export const conversationId = signal<string | null>(null);
export const envelope = signal<MessagesPage['envelope']>({
    typing: false,
    queue: null,
    survey: null,
    blocked: false,
});
export const online = signal(navigator.onLine);
export const unread = signal(0);
/** I01: the widget's own notification question — opened ONLY after the first sent message. */
export const notifyPromptOpen = signal(false);
export const lastSeenId = signal<string | null>(null);
/** The HOST viewport (the loader's fact — this frame is 0×0 until it opens): the full sheet under 480 px (B10). */
export const hostViewport = signal<{ width: number; height: number } | null>(null);
export const sheet = computed(
    () => (hostViewport.value ? hostViewport.value.width : window.innerWidth) < 480,
);

export const tabsShown = computed(() => {
    const c = config.value?.config.content.tabs;
    return c ? c.home || c.help : true; // B09: with Home and Help both off there is no tab bar
});

window.addEventListener('online', () => (online.value = true));
window.addEventListener('offline', () => (online.value = false));
