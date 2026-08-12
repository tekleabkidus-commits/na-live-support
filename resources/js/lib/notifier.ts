import { isLeader } from './tabLeader';

/**
 * FR-27 sounds + SEC-04 desktop notifications (S07 step 12).
 *
 * SEC-04 IS A CONTENT LAW: a desktop notification carries the customer
 * display name + ticket number ONLY — never message content (the
 * shoulder-surfing posture); the reduced setting drops even the name
 * ("New activity"). buildNotificationContent is the ONE place that shapes
 * it — sabotage #19 adds the snippet there and the content proof goes red.
 *
 * FR-04/FR-27: the TAB LEADER plays once — every emission gates on
 * isLeader(); OS focus is respected (no sound while the thread is visible
 * and focused). FS-04: a DENIED Notification permission downgrades
 * SILENTLY, sets the one-time bell-menu hint flag, and NEVER re-prompts.
 */

export type NotifyEvent = {
    kind: 'my_open_message' | 'unassigned_in_queue';
    customerName: string;
    ticketNumber: number | string;
    snippet?: string; // present in the EVENT — never in the notification
};

export type NotifierPrefs = {
    soundMyOpen: boolean; // FR-27 default ON
    soundUnassigned: boolean; // FR-27 default OFF
    reduced: boolean; // SEC-04's further reduction
    mutedUntil: string | null; // FR-26 quick mute
    reducedLabel: string; // i18n: "New activity"
};

// Observable emissions for the dual-tab proof (a data hook, not audio
// hardware — the PROOF counts emissions per tab).
const emitted: { sounds: number; notifications: string[] } = { sounds: 0, notifications: [] };
let permissionHintNeeded = false;

/** SEC-04's one shaping point: name + number ONLY. Sabotage #19's target. */
export function buildNotificationContent(event: NotifyEvent, prefs: NotifierPrefs): string {
    if (prefs.reduced) {
        return prefs.reducedLabel;
    }

    return `${event.customerName} · #${event.ticketNumber}`;
}

export function notify(event: NotifyEvent, prefs: NotifierPrefs): void {
    // FR-26 quick mute silences everything.
    if (prefs.mutedUntil !== null && new Date(prefs.mutedUntil).getTime() > Date.now()) {
        return;
    }
    // FR-04: only the leader emits — the dedup the dual-tab proof pins.
    if (!isLeader()) {
        return;
    }
    // FR-27: respect OS focus — no sound when the tab is visible & focused.
    const focused = document.visibilityState === 'visible' && document.hasFocus();

    const soundWanted =
        event.kind === 'my_open_message' ? prefs.soundMyOpen : prefs.soundUnassigned;
    if (soundWanted && !focused) {
        emitted.sounds += 1; // the audible half is an <audio> hook later; the COUNT is the contract
    }

    if (!focused && typeof Notification !== 'undefined') {
        if (Notification.permission === 'granted') {
            const content = buildNotificationContent(event, prefs);
            emitted.notifications.push(content);

            new Notification(content);
        } else if (Notification.permission === 'denied') {
            // FS-04: silent downgrade + the one-time hint; NEVER a re-prompt loop.
            permissionHintNeeded = true;
        }
    }
}

export function notificationPermissionHintNeeded(): boolean {
    return permissionHintNeeded;
}

export function notifierEmissions(): { sounds: number; notifications: string[] } {
    return { sounds: emitted.sounds, notifications: [...emitted.notifications] };
}
