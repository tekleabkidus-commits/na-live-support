<?php

declare(strict_types=1);

namespace App\Domain\Tickets\Tags;

use App\Models\User;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Rbac\CapabilityMapProvider;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * T-07: "Tag rename/merge: chunked, resumable background rewrite over the GIN-indexed
 * tags array, audited with counts."
 *
 * HEADLESS BY OWNER RULING R-22. The engine is built and tested here; the admin
 * SURFACE is a recorded design gap (`B2-S06-closing-audit-v2`: "Tags — per-tenant
 * autocomplete; no rename or merge designed") and defers to Doc 07 with the §8/§9
 * rendering bucket. Nothing is improvised in its place.
 *
 * CHUNKED AND RESUMABLE, and the two words mean specific things:
 *  - CHUNKED: each chunk is its own transaction over a bounded number of rows, so a
 *    tenant with 200k tickets does not hold one lock for minutes (FR-19 says this
 *    propagates HISTORICALLY, which is exactly the large-table case).
 *  - RESUMABLE: progress is the DATA, not a cursor. Each chunk selects rows that still
 *    contain the old tag, so a run killed halfway is restarted by calling it again —
 *    there is no bookmark to lose and no way to double-apply, because a row that no
 *    longer has the old tag is no longer selected.
 *
 * RENAME vs MERGE is one operation with two names: renaming to a tag that already
 * exists on some tickets IS the merge, and the DISTINCT in the rewrite is what keeps
 * those rows from ending up with the target twice.
 */
final class TagRewrite
{
    private const CHUNK = 500;

    public function __construct(
        private readonly CapabilityMapProvider $verdicts,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return int the number of tickets rewritten — T-07's "audited with counts"
     */
    public function rename(string $from, string $to, User $actor, int $chunkSize = self::CHUNK): int
    {
        if (! $this->verdicts->verdictFor($actor, 'tickets.tags.manage')->allowed) {
            throw new DomainException('tickets.tags.manage is required (T-07, mapping A-9).');
        }

        $from = trim($from);
        $to = trim($to);

        if ($from === '' || $to === '') {
            throw new DomainException('both the old and the new tag are required (T-07).');
        }
        if ($from === $to) {
            return 0;
        }

        $rewritten = 0;

        while (true) {
            // One chunk, one transaction. The subquery re-selects rows that STILL
            // carry the old tag, which is what makes the operation resumable and
            // idempotent at the same time.
            $affected = DB::transaction(fn (): int => DB::update(
                <<<'SQL'
                    UPDATE tickets
                       SET tags = ARRAY(SELECT DISTINCT unnest(array_replace(tags, ?, ?)) ORDER BY 1),
                           updated_at = clock_timestamp()
                     WHERE id IN (
                           SELECT id FROM tickets WHERE ? = ANY(tags) LIMIT ?
                     )
                    SQL,
                [$from, $to, $from, $chunkSize],
            ));

            $rewritten += $affected;

            if ($affected === 0) {
                break;
            }
        }

        // Audited with counts, once per run rather than per chunk: the operator asked
        // one question ("rename X to Y") and the record answers it.
        $this->audit->record(
            actionKey: 'ticket.tags_rewritten',
            entity: 'ticket',
            entityId: null,
            actorType: AuditActor::Staff,
            payload: ['from_tag' => $from, 'to_tag' => $to, 'tickets_rewritten' => $rewritten],
            actorId: $actor->identifier(),
        );

        return $rewritten;
    }
}
