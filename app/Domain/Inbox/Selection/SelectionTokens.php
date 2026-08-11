<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Selection;

use App\Domain\Inbox\ReadModels\ViewZsets;
use App\Domain\Tickets\Bulk\BulkOperation;
use App\Models\User;
use App\Support\Tenancy\ResolutionSource;
use App\Support\Tenancy\RlsSession;
use App\Support\Tenancy\TenantContext;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * T-05 (S07 step 7): "select all in view" mints a SERVER-SIDE selection —
 * view key + filter snapshot + the TRUE count at mint time — consumed by
 * Doc 06's bulk machinery. The proofs the plan ordered BEFORE any bulk
 * wiring, and their sabotages:
 *
 *  - USER-BOUND (SEC-06): only the minting user executes; anyone else gets
 *    the same silent not-found (C-10).
 *  - SINGLE-USE (SEC-06): the consumed_at latch is claimed atomically
 *    (UPDATE … WHERE consumed_at IS NULL — the column grant allows exactly
 *    that latch); a second execute refuses. Sabotage #4 drops the WHERE and
 *    the reuse proof goes red.
 *  - EXECUTION-TIME RESOLUTION + DRIFT REPORT: ids resolve from the CURRENT
 *    view membership at execute, never from a mint-time list; the report
 *    carries what left ("N items left the view and were skipped") — EC-02's
 *    tray renders this server truth.
 *  - THE DOC 06 CAP: chunks of BulkOperation::MAX_ITEMS (200, FR-25's
 *    number) per submitted batch — EC-05's 1k intent becomes queued batches
 *    (the drawer's data; the per-tenant concurrency cap of 1 serializes
 *    them). Sabotage #3 removes the chunking and Doc 06's own bulk-limit
 *    refusal turns the EC-05 golden red.
 */
final class SelectionTokens
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly ViewZsets $zsets,
        private readonly BulkOperation $bulk,
    ) {}

    /**
     * @param  array<string, mixed>  $filterSnapshot
     * @return array{id: string, count: int}
     */
    public function mint(User $user, string $viewKey, array $filterSnapshot = []): array
    {
        $count = $this->zsets->count($viewKey);
        if ($count === 0) {
            throw new DomainException('empty_selection');
        }

        $id = (string) Str::uuid7();
        DB::table('inbox_selection_tokens')->insert([
            'id' => $id,
            'tenant_id' => $this->context->tenantId(),
            'user_id' => $user->identifier(),
            'view_hash' => $viewKey,
            'filter_snapshot' => json_encode($filterSnapshot, JSON_THROW_ON_ERROR),
            'item_count' => $count,
        ]);

        return ['id' => $id, 'count' => $count];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{resolved: int, skipped: int, bulk_job_ids: list<string>}
     */
    public function execute(User $user, string $tokenId, string $action, array $params = []): array
    {
        /** @var object{id: string, user_id: string, view_hash: string, item_count: int}|null $token */
        $token = DB::table('inbox_selection_tokens')->where('id', $tokenId)->first();

        // SEC-06 user binding — a foreign token answers exactly like a
        // missing one (C-10: no existence probing).
        if ($token === null || $token->user_id !== $user->identifier()) {
            throw new DomainException('token_not_found');
        }

        // SEC-06 single-use: claim the latch atomically; losing the race (or
        // re-presenting a consumed token) refuses. Sabotage #4's target.
        $claimed = DB::table('inbox_selection_tokens')
            ->where('id', $tokenId)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => DB::raw('clock_timestamp()')]);
        if ($claimed !== 1) {
            throw new DomainException('token_consumed');
        }

        // T-05: resolve at EXECUTION from the live view — membership drift
        // since minting is REPORTED, never silently absorbed.
        /** @var list<string> $currentIds */
        $currentIds = $this->zsets->allIds($token->view_hash);
        $resolved = count($currentIds);
        $skipped = max(0, $token->item_count - $resolved);

        // EC-05: chunk per the Doc 06 cap — each batch is a first-class
        // ticket_bulk_jobs row (the queued-batches drawer subscribes to each).
        $boundTenant = $this->context->tenantId();
        $jobIds = [];
        foreach (array_chunk($currentIds, BulkOperation::MAX_ITEMS) as $chunk) {
            $jobIds[] = $this->bulk->submit($user, $action, $chunk, $params);

            // GUARDED RESTORE (the sync-driver middleware semantics, the
            // session's SECOND sighting of it — the sighting that produced
            // ruling A-35): under queue=sync, TicketBulkJob runs INLINE inside
            // submit() and BindTenantContextToJob used to CLEAR the ambient
            // binding in its finally instead of restoring the caller's. A-35
            // made the middleware restore-what-you-found, so this branch is
            // now unreachable in practice — it REMAINS as defense in depth
            // per A-35 condition (4). Production (redis queues) never enters
            // it either way.
            if ($this->context->tenantIdOrNull() === null) {
                $this->context->bind($boundTenant, ResolutionSource::StaffSession);
                app(RlsSession::class)->set($boundTenant);
            }
        }

        return ['resolved' => $resolved, 'skipped' => $skipped, 'bulk_job_ids' => $jobIds];
    }
}
