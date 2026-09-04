import type { FunctionComponent } from 'preact';
import { t } from '../i18n';
import { config, language, messages, tab } from '../state';
import type { Shortcut } from '../types';

function localTime(iso: string, tz: string): string {
    try {
        return new Intl.DateTimeFormat(language.value, {
            hour: '2-digit',
            minute: '2-digit',
            timeZone: tz,
        }).format(new Date(iso));
    } catch {
        return iso;
    }
}

/** C01–C07: greeting from cached config (instant, never a skeleton), the honest band, the resume card outranking the shortcuts, four shortcut kinds, nothing else. */
export const Home: FunctionComponent<{
    onShortcut: (s: Shortcut) => void;
    onStartChat: () => void;
}> = ({ onShortcut, onStartChat }) => {
    const c = config.value;
    const lang = language.value;
    const welcome =
        c?.config.content.welcome[lang] ||
        (c?.hours.open === false ? t('home.greeting_off_hours') : t('home.greeting'));
    const band = c?.home.band ?? null;
    const shortcuts = c?.config.content.shortcuts ?? [];
    const history = messages.value;
    const last = history[history.length - 1];
    const bandText =
        band === 'off_hours' && c?.hours.opens_at
            ? t('home.band_off_hours', { time: localTime(c.hours.opens_at, c.hours.timezone) })
            : band
              ? t(`home.band_${band}`)
              : null;
    return (
        <div class="nlw-home">
            <p class="nlw-greeting">{welcome}</p>
            {c === null ? (
                <div class="nlw-band">
                    <span class="nlw-skeleton" style="width: 60%" />
                </div>
            ) : bandText ? (
                <p class="nlw-band" data-band={band ?? ''}>
                    {band !== 'off_hours' ? (
                        <span class="nlw-dot" aria-hidden="true" />
                    ) : (
                        <span aria-hidden="true">◷</span>
                    )}
                    <span>{bandText}</span>
                </p>
            ) : null}
            {history.length > 0 && last ? (
                <div class="nlw-card">
                    <h3>{t('home.resume')}</h3>
                    <button type="button" class="nlw-btn" onClick={() => (tab.value = 'messages')}>
                        <span>{last.body ? last.body.slice(0, 60) : t('a11y.attachment')}</span>
                        <small>
                            {t('home.resume_meta', {
                                who: last.author === 'customer' ? '' : t('header.team'),
                                when: '',
                                count: history.length,
                            })}
                        </small>
                    </button>
                </div>
            ) : null}
            <div class="nlw-card">
                <h3>{t('home.quick_answers')}</h3>
                <div class="nlw-list">
                    {shortcuts.map((s, i) => (
                        <button key={i} type="button" class="nlw-btn" onClick={() => onShortcut(s)}>
                            <span aria-hidden="true">
                                {s.kind === 'article'
                                    ? '◫'
                                    : s.kind === 'form'
                                      ? '▤'
                                      : s.kind === 'url'
                                        ? '↗'
                                        : '✻'}
                            </span>
                            <span>{s.label[lang] ?? Object.values(s.label)[0] ?? ''}</span>
                            {s.kind === 'url' ? <small>{t('home.external')}</small> : null}
                        </button>
                    ))}
                    {shortcuts.length === 0 ? (
                        <button type="button" class="nlw-btn" onClick={onStartChat}>
                            <span aria-hidden="true">✻</span>
                            <span>{t('tabs.messages')}</span>
                        </button>
                    ) : null}
                </div>
                {history.length === 0 ? <p class="nlw-meta">{t('home.no_account')}</p> : null}
            </div>
        </div>
    );
};
