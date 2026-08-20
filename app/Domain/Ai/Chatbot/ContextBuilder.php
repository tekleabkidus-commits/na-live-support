<?php

declare(strict_types=1);

namespace App\Domain\Ai\Chatbot;

use Illuminate\Support\Facades\DB;

/**
 * THE SEC-04 ALLOWLIST AS PHYSICS (Doc 09 SEC-04, BR-06, FR-08; sabotage
 * #4's subject; landed BEFORE any prompt is built from customer context —
 * the approved plan's safety head).
 *
 * The customer-context layer is built ONLY from the fields this class
 * explicitly reads — an ALLOWLIST, never a serialization of the customer
 * row. RG flag contents NEVER enter prompts outside RG flows and staff
 * notes NEVER enter prompts at all (BR-06 — Doc 04 BR-01's notes law
 * extended to prompts): those tables/columns are simply never queried
 * here, and the proving test PLANTS both and asserts absence from the
 * built layer (AiContextAllowlistTest).
 *
 * SEC-01's send-minimization rides the same mechanism: only the T-02
 * context layers travel, never full customer profiles.
 */
final class ContextBuilder
{
    /**
     * The whole allowlist, in one place a reviewer can read:
     *   - customer language (FR-08's per-language behavior),
     *   - prior-ticket TOPICAL summaries (subjects only — never bodies,
     *     never notes),
     *   - the acquisition context (Doc 05 A-29's deep-link seam),
     *   - the active conversation window (passed in by the caller from
     *     Doc 04's messages — customer + assistant turns, never notes:
     *     kind='note' rows are structurally excluded by the caller's query
     *     and re-checked here).
     *
     * @param  list<array{role: string, content: string, kind?: string}>  $conversationWindow
     * @return array{language: string, prior_topics: list<string>, acquisition: ?string, window: list<array{role: string, content: string}>}
     */
    public function build(string $customerId, string $language, array $conversationWindow, ?string $acquisition = null): array
    {
        $priorTopics = [];
        foreach (DB::table('tickets')
            ->where('customer_id', $customerId)
            ->whereNotNull('subject')
            ->orderByDesc('created_at')
            ->limit(5)
            ->pluck('subject') as $subject) {
            if (is_string($subject)) {
                $priorTopics[] = $subject;
            }
        }

        $window = [];
        foreach ($conversationWindow as $turn) {
            // BR-06's re-check: a note can never ride the window, whatever
            // the caller queried.
            if (($turn['kind'] ?? null) === 'note') {
                continue;
            }
            $window[] = ['role' => $turn['role'], 'content' => $turn['content']];
        }

        return [
            'language' => $language,
            'prior_topics' => $priorTopics,
            'acquisition' => $acquisition,
            'window' => $window,
        ];
    }
}
