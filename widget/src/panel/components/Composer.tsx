import type { FunctionComponent } from 'preact';
import { useRef, useState } from 'preact/hooks';
import { t } from '../i18n';
import { envelope, online } from '../state';

/** G01–G04: one line growing to five, Enter sends, Shift+Enter breaks; paused (blocked) → neutral copy; offline → the banner and typing still works. */
export const Composer: FunctionComponent<{
    onSend: (text: string) => void;
    onTyping: () => void;
    maxText: number;
}> = ({ onSend, onTyping, maxText }) => {
    const [text, setText] = useState('');
    const ref = useRef<HTMLTextAreaElement>(null);
    if (envelope.value.blocked) {
        return (
            <p class="nlw-paused" data-role="paused">
                {t('composer.paused')}
            </p>
        );
    }
    const submit = (): void => {
        const v = text.trim();
        if (!v) return;
        onSend(v.slice(0, maxText));
        setText('');
        if (ref.current) ref.current.style.height = '';
    };
    return (
        <>
            {!online.value ? (
                <p class="nlw-banner" data-role="offline">
                    ◌ {t('composer.offline')}
                </p>
            ) : null}
            <form
                class="nlw-composer"
                onSubmit={(e) => {
                    e.preventDefault();
                    submit();
                }}
            >
                <textarea
                    ref={ref}
                    rows={1}
                    value={text}
                    maxLength={maxText}
                    placeholder={t('composer.placeholder')}
                    aria-label={t('composer.placeholder')}
                    onInput={(e) => {
                        const el = e.currentTarget;
                        setText(el.value);
                        el.style.height = 'auto';
                        el.style.height = Math.min(el.scrollHeight, 5 * 20 + 20) + 'px';
                        onTyping();
                    }}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter' && !e.shiftKey) {
                            e.preventDefault();
                            submit();
                        }
                    }}
                />
                <button
                    type="submit"
                    class="nlw-send"
                    aria-label={t('composer.send')}
                    disabled={text.trim() === ''}
                >
                    <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true">
                        <path fill="currentColor" d="M3 11.5 21 3l-8.5 18-2.5-7.5z" />
                    </svg>
                </button>
            </form>
        </>
    );
};
