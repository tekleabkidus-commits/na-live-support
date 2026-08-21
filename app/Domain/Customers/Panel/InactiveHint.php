<?php

declare(strict_types=1);

namespace App\Domain\Customers\Panel;

use Illuminate\Support\Facades\DB;

/**
 * Doc 08 EC-03's heuristic chip (the S09 side-row, R-D09-3; countersigned
 * packet): "profile shows last-known display with an INACTIVE HINT from
 * repeated hard failures (heuristic chip, informational)".
 *
 * THE HEURISTIC, stated so it can be argued with: per channel of the
 * customer's conversations, the LAST three outbound rows are ALL
 * failed_final with delivery_error.reason = blocked_by_user (Doc 05's hard
 * class for a deleted/blocking account) and no sent/delivered row follows
 * them. Anything else — fewer failures, a transient reason (network,
 * rate-limit, window), a later success — is NOT inactive. Read-only, DB
 * ordering (C-11), identifiers + counts + a stamp out; never a message.
 * Informational only: nothing routes, blocks or deletes on it (N-19's
 * spirit — a hint hides nothing and destroys nothing).
 */
final class InactiveHint
{
    public const RUN = 3;

    /** @return array{channel: string, hard_failures: int, since: string}|null */
    public function for(string $customerId): ?array
    {
        /** @var list<object{channel: string, states: string, reasons: string, since: ?string}> $rows */
        $rows = DB::select(<<<'SQL'
            WITH recent AS (
                SELECT c.channel, m.delivery_state, m.delivery_error->>'reason' AS reason, m.created_at,
                       row_number() OVER (PARTITION BY c.channel ORDER BY m.created_at DESC, m.id DESC) AS rn
                  FROM messages m
                  JOIN conversations c ON c.id = m.conversation_id
                 WHERE c.customer_id = ? AND m.direction = 'out' AND m.kind <> 'note'
            )
            SELECT channel,
                   string_agg(delivery_state, ',' ORDER BY rn) AS states,
                   string_agg(coalesce(reason, '-'), ',' ORDER BY rn) AS reasons,
                   min(created_at)::text AS since
              FROM recent
             WHERE rn <= ?
             GROUP BY channel
        SQL, [$customerId, self::RUN]);

        foreach ($rows as $row) {
            $states = explode(',', $row->states);
            $reasons = explode(',', $row->reasons);
            if (count($states) < self::RUN) {
                continue;
            }
            if (array_unique($states) !== ['failed_final'] || array_unique($reasons) !== ['blocked_by_user']) {
                continue;
            }

            return ['channel' => $row->channel, 'hard_failures' => self::RUN, 'since' => (string) $row->since];
        }

        return null;
    }
}
