<?php

declare(strict_types=1);

namespace App\Domain\Ai\DecisionLog;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * T-08's enrichment writer — outcome arrives AFTER the fact from downstream
 * events (resolved-then-reopened, CSAT [D12], handoff-agreed [D11]/step 9,
 * Copilot edit outcomes FR-16/step 10). The grant physics make
 * (outcome, outcome_meta, updated_at) the ONLY columns this can touch —
 * a widened enrichment is a red grant-physics derivation, not a code review
 * question.
 *
 * The durable CONSUMERS registering for the enrichment events land WITH
 * their producers (the events carrying decision_id do not exist before
 * steps 7/9/10); this writer is their one shared pen. Outcome categories
 * are CLOSED (Q7 discipline).
 */
final class OutcomeEnrichment
{
    public const OUTCOMES = [
        'resolved', 'resolved_then_reopened', 'handoff_agreed_routing',
        'handoff_rerouted', 'csat_scored', 'inserted_unedited',
        'inserted_edited', 'discarded',
    ];

    /** @param array<string, mixed> $meta identifiers and categories only (Q7) */
    public function enrich(string $decisionId, string $outcome, array $meta = []): bool
    {
        if (! in_array($outcome, self::OUTCOMES, true)) {
            throw new InvalidArgumentException(sprintf('unknown outcome category "%s" (T-08/Q7 — the list is closed)', $outcome));
        }

        $updated = DB::update(
            <<<'SQL'
            UPDATE ai_decisions
               SET outcome = ?, outcome_meta = ?, updated_at = clock_timestamp()
             WHERE id = ?
            SQL,
            [$outcome, json_encode($meta, JSON_THROW_ON_ERROR), $decisionId],
        );

        return $updated === 1;
    }
}
