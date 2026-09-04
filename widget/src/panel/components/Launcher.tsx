import type { FunctionComponent } from 'preact';
import { t } from '../i18n';
import { open, unread } from '../state';
import { reducedMotion } from '../theme';

/** B01–B03: the 56 px bubble; the badge is a NUMBER and the button's label says it (never a bare dot); the 150 ms mark→close morph. */
export const Launcher: FunctionComponent<{ onToggle: () => void; nudge: boolean }> = ({
    onToggle,
    nudge,
}) => {
    const n = unread.value;
    const label =
        (open.value ? t('launcher.close') : t('launcher.open')) +
        (n > 0 && !open.value ? ' — ' + t('launcher.unread', { count: n }) : '');
    return (
        <button
            type="button"
            class="nlw-launcher"
            data-open={String(open.value)}
            data-nudge={String(nudge && !reducedMotion.value)}
            aria-label={label}
            onClick={onToggle}
        >
            <svg class="nlw-mark" viewBox="0 0 24 24" aria-hidden="true">
                <path
                    fill="currentColor"
                    d="M4 4h16a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H9l-5 4v-4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z"
                />
            </svg>
            <svg class="nlw-x" viewBox="0 0 24 24" aria-hidden="true">
                <path
                    fill="currentColor"
                    d="M6.2 4.8 12 10.6l5.8-5.8 1.4 1.4L13.4 12l5.8 5.8-1.4 1.4L12 13.4l-5.8 5.8-1.4-1.4L10.6 12 4.8 6.2z"
                />
            </svg>
            {n > 0 && !open.value ? (
                <span class="nlw-badge" aria-hidden="true">
                    {n}
                </span>
            ) : null}
        </button>
    );
};
