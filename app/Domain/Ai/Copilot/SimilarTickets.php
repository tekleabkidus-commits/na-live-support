<?php

declare(strict_types=1);

namespace App\Domain\Ai\Copilot;

use App\Domain\Ai\Orchestrator\AiOrchestrator;
use App\Domain\Inbox\ReadModels\ListReadModel;
use App\Models\TicketListItem;
use App\Models\User;
use App\Support\Rbac\Scopes\ScopeSets;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * T-05's similar-tickets half + FR-14's "similar resolved tickets (top 3,
 * scope-checked)": cosine over ai_ticket_summaries (nightly-embedded by
 * TicketSummaryEmbedder), then Doc 03's scope filter AT QUERY TIME —
 * ScopeSets::apply over TicketListItem with A-7's column map, the same
 * filter every list surface uses (no stored scope: reassignment would
 * stale it, the S08 lesson). Candidates are over-fetched so the scope cut
 * still leaves three; the current ticket is excluded.
 */
final class SimilarTickets
{
    private const CANDIDATES = 12;

    public function __construct(
        private readonly AiOrchestrator $orchestrator,
        private readonly ScopeSets $scopes,
    ) {}

    /**
     * @return list<array{ticket_id: string, number: int|null, subject: string|null, summary: string, score: float}>
     */
    public function forQuery(User $user, string $query, ?string $excludeTicketId = null, int $topK = 3): array
    {
        if (trim($query) === '') {
            return [];
        }

        try {
            $vector = $this->orchestrator->executeEmbeddings([$query])[0] ?? null;
        } catch (Throwable) {
            return []; // FS-02 posture: no vector, no similar-tickets section (the panel says so)
        }
        if ($vector === null) {
            return [];
        }

        $literal = '['.implode(',', $vector).']';
        /** @var list<object{ticket_id: string, summary: string, score: string|float}> $candidates */
        $candidates = DB::table('ai_ticket_summaries')
            ->whereNotNull('embedding')
            ->when($excludeTicketId !== null, fn ($q) => $q->where('ticket_id', '!=', $excludeTicketId))
            ->selectRaw('ticket_id, summary, 1 - (embedding <=> ?::vector) AS score', [$literal])
            ->orderByRaw('embedding <=> ?::vector', [$literal])
            ->limit(self::CANDIDATES)
            ->get()
            ->all();

        if ($candidates === []) {
            return [];
        }

        // Doc 03 scope filter — the SAME apply() the inbox list uses.
        $visibleQuery = TicketListItem::query()->whereIn('ticket_id', array_map(static fn (object $c): string => $c->ticket_id, $candidates));
        $this->scopes->apply($visibleQuery, 'tickets', $user, ListReadModel::columns());
        $visible = [];
        foreach ($visibleQuery->pluck('ticket_id') as $id) {
            if (is_string($id)) {
                $visible[$id] = true;
            }
        }

        $meta = DB::table('tickets')->whereIn('id', array_keys($visible))->get(['id', 'number', 'subject'])->keyBy('id');

        $results = [];
        foreach ($candidates as $candidate) {
            if (! isset($visible[$candidate->ticket_id])) {
                continue;
            }
            $row = $meta->get($candidate->ticket_id);
            $results[] = [
                'ticket_id' => $candidate->ticket_id,
                'number' => is_object($row) && is_numeric($row->number ?? null) ? (int) $row->number : null,
                'subject' => is_object($row) && is_string($row->subject ?? null) ? $row->subject : null,
                'summary' => $candidate->summary,
                'score' => round((float) $candidate->score, 4),
            ];
            if (count($results) >= $topK) {
                break;
            }
        }

        return $results;
    }
}
