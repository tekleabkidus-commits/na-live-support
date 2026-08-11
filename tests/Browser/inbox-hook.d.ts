/**
 * The S07 step-9 scripted-stream hook (AC-03): ListPane exposes the SAME
 * store entry points the realtime consumer will call. Shared declaration so
 * every inbox spec sees one type.
 */
type InboxListHook = {
    applyDelta: (delta: {
        op: string;
        id: string;
        sort_key?: number;
        patch?: Record<string, unknown>;
    }) => void;
    viewRemoved: (viewKey: string) => void;
    flush: () => void;
    state: () => { order: string[]; affordance: boolean; selected: string[] };
};

declare interface Window {
    __inboxList: InboxListHook;
}
