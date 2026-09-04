/*
 * C-08 / N-03: every visible string is a KEY resolved from the config envelope's
 * `strings` (the widget.* namespace for the resolved language). A missing key
 * renders as the key itself — visible, never silent — so a hole is a defect on
 * screen, not a blank. `:param` interpolation and Laravel's `{1} one|[2,*] many`
 * choice form are supported (the unread label uses it).
 */
import { signal } from '@preact/signals';

export const strings = signal<Record<string, unknown>>({});

function lookup(key: string): string | null {
    let node: unknown = strings.value;
    for (const part of key.split('.')) {
        if (node && typeof node === 'object' && part in (node as Record<string, unknown>))
            node = (node as Record<string, unknown>)[part];
        else return null;
    }
    return typeof node === 'string' ? node : null;
}

function choose(text: string, count: number): string {
    if (!text.includes('|')) return text;
    for (const part of text.split('|')) {
        const m = /^\s*(\{(\d+)\}|\[(\d+),(\d+|\*)\])\s*(.*)$/.exec(part);
        if (!m) continue;
        if (m[2] !== undefined && Number(m[2]) === count) return m[5] ?? '';
        if (m[3] !== undefined) {
            const lo = Number(m[3]);
            const hi = m[4] === '*' ? Infinity : Number(m[4]);
            if (count >= lo && count <= hi) return m[5] ?? '';
        }
    }
    return text.split('|').pop() ?? text;
}

export function t(key: string, params: Record<string, string | number> = {}): string {
    let text = lookup(key) ?? key;
    if (typeof params.count === 'number') text = choose(text, params.count);
    for (const [k, v] of Object.entries(params)) text = text.split(':' + k).join(String(v));
    return text;
}

export function has(key: string): boolean {
    return lookup(key) !== null;
}
