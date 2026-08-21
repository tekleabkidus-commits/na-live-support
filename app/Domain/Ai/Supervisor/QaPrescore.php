<?php

declare(strict_types=1);

namespace App\Domain\Ai\Supervisor;

use App\Domain\Ai\Chatbot\ContextBuilder;
use App\Domain\Ai\Orchestrator\AiOrchestrator;
use App\Domain\Ai\Prompt\LayeredAssembly;
use App\Support\Settings\SettingsRepository;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * FR-17 qa_prescore: SAMPLED resolved tickets scored against the operator's
 * rubric to PRIORITIZE human QA review. ADVISORY BY CONSTRUCTION: the only
 * thing written is a supervisor decision row (score + per-criterion scores
 * + flags) — no agent, no ticket, no score table is touched, and there is
 * no such table to touch (QaPrescoreTest asserts the absence). The rubric
 * CONTENT is Doc 12/16's ([D12]/[D16]); fixtures/ai/qa/rubric.json is the
 * fixture-fed seam until they publish one. The sample size is a registered
 * setting; a ticket is scored once (idempotent on ticket_id).
 */
final class QaPrescore
{
    public const TEMPLATE = <<<'TPL'
        You are pre-scoring a RESOLVED support conversation against the operator's QA rubric to help a human reviewer prioritize. Score each criterion 0-10 and the whole 0-100. Return ONLY a JSON object: {"score": <0-100>, "criteria": {"<id>": <0-10>, ...}, "flags": ["<short category, e.g. invented_fact|rude_tone|no_closing>"]}. Never quote customer text in flags.
        RUBRIC:
        %s
        TPL;

    public function __construct(
        private readonly AiOrchestrator $orchestrator,
        private readonly LayeredAssembly $assembly,
        private readonly ContextBuilder $contextBuilder,
        private readonly SupervisorLog $log,
        private readonly SettingsRepository $settings,
        private readonly SupervisorGate $gate,
        private readonly ?string $rubricPath = null,
    ) {}

    /** @return array{paused: ?string, scored: int, skipped: int, sampled: int} */
    public function run(): array
    {
        if (($paused = $this->gate->paused('fast')) !== null) {
            $this->log->write('qa_prescore', 'paused', ['reason' => $paused]);

            return ['paused' => $paused, 'scored' => 0, 'skipped' => 0, 'sampled' => 0];
        }

        $raw = $this->settings->get('ai.supervisor.qa_sample_size');
        $sample = is_int($raw) ? $raw : (is_numeric($raw) ? (int) $raw : 20);

        /** @var list<object{id: string, conversation_id: string, customer_id: string}> $tickets */
        $tickets = DB::table('tickets')
            ->whereIn('status', ['resolved', 'closed'])
            ->whereRaw("resolved_at >= date_trunc('day', clock_timestamp()) - interval '1 day' AND resolved_at < date_trunc('day', clock_timestamp())")
            ->whereNotExists(function (\Illuminate\Database\Query\Builder $q): void {
                $q->selectRaw('1')->from('ai_decisions')->whereColumn('ai_decisions.ticket_id', 'tickets.id')->where('ai_decisions.action', 'qa_prescore');
            })
            ->orderByRaw('md5(id::text)') // a stable pseudo-random sample, no app-side randomness
            ->limit($sample)
            ->get(['id', 'conversation_id', 'customer_id'])
            ->all();

        $rubric = $this->rubric();
        $scored = 0;
        $skipped = 0;
        foreach ($tickets as $ticket) {
            try {
                $this->scoreOne($ticket->id, $ticket->conversation_id, $ticket->customer_id, $rubric);
                $scored++;
            } catch (Throwable) {
                $skipped++;
            }
        }

        return ['paused' => null, 'scored' => $scored, 'skipped' => $skipped, 'sampled' => count($tickets)];
    }

    /** @param array{version: string, criteria: list<array{id: string, label: string, weight: int}>} $rubric */
    private function scoreOne(string $ticketId, string $conversationId, string $customerId, array $rubric): void
    {
        $rows = DB::table('messages')->where('conversation_id', $conversationId)
            ->whereIn('author_type', ['customer', 'agent', 'ai'])->where('is_internal', false)
            ->orderBy('created_at')->limit(40)->get(['author_type', 'body', 'kind', 'language']);
        $window = [];
        $language = 'en';
        foreach ($rows as $row) {
            if (is_string($row->body) && $row->body !== '') {
                $window[] = ['role' => $row->author_type === 'customer' ? 'user' : 'assistant', 'content' => $row->body, 'kind' => is_string($row->kind) ? $row->kind : 'text'];
                if ($row->author_type === 'customer' && in_array($row->language, ['en', 'am'], true)) {
                    $language = $row->language;
                }
            }
        }
        if ($window === []) {
            throw new \RuntimeException('nothing to score');
        }

        $rubricText = implode("\n", array_map(static fn (array $c): string => sprintf('- %s (weight %d): %s', $c['id'], $c['weight'], $c['label']), $rubric['criteria']));
        $context = $this->contextBuilder->build($customerId, $language, $window);
        $assembled = $this->assembly->assemble('qa_prescore', sprintf(self::TEMPLATE, $rubricText), $context);
        $started = microtime(true);
        $result = $this->orchestrator->execute('qa_prescore', $assembled['messages']);

        $decoded = json_decode(trim((string) $result['response']->content), true);
        $score = is_array($decoded) && is_numeric($decoded['score'] ?? null) ? max(0, min(100, (int) $decoded['score'])) : null;
        $criteria = [];
        if (is_array($decoded) && is_array($decoded['criteria'] ?? null)) {
            foreach ($rubric['criteria'] as $c) {
                $v = $decoded['criteria'][$c['id']] ?? null;
                $criteria[$c['id']] = is_numeric($v) ? max(0, min(10, (int) $v)) : null;
            }
        }
        $flags = [];
        if (is_array($decoded) && is_array($decoded['flags'] ?? null)) {
            foreach ($decoded['flags'] as $flag) {
                if (is_string($flag) && preg_match('/^[a-z_]{2,40}$/', $flag) === 1) { // categories only, never prose
                    $flags[] = $flag;
                }
            }
        }

        $this->log->write(
            'qa_prescore', 'qa_prescore',
            ['score' => $score, 'criteria' => $criteria, 'flags' => $flags, 'rubric_version' => $rubric['version'], 'advisory' => true, 'parse_ok' => $score !== null],
            $conversationId, $ticketId, $language, $score === null ? null : $score / 100,
            $result['provider'], $result['model'], $assembled['layer_hashes'],
            $result['response']->tokensIn, $result['response']->tokensOut, (int) round((microtime(true) - $started) * 1000),
            ['messages' => $assembled['messages'], 'masking_map' => $assembled['masking_map']],
            $score === null ? 'invalid_action' : null,
        );
    }

    /** @return array{version: string, criteria: list<array{id: string, label: string, weight: int}>} */
    private function rubric(): array
    {
        $path = $this->rubricPath ?? base_path('fixtures/ai/qa/rubric.json');
        $decoded = json_decode((string) file_get_contents($path), true);
        $criteria = [];
        if (is_array($decoded) && is_array($decoded['criteria'] ?? null)) {
            foreach ($decoded['criteria'] as $c) {
                if (is_array($c) && is_string($c['id'] ?? null) && is_string($c['label'] ?? null)) {
                    $criteria[] = ['id' => $c['id'], 'label' => $c['label'], 'weight' => is_numeric($c['weight'] ?? null) ? (int) $c['weight'] : 1];
                }
            }
        }

        return ['version' => is_array($decoded) && is_string($decoded['version'] ?? null) ? $decoded['version'] : 'unknown', 'criteria' => $criteria];
    }
}
