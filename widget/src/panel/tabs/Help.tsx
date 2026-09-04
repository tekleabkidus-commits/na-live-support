/*
 * Doc 10 FR-18 / Doc 13 §8 — THE HELP TAB (B3-S04 E01–E07; a LAZY chunk):
 * browse (Popular + Categories), search as you type (debounced 150 ms, the
 * match highlighted, the first line shown), the article reader (16 px body),
 * the 👍👎 feedback row, the start-a-chat footer PINNED above the tab bar on
 * every screen (the one control that never scrolls away — never a deflection
 * funnel), K05 "index down — chat still works", E07 the language-fallback
 * notice from `served_language_matches`. Only public + published content ever
 * arrives (the server's law; the tab renders what it is given).
 */
import type { FunctionComponent } from 'preact';
import { useEffect, useRef, useState } from 'preact/hooks';
import type { WidgetApi } from '../api';
import { t } from '../i18n';
import { language } from '../state';
import type { HelpArticle, HelpTree } from '../types';

type Hit = {
    article_id: string;
    title: string;
    summary: string | null;
    language: string;
    slug: string;
};

function highlight(text: string, q: string): preact.JSX.Element {
    if (!q) return <>{text}</>;
    const i = text.toLowerCase().indexOf(q.toLowerCase());
    if (i < 0) return <>{text}</>;
    return (
        <>
            {text.slice(0, i)}
            <mark>{text.slice(i, i + q.length)}</mark>
            {text.slice(i + q.length)}
        </>
    );
}

export const Help: FunctionComponent<{
    api: WidgetApi;
    onStartChat: () => void;
    initialArticle?: string | null;
}> = ({ api, onStartChat, initialArticle }) => {
    const lang = language.value;
    const [tree, setTree] = useState<HelpTree | null | 'down'>(null);
    const [q, setQ] = useState('');
    const [hits, setHits] = useState<Hit[] | null>(null);
    const [searchDown, setSearchDown] = useState(false);
    const [article, setArticle] = useState<HelpArticle | null>(null);
    const [feedback, setFeedback] = useState<'none' | 'sent'>('none');
    const timer = useRef<number | null>(null);

    useEffect(() => {
        void api.helpTree().then((tr) => setTree(tr ?? 'down'));
    }, [api]);
    useEffect(() => {
        if (initialArticle) void api.helpArticle(initialArticle, lang).then((a) => setArticle(a));
    }, [api, initialArticle, lang]);

    const search = (value: string): void => {
        setQ(value);
        if (timer.current !== null) window.clearTimeout(timer.current);
        if (value.trim() === '') {
            setHits(null);
            return;
        }
        timer.current = window.setTimeout(() => {
            void api.helpSearch(value.trim(), lang).then((r) => {
                setSearchDown(!!r?.unavailable || r === null);
                setHits(r?.hits ?? []);
            });
        }, 150); // E02: debounced as the visitor types
    };

    const open = (id: string): void => {
        setFeedback('none');
        void api.helpArticle(id, lang).then((a) => setArticle(a));
    };

    const footer = (
        <div class="nlw-help-footer" data-role="help-footer">
            <span>{article ? t('help.not_answered') : t('help.still_need')}</span>
            <button type="button" class="nlw-btn" onClick={onStartChat}>
                {t('help.start_chat')}
            </button>
        </div>
    );

    if (article) {
        return (
            <div class="nlw-help" data-role="help" data-view="article">
                <div class="nlw-help-scroll">
                    <button type="button" class="nlw-btn" onClick={() => setArticle(null)}>
                        ← {t('help.back')}
                    </button>
                    {!article.served_language_matches ? (
                        <p class="nlw-notice" data-role="language-fallback">
                            {t('help.language_fallback', {
                                language: article.language === 'am' ? 'አማርኛ' : 'English',
                            })}
                        </p>
                    ) : null}
                    <article class="nlw-article">
                        <h2>{article.title}</h2>
                        {article.updated_at ? (
                            <p class="nlw-meta">
                                {t('help.updated', { date: article.updated_at.slice(0, 10) })}
                            </p>
                        ) : null}
                        {article.blocks.map((b, i) =>
                            b.type === 'heading' ? (
                                <h3 key={i}>{b.text}</h3>
                            ) : b.type === 'list' ? (
                                <ul key={i}>
                                    {(b.items ?? []).map((it, j) => (
                                        <li key={j}>{it}</li>
                                    ))}
                                </ul>
                            ) : b.type === 'image' && b.url ? (
                                <img key={i} src={b.url} alt={b.alt ?? ''} />
                            ) : (
                                <p key={i}>{b.text}</p>
                            ),
                        )}
                    </article>
                    <div class="nlw-feedback" data-role="feedback">
                        {feedback === 'sent' ? (
                            <span>{t('help.thanks')}</span>
                        ) : (
                            <>
                                <span>{t('help.helpful')}</span>
                                <button
                                    type="button"
                                    class="nlw-icon"
                                    aria-label="👍"
                                    onClick={() =>
                                        void api
                                            .helpFeedback(article.variant_id, 1)
                                            .then(() => setFeedback('sent'))
                                    }
                                >
                                    👍
                                </button>
                                <button
                                    type="button"
                                    class="nlw-icon"
                                    aria-label="👎"
                                    onClick={() =>
                                        void api
                                            .helpFeedback(article.variant_id, -1)
                                            .then(() => setFeedback('sent'))
                                    }
                                >
                                    👎
                                </button>
                            </>
                        )}
                    </div>
                </div>
                {footer}
            </div>
        );
    }

    const categories =
        tree && tree !== 'down'
            ? tree.categories.filter(
                  (c) => c.articles.some((a) => a.language === lang) || c.articles.length > 0,
              )
            : [];
    const popular =
        tree && tree !== 'down'
            ? tree.categories
                  .flatMap((c) => c.articles)
                  .filter((a, i, all) => all.findIndex((x) => x.article_id === a.article_id) === i)
                  .slice(0, 3)
            : [];
    return (
        <div class="nlw-help" data-role="help" data-view={hits !== null ? 'search' : 'browse'}>
            <div class="nlw-help-scroll">
                <label class="nlw-search">
                    <span class="nlw-sr">{t('help.search')}</span>
                    <input
                        type="search"
                        value={q}
                        placeholder={t('help.search_placeholder')}
                        onInput={(e) => search(e.currentTarget.value)}
                    />
                </label>
                {tree === 'down' || searchDown ? (
                    <p class="nlw-notice" data-role="index-down">
                        {t('help.index_down')}
                    </p>
                ) : null}
                {hits !== null ? (
                    <section>
                        <h3 class="nlw-meta">{t('help.results', { count: hits.length })}</h3>
                        <div class="nlw-list">
                            {hits.map((h) => (
                                <button
                                    key={h.article_id}
                                    type="button"
                                    class="nlw-btn nlw-hit"
                                    onClick={() => open(h.article_id)}
                                >
                                    <span>
                                        <strong>{highlight(h.title, q)}</strong>
                                        {h.summary ? (
                                            <small>{highlight(h.summary, q)}</small>
                                        ) : null}
                                    </span>
                                </button>
                            ))}
                        </div>
                    </section>
                ) : (
                    <>
                        {popular.length ? (
                            <section>
                                <h3 class="nlw-meta">{t('help.popular')}</h3>
                                <div class="nlw-list">
                                    {popular.map((a) => (
                                        <button
                                            key={a.article_id}
                                            type="button"
                                            class="nlw-btn"
                                            onClick={() => open(a.article_id)}
                                        >
                                            {a.title} <small>›</small>
                                        </button>
                                    ))}
                                </div>
                            </section>
                        ) : null}
                        {categories.length ? (
                            <section>
                                <h3 class="nlw-meta">{t('help.categories')}</h3>
                                <div class="nlw-list">
                                    {categories.map((c) => (
                                        <button
                                            key={c.id}
                                            type="button"
                                            class="nlw-btn"
                                            onClick={() =>
                                                c.articles[0] && open(c.articles[0].article_id)
                                            }
                                        >
                                            {c.name[lang] ?? Object.values(c.name)[0]}{' '}
                                            <small>
                                                {t('help.articles_count', {
                                                    count: c.articles.length,
                                                })}
                                            </small>
                                        </button>
                                    ))}
                                </div>
                            </section>
                        ) : null}
                    </>
                )}
            </div>
            {footer}
        </div>
    );
};
export default Help;
