/*
 * §9 / L03: light, dark, or auto — auto follows the VISITOR's system preference
 * (`prefers-color-scheme`), never the host page's stylesheet (the iframe cannot
 * see it; that isolation is the point). EC-11: a swap re-themes in place, no
 * remount — the tokens are CSS variables on the document element.
 */
import { effect, signal } from '@preact/signals';

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
