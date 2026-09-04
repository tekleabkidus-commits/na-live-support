/*
 * Doc 13 FR-16 / EC-08 / EC-10 / G05–G10 — THE ATTACH PATH: the attach menu
 * (photo, document; voice ONLY under the platform flag — the row is ABSENT,
 * never greyed), the picker, drag/drop, paste (a screenshot lands as a card
 * with a thumbnail, a size and a remove — never a blob that sends itself), the
 * cap checked BEFORE a byte moves, progress with cancel. Keyboard-only: the
 * attach button and the picker are the fallback for a pointer-less visitor.
 */
import type { FunctionComponent } from 'preact';
import { useRef, useState } from 'preact/hooks';
import type { WidgetApi } from '../api';
import { t } from '../i18n';

export interface Pending {
    id: string;
    file: File;
    kind: 'photo' | 'video' | 'voice' | 'document';
    preview: string | null;
    done: number;
    total: number;
    controller: AbortController;
    reference: string | null; // set once the intake accepted it
    refused: string | null; // a calm reason key
}

export function kindOf(file: File): Pending['kind'] {
    if (file.type.startsWith('image/')) return 'photo';
    if (file.type.startsWith('video/')) return 'video';
    if (file.type.startsWith('audio/')) return 'voice';
    return 'document';
}

export function formatBytes(n: number): string {
    if (n >= 1024 * 1024) return (n / (1024 * 1024)).toFixed(1) + ' MB';
    if (n >= 1024) return Math.round(n / 1024) + ' KB';
    return n + ' B';
}

interface Props {
    api: WidgetApi;
    maxMb: number;
    voice: boolean;
    pending: Pending | null;
    onPending: (p: Pending | null) => void;
}

export const AttachMenu: FunctionComponent<Props & { open: boolean; onToggle: () => void }> = ({
    api,
    maxMb,
    voice,
    pending,
    onPending,
    open,
    onToggle,
}) => {
    const input = useRef<HTMLInputElement>(null);
    const [accept, setAccept] = useState('image/*');

    const start = (file: File): void => {
        // EC-08: the cap, checked BEFORE a single byte moves
        if (file.size > maxMb * 1024 * 1024) {
            onPending({
                id: 'refused',
                file,
                kind: kindOf(file),
                preview: null,
                done: 0,
                total: file.size,
                controller: new AbortController(),
                reference: null,
                refused: t('upload.too_large', { max_mb: maxMb }),
            });
            return;
        }
        const controller = new AbortController();
        const p: Pending = {
            id: String(Date.now()),
            file,
            kind: kindOf(file),
            preview: file.type.startsWith('image/') ? URL.createObjectURL(file) : null,
            done: 0,
            total: file.size,
            controller,
            reference: null,
            refused: null,
        };
        onPending(p);
        void api
            .upload(file, (done, total) => onPending({ ...p, done, total }), controller.signal)
            .then((r) => {
                if (r.status === -1) return; // cancelled: the card is already gone
                if (r.ok && typeof r.body.upload_id === 'string')
                    onPending({ ...p, done: p.total, total: p.total, reference: r.body.upload_id });
                else
                    onPending({
                        ...p,
                        refused: t(
                            typeof r.body.title === 'string' && r.body.title.startsWith('widget.')
                                ? r.body.title
                                : 'upload.unknown_media',
                            { max_mb: maxMb },
                        ),
                    });
            });
    };

    const pick = (a: string): void => {
        setAccept(a);
        onToggle();
        window.setTimeout(() => input.current?.click(), 0);
    };

    return (
        <>
            <input
                ref={input}
                type="file"
                accept={accept}
                class="nlw-sr"
                aria-label={t('composer.attach')}
                onChange={(e) => {
                    const f = e.currentTarget.files?.[0];
                    if (f) start(f);
                    e.currentTarget.value = '';
                }}
            />
            {open ? (
                <div class="nlw-menu nlw-attach" role="menu" data-role="attach-menu">
                    <button type="button" role="menuitem" onClick={() => pick('image/*')}>
                        🖼 {t('upload.photo')}
                    </button>
                    <button
                        type="button"
                        role="menuitem"
                        onClick={() => pick('.pdf,.doc,.docx,.xls,.xlsx,.txt,application/pdf')}
                    >
                        📄 {t('upload.document')}
                    </button>
                    {voice ? (
                        <button type="button" role="menuitem" onClick={() => pick('audio/*')}>
                            🎤 {t('upload.voice')}
                        </button>
                    ) : null}
                    <span class="nlw-meta">{t('upload.limits', { max_mb: maxMb })}</span>
                </div>
            ) : null}
            {pending ? (
                <div
                    class="nlw-attachment"
                    data-role="attachment"
                    data-state={
                        pending.refused ? 'refused' : pending.reference ? 'ready' : 'uploading'
                    }
                >
                    {pending.preview ? (
                        <img src={pending.preview} alt="" />
                    ) : (
                        <span aria-hidden="true">▤</span>
                    )}
                    <span class="nlw-attachment-body">
                        <strong>{pending.file.name || t('upload.pasted')}</strong>
                        {pending.refused ? (
                            <small class="nlw-error">{pending.refused}</small>
                        ) : pending.reference ? (
                            <small>{formatBytes(pending.total)}</small>
                        ) : (
                            <small>
                                {t('upload.uploading', {
                                    done: formatBytes(pending.done),
                                    total: formatBytes(pending.total),
                                })}
                                <progress max={pending.total || 1} value={pending.done} />
                            </small>
                        )}
                    </span>
                    <button
                        type="button"
                        class="nlw-icon"
                        aria-label={
                            pending.reference || pending.refused
                                ? t('upload.remove')
                                : t('upload.cancel')
                        }
                        onClick={() => {
                            pending.controller.abort();
                            if (pending.preview) URL.revokeObjectURL(pending.preview);
                            onPending(null);
                        }}
                    >
                        ✕
                    </button>
                </div>
            ) : null}
        </>
    );
};

/** A paste or a drop hands its first file to the same path (G06: the pasted screenshot is first-class). */
export function fileFrom(dt: DataTransfer | null): File | null {
    if (!dt) return null;
    const f = dt.files?.[0];
    if (f) return f; // a nameless paste keeps no name here: the card says 'Pasted screenshot' (G06); the upload names the part
    for (const item of Array.from(dt.items ?? [])) {
        if (item.kind === 'file') {
            const file = item.getAsFile();
            if (file) return new File([file], file.name || 'pasted.png', { type: file.type });
        }
    }
    return null;
}

export { kindOf as attachmentKind };
