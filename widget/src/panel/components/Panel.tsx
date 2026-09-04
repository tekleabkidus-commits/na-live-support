import type { FunctionComponent } from 'preact';
import { useEffect, useRef, useState } from 'preact/hooks';
import { t } from '../i18n';
import { boot, config, envelope, sheet, tab, tabsShown, language } from '../state';
import { theme } from '../theme';
import type { Shortcut } from '../types';
import { Composer } from './Composer';
import { Home } from './Home';
import { Thread } from './Thread';

interface Props {
    onClose: () => void;
    onSend: (text: string) => void;
    onTyping: () => void;
    onStar: (token: string, score: number) => void;
    onShortcut: (s: Shortcut) => void;
    onLanguage: (code: string) => void;
    onRetry: () => void;
    announcement: string;
}

/** B05–B09: a dialog (focus trapped, Esc closes, focus returns to the launcher — the loader's side), header per state, tab bar per config, full sheet under 480 px; K03 the last-resort card. */
export const Panel: FunctionComponent<Props> = (p) => {
    const root = useRef<HTMLDivElement>(null);
    const [menu, setMenu] = useState(false);
    const c = config.value;
    const tabs = c?.config.content.tabs ?? { home: true, help: true };
    const connecting = envelope.value.queue !== null;

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
                    <button type="button" role="menuitem" onClick={() => setMenu(false)}>
                        {t('overflow.privacy')}
                    </button>
                </div>
            ) : null}
            <div class="nlw-body" role="tabpanel" aria-labelledby={'nlw-tab-' + tab.value}>
                {tab.value === 'home' && tabs.home ? (
                    <Home onShortcut={p.onShortcut} onStartChat={() => (tab.value = 'messages')} />
                ) : (
                    <Thread onStar={p.onStar} onShortcut={(label) => p.onSend(label)} />
                )}
            </div>
            {tab.value === 'messages' || !tabs.home ? (
                <Composer
                    onSend={p.onSend}
                    onTyping={p.onTyping}
                    maxText={c?.capabilities.max_text ?? 4000}
                />
            ) : null}
            {tabsShown.value ? (
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
            <div
                class="nlw-sr"
                role="log"
                aria-live="polite"
                aria-label={t('a11y.log')}
                data-role="log"
            >
                {p.announcement}
            </div>
        </div>
    );
};
