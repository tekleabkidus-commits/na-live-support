/*
 * §9 / L03: light, dark, or auto — auto follows the VISITOR's system preference
 * (`prefers-color-scheme`), never the host page's stylesheet (the iframe cannot
 * see it; that isolation is the point). EC-11: a swap re-themes in place, no
 * remount — the tokens are CSS variables on the document element.
 */
import { effect, signal } from '@preact/signals';
import { config } from './state';

export type ThemeChoice = 'light' | 'dark' | 'auto';
export const theme = signal<ThemeChoice>('auto');
export const reducedMotion = signal(false);

const dark = window.matchMedia('(prefers-color-scheme: dark)');
const motion = window.matchMedia('(prefers-reduced-motion: reduce)');
const systemDark = signal(dark.matches);
reducedMotion.value = motion.matches;
dark.addEventListener('change', (e) => (systemDark.value = e.matches));
motion.addEventListener('change', (e) => (reducedMotion.value = e.matches));

export function resolvedTheme(): 'light' | 'dark' {
    return theme.value === 'auto' ? (systemDark.value ? 'dark' : 'light') : theme.value;
}

effect(() => {
    document.documentElement.setAttribute('data-theme', resolvedTheme());
    document.documentElement.setAttribute('data-motion', reducedMotion.value ? 'reduce' : 'full');
});

/** FR-19 Appearance / EC-11: the published brand accent lands as CSS variables — a swap in place, never a remount.
 *  A null brand = the neutral default tokens (the design system's own, AA by construction). */
effect(() => {
    const brand = config.value?.config.appearance.brand ?? null;
    const root = document.documentElement.style;
    if (brand && /^#[0-9a-fA-F]{6}$/.test(brand.accent)) {
        root.setProperty('--ds-accent', brand.accent);
        root.setProperty('--ds-accent-contrast', accentContrast(brand.accent));
    } else {
        root.removeProperty('--ds-accent');
        root.removeProperty('--ds-accent-contrast');
    }
});

/** White or near-black on the accent, whichever reads better (the server gate already proved the accent on both panels). */
function accentContrast(hex: string): string {
    const n = parseInt(hex.slice(1), 16);
    const lin = (c: number): number => {
        const v = c / 255;
        return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4;
    };
    const l = 0.2126 * lin((n >> 16) & 255) + 0.7152 * lin((n >> 8) & 255) + 0.0722 * lin(n & 255);
    return 1.05 / (l + 0.05) >= (l + 0.05) / 0.05 ? '#ffffff' : '#171714';
}
