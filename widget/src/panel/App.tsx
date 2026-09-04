import type { FunctionComponent } from 'preact';

/** Step-6 shell: nothing user-visible is a literal here — copy arrives from the config envelope at step 8 (N-03). */
export const App: FunctionComponent<{ kind: 'launcher' | 'panel' }> = ({ kind }) => (
    <div data-nlw-frame={kind} class="nlw-shell" />
);
