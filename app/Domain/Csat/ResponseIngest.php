<?php

declare(strict_types=1);

namespace App\Domain\Csat;

use App\Domain\Ai\DecisionLog\OutcomeEnrichment;
use App\Domain\Ai\Supervisor\SupervisorLog;
use App\Support\Cache\TenantCache;
use App\Support\Events\EventPublisher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Doc 12 FR-25 / SEC-05 / EC-11 / T-08 — response ingestion, ONE door for the
 * Telegram callback and the widget API. Token-bound (hash compare; an unknown
 * token answers `unknown` — nothing to enumerate, C-10's posture),
 * rate-limited at the door, single-response SERVER-SIDE (the row unique is the
 * physics; the 1 h edit window is an UPDATE that replaces, never a second
 * row). Hour 73 is EC-11's polite refusal — nothing recorded, csat.expired
 * flipped lazily if the sweep has not passed yet. A score ≤ 2 raises the
 * Doc 09 FR-20 review item (a supervisor decision row); an AI-resolver score
 * lands `csat_scored` on the resolving decision through Doc 09's own
 * OutcomeEnrichment door (closing rows 2/4).
 */
final class ResponseIngest
{
    private const RATE_LIMIT_PER_MINUTE = 10;

    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantCache $cache,
        private readonly EventPublisher $events,
        private readonly SupervisorLog $supervisor,
        private readonly OutcomeEnrichment $enrichment,
        private readonly \App\Support\Settings\SettingsRepository $settings,
    ) {}

    /**
     * @return array{state: string, text_key?: string, comment_prompt?: bool}
     */
    public function capture(string $rawToken, int $score, ?string $comment = null): array
    {
        // SEC-05: rate-limited per source token before any row is touched.
        $attempts = $this->cache->increment('csat:attempts:'.hash('sha256', $rawToken), 1, 60, 'csat.received');
        if ($attempts > self::RATE_LIMIT_PER_MINUTE) {
            return ['state' => 'rate_limited'];
        }
        if ($score < 1 || $score > 5) {
            return ['state' => 'invalid_score'];
        }
        /** @var object{id: string, ticket_id: ?string, customer_id: string, resolver_type: string, resolution_event_id: string, expires_at: string, expired_at: ?string}|null $survey */
        $survey = DB::table('surveys')->where('token_hash', hash('sha256', $rawToken))
            ->first(['id', 'ticket_id', 'customer_id', 'resolver_type', 'resolution_event_id', 'expires_at', 'expired_at']);
        if ($survey === null) {
            return ['state' => 'unknown'];
        }
        /** @var object{gone: bool} $clock */
        $clock = DB::selectOne('SELECT clock_timestamp() > ?::timestamptz AS gone', [$survey->expires_at]);
        $expired = $survey->expired_at !== null || $clock->gone;
        if ($expired) {
            // EC-11: nothing recorded; flip + emit lazily if the sweep is behind.
            if ($survey->expired_at === null) {
                app(CsatSweep::class)->expire($survey->id, $survey->ticket_id);
            }

            return ['state' => 'window_closed', 'text_key' => 'workflow.csat.window_closed'];
        }

        /** @var object{id: string, responded_at: string}|null $existing */
        $existing = DB::table('survey_responses')->where('survey_id', $survey->id)->first(['id', 'responded_at']);
        if ($existing !== null) {
            /** @var object{open: bool} $window */
            $window = DB::selectOne("SELECT clock_timestamp() <= ?::timestamptz + interval '1 hour' AS open", [$existing->responded_at]);
            if (! $window->open) {
                return ['state' => 'locked'];
            }
            DB::table('survey_responses')->where('id', $existing->id)->update([
                'score' => $score, 'comment' => $comment, 'edited_at' => DB::raw('clock_timestamp()'),
            ]);
        } else {
            DB::table('survey_responses')->insert([
                'id' => (string) Str::uuid7(), 'tenant_id' => $this->context->tenantId(),
                'survey_id' => $survey->id, 'score' => $score, 'comment' => $comment,
            ]);
        }

        DB::transaction(fn () => $this->events->publish('csat.received', [
            'tenant_id' => $this->context->tenantId(), 'survey_id' => $survey->id,
            'ticket_id' => $survey->ticket_id, 'customer_id' => $survey->customer_id,
            'score' => $score, 'comment' => $comment, 'resolver_type' => $survey->resolver_type,
        ]));
        if ($score <= 2) {
            // FR-25 → Doc 09 FR-20's loop: a review item on the supervisor feed.
            // Task = qa_prescore (the CLOSED 15-task catalog; FR-17's QA hook —
            // closing row 3's step-12 half); the kind rides the payload.
            $this->supervisor->write('qa_prescore', 'review_item',
                ['kind' => 'low_csat_review', 'survey_id' => $survey->id, 'score' => $score, 'resolver_type' => $survey->resolver_type],
                ticketId: $survey->ticket_id);
        }
        if ($survey->resolver_type === 'ai') {
            $this->enrichDecision($survey->resolution_event_id, $score);
        }

        return ['state' => 'scored', 'comment_prompt' => (bool) $this->settings->get('csat.comment_prompt.enabled')];
    }

    /** Closing rows 2/4: the ai.resolved event row names the decision; Doc 09's own enrichment door lands csat_scored. */
    private function enrichDecision(string $resolutionEventId, int $score): void
    {
        $payload = DB::table('outbox')->where('event_id', $resolutionEventId)->value('payload');
        if (! is_string($payload)) {
            return;
        }
        $decoded = json_decode($payload, true, 8);
        $decisionId = is_array($decoded) && is_string($decoded['decision_id'] ?? null) ? $decoded['decision_id'] : null;
        if ($decisionId !== null) {
            $this->enrichment->enrich($decisionId, 'csat_scored', ['score' => $score]);
        }
    }
}
