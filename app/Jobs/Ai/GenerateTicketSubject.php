<?php

declare(strict_types=1);

namespace App\Jobs\Ai;

use App\Domain\Ai\DecisionLog\DecisionRecord;
use App\Domain\Ai\DecisionLog\DecisionWriter;
use App\Domain\Ai\Orchestrator\AiOrchestrator;
use App\Domain\Ai\Prompt\LayeredAssembly;
use App\Domain\Tickets\Creation\SubjectWriter;
use App\Jobs\OutboxConsumer;
use App\Models\Ticket;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;

/**
 * Doc 06 FR-03's generation half (Doc 09 generate_subject, FR-01's catalog):
 * a durable ADR 0011 consumer on ticket.created — a ticket created WITHOUT
 * a subject gets one from the first customer messages within FR-03's 30 s,
 * written through Doc 06's OWN door SubjectWriter::generated() (stamps
 * subject_source=ai, never overwrites — EC-11's absence posture: a failed
 * generation simply never calls it, the column stays NULL for Doc 07's
 * snippet fallback + retry affordance).
 *
 * IDEMPOTENT ON event_id (C-09): a redelivery finds the subject already set
 * (the door refuses to overwrite) or a generate_subject decision already
 * logged for the ticket, and changes nothing. ai PRIORITY lane (an agent
 * opens the ticket within seconds). The subject is ≤ 80 chars, one line,
 * no quotes of amounts (the template forbids account/transaction facts).
 */
final class GenerateTicketSubject extends OutboxConsumer
{
    public const TEMPLATE = <<<'TPL'
        Write a ticket SUBJECT for this support conversation: one line, at most 8 words, in the customer's language, naming the issue category and object (e.g. "Delayed telebirr deposit", "Bonus not credited"). Never include amounts, reference numbers, names or phone numbers. Return ONLY the subject line.
        TPL;

    public function handle(AiOrchestrator $orchestrator, LayeredAssembly $assembly, SubjectWriter $subjects, DecisionWriter $decisions): void
    {
        /** @var object{name: string, payload: string}|null $event */
        $event = DB::table('events')->where('id', $this->eventId)->first();
        if ($event === null || $event->name !== 'ticket.created') {
            return;
        }
        $payload = json_decode($event->payload, true);
        $ticketId = is_array($payload) && is_string($payload['ticket_id'] ?? null) ? $payload['ticket_id'] : null;
        if ($ticketId === null) {
            return;
        }

        $ticket = Ticket::query()->find($ticketId);
        if ($ticket === null || $ticket->subject !== null) {
            return; // already has one (staff or an earlier delivery) — C-09
        }
        if (DB::table('ai_decisions')->where('ticket_id', $ticketId)->where('task', 'generate_subject')->exists()) {
            return;
        }

        $rows = DB::table('messages')->where('conversation_id', $ticket->conversation_id)
            ->where('author_type', 'customer')->where('is_internal', false)
            ->orderBy('created_at')->limit(3)->get(['body', 'language']);
        $window = [];
        $language = 'en';
        foreach ($rows as $row) {
            if (is_string($row->body) && $row->body !== '') {
                $window[] = ['role' => 'user', 'content' => $row->body];
                if (in_array($row->language, ['en', 'am'], true)) {
                    $language = $row->language;
                }
            }
        }
        if ($window === []) {
            return; // nothing to summarize yet — EC-11's absence, Doc 07's snippet stands
        }

        $assembled = $assembly->assemble('generate_subject', self::TEMPLATE, ['language' => $language, 'prior_topics' => [], 'acquisition' => null, 'window' => $window]);
        $started = microtime(true);
        $result = $orchestrator->execute('generate_subject', $assembled['messages']);
        $subject = trim((string) preg_replace('/\s+/u', ' ', trim((string) $result['response']->content, " \t\n\r\"'“”")));
        $subject = mb_substr($subject, 0, 80);

        $written = $subject !== '' && $subjects->generated($ticket, $subject);

        $decisions->record(new DecisionRecord(
            id: (string) new UuidV7, surface: 'copilot', task: 'generate_subject',
            provider: $result['provider'], model: $result['model'], layerHashes: $assembled['layer_hashes'],
            conversationId: $ticket->conversation_id, ticketId: $ticketId, language: $language,
            action: $written ? 'subject_written' : 'subject_skipped',
            tokensIn: $result['response']->tokensIn, tokensOut: $result['response']->tokensOut,
            latencyMs: (int) round((microtime(true) - $started) * 1000),
            errorCategory: $subject === '' ? 'invalid_action' : null,
            requestPayload: ['messages' => $assembled['messages'], 'masking_map' => $assembled['masking_map']],
            responsePayload: ['subject' => $written ? $subject : null],
        ));
        $decisions->flush();
    }

    protected function queueFamily(): string
    {
        return 'ai';
    }
}
