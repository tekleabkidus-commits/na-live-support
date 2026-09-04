import type { FunctionComponent } from 'preact';
import { useRef, useState } from 'preact/hooks';
import type { WidgetApi } from '../api';
import { AttachMenu, fileFrom, type Pending } from './Attachments';
import { t } from '../i18n';
import { envelope, online } from '../state';

/** G01–G04: one line growing to five, Enter sends, Shift+Enter breaks; paused (blocked) → neutral copy; offline → the banner and typing still works. */
/** EC-10: soft split at `max_text` on the last whitespace before the cap; the customer is told "sent as N messages". */
export function splitText(text: string, max: number): string[] {
    const out: string[] = [];
    let rest = text;
    while (rest.length > max) {
        const cut = rest.lastIndexOf(' ', max);
        const at = cut > max / 2 ? cut : max;
        out.push(rest.slice(0, at).trimEnd());
        rest = rest.slice(at).trimStart();
    }
    if (rest.length > 0 || out.length === 0) out.push(rest);
    return out;
}

export const Composer: FunctionComponent<{
    api: WidgetApi;
    onSend: (text: string, media?: { reference: string; mime: string }) => void;
    onTyping: () => void;
    maxText: number;
    maxMb: number;
    voice: boolean;
    fileUpload: boolean;
}> = ({ api, onSend, onTyping, maxText, maxMb, voice, fileUpload }) => {
    const [text, setText] = useState('');
    const ref = useRef<HTMLTextAreaElement>(null);
    const [pending, setPending] = useState<Pending | null>(null);
    const [menu, setMenu] = useState(false);
    const parts = splitText(text, maxText).length;
    if (envelope.value.blocked) {
        return (
            <p class="nlw-paused" data-role="paused">
                {t('composer.paused')}
            </p>
        );
    }
    const submit = (): void => {
        const v = text.trim();
        if (pending?.reference) {
            onSend(v, { reference: pending.reference, mime: pending.file.type });
            if (pending.preview) URL.revokeObjectURL(pending.preview);
            setPending(null);
        } else if (v) {
            for (const part of splitText(v, maxText)) onSend(part); // EC-10: N parts, in order
        } else {
            return;
        }
        setText('');
        if (ref.current) ref.current.style.height = '';
    };
    const startFile = (f: File): void => {
        if (f.size > maxMb * 1024 * 1024) {
            setPending({
                id: 'refused',
                file: f,
                kind: 'document',
                preview: null,
                done: 0,
                total: f.size,
                controller: new AbortController(),
                reference: null,
                refused: t('upload.too_large', { max_mb: maxMb }),
            });
            return;
        }
        const controller = new AbortController();
        const p: Pending = {
            id: String(Date.now()),
            file: f,
            kind: f.type.startsWith('image/')
                ? 'photo'
                : f.type.startsWith('video/')
                  ? 'video'
                  : f.type.startsWith('audio/')
                    ? 'voice'
                    : 'document',
            preview: f.type.startsWith('image/') ? URL.createObjectURL(f) : null,
            done: 0,
            total: f.size,
            controller,
            reference: null,
            refused: null,
        };
        setPending(p);
        void api
            .upload(
                f,
                (done, total) =>
                    setPending((cur) => (cur && cur.id === p.id ? { ...cur, done, total } : cur)),
                controller.signal,
            )
            .then((r) => {
                if (r.status === -1) return;
                setPending((cur) => {
                    if (!cur || cur.id !== p.id) return cur;
                    if (r.ok && typeof r.body.upload_id === 'string')
                        return { ...cur, done: cur.total, reference: r.body.upload_id };
                    return {
                        ...cur,
                        refused: t(
                            typeof r.body.title === 'string' && r.body.title.startsWith('widget.')
                                ? r.body.title
                                : 'upload.unknown_media',
                            { max_mb: maxMb },
                        ),
                    };
                });
            });
    };
    const onPaste = (e: ClipboardEvent): void => {
        const f = fileUpload && online.value ? fileFrom(e.clipboardData) : null;
        if (f) {
            e.preventDefault();
            (
                document.querySelector('[data-role=attach-start]') as HTMLButtonElement | null
            )?.dispatchEvent(new CustomEvent('nlw-file', { detail: f }));
        }
    };
    return (
        <>
            {!online.value ? (
                <p class="nlw-banner" data-role="offline">
                    ◌ {t('composer.offline')}
                </p>
            ) : null}
            {parts > 1 ? (
                <p class="nlw-banner" data-role="split-notice">
                    {t('upload.split', { count: parts })}
                </p>
            ) : null}
            {fileUpload ? (
                <AttachMenu
                    api={api}
                    maxMb={maxMb}
                    voice={voice}
                    pending={pending}
                    onPending={setPending}
                    open={menu}
                    onToggle={() => setMenu(!menu)}
                />
            ) : null}
            <form
                class="nlw-composer"
                data-drop=""
                onSubmit={(e) => {
                    e.preventDefault();
                    submit();
                }}
                onDragOver={(e) => e.preventDefault()}
                onDrop={(e) => {
                    e.preventDefault();
                    const f = fileFrom(e.dataTransfer);
                    if (f && fileUpload && online.value)
                        (
                            document.querySelector(
                                '[data-role=attach-start]',
                            ) as HTMLButtonElement | null
                        )?.dispatchEvent(new CustomEvent('nlw-file', { detail: f }));
                }}
            >
                {fileUpload ? (
                    <button
                        type="button"
                        class="nlw-icon"
                        data-role="attach-start"
                        aria-label={t('composer.attach')}
                        aria-haspopup="menu"
                        aria-expanded={menu}
                        disabled={!online.value}
                        onClick={() => setMenu(!menu)}
                        ref={(el) => {
                            if (
                                el &&
                                !(el as HTMLButtonElement & { __nlwBound?: boolean }).__nlwBound
                            ) {
                                (el as HTMLButtonElement & { __nlwBound?: boolean }).__nlwBound =
                                    true;
                                el.addEventListener('nlw-file', (ev) =>
                                    startFile((ev as CustomEvent<File>).detail),
                                );
                            }
                        }}
                    >
                        ＋
                    </button>
                ) : null}
                <textarea
                    ref={ref}
                    rows={1}
                    value={text}
                    placeholder={t('composer.placeholder')}
                    aria-label={t('composer.placeholder')}
                    onInput={(e) => {
                        const el = e.currentTarget;
                        setText(el.value);
                        el.style.height = 'auto';
                        const cs = getComputedStyle(el);
                        const cap =
                            5 * parseFloat(cs.lineHeight) +
                            parseFloat(cs.paddingTop) +
                            parseFloat(cs.paddingBottom) +
                            parseFloat(cs.borderTopWidth) +
                            parseFloat(cs.borderBottomWidth); // G01: five lines, then it scrolls
                        el.style.height = Math.min(el.scrollHeight + 2, cap) + 'px';
                        onTyping();
                    }}
                    onPaste={onPaste}
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
                    disabled={text.trim() === '' && !pending?.reference}
                >
                    <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true">
                        <path fill="currentColor" d="M3 11.5 21 3l-8.5 18-2.5-7.5z" />
                    </svg>
                </button>
            </form>
        </>
    );
};
