import { defineStore } from 'pinia';

/**
 * FR-11 — the live list (S07 step 9). T-02 deltas {op: add|remove|patch}
 * PATCH ROWS IN PLACE immediately; RE-ORDERING is BATCHED EVERY 5 s, and
 * while the user's pointer is inside the list the batch does NOT apply —
 * a "list updated — resort" affordance shows instead (no rug-pulls
 * mid-click; B12-A01 AC-04's exact bar). New rows entering slide in with a
 * 300 ms highlight; leaving rows fade + collapse (150 ms) — both suppressed
 * under prefers-reduced-motion (the components read `entering`/`leaving`).
 *
 * SABOTAGE #6's TARGET: bypass the batcher (apply resorts the moment a
 * delta lands) and inbox-live-list.spec.ts goes red on exactly the
 * affordance + rows-never-move-under-pointer assertions.
 *
 * FR-13/EC-02: selection lives HERE by ticket id — it survives resorts by
 * construction (ids don't move), and a selected id whose row LEAVES the
 * view moves to the "no longer in view" tray on the bulk bar (EC-02) until
 * deselected or executed (the T-05 drift report stays the server truth).
 *
 * EC-09: a `view_removed` push for the current view → onViewRemoved fires
 * (the pane toasts and falls back to My Open; deep links to it do the same).
 */

export type ListRow = Record<string, unknown> & {
    ticket_id: string;
    conversation_id: string;
    sort_key?: number;
    unread?: number;
    has_draft?: boolean;
};

type Delta = {
    op: 'add' | 'remove' | 'patch';
    id: string;
    sort_key?: number;
    patch?: Record<string, unknown>;
};

export const RESORT_BATCH_MS = 5000;

export const useLiveList = defineStore('inbox-live-list', {
    state: () => ({
        viewKey: '' as string,
        rows: {} as Record<string, ListRow>,
        order: [] as string[],
        total: 0,
        nextCursor: null as string | null,
        // FR-11 batching state
        pendingResort: false,
        pendingChanges: 0,
        resortAffordance: false,
        pointerInside: false,
        entering: {} as Record<string, boolean>,
        leaving: {} as Record<string, boolean>,
        // FR-13 selection (by id — survives resorts by construction)
        selected: [] as string[],
        selectionToken: null as { id: string; count: number } | null,
        // FR-31/§13 (step 13): the roving-tabindex listbox's ACTIVE row —
        // J/K and the arrows move it; Enter/O open it; X selects it. It is
        // presentation state, not selection (aria-selected is `selected`).
        activeId: null as string | null,
        // EC-09
        removedViewToast: false,
        _timer: null as ReturnType<typeof setTimeout> | null,
    }),

    getters: {
        orderedRows(state): ListRow[] {
            return state.order
                .map((id) => state.rows[id])
                .filter((row): row is ListRow => row !== undefined);
        },
        // EC-02: selected ids with no row in the current view membership.
        trayIds(state): string[] {
            return state.selected.filter((id) => !state.order.includes(id));
        },
        selectedInView(state): string[] {
            return state.selected.filter((id) => state.order.includes(id));
        },
    },

    actions: {
        hydrate(viewKey: string, rows: ListRow[], total: number, nextCursor: string | null): void {
            this.viewKey = viewKey;
            this.rows = Object.fromEntries(rows.map((row) => [row.ticket_id, row]));
            this.order = rows.map((row) => row.ticket_id);
            this.total = total;
            this.nextCursor = nextCursor;
            this.pendingResort = false;
            this.pendingChanges = 0;
            this.resortAffordance = false;
            // Selection is NOT cleared on refetch — FR-13: it survives.
            // The ACTIVE row is presentation state and does NOT survive its
            // row leaving the view: a lit id whose row a refetch dropped is
            // not "lit" in any sense a user can see, and holding it made
            // every ticket action a silent no-op (D1, advisor ruling
            // 2026-08-13 — the dispatcher falls back to the open thread).
            if (this.activeId !== null && !(this.activeId in this.rows)) {
                this.activeId = null;
            }
        },

        appendPage(rows: ListRow[], nextCursor: string | null): void {
            for (const row of rows) {
                if (!(row.ticket_id in this.rows)) {
                    this.order.push(row.ticket_id);
                }
                this.rows[row.ticket_id] = row;
            }
            this.nextCursor = nextCursor;
        },

        /** The T-02 consumer. Patches land IN PLACE now; ORDER waits for the batch. */
        applyDelta(delta: Delta): void {
            const existing = this.rows[delta.id];
            if (delta.op === 'patch' && existing) {
                Object.assign(existing, delta.patch ?? {});
                if (typeof delta.sort_key === 'number') {
                    existing.sort_key = delta.sort_key;
                    this.queueResort(); // position may have changed — batched
                }
                return;
            }

            if (delta.op === 'add') {
                this.rows[delta.id] = {
                    ticket_id: delta.id,
                    conversation_id: String((delta.patch ?? {}).conversation_id ?? ''),
                    sort_key: delta.sort_key,
                    ...(delta.patch ?? {}),
                } as ListRow;
                this.total += 1;
                this.entering[delta.id] = true; // 300 ms highlight at insert
                this.queueResort();
                return;
            }

            // remove: the row fades + collapses (150 ms), then leaves on the
            // batch; the id stays selected (EC-02 — the tray shows it).
            if (this.rows[delta.id]) {
                this.leaving[delta.id] = true;
                this.total = Math.max(0, this.total - 1);
                this.queueResort();
            }
        },

        /** FR-11: the 5 s batcher — sabotage #6 bypasses exactly this. */
        queueResort(): void {
            this.pendingResort = true;
            this.pendingChanges += 1;
            if (this._timer !== null) {
                return;
            }
            this._timer = setTimeout(() => {
                this._timer = null;
                if (this.pointerInside) {
                    // The pointer holds the list still — offer, never yank.
                    this.resortAffordance = true;
                    return;
                }
                this.flushResort();
            }, RESORT_BATCH_MS);
        },

        /** Applies the batched order — the affordance's click lands here too. */
        flushResort(): void {
            if (!this.pendingResort) {
                return;
            }
            for (const id of Object.keys(this.leaving)) {
                delete this.rows[id];
                delete this.leaving[id];
            }
            const currentIds = Object.keys(this.rows);
            const descending = this.descendingViews().includes(this.viewKey);
            currentIds.sort((a, b) => {
                const scoreA = Number(this.rows[a]?.sort_key ?? 0);
                const scoreB = Number(this.rows[b]?.sort_key ?? 0);
                if (scoreA !== scoreB) {
                    return descending ? scoreB - scoreA : scoreA - scoreB;
                }
                return a < b ? -1 : 1; // the R-23 lexical tiebreak, mirrored
            });
            this.order = currentIds;
            this.pendingResort = false;
            this.pendingChanges = 0;
            this.resortAffordance = false;
            // Same D1 repair as hydrate: a leaving row takes its lit state
            // with it, so the next action targets the open thread instead of
            // silently dereferencing a ghost.
            if (this.activeId !== null && !(this.activeId in this.rows)) {
                this.activeId = null;
            }
            // entering highlights clear on the next frame cycle
            setTimeout(() => {
                this.entering = {};
            }, 350);
        },

        setPointerInside(inside: boolean): void {
            this.pointerInside = inside;
            if (!inside && this.resortAffordance) {
                this.flushResort(); // the pointer left — the held batch lands
            }
        },

        // ── FR-13 selection ────────────────────────────────────────────────
        /** FR-31 J/K (and the mirroring arrows): step the active row. */
        activateStep(delta: 1 | -1): void {
            if (this.order.length === 0) {
                return;
            }
            const index = this.activeId === null ? -1 : this.order.indexOf(this.activeId);
            const next =
                index === -1
                    ? delta === 1
                        ? 0
                        : this.order.length - 1
                    : Math.min(this.order.length - 1, Math.max(0, index + delta));
            this.activeId = this.order[next] ?? null;
        },

        toggleSelect(id: string): void {
            this.selected = this.selected.includes(id)
                ? this.selected.filter((s) => s !== id)
                : [...this.selected, id];
            this.selectionToken = null; // any manual change invalidates a minted token
        },

        rangeSelect(fromId: string, toId: string): void {
            const from = this.order.indexOf(fromId);
            const to = this.order.indexOf(toId);
            if (from === -1 || to === -1) {
                return;
            }
            const slice = this.order.slice(Math.min(from, to), Math.max(from, to) + 1);
            this.selected = [...new Set([...this.selected, ...slice])];
            this.selectionToken = null;
        },

        deselect(id: string): void {
            this.selected = this.selected.filter((s) => s !== id);
        },

        clearSelection(): void {
            this.selected = [];
            this.selectionToken = null;
        },

        // ── EC-09 ──────────────────────────────────────────────────────────
        onViewRemoved(viewKey: string): void {
            if (viewKey === this.viewKey) {
                this.removedViewToast = true;
            }
        },

        descendingViews(): string[] {
            return ['my_open', 'following', 'rescue', 'mentions'];
        },
    },
});
