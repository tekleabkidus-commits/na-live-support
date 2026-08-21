<?php

declare(strict_types=1);

namespace App\Domain\Ai\Copilot;

use App\Domain\Ai\Chatbot\ContextBuilder;
use App\Domain\Ai\DecisionLog\DecisionRecord;
use App\Domain\Ai\DecisionLog\DecisionWriter;
use App\Domain\Ai\Orchestrator\AiOrchestrator;
use App\Domain\Ai\Prompt\LayeredAssembly;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;
use Throwable;

/**
 * T-05: "similar-tickets retrieval over NIGHTLY-EMBEDDED resolved-ticket
 * summaries". For each resolved/closed ticket without a summary row (or
 * resolved again since its last embedding), summarize the thread
 * (summarize_thread, fast tier — the same task the composer's Summarize
 * uses, surface supervisor: background work) and embed it through the
 * orchestrator's embeddings seam (ADR 0018's local sidecar). UNIQUE
 * (tenant, ticket) is the upsert arbiter. Bounded per run; a provider
 * failure skips the ticket for the next night (no retry storm).
 */
final class TicketSummaryEmbedder
{
    public const SUMMARY_TEMPLATE = <<<'TPL'
        Summarize this resolved support conversation in 2 to 3 sentences for later similarity search: the customer's issue (as their claim), and how it was resolved. No names, no numbers, no greetings. Return ONLY the summary.
        TPL;

    public function __construct(
        private readonly AiOrchestrator $orchestrator,
        private readonly LayeredAssembly $assembly,
        private readonly ContextBuilder $contextBuilder,
        private readonly DecisionWriter $decisions,
        private readonly TenantContext $context,
    ) {}

    /** @return array{embedded: int, skipped: int} */
    public function run(int $limit = 200): array
    {
        /** @var list<object{id: string, conversation_id: string, customer_id: string, language: ?string}> $tickets */
        // Language: the ticket has none (Doc 06); the customer's (Doc 08 §14) stands in.
        $tickets = DB::table('tickets')
            ->leftJoin('ai_ticket_summaries', 'ai_ticket_summaries.ticket_id', '=', 'tickets.id')
            ->whereIn('tickets.status', ['resolved', 'closed'])
            ->where(function (\Illuminate\Database\Query\Builder $q): void {
                $q->whereNull('ai_ticket_summaries.id')
                    ->orWhereColumn('tickets.resolved_at', '>', 'ai_ticket_summaries.embedded_at');
            })
            ->orderBy('tickets.resolved_at')
            ->limit($limit)
            ->leftJoin('customers', 'customers.id', '=', 'tickets.customer_id')
            ->get(['tickets.id', 'tickets.conversation_id', 'tickets.customer_id', 'customers.language'])
            ->all();

        $embedded = 0;
        $skipped = 0;

        foreach ($tickets as $ticket) {
            try {
                $this->embedOne($ticket->id, $ticket->conversation_id, $ticket->customer_id, in_array($ticket->language, ['en', 'am'], true) ? $ticket->language : 'en');
                $embedded++;
            } catch (Throwable) {
                $skipped++;
            }
        }

        return ['embedded' => $embedded, 'skipped' => $skipped];
    }

    private function embedOne(string $ticketId, string $conversationId, string $customerId, string $language): void
    {
        $rows = DB::table('messages')
            ->where('conversation_id', $conversationId)
            ->whereIn('author_type', ['customer', 'agent', 'ai'])
            ->where('is_internal', false)
            ->orderBy('created_at')
            ->limit(40)
            ->get(['author_type', 'body', 'kind']);

        $window = [];
        foreach ($rows as $row) {
            if (is_string($row->body) && $row->body !== '') {
                $window[] = ['role' => $row->author_type === 'customer' ? 'user' : 'assistant', 'content' => $row->body, 'kind' => is_string($row->kind) ? $row->kind : 'text'];
            }
        }
        if ($window === []) {
            throw new \RuntimeException('nothing to summarize');
        }

        $context = $this->contextBuilder->build($customerId, $language, $window);
        $assembled = $this->assembly->assemble('summarize_thread', self::SUMMARY_TEMPLATE, $context);
        $started = microtime(true);
        $result = $this->orchestrator->execute('summarize_thread', $assembled['messages']);
        $summary = trim((string) $result['response']->content);
        if ($summary === '') {
            throw new \RuntimeException('empty summary');
        }

        $vector = $this->orchestrator->executeEmbeddings([$summary])[0] ?? null;
        if ($vector === null) {
            throw new \RuntimeException('no embedding');
        }

        $this->decisions->record(new DecisionRecord(
            id: (string) new UuidV7, surface: 'supervisor', task: 'summarize_thread',
            provider: $result['provider'], model: $result['model'], layerHashes: $assembled['layer_hashes'],
            conversationId: $conversationId, ticketId: $ticketId, customerId: $customerId, language: $language,
            action: 'ticket_summary', tokensIn: $result['response']->tokensIn, tokensOut: $result['response']->tokensOut,
            latencyMs: (int) round((microtime(true) - $started) * 1000),
            requestPayload: ['messages' => $assembled['messages'], 'masking_map' => $assembled['masking_map']],
            responsePayload: ['content' => $summary],
        ));
        $this->decisions->flush();

        DB::statement(
            <<<'SQL'
            INSERT INTO ai_ticket_summaries (id, tenant_id, ticket_id, summary, language, embedding, embedded_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?::vector, clock_timestamp(), clock_timestamp())
            ON CONFLICT (tenant_id, ticket_id) DO UPDATE
               SET summary = EXCLUDED.summary, language = EXCLUDED.language, embedding = EXCLUDED.embedding,
                   embedded_at = clock_timestamp(), updated_at = clock_timestamp()
            SQL,
            [(string) new UuidV7, $this->context->tenantId(), $ticketId, $summary, $language, '['.implode(',', $vector).']'],
        );
    }
}
