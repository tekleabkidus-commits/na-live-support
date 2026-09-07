import type { FunctionComponent } from 'preact';
import { useEffect, useRef } from 'preact/hooks';
import { signal } from '@preact/signals';
import { t } from '../i18n';
import { config, earlierCursor, envelope, language, messages } from '../state';
import type { Message } from '../types';

function dayLabel(iso: string): string {
    const d = new Date(iso);
    const now = new Date();
    const same = (a: Date, b: Date): boolean => a.toDateString() === b.toDateString();
    if (same(d, now)) return t('messages.today');
    const y = new Date(now);
    y.setDate(now.getDate() - 1);
    if (same(d, y)) return t('messages.yesterday');
    try {
        return new Intl.DateTimeFormat(language.value, { day: 'numeric', month: 'long' }).format(d);
    } catch {
        return iso.slice(0, 10);
    }
}

function clock(iso: string): string {
    try {
        return new Intl.DateTimeFormat(language.value, {
            hour: '2-digit',
            minute: '2-digit',
        }).format(new Date(iso));
    } catch {
        return '';
    }
}

/** AC-01: the double-check is DATA — a customer row shows ✓✓ only once the server's page carries it; ✓ on the 202; nothing before. */
function glyph(m: Message): { text: string; label: string } | null {
    if (m.author !== 'customer') return null;
    const state = m.local ?? 'delivered';
    if (state === 'delivered') return { text: '✓✓', label: t('messages.delivered') };
    if (state === 'sent') return { text: '✓', label: t('messages.sent') };
    return { text: '…', label: t('messages.sending') };
}

/** D15/G06: a clean image is a thumbnail that opens the themed lightbox; a document is a card; pending/blocked are labeled, never a broken icon. */
export const lightbox = signal<{ url: string; alt: string } | null>(null);
const MediaItem: FunctionComponent<{ item: Message['media'][number] }> = ({ item }) => {
    if (item.scan !== 'clean' || !item.url) {
        return (
            <span
                class="nlw-media-state"
                data-role="media-state"
                data-scan={item.scan ?? 'pending'}
            >
                {item.scan === 'pending' || item.scan === 'scanning'
                    ? t('media.checking')
                    : t('media.blocked')}
            </span>
        );
    }
    if (item.kind === 'photo') {
        return (
            <button
                type="button"
                class="nlw-media-thumb"
                data-role="media"
                onClick={() =>
                    (lightbox.value = { url: item.url ?? '', alt: t('a11y.attachment') })
                }
            >
                <img src={item.url} alt={t('a11y.attachment')} loading="lazy" />
            </button>
        );
    }
    return (
        <a class="nlw-media-doc" data-role="media" href={item.url} target="_blank" rel="noopener">
            <span aria-hidden="true">▤</span> {item.mime ?? item.kind ?? ''}{' '}
            {item.bytes ? '· ' + Math.round(item.bytes / 1024) + ' KB' : ''}
        </a>
    );
};

const Bubble: FunctionComponent<{ m: Message }> = ({ m }) => {
    const g = glyph(m);
    // 13c F-13b-2 (M02): the agent label follows `widget.agent_identity` — the server sends the
    // name only when the mode wants one (first_name; alias = the first-name stand-in, filed);
    // team_name (or no name on record) draws the team label. The bot's label is never a person's.
    const name =
        m.author === 'ai'
            ? t('messages.ai_label')
            : m.author === 'agent'
              ? m.author_display || t('header.team')
              : '';
    if (m.author === 'system') {
        return (
            <p class="nlw-system" data-message={m.id} data-author="system">
                {m.body}
            </p>
        );
    }
    return (
        <div
            class="nlw-msg"
            data-message={m.id}
            data-author={m.author}
            data-delivery={m.local ?? (m.author === 'customer' ? 'delivered' : '')}
        >
            {m.author !== 'customer' ? (
                <span class="nlw-label" data-ai={m.author === 'ai' ? '' : undefined}>
                    {m.author === 'ai' ? <span aria-hidden="true">✦</span> : null}
                    {name}
                </span>
            ) : null}
            <div class="nlw-bubble">
                {m.body}
                {m.media.map((a, i) => (
                    <MediaItem key={i} item={a} />
                ))}
            </div>
            <span class="nlw-meta">
                <span>{clock(m.created_at)}</span>
                {g ? (
                    <span data-role="delivery" aria-label={g.label} title={g.label}>
                        {g.text}
                    </span>
                ) : null}
            </span>
        </div>
    );
};

/** D10–D13 + EC-06: the queue card in the thread; the number NEVER counts upward — a regressed position drops to the band and holds until it improves. */
let bestPosition: number | null = null;
export function queueView(): { position: number | null; band: string } | null {
    const q = envelope.value.queue;
    if (!q) {
        bestPosition = null;
        return null;
    }
    if (q.position !== null && (bestPosition === null || q.position < bestPosition))
        bestPosition = q.position;
    const shown =
        q.position !== null && bestPosition !== null && q.position <= bestPosition
            ? q.position
            : null;
    return { position: shown, band: q.band };
}

const QueueCard: FunctionComponent = () => {
    const q = queueView();
    if (!q) return null;
    const bandKey = q.band === 'off_hours' ? 'queue.band_long' : `queue.band_${q.band}`;
    return (
        <section
            class="nlw-queue"
            data-role="queue"
            data-position={q.position ?? ''}
            aria-live="off"
        >
            {q.position !== null ? (
                <strong>{t('queue.position', { n: q.position })}</strong>
            ) : (
                <strong>{t('queue.waiting')}</strong>
            )}
            <span>
                {q.position !== null ? t(bandKey) : t('queue.usually', { band: t(bandKey) })}
            </span>
            <span>{t('queue.hold')}</span>
        </section>
    );
};

/** 13c P-FOLD (D19, frame row 20): at most this many rows render; older ones come back on request. */
const FOLD = 200;

export const Thread: FunctionComponent<{
    onStar: (token: string, score: number) => void;
    onShortcut: (label: string) => void;
    onLoadEarlier: () => void;
}> = ({ onStar, onShortcut, onLoadEarlier }) => {
    const list = messages.value.slice(-FOLD);
    const endRef = useRef<HTMLDivElement>(null);
    useEffect(() => {
        endRef.current?.scrollIntoView({ block: 'end' });
    }, [list.length]);
    if (list.length === 0) {
        const shortcuts =
            config.value?.config.content.shortcuts.filter((s) => s.kind === 'chat') ?? [];
        return (
            <div class="nlw-empty" data-role="empty">
                <span aria-hidden="true">✻</span>
                <strong>{t('messages.empty_title')}</strong>
                <span>{t('messages.empty_body')}</span>
                <div class="nlw-list">
                    {shortcuts.map((s, i) => (
                        <button
                            key={i}
                            type="button"
                            class="nlw-btn"
                            onClick={() => onShortcut(s.label[language.value] ?? s.target)}
                        >
                            {s.label[language.value] ?? s.target}
                        </button>
                    ))}
                </div>
            </div>
        );
    }
    let lastDay = '';
    const survey = list.filter((m) => m.survey).pop()?.survey ?? null;
    return (
        <div class="nlw-thread" data-role="thread">
            {earlierCursor.value !== null ? (
                <button
                    type="button"
                    class="nlw-btn nlw-load-earlier"
                    data-role="load-earlier"
                    onClick={onLoadEarlier}
                >
                    {t('messages.load_earlier')}
                </button>
            ) : null}
            {list.map((m) => {
                const day = dayLabel(m.created_at);
                const sep = day !== lastDay;
                lastDay = day;
                return (
                    <>
                        {sep ? (
                            <span class="nlw-day" key={'d' + m.id}>
                                {day}
                            </span>
                        ) : null}
                        <Bubble key={m.id} m={m} />
                    </>
                );
            })}
            <QueueCard />
            {survey ? <StarRow token={survey.token} onStar={onStar} /> : null}
            <div ref={endRef} />
        </div>
    );
};

/** D16/D17: the CSAT star row inline — a radiogroup; the thread simply carries on after it (BR-04). */
export const StarRow: FunctionComponent<{
    token: string;
    onStar: (token: string, score: number) => void;
}> = ({ token, onStar }) => (
    <section class="nlw-queue" data-role="csat">
        <strong>{t('csat.question')}</strong>
        <div class="nlw-stars" role="radiogroup" aria-label={t('csat.question')}>
            {[1, 2, 3, 4, 5].map((n) => (
                <button
                    key={n}
                    type="button"
                    role="radio"
                    aria-checked="false"
                    aria-label={t('csat.star', { n })}
                    class="nlw-star"
                    onClick={() => onStar(token, n)}
                >
                    ★
                </button>
            ))}
        </div>
        <span>{t('csat.optional')}</span>
    </section>
);
