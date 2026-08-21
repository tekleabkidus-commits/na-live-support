<?php

declare(strict_types=1);

namespace App\Domain\Ai\Supervisor;

use App\Domain\Ai\Copilot\DigitValidator;
use App\Domain\Ai\Orchestrator\AiOrchestrator;
use App\Domain\Ai\Prompt\LayeredAssembly;
use App\Domain\Ai\Providers\AllProvidersExhausted;
use App\Domain\Ai\Quota\AiQuotaExhausted;
use App\Domain\Inbox\ReadModels\ListReadModel;
use App\Models\TicketListItem;
use App\Models\User;
use App\Support\Rbac\CapabilityMapProvider;
use App\Support\Rbac\Scopes\ScopeSets;
use Illuminate\Support\Facades\DB;

/**
 * FR-17 daily_digest: YESTERDAY IN 6 BULLETS, per manager SCOPE, LOCALIZED.
 *
 * Recipients = active staff whose verdict on reports.supervisor.live is
 * allow (the supervisor surface's own key; no new key — the catalog is
 * closed). Scope = the recipient's tickets scope through ScopeSets (the one
 * implementation): the ticket facts are computed over the tickets THEY can
 * see; the AI facts tenant-wide for an All scope, else restricted to the
 * conversations of their visible tickets (AI-resolved conversations carry
 * no ticket, so a narrow scope never sees them — stated).
 *
 * The MODEL writes the six bullets in the recipient's language from a
 * FACTS block that rides the system layer (platform aggregates, no customer
 * text). Two guards: exactly six bullets, and NO NUMBER the facts do not
 * contain (§14's validator, one direction — a digest may omit a number,
 * never invent one); one repair retry, then the TEMPLATE bullets (i18n
 * keys + the facts) — a digest is never silent and never fabricated.
 * Output = a supervisor decision row per recipient (the delivery into the
 * bell is the notify.ai.digest category's Doc 07 mapping — escalated).
 */
final class DailyDigest
{
    public const TEMPLATE = <<<'TPL'
        Write yesterday's support digest for a manager as EXACTLY SIX bullet lines, each starting with "- ", in the language tagged `digest_language` in the FACTS block below. Use ONLY the numbers in the FACTS block, verbatim — never compute, round or invent a number. Cover: ticket volume, AI resolution, handoffs, responsible-gaming fires, anomalies, and the top intents. Return ONLY the six lines.
        TPL;

    public function __construct(
        private readonly AiOrchestrator $orchestrator,
        private readonly LayeredAssembly $assembly,
        private readonly SupervisorLog $log,
        private readonly SupervisorGate $gate,
        private readonly CapabilityMapProvider $verdicts,
        private readonly ScopeSets $scopes,
    ) {}

    /** @return array{paused: ?string, digests: list<array{user_id: string, language: string, decision_id: string, source: string}>} */
    public function run(): array
    {
        if (($paused = $this->gate->paused('strong')) !== null) {
            $this->log->write('daily_digest', 'paused', ['reason' => $paused]);

            return ['paused' => $paused, 'digests' => []];
        }

        $digests = [];
        foreach (User::query()->whereNull('disabled_at')->get() as $user) {
            if (! $this->verdicts->verdictFor($user, 'reports.supervisor.live')->allowed) {
                continue;
            }
            $digests[] = $this->forRecipient($user);
        }

        return ['paused' => null, 'digests' => $digests];
    }

    /** @return array{user_id: string, language: string, decision_id: string, source: string} */
    public function forRecipient(User $user): array
    {
        $locale = $user->getAttribute('locale');
        $language = in_array($locale, ['en', 'am'], true) ? $locale : 'en';
        $facts = $this->facts($user);

        $factLines = ["digest_language: {$language}"];
        foreach ($facts as $k => $v) {
            $factLines[] = $k.': '.(is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (string) $v);
        }
        $factsText = implode("\n", $factLines);
        $context = ['language' => $language, 'prior_topics' => [], 'acquisition' => null, 'window' => []];
        $assembled = $this->assembly->assemble('daily_digest', self::TEMPLATE."\n\nFACTS:\n".$factsText, $context);

        $started = microtime(true);
        $bullets = null;
        $provider = 'none';
        $model = 'none';
        $tokens = [0, 0];
        $source = 'template';
        try {
            $result = $this->orchestrator->execute('daily_digest', $assembled['messages']);
            $provider = $result['provider'];
            $model = $result['model'];
            $tokens = [$result['response']->tokensIn, $result['response']->tokensOut];
            $bullets = $this->validBullets((string) $result['response']->content, $factsText);
            if ($bullets === null) {
                $repair = $assembled['messages'];
                $repair[] = ['role' => 'system', 'content' => 'Your previous output was not exactly six "- " lines using only the FACTS numbers. Return ONLY the six lines.'];
                $result = $this->orchestrator->execute('daily_digest', $repair);
                $tokens = [$tokens[0] + $result['response']->tokensIn, $tokens[1] + $result['response']->tokensOut];
                $bullets = $this->validBullets((string) $result['response']->content, $factsText);
            }
            $source = $bullets === null ? 'template' : 'model';
        } catch (AllProvidersExhausted|AiQuotaExhausted) {
            $source = 'template';
        }

        $bullets ??= $this->templateBullets($facts, $language);

        $decisionId = $this->log->write(
            'daily_digest', 'daily_digest',
            ['recipient_id' => $user->identifier(), 'bullets' => $bullets, 'facts' => $facts, 'source' => $source],
            null, null, $language, null, $provider, $model, $assembled['layer_hashes'], $tokens[0], $tokens[1],
            (int) round((microtime(true) - $started) * 1000),
            ['messages' => $assembled['messages'], 'masking_map' => $assembled['masking_map']],
        );

        return ['user_id' => $user->identifier(), 'language' => $language, 'decision_id' => $decisionId, 'source' => $source];
    }

    /** @return list<string>|null */
    private function validBullets(string $content, string $factsText): ?array
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', trim($content)) ?: []), static fn (string $l): bool => $l !== ''));
        if (count($lines) !== 6) {
            return null;
        }
        foreach ($lines as $line) {
            if (! str_starts_with($line, '- ')) {
                return null;
            }
        }
        // §14 one-directional: every number in the bullets must exist in the facts.
        $verdict = DigitValidator::compare(implode("\n", $lines), $factsText);
        if ($verdict['missing'] !== []) {
            return null; // numbers the facts do not contain = invented
        }

        return $lines;
    }

    /**
     * @param  array{tickets_created: int, tickets_resolved: int, ai_resolved: int, handoffs: int, handoffs_by_reason: array<string, int>, rg_fires: int, anomalies: int, top_intents: array<string, int>}  $facts
     * @return list<string>
     */
    private function templateBullets(array $facts, string $language): array
    {
        $t = static function (string $key, array $params) use ($language): string {
            /** @var array<string, int|string> $params */
            return (string) __('ai.supervisor.digest.'.$key, $params, $language);
        };

        return [
            '- '.$t('tickets', ['created' => $facts['tickets_created'], 'resolved' => $facts['tickets_resolved']]),
            '- '.$t('ai_resolved', ['count' => $facts['ai_resolved']]),
            '- '.$t('handoffs', ['count' => $facts['handoffs'], 'reasons' => self::pairs($facts['handoffs_by_reason'])]),
            '- '.$t('rg', ['count' => $facts['rg_fires']]),
            '- '.$t('anomalies', ['count' => $facts['anomalies']]),
            '- '.$t('intents', ['intents' => self::pairs($facts['top_intents'])]),
        ];
    }

    /** @param array<string, int> $pairs */
    private static function pairs(array $pairs): string
    {
        $out = [];
        foreach ($pairs as $k => $v) {
            $out[] = $k.' '.$v;
        }

        return $out === [] ? '—' : implode(', ', $out);
    }

    /** @return array{tickets_created: int, tickets_resolved: int, ai_resolved: int, handoffs: int, handoffs_by_reason: array<string, int>, rg_fires: int, anomalies: int, top_intents: array<string, int>} */
    private function facts(User $user): array
    {
        $day = "created_at >= date_trunc('day', clock_timestamp()) - interval '1 day' AND created_at < date_trunc('day', clock_timestamp())";
        $resolvedDay = "resolved_at >= date_trunc('day', clock_timestamp()) - interval '1 day' AND resolved_at < date_trunc('day', clock_timestamp())";

        $visible = TicketListItem::query();
        $this->scopes->apply($visible, 'tickets', $user, ListReadModel::columns());
        $visibleIds = $visible->pluck('ticket_id')->filter(static fn ($id): bool => is_string($id))->values()->all();
        $scopeAll = ($user->role?->scopes['tickets'] ?? null) === 'All';

        $ticketsCreated = (int) DB::table('tickets')->whereIn('id', $visibleIds)->whereRaw($day)->count();
        $ticketsResolved = (int) DB::table('tickets')->whereIn('id', $visibleIds)->whereRaw($resolvedDay)->count();

        $ai = DB::table('ai_decisions')->whereRaw($day);
        if (! $scopeAll) {
            $conversationIds = DB::table('tickets')->whereIn('id', $visibleIds)->pluck('conversation_id')->filter(static fn ($id): bool => is_string($id))->values()->all();
            $ai->whereIn('conversation_id', $conversationIds);
        }

        $aiResolved = (int) (clone $ai)->where('surface', 'chatbot')->where('action', 'resolve')->count();
        $handoffRows = (clone $ai)->where('surface', 'chatbot')->where('action', 'handoff')->selectRaw("coalesce(response_payload->>'handoff_reason', 'unknown') AS reason, count(*) AS n")->groupBy('reason')->orderByDesc('n')->limit(5)->get();
        $handoffsByReason = [];
        $handoffs = 0;
        foreach ($handoffRows as $row) {
            $reason = is_string($row->reason) ? $row->reason : 'unknown';
            $n = is_numeric($row->n) ? (int) $row->n : 0;
            $handoffsByReason[$reason] = $n;
            $handoffs += $n;
        }
        $rgFires = (int) (clone $ai)->where('task', 'classify_message')->where('action', 'rg_override')->count();
        $anomalies = (int) DB::table('ai_decisions')->whereRaw($day)->where('surface', 'supervisor')->where('action', 'anomaly_scan')->whereRaw("jsonb_array_length(coalesce(response_payload->'alerts', '[]'::jsonb)) > 0")->count();
        $intentRows = (clone $ai)->where('task', 'classify_message')->whereNotNull('intent')->where('intent', '!=', 'spam')->selectRaw('intent, count(*) AS n')->groupBy('intent')->orderByDesc('n')->limit(3)->get();
        $topIntents = [];
        foreach ($intentRows as $row) {
            if (is_string($row->intent)) {
                $topIntents[$row->intent] = is_numeric($row->n) ? (int) $row->n : 0;
            }
        }

        return [
            'tickets_created' => $ticketsCreated, 'tickets_resolved' => $ticketsResolved, 'ai_resolved' => $aiResolved,
            'handoffs' => $handoffs, 'handoffs_by_reason' => $handoffsByReason, 'rg_fires' => $rgFires,
            'anomalies' => $anomalies, 'top_intents' => $topIntents,
        ];
    }
}
