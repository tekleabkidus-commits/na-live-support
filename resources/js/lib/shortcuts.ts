// FR-31 (S07 step 13) — the COMPLETE v1 keyboard map, as data.
//
// One source of truth: the dispatcher executes THIS array and the ? overlay
// renders THIS array — AC-08's "overlay matches live bindings" is parity by
// construction, and the spec still proves it from the DOM (a second
// implementation of the map anywhere is the defect the parity test exists
// to catch). Labels are i18n keys (§14: localized labels); the KEYS are
// physical and layout-aware only to v1's documented extent (event.key on a
// US-QWERTY assumption — §19 names remapping + non-QWERTY as v2, and the
// overlay footnotes it).
//
// Dispatch rules (AC-08's no-conflict clause is designed here, then proven):
//  - Plain letters and chords NEVER fire while typing (input/textarea/
//    contenteditable) — only bindings marked inInputs (⌘Enter, Esc) do.
//  - Modifier combos avoid every browser default on the support matrix:
//    the only modified bindings are ⌘K, ⌘Enter, Shift+X, Shift+E, ? —
//    none of which the browser claims (⌘L/⌘T/⌘W etc. stay untouched).
//  - G is a CHORD prefix (G then U/M/A within 1.5 s), Gmail-shaped.

export type ShortcutActions = {
    listNext: () => void;
    listPrev: () => void;
    openActive: () => void;
    selectActive: () => void;
    rangeSelectActive: () => void;
    escape: () => void;
    goUnassigned: () => void;
    goMyOpen: () => void;
    goSlaAtRisk: () => void;
    goMentions: () => void;
    openPalette: () => void;
    assignToMe: () => void;
    assignPalette: () => void;
    resolve: () => void;
    priorityPalette: () => void;
    tagPalette: () => void;
    snoozePalette: () => void;
    focusReply: () => void;
    noteMode: () => void;
    send: () => void;
    sendAndResolve: () => void;
    openOverlay: () => void;
};

export type Binding = {
    id: keyof ShortcutActions;
    /** Human-readable key sequence for the overlay (localized keycaps stay physical). */
    keys: string[];
    section: 'list' | 'goto' | 'ticket' | 'composer';
    labelKey: string;
    /** Fires even while an input has focus. */
    inInputs?: boolean;
};

// The nineteen FR-31 bindings, grouped the way B1-S03 draws them.
export const BINDINGS: Binding[] = [
    { id: 'listNext', keys: ['J'], section: 'list', labelKey: 'next_prev' },
    { id: 'listPrev', keys: ['K'], section: 'list', labelKey: 'next_prev' },
    { id: 'openActive', keys: ['Enter', 'O'], section: 'list', labelKey: 'open' },
    { id: 'selectActive', keys: ['X'], section: 'list', labelKey: 'select' },
    { id: 'rangeSelectActive', keys: ['Shift X'], section: 'list', labelKey: 'select_range' },
    { id: 'escape', keys: ['Esc'], section: 'list', labelKey: 'escape', inInputs: true },
    { id: 'goUnassigned', keys: ['G U'], section: 'goto', labelKey: 'go_unassigned' },
    { id: 'goMyOpen', keys: ['G M'], section: 'goto', labelKey: 'go_my_open' },
    { id: 'goSlaAtRisk', keys: ['G A'], section: 'goto', labelKey: 'go_sla' },
    { id: 'goMentions', keys: ['M'], section: 'goto', labelKey: 'go_mentions' },
    { id: 'openPalette', keys: ['⌘K'], section: 'goto', labelKey: 'palette' },
    { id: 'assignToMe', keys: ['I'], section: 'ticket', labelKey: 'assign_me' },
    { id: 'assignPalette', keys: ['A'], section: 'ticket', labelKey: 'assign_to' },
    { id: 'resolve', keys: ['E'], section: 'ticket', labelKey: 'resolve' },
    { id: 'priorityPalette', keys: ['P'], section: 'ticket', labelKey: 'priority' },
    { id: 'tagPalette', keys: ['T'], section: 'ticket', labelKey: 'tags' },
    { id: 'snoozePalette', keys: ['S'], section: 'ticket', labelKey: 'snooze' },
    { id: 'focusReply', keys: ['R'], section: 'composer', labelKey: 'focus_reply' },
    { id: 'noteMode', keys: ['N'], section: 'composer', labelKey: 'note_mode' },
    { id: 'send', keys: ['⌘↵'], section: 'composer', labelKey: 'send', inInputs: true },
    { id: 'sendAndResolve', keys: ['Shift E'], section: 'composer', labelKey: 'send_resolve' },
    { id: 'openOverlay', keys: ['?'], section: 'composer', labelKey: 'overlay' },
];

const CHORD_WINDOW_MS = 1_500;

function isTypingTarget(target: EventTarget | null): boolean {
    if (!(target instanceof HTMLElement)) return false;
    if (target.isContentEditable) return true;
    const tag = target.tagName;
    return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT';
}

/**
 * Attach the dispatcher. Returns a detach function. The G-chord state lives
 * inside the closure; a pending G expires after 1.5 s or on any non-chord key.
 */
export function attachShortcuts(actions: ShortcutActions): () => void {
    let pendingG = 0;

    function onKeydown(event: KeyboardEvent): void {
        const typing = isTypingTarget(event.target);
        const meta = event.metaKey || event.ctrlKey;

        // ⌘K / ⌘Enter — allowed everywhere.
        if (meta && event.key.toLowerCase() === 'k') {
            event.preventDefault();
            actions.openPalette();
            return;
        }
        if (meta && event.key === 'Enter') {
            event.preventDefault();
            actions.send();
            return;
        }
        if (event.key === 'Escape') {
            actions.escape();
            return;
        }
        if (typing || meta || event.altKey) {
            return; // plain-letter bindings never fire while typing
        }

        // The G chord.
        if (pendingG !== 0 && Date.now() - pendingG <= CHORD_WINDOW_MS) {
            pendingG = 0;
            switch (event.key.toLowerCase()) {
                case 'u':
                    event.preventDefault();
                    actions.goUnassigned();
                    return;
                case 'm':
                    event.preventDefault();
                    actions.goMyOpen();
                    return;
                case 'a':
                    event.preventDefault();
                    actions.goSlaAtRisk();
                    return;
                default:
                    break; // fall through — the key means itself
            }
        }
        pendingG = 0;

        if (event.key === '?') {
            event.preventDefault();
            actions.openOverlay();
            return;
        }

        switch (event.key.toLowerCase()) {
            case 'g':
                pendingG = Date.now();
                return;
            case 'j':
                event.preventDefault();
                actions.listNext();
                return;
            case 'k':
                event.preventDefault();
                actions.listPrev();
                return;
            case 'enter': {
                // Enter belongs to a focused interactive element (buttons,
                // links, the listbox's own pattern) — the global map only
                // claims it when focus is roaming (§13's focus system).
                const el = event.target instanceof HTMLElement ? event.target : null;
                if (
                    el &&
                    el.closest(
                        'button, a, select, [role="option"], [role="menuitem"], [role="listbox"], [role="row"], [role="grid"], [role="dialog"]',
                    )
                ) {
                    return;
                }
                event.preventDefault();
                actions.openActive();
                return;
            }
            case 'o':
                event.preventDefault();
                actions.openActive();
                return;
            case 'x':
                event.preventDefault();
                if (event.shiftKey) {
                    actions.rangeSelectActive();
                } else {
                    actions.selectActive();
                }
                return;
            case 'm':
                event.preventDefault();
                actions.goMentions();
                return;
            case 'i':
                event.preventDefault();
                actions.assignToMe();
                return;
            case 'a':
                event.preventDefault();
                actions.assignPalette();
                return;
            case 'e':
                event.preventDefault();
                if (event.shiftKey) {
                    actions.sendAndResolve();
                } else {
                    actions.resolve();
                }
                return;
            case 'p':
                event.preventDefault();
                actions.priorityPalette();
                return;
            case 't':
                event.preventDefault();
                actions.tagPalette();
                return;
            case 's':
                event.preventDefault();
                actions.snoozePalette();
                return;
            case 'r':
                event.preventDefault();
                actions.focusReply();
                return;
            case 'n':
                event.preventDefault();
                actions.noteMode();
                return;
            default:
                return;
        }
    }

    window.addEventListener('keydown', onKeydown);
    return () => window.removeEventListener('keydown', onKeydown);
}
