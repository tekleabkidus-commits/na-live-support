import { defineStore } from 'pinia';

/**
 * FR-01/FR-08/§9 — per-user workspace prefs (region sizes, rail/context
 * collapse, density), server-persisted via PATCH /inbox/prefs so they
 * restore across sessions (AC-01).
 *
 * THE COUNTERSIGNED READ-ONLY RIDER (advisor, 4+5 acceptance; due this
 * step): the prefs WRITE rides views.manage (R-26's generalized principle),
 * so a Read Only viewer's PATCH answers 403 BY DESIGN. The UI degrades
 * GRACEFULLY: prefs fall back to session-local storage, the refusal is
 * SILENT, and zero error toasts render. A read-and-observe role gets a
 * workspace that remembers its choices for the session and forgets them
 * after — never an error state.
 */

export type RegionPrefs = {
    rail_collapsed?: boolean;
    context_collapsed?: boolean;
    list_width?: number;
};

type PrefsBlob = {
    regions?: RegionPrefs;
    density?: 'comfortable' | 'compact';
    view_order?: string[];
};

const SESSION_KEY = 'inbox-prefs-session-local';

export const LIST_WIDTH_MIN = 300;
export const LIST_WIDTH_MAX = 480;
export const LIST_WIDTH_DEFAULT = 360;

function xsrf(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return match?.[1] ? decodeURIComponent(match[1]) : '';
}

export const useInboxPrefs = defineStore('inbox-prefs', {
    state: () => ({
        regions: {} as RegionPrefs,
        density: 'comfortable' as 'comfortable' | 'compact',
        viewOrder: [] as string[],
        // Flipped on the FIRST 403 — later writes skip the network entirely
        // (silent, no retry storm) and stay session-local.
        sessionLocal: false,
    }),
    actions: {
        hydrate(server: PrefsBlob): void {
            // Session-local overlay wins WITHIN the session (the rider's
            // "remembers its choices for the session" shape); the server
            // blob is the cross-session truth for everyone else.
            const local = this.readSessionLocal();
            const merged = { ...server, ...local };
            this.regions = merged.regions ?? {};
            this.density = merged.density ?? 'comfortable';
            this.viewOrder = merged.view_order ?? [];
        },

        setRegions(patch: RegionPrefs): void {
            this.regions = { ...this.regions, ...patch };
            void this.persist({ regions: this.regions });
        },

        setDensity(density: 'comfortable' | 'compact'): void {
            this.density = density;
            void this.persist({ density });
        },

        async persist(patch: PrefsBlob): Promise<void> {
            if (this.sessionLocal) {
                this.writeSessionLocal(patch);
                return;
            }
            try {
                const response = await fetch('/inbox/prefs', {
                    method: 'PATCH',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-XSRF-TOKEN': xsrf(),
                    },
                    body: JSON.stringify({ prefs: patch }),
                });
                if (response.status === 403) {
                    // The designed Read Only refusal — degrade, silently.
                    this.sessionLocal = true;
                    this.writeSessionLocal(patch);
                }
                // Any other failure: the in-memory state already applied;
                // next successful write carries the merged blob. No toast —
                // prefs are never worth an interruption.
            } catch {
                // Offline: same posture. FR-29's offline machinery (step 13)
                // owns queued mutations; prefs simply stay in memory.
            }
        },

        readSessionLocal(): PrefsBlob {
            try {
                const raw = sessionStorage.getItem(SESSION_KEY);
                return raw ? (JSON.parse(raw) as PrefsBlob) : {};
            } catch {
                return {};
            }
        },

        writeSessionLocal(patch: PrefsBlob): void {
            try {
                sessionStorage.setItem(
                    SESSION_KEY,
                    JSON.stringify({ ...this.readSessionLocal(), ...patch }),
                );
            } catch {
                // Storage full/blocked: in-memory state still holds for the tab.
            }
        },
    },
});
