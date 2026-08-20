<?php

declare(strict_types=1);

namespace App\Jobs\Ai;

use App\Support\Tenancy\PlatformActor;
use App\Support\Tenancy\PlatformConnection;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SEC-06's PRIVACY BY SCHEDULE — a job, not a policy sentence: decision
 * payloads live 30 days, then reduce to hashes + metrics. Runs DAILY as the
 * PLATFORM ROLE (the Doc 01 SEC-02 retention-job class — its second
 * sanctioned use, cited, not a widening): app holds NO grant on
 * payload_hash/payloads_reduced_at in either direction, so this job is the
 * only pen that can write the reduction (grant physics, proven in
 * AiGrantPhysicsTest).
 *
 * Cross-tenant BY DESIGN (a retention sweep visits every tenant) via the
 * platform connection; the hash is over both payloads so AC-10-era
 * disputes can still prove WHAT was sent without keeping it.
 */
final class ReduceAiDecisionPayloads implements ShouldQueue
{
    use Queueable;

    public const RETENTION_DAYS = 30;

    public function __construct()
    {
        $this->onQueue('ai-background');
    }

    public function handle(): void
    {
        $reduced = PlatformConnection::run(
            PlatformActor::RetentionJob,
            'SEC-06 decision-payload reduction (30-day privacy schedule)',
            fn (): int => DB::connection('pgsql_platform')->update(
                <<<'SQL'
            UPDATE ai_decisions
               SET payload_hash = encode(sha256(convert_to(coalesce(request_payload::text, '') || coalesce(response_payload::text, ''), 'UTF8')), 'hex'),
                   payloads_reduced_at = clock_timestamp(),
                   request_payload = NULL,
                   response_payload = NULL
             WHERE created_at < clock_timestamp() - make_interval(days => ?)
               AND payloads_reduced_at IS NULL
               AND (request_payload IS NOT NULL OR response_payload IS NOT NULL)
            SQL,
                [self::RETENTION_DAYS],
            ),
        );

        Log::info('ai.decisions.payloads_reduced', ['reduced_count' => $reduced, 'retention_days' => self::RETENTION_DAYS]);
    }
}
