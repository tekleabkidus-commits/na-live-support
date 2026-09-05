import type { FunctionComponent } from 'preact';
import { useEffect, useRef, useState } from 'preact/hooks';
import { t } from '../i18n';
import {
    boot,
    config,
    envelope,
    helpArticle,
    language,
    notifyPromptOpen,
    preChat,
    sheet,
    tab,
    tabsShown,
    messages,
} from '../state';
import { theme } from '../theme';
import type { Shortcut } from '../types';
import { lazy, Suspense } from 'preact/compat';
import type { WidgetApi } from '../api';
import { Composer } from './Composer';
import { Home } from './Home';
import { lightbox, Thread } from './Thread';

interface Props {
    onClose: () => void;
    onSend: (text: string, media?: { reference: string; mime: string }) => void;
    onTranscript: () => void;
    onTyping: () => void;
    onStar: (token: string, score: number) => void;
    onShortcut: (s: Shortcut) => void;
    onLanguage: (code: string) => void;
    onRetry: () => void;
    onNotify: (yes: boolean) => void;
    api: WidgetApi;
    onPreChatDone: (answers: Record<string, unknown>) => void;
    onPreChatSkip: () => void;
    announcement: string;
}

/** B05–B09: a dialog (focus trapped, Esc closes, focus returns to the launcher — the loader's side), header per state, tab bar per config, full sheet under 480 px; K03 the last-resort card. */
const HelpTab = lazy(() => import('../tabs/Help'));
const FormRenderer = lazy(() => import('../forms/FormRenderer')); // a LAZY chunk (NFR-01) — the gate reads the manifest

export const Panel: FunctionComponent<Props> = (p) => {
    const root = useRef<HTMLDivElement>(null);
    const [menu, setMenu] = useState(false);
    const c = config.value;
    const tabs = c?.config.content.tabs ?? { home: true, help: true };
    const connecting = envelope.value.queue !== null;
    const [receipt, setReceipt] = useState<{
        ticket_number: number;
        phone_masked: string | null;
    } | null>(null);
    // F03/F04: a pending pre-chat form takes the whole panel (no tab bar); F09/F10: out of hours with the bot OFF and an
    // offline form configured, the offline form replaces the composer — the bot ON (F08) keeps the thread.
    const gate: 'none' | 'prechat' | 'offline' =
        preChat.value === 'pending' && (tab.value === 'messages' || !tabs.home)
            ? 'prechat'
            : c &&
                !c.hours.open &&
                !c.capabilities.chatbot &&
                c.forms.offline.version_id &&
                messages.value.length === 0 && // F11: a visitor IN a thread keeps it across the boundary — the offline form is for a visitor with no thread yet
                (tab.value === 'messages' || !tabs.home)
              ? 'offline'
              : 'none';
    const opensAt = (() => {
        try {
            return c?.hours.opens_at
                ? new Intl.DateTimeFormat(language.value, {
                      hour: '2-digit',
                      minute: '2-digit',
                      timeZone: c.hours.timezone,
                  }).format(new Date(c.hours.opens_at))
                : '';
        } catch {
            return c?.hours.opens_at ?? '';
        }
    })();

    useEffect(() => {
        const el = root.current;
        if (!el) return;
        const first = el.querySelector<HTMLElement>(
            'button, [href], textarea, input, [tabindex]:not([tabindex="-1"])',
        );
        first?.focus();
        const trap = (e: KeyboardEvent): void => {
            if (e.key === 'Escape') {
                e.preventDefault();
                if (lightbox.value) {
                    lightbox.value = null;
                    return;
                }
                p.onClose();
                return;
            }
            if (e.key !== 'Tab') return;
            const items = Array.from(
                el.querySelectorAll<HTMLElement>(
                    'button:not([disabled]), [href], textarea, input, [tabindex]:not([tabindex="-1"])',
                ),
            ).filter((n) => n.offsetParent !== null);
            if (items.length === 0) return;
            const firstItem = items[0];
            const lastItem = items[items.length - 1];
            if (e.shiftKey && document.activeElement === firstItem) {
                e.preventDefault();
                lastItem?.focus();
            } else if (!e.shiftKey && document.activeElement === lastItem) {
                e.preventDefault();
                firstItem?.focus();
            }
        };
        el.addEventListener('keydown', trap);
        return () => el.removeEventListener('keydown', trap);
    }, [p.onClose]);

    if (boot.value === 'down') {
        return (
            <div
                ref={root}
                class="nlw-panel"
                role="dialog"
                aria-modal="true"
                aria-label={t('a11y.dialog')}
                data-sheet={String(sheet.value)}
            >
                <header class="nlw-header">
                    <div class="nlw-title">
                        <strong>{t('header.team')}</strong>
                    </div>
                    <button
                        type="button"
                        class="nlw-icon"
                        aria-label={t('header.close')}
                        onClick={p.onClose}
                    >
                        ✕
                    </button>
                </header>
                <div class="nlw-retry" data-role="retry">
                    <strong>⚠ {t('retry.title')}</strong>
                    <p>{t('retry.body')}</p>
                    <button type="button" class="nlw-btn" onClick={p.onRetry}>
                        {t('retry.action')}
                    </button>
                    <p>{t('retry.urgent')}</p>
                </div>
            </div>
        );
    }

    const title = connecting ? t('header.connecting') : t('header.assistant');
    return (
        <div
            ref={root}
            class="nlw-panel"
            role="dialog"
            aria-modal="true"
            aria-label={t('a11y.dialog')}
            data-sheet={String(sheet.value)}
            data-tab={tab.value}
        >
            <header class="nlw-header">
                <div class="nlw-avatar" aria-hidden="true">
                    {t('header.team').slice(0, 1)}
                </div>
                <div class="nlw-title">
                    <strong>{t('header.team')}</strong>
                    <span data-role="header-state">{title}</span>
                </div>
                <button
                    type="button"
                    class="nlw-icon"
                    aria-label={t('header.menu')}
                    aria-expanded={menu}
                    aria-haspopup="menu"
                    onClick={() => setMenu(!menu)}
                >
                    ⋯
                </button>
                {!sheet.value ? (
                    <button
                        type="button"
                        class="nlw-icon"
                        aria-label={t('header.minimize')}
                        onClick={p.onClose}
                    >
                        －
                    </button>
                ) : null}
                <button
                    type="button"
                    class="nlw-icon"
                    aria-label={t('header.close')}
                    onClick={p.onClose}
                >
                    ✕
                </button>
            </header>
            {menu ? (
                <div class="nlw-menu" role="menu">
                    <button type="button" role="menuitem" onClick={() => setMenu(false)}>
                        🔔 {t('overflow.sound_on')}
                    </button>
                    {(c?.languages ?? ['en', 'am']).map((code) => (
                        <button
                            key={code}
                            type="button"
                            role="menuitemradio"
                            aria-checked={language.value === code}
                            onClick={() => {
                                setMenu(false);
                                p.onLanguage(code);
                            }}
                        >
                            🌐 {t('overflow.language')} · {code === 'am' ? 'አማርኛ' : 'English'}
                        </button>
                    ))}
                    <button
                        type="button"
                        role="menuitemradio"
                        aria-checked={theme.value === 'dark'}
                        onClick={() => (theme.value = theme.value === 'dark' ? 'light' : 'dark')}
                    >
                        ◐ {theme.value}
                    </button>
                    <button
                        type="button"
                        role="menuitem"
                        data-role="transcript"
                        onClick={() => {
                            setMenu(false);
                            p.onTranscript();
                        }}
                    >
                        ⤓ {t('overflow.transcript')}
                    </button>
                    <button type="button" role="menuitem" onClick={() => setMenu(false)}>
                        {t('overflow.privacy')}
                    </button>
                </div>
            ) : null}
            <div class="nlw-body" role="tabpanel" aria-labelledby={'nlw-tab-' + tab.value}>
                {gate === 'prechat' && c?.forms.pre_chat.version_id ? (
                    <Suspense fallback={<div class="nlw-form" />}>
                        <FormRenderer
                            api={p.api}
                            versionId={c.forms.pre_chat.version_id}
                            mode="form"
                            required={c.forms.pre_chat.mode === 'required'}
                            intro={
                                c.forms.pre_chat.mode === 'required'
                                    ? t('prechat.required_intro')
                                    : t('prechat.optional_intro')
                            }
                            onComplete={p.onPreChatDone}
                            onSkip={
                                c.forms.pre_chat.mode === 'required' ? undefined : p.onPreChatSkip
                            }
                        />
                    </Suspense>
                ) : gate === 'offline' && c?.forms.offline.version_id ? (
                    receipt ? (
                        <div class="nlw-receipt" data-role="offline-receipt">
                            <strong>
                                ✓ {t('offline.receipt_title', { number: receipt.ticket_number })}
                            </strong>
                            <span>{t('offline.receipt_body', { time: opensAt })}</span>
                            {receipt.phone_masked ? (
                                <span>
                                    {t('offline.receipt_sms', { phone: receipt.phone_masked })}
                                </span>
                            ) : null}
                            <span class="nlw-meta">{t('offline.receipt_done')}</span>
                        </div>
                    ) : (
                        <Suspense fallback={<div class="nlw-form" />}>
                            <FormRenderer
                                api={p.api}
                                versionId={c.forms.offline.version_id}
                                mode="offline"
                                required
                                title={t('offline.title')}
                                intro={t('offline.intro', { time: opensAt })}
                                onComplete={() => undefined}
                                onReceipt={(r) => setReceipt(r)}
                            />
                        </Suspense>
                    )
                ) : tab.value === 'help' && tabs.help ? (
                    <Suspense fallback={<div class="nlw-help" />}>
                        <HelpTab
                            api={p.api}
                            initialArticle={helpArticle.value}
                            onStartChat={() => (tab.value = 'messages')}
                        />
                    </Suspense>
                ) : tab.value === 'home' && tabs.home ? (
                    <>
                        {c?.visitor.display ? (
                            <div
                                class="nlw-badge-identity"
                                data-role="identity"
                                data-placement="home"
                            >
                                <span aria-hidden="true">{c.visitor.display.slice(0, 1)}</span>
                                <span>
                                    {t('identity.signed_in_as', { name: c.visitor.display })}{' '}
                                    <small>{t('identity.from_site')}</small>
                                </span>
                            </div>
                        ) : null}
                        <Home
                            onShortcut={p.onShortcut}
                            onStartChat={() => (tab.value = 'messages')}
                        />
                    </>
                ) : (
                    <Thread onStar={p.onStar} onShortcut={(label) => p.onSend(label)} />
                )}
            </div>
            {notifyPromptOpen.value ? (
                <section class="nlw-queue" data-role="notify-prompt" aria-label={t('notify.title')}>
                    <strong>{t('notify.title')}</strong>
                    <span>{t('notify.body')}</span>
                    <div class="nlw-list nlw-list-2col">
                        <button type="button" class="nlw-btn" onClick={() => p.onNotify(true)}>
                            {t('notify.yes')}
                        </button>
                        <button type="button" class="nlw-btn" onClick={() => p.onNotify(false)}>
                            {t('notify.no')}
                        </button>
                    </div>
                </section>
            ) : null}
            {gate === 'none' && (tab.value === 'messages' || !tabs.home) ? (
                <Composer
                    api={p.api}
                    onSend={p.onSend}
                    onTyping={p.onTyping}
                    maxText={c?.capabilities.max_text ?? 4000}
                    maxMb={c?.capabilities.max_mb ?? 25}
                    voice={!!c?.capabilities.voice}
                    fileUpload={c?.config.behavior.file_upload ?? true}
                />
            ) : null}
            {tabsShown.value && gate === 'none' ? (
                <nav class="nlw-tabs" role="tablist" aria-label={t('a11y.tabs')}>
                    {tabs.home ? (
                        <button
                            type="button"
                            role="tab"
                            id="nlw-tab-home"
                            class="nlw-tab"
                            aria-selected={tab.value === 'home'}
                            onClick={() => (tab.value = 'home')}
                        >
                            <span aria-hidden="true">⌂</span>
                            {t('tabs.home')}
                        </button>
                    ) : null}
                    <button
                        type="button"
                        role="tab"
                        id="nlw-tab-messages"
                        class="nlw-tab"
                        aria-selected={tab.value === 'messages'}
                        onClick={() => (tab.value = 'messages')}
                    >
                        <span aria-hidden="true">✉</span>
                        {t('tabs.messages')}
                    </button>
                    {tabs.help ? (
                        <button
                            type="button"
                            role="tab"
                            id="nlw-tab-help"
                            class="nlw-tab"
                            aria-selected={tab.value === 'help'}
                            onClick={() => (tab.value = 'help')}
                        >
                            <span aria-hidden="true">?</span>
                            {t('tabs.help')}
                        </button>
                    ) : null}
                </nav>
            ) : null}
            {lightbox.value ? (
                <div
                    class="nlw-lightbox"
                    role="dialog"
                    aria-modal="true"
                    aria-label={lightbox.value.alt}
                    data-role="lightbox"
                    onClick={() => (lightbox.value = null)}
                >
                    <img src={lightbox.value.url} alt={lightbox.value.alt} />
                    <button
                        type="button"
                        class="nlw-icon"
                        aria-label={t('upload.lightbox_close')}
                        onClick={() => (lightbox.value = null)}
                    >
                        ✕
                    </button>
                </div>
            ) : null}
            <div
                class="nlw-sr"
                role="log"
                aria-live="polite"
                aria-label={t('a11y.log')}
                data-role="log"
            >
                {p.announcement.split('\n').map((line, i) => (
                    <p key={i}>{line}</p>
                ))}
            </div>
        </div>
    );
};
