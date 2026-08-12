// T-06 (S07 step 13): every optimistic mutation is a COMMAND OBJECT pairing
// its optimistic apply with a rollback handler — NFR-06's enforcement point.
//
// The shape is deliberately small: `optimistic()` applies the hopeful state
// and returns whatever snapshot the rollback needs; `execute()` asks the
// server; a non-2xx or a thrown fetch rolls the snapshot back and hands the
// caller a reason to SHOW (BR-01: the inbox never lies — reconcile or roll
// back visibly; the caller owns the toast/strip, this owns the ordering).
//
// Consumers this step: the mobile swipe-claim (row optimistically "claimed by
// you", rolled back on already_claimed with the racer's name), the actions
// sheet's transition/priority rows, and the palettes' assign/priority/tags.

export type CommandResult = {
    ok: boolean;
    status: number | null;
    /** The stable C-04 code when the server sent problem details. */
    code: string | null;
    /** Parsed response body when JSON — the caller may need `by`, `tags`, … */
    body: Record<string, unknown> | null;
};

export async function runCommand<Snapshot>(command: {
    optimistic: () => Snapshot;
    execute: () => Promise<Response>;
    rollback: (snapshot: Snapshot) => void;
}): Promise<CommandResult> {
    const snapshot = command.optimistic();
    let response: Response;
    try {
        response = await command.execute();
    } catch {
        command.rollback(snapshot);
        return { ok: false, status: null, code: 'network_lost', body: null };
    }

    let body: Record<string, unknown> | null = null;
    try {
        body = (await response.json()) as Record<string, unknown>;
    } catch {
        body = null;
    }

    if (!response.ok) {
        command.rollback(snapshot);
        return {
            ok: false,
            status: response.status,
            code: typeof body?.code === 'string' ? body.code : null,
            body,
        };
    }
    return { ok: true, status: response.status, code: null, body };
}
