<?php

declare(strict_types=1);

namespace App\Domain\Csat;

use App\Support\Events\EventPublisher;
use App\Support\Tenancy\PlatformActor;
use App\Support\Tenancy\PlatformConnection;
use App\Support\Tenancy\ResolutionSource;
use App\Support\Tenancy\RlsSession;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Doc 12 EC-11 — the expiry sweep: unanswered surveys past their MATERIALIZED
 * expires_at flip expired_at (the one UPDATE the grant allows on surveys) and
 * emit csat.expired (Doc 15 reads non-response honestly). The ScheduleSweep
 * posture: one platform-scope find, then per-tenant work under that tenant's
 * own binding. Idempotent: the expired_at IS NULL predicate makes a re-run a
 * no-op; the lazy flip on an hour-73 tap (ResponseIngest) uses the same door.
 */
final class CsatSweep
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly EventPublisher $events,
    ) {}

    /** @return int how many surveys expired this pass */
    public function sweep(): int
    {
        /** @var list<object{id: string, tenant_id: string, ticket_id: ?string}> $due */
        $due = PlatformConnection::run(
            PlatformActor::RetentionJob,
            'Doc 12 EC-11 CSAT sweep: find unanswered surveys past expiry',
            static fn (): array => DB::connection(PlatformConnection::NAME)->select(<<<'SQL'
                SELECT s.id, s.tenant_id, s.ticket_id FROM surveys s
                WHERE s.expired_at IS NULL AND s.expires_at < clock_timestamp()
                  AND NOT EXISTS (SELECT 1 FROM survey_responses r WHERE r.survey_id = s.id)
                ORDER BY s.tenant_id, s.expires_at
            SQL),
        );
        foreach ($due as $row) {
            $this->context->runFor($row->tenant_id, ResolutionSource::Console, function () use ($row): void {
                app(RlsSession::class)->set($row->tenant_id);
                $this->expire($row->id, $row->ticket_id);
            });
        }

        return count($due);
    }

    /** The one flip door (the sweep's pass and ResponseIngest's lazy hour-73 flip). */
    public function expire(string $surveyId, ?string $ticketId): void
    {
        DB::transaction(function () use ($surveyId, $ticketId): void {
            $flipped = DB::table('surveys')->where('id', $surveyId)->whereNull('expired_at')
                ->update(['expired_at' => DB::raw('clock_timestamp()')]);
            if ($flipped === 1) {
                $this->events->publish('csat.expired', [
                    'tenant_id' => $this->context->tenantId(), 'survey_id' => $surveyId, 'ticket_id' => $ticketId,
                ]);
            }
        });
    }
}
