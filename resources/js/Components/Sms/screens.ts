// Doc 14 (S14 step 13) — the SMS pages' shared helpers. `door`/`tr` are the
// house JSON-door fetch and :key interpolation (one source, re-exported from
// the Designer pages' shared module).
export { door, tr } from '../Widgets/shared';

export type ScreensCopy = Record<string, unknown>;

/** The nested sms.screens copy: section(copy, 'templates') as a flat string map. */
export function section(copy: ScreensCopy, name: string): Record<string, string> {
    const s = copy[name];
    return typeof s === 'object' && s !== null ? (s as Record<string, string>) : {};
}

export function shared(copy: ScreensCopy, key: string): string {
    const v = copy[key];
    return typeof v === 'string' ? v : '';
}

/**
 * The editor's LIVE counter — a client mirror of Encoding\Segmenter's figures
 * (FR-05): the GSM-7 basic set + extension (counted as 2 units); ANY other
 * character (Ethiopic included) switches the whole message to UCS-2. The
 * server's validate door stays the figure of record at Check/Publish.
 */
const GSM7 =
    '@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !"#¤%&\'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà';
const GSM7_EXT = '^{}\\[~]|€';

export function segmentFacts(body: string): {
    chars: number;
    units: number;
    encoding: 'GSM-7' | 'UCS-2';
    perSegment: number;
    segments: number;
} {
    let units = 0;
    let gsm = true;
    for (const ch of body) {
        if (GSM7.includes(ch)) units += 1;
        else if (GSM7_EXT.includes(ch)) units += 2;
        else {
            gsm = false;
            break;
        }
    }
    const chars = [...body].length;
    if (!gsm) {
        const per = chars <= 70 ? 70 : 67;
        return {
            chars,
            units: chars,
            encoding: 'UCS-2',
            perSegment: per,
            segments: Math.max(1, Math.ceil(chars / per)),
        };
    }
    const per = units <= 160 ? 160 : 153;
    return {
        chars,
        units,
        encoding: 'GSM-7',
        perSegment: per,
        segments: Math.max(1, Math.ceil(units / per)),
    };
}
