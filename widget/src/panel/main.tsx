/*
 * Doc 13 T-01 — the PANEL entry (Preact + signals; ADR 0020). Step 6 lands the
 * shell only: the frame boots, tells the loader it is `ready`, and lazy-loads
 * the Help tab and the form renderer as separate chunks the FIRST time the
 * loader's API asks for them (NFR-01: the core never imports them statically —
 * the budget gate asserts it from Vite's manifest: `dynamicImports`, never
 * `imports`). The three tabs, the thread and the transport arrive at step 8.
 */
import { render } from 'preact';
import { signal } from '@preact/signals';
import { App } from './App';

export const frameKind = signal<'launcher' | 'panel'>(
    new URLSearchParams(location.search).get('frame') === 'launcher' ? 'launcher' : 'panel',
);
export const helpRequested = signal(false);
export const formRequested = signal(false);

type ApiCall = { method: string; args: unknown[] };

/** The loader's API frames (queued until `ready`): the lazy doors open here, once. */
function onLoaderMessage(ev: MessageEvent): void {
    const data = ev.data as { nlw?: number; type?: string; data?: ApiCall } | null;
    if (!data || data.nlw !== 1 || data.type !== 'api' || !data.data) return;
    if (data.data.method === 'openHelp' && !helpRequested.value) {
        helpRequested.value = true;
        void import('./tabs/Help');
    }
    if (data.data.method === 'open' && !formRequested.value) {
        formRequested.value = true;
        void import('./forms/FormRenderer');
    }
    // FR-02 open/close on the shell: the loader sizes the panel frame from THIS frame's answer
    // (380×640, or the full sheet under 640 px — the drawn 375 law) and the host's hooks hear
    // the event NAME only. The tabs and the thread arrive at step 8.
    if (data.data.method === 'open' || data.data.method === 'close') {
        const open = data.data.method === 'open';
        window.parent.postMessage(
            {
                nlw: 1,
                type: 'size',
                data: { open, sheet: open && window.matchMedia('(max-width: 640px)').matches },
            },
            '*',
        );
        window.parent.postMessage(
            { nlw: 1, type: 'event', data: { name: open ? 'open' : 'close' } },
            '*',
        );
    }
}

const root = document.getElementById('nlw-root');
if (root) {
    window.addEventListener('message', onLoaderMessage);
    render(<App kind={frameKind.value} />, root);
    try {
        window.parent.postMessage({ nlw: 1, type: 'ready' }, '*'); // the loader filters on its frame origin; the panel narrows the target at step 8 (config-carried)
    } catch {
        /* a dead parent is not our problem to crash on (NFR-06) */
    }
}
