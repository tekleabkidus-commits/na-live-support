<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inbox;

use App\Domain\Tickets\Bulk\BulkJobStore;
use App\Domain\Tickets\Bulk\BulkOperation;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Problem\ErrorCode;
use App\Support\Problem\ProblemDetails;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * C15/C16's doors (S07 step 14, Unit D — the bulk-execution client's server
 * seam over Doc 07's own step-7 bulk module).
 *
 *  - store():  the ID-BASED submit the drawn bar requires ("3 selected ·
 *    Close") — the token door only executes whole views (T-05's shape), so a
 *    partial selection had NO door until this one. Scope needs no gate here
 *    beyond RLS + the tenant existence check in BulkOperation::submit
 *    (C-10: foreign ids read as "do not exist here"): SEC-04 places every
 *    verdict per item, live, inside the owning operations — exactly as the
 *    token path already relies on.
 *  - index():  the progress read the drawer polls. Requester-bound — jobs
 *    someone else requested are silently omitted, never confirmed (C-10's
 *    posture for a list read).
 *  - cancel(): C15's Stop — latches cancel_requested_at; the running job
 *    observes it live between items.
 *  - retry():  "Retry the N that failed" — a NEW batch over the failed
 *    items' tickets, same action and params.
 */
final class BulkJobsController extends Controller
{
    public function __construct(
        private readonly BulkOperation $bulk,
        private readonly BulkJobStore $store,
    ) {}

    public function store(Request $request, AuditLogger $audit): JsonResponse
    {
        /** @var array{action: string, ticket_ids: list<string>, params?: array<string, mixed>} $input */
        $input = $request->validate([
            'action' => ['required', 'string', 'in:'.implode(',', BulkOperation::ACTIONS)],
            'ticket_ids' => ['required', 'array', 'min:1', 'max:'.BulkOperation::MAX_ITEMS],
            'ticket_ids.*' => ['string', 'uuid'],
            'params' => ['sometimes', 'array'],
        ]);

        /** @var User $user */
        $user = $request->user();

        try {
            $jobId = $this->bulk->submit($user, $input['action'], $input['ticket_ids'], $input['params'] ?? []);
        } catch (DomainException $e) {
            return ProblemDetails::response(ErrorCode::ValidationFailed, 422, $e->getMessage());
        }

        $count = count(array_unique($input['ticket_ids']));

        $audit->record(
            actionKey: 'inbox.bulk_submitted',
            entity: 'ticket_bulk_job',
            entityId: $jobId,
            actorType: AuditActor::Staff,
            payload: ['action' => $input['action'], 'count' => $count],
            actorId: $user->identifier(),
        );

        return new JsonResponse(['resolved' => $count, 'skipped' => 0, 'bulk_job_ids' => [$jobId]], 201);
    }

    public function index(Request $request): JsonResponse
    {
        /** @var array{ids: list<string>} $input */
        $input = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:20'],
            'ids.*' => ['string', 'uuid'],
        ]);

        /** @var User $user */
        $user = $request->user();

        /** @var list<object{id: string, action: string, status: string, total: int, cancel_requested_at: string|null}> $jobs */
        $jobs = DB::table('ticket_bulk_jobs')
            ->whereIn('id', $input['ids'])
            ->where('requested_by', $user->identifier())
            ->get(['id', 'action', 'status', 'total', 'cancel_requested_at'])
            ->all();

        $jobIds = array_map(static fn (object $j): string => $j->id, $jobs);

        /** @var array<string, array<string, int>> $tallies job id → status → count */
        $tallies = [];
        if ($jobIds !== []) {
            /** @var list<object{bulk_job_id: string, status: string, n: int}> $rows */
            $rows = DB::table('ticket_bulk_job_items')
                ->whereIn('bulk_job_id', $jobIds)
                ->groupBy('bulk_job_id', 'status')
                ->select('bulk_job_id', 'status')
                ->selectRaw('count(*) as n')
                ->get()
                ->all();
            foreach ($rows as $row) {
                $tallies[$row->bulk_job_id][$row->status] = (int) $row->n;
            }
        }

        /** @var array<string, list<array{number: int, reason: string|null}>> $failures */
        $failures = [];
        if ($jobIds !== []) {
            // The ticket NUMBER is immutable identity (C-01's per-tenant
            // counter), joined from the owning row — this is a progress read
            // over the bulk module's own tables, not a list read (T-08's
            // read-model rule governs lists; numbers are not list state).
            /** @var list<object{bulk_job_id: string, number: int, failure_reason: string|null}> $failedRows */
            $failedRows = DB::table('ticket_bulk_job_items as i')
                ->join('tickets as t', 't.id', '=', 'i.ticket_id')
                ->whereIn('i.bulk_job_id', $jobIds)
                ->where('i.status', 'failed')
                ->orderBy('i.position')
                ->get(['i.bulk_job_id', 't.number', 'i.failure_reason'])
                ->all();
            foreach ($failedRows as $row) {
                $failures[$row->bulk_job_id][] = ['number' => (int) $row->number, 'reason' => $row->failure_reason];
            }
        }

        $payload = array_map(static function (object $job) use ($tallies, $failures): array {
            $ok = $tallies[$job->id]['ok'] ?? 0;
            $failed = $tallies[$job->id]['failed'] ?? 0;

            return [
                'id' => $job->id,
                'action' => $job->action,
                'status' => $job->status,
                'total' => (int) $job->total,
                'ok' => $ok,
                'failed_count' => $failed,
                'done' => $ok + $failed,
                'cancel_requested' => $job->cancel_requested_at !== null,
                'failed' => $failures[$job->id] ?? [],
            ];
        }, $jobs);

        return new JsonResponse(['jobs' => $payload]);
    }

    public function cancel(Request $request, string $jobId, AuditLogger $audit): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $job = $this->ownJob($user, $jobId);
        if ($job === null) {
            return $this->notFound();
        }

        if (! $this->store->requestCancel($jobId)) {
            // Finished (or already latched) — nothing left to stop. 409 per
            // the token door's consumed-state precedent.
            return ProblemDetails::response(ErrorCode::ValidationFailed, 409, __('inbox.bulk.stop_too_late'));
        }

        $audit->record(
            actionKey: 'inbox.bulk_stop_requested',
            entity: 'ticket_bulk_job',
            entityId: $jobId,
            actorType: AuditActor::Staff,
            payload: ['action' => $job->action],
            actorId: $user->identifier(),
        );

        return new JsonResponse(['ok' => true]);
    }

    public function retry(Request $request, string $jobId, AuditLogger $audit): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $job = $this->ownJob($user, $jobId);
        if ($job === null) {
            return $this->notFound();
        }

        /** @var list<string> $failedTicketIds */
        $failedTicketIds = DB::table('ticket_bulk_job_items')
            ->where('bulk_job_id', $jobId)
            ->where('status', 'failed')
            ->orderBy('position')
            ->pluck('ticket_id')
            ->all();

        if ($failedTicketIds === []) {
            return ProblemDetails::response(ErrorCode::ValidationFailed, 422, __('inbox.bulk.nothing_to_retry'));
        }

        /** @var array<string, mixed> $params */
        $params = $job->params !== null ? json_decode($job->params, true, 512, JSON_THROW_ON_ERROR) : [];

        try {
            $newJobId = $this->bulk->submit($user, $job->action, $failedTicketIds, $params);
        } catch (DomainException $e) {
            return ProblemDetails::response(ErrorCode::ValidationFailed, 422, $e->getMessage());
        }

        $audit->record(
            actionKey: 'inbox.bulk_submitted',
            entity: 'ticket_bulk_job',
            entityId: $newJobId,
            actorType: AuditActor::Staff,
            payload: ['action' => $job->action, 'count' => count($failedTicketIds), 'retried_from' => $jobId],
            actorId: $user->identifier(),
        );

        return new JsonResponse(['resolved' => count($failedTicketIds), 'skipped' => 0, 'bulk_job_ids' => [$newJobId]], 201);
    }

    /** @return object{id: string, action: string, params: string|null, status: string}|null */
    private function ownJob(User $user, string $jobId): ?object
    {
        /** @var object{id: string, action: string, params: string|null, status: string}|null */
        return DB::table('ticket_bulk_jobs')
            ->where('id', $jobId)
            ->where('requested_by', $user->identifier())
            ->first(['id', 'action', 'params', 'status']);
    }

    private function notFound(): JsonResponse
    {
        Log::info('inbox operation 404-pattern served', ['metric' => 'security.cross_tenant_probe']);

        return ProblemDetails::response(ErrorCode::ResourceNotFound, 404, 'not found', extra: ['search_instead' => '/inbox']);
    }
}
