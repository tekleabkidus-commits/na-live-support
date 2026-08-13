<?php

declare(strict_types=1);

namespace App\Jobs\Tickets;

use App\Domain\Conversations\Realtime\RealtimePusher;
use App\Domain\Tickets\Assignment\AlreadyClaimed;
use App\Domain\Tickets\Assignment\AssigneeOutOfScope;
use App\Domain\Tickets\Assignment\AssignTicket;
use App\Domain\Tickets\Creation\SequenceContention;
use App\Domain\Tickets\Priority\ChangePriority;
use App\Domain\Tickets\Resolution\ResolveTicket;
use App\Domain\Tickets\Spam\SpamTicket;
use App\Domain\Tickets\Tags\TicketTags;
use App\Domain\Tickets\Transfer\TransferTicket;
use App\Domain\Tickets\Transitions\IllegalTransition;
use App\Domain\Tickets\Transitions\TicketStatus;
use App\Domain\Tickets\Transitions\TicketTransitionService;
use App\Domain\Tickets\Transitions\TransitionContext;
use App\Domain\Tickets\Transitions\TransitionNotPermitted;
use App\Domain\Tickets\Transitions\TransitionRefused;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Events\EventPublisher;
use App\Support\Tenancy\TenantAware;
use DomainException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * FR-25's executor: the queued batch, on the automation family (T-06), each item an
 * INDIVIDUALLY validated operation.
 *
 * SEC-04 IS STRUCTURAL HERE: this job holds NO verdicts. Every item calls the
 * OWNING operation (AssignTicket, TransferTicket, TicketTags, ChangePriority,
 * ResolveTicket, the transition service, SpamTicket), and each of those asks the
 * live CapabilityMapProvider at call time — so "no batch-level privilege averaging"
 * is not a discipline this loop maintains but a property it cannot break: there is
 * no cached verdict to average WITH. EC-12 falls out of the same shape: a permission
 * revoked mid-batch is simply absent when the next item asks.
 *
 * PER-ITEM PARTIAL SUCCESS: each operation runs in its own transaction; a failed
 * item records its stable refusal code (C-04's enum values where one exists) and the
 * loop continues. Re-driving a crashed job resumes at the pending items — completed
 * rows are skipped, which is C-09's idempotency for a batch.
 *
 * PROGRESS is per item over Reverb user.{requester} (T-06/NFR-04), through Doc 04's
 * one door (RealtimePusher — v:1, size ceiling, tenant fence all apply). The batch
 * ends with ONE ticket.bulk_completed {ok, failed[]} (FR-25; name per A-8(ii)) and
 * SEC-03's bulk-summary audit entry referencing the job row.
 *
 * PER-TENANT CONCURRENCY (T-06): WithoutOverlapping keyed on the tenant serializes
 * batches within a tenant (cap = 1 — the smallest honest reading of "per-tenant
 * concurrency caps"; raising it is a tuning decision, not a semantic one) while
 * other tenants' batches proceed — one tenant's 200-item storm cannot monopolise
 * the family's workers.
 */
final class TicketBulkJob implements ShouldQueue
{
    use Queueable, TenantAware;

    public int $tries = 3;

    public function __construct(public readonly string $bulkJobId)
    {
        $this->captureTenant();
        $this->onQueue('automation');
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [
            new \App\Jobs\Middleware\BindTenantContextToJob,
            (new WithoutOverlapping('t:'.($this->tenantId ?? 'unbound').':bulk'))->releaseAfter(30),
        ];
    }

    public function handle(
        AssignTicket $assign,
        TransferTicket $transfer,
        TicketTags $tags,
        ChangePriority $priority,
        ResolveTicket $resolve,
        TicketTransitionService $transitions,
        SpamTicket $spam,
        RealtimePusher $realtime,
        EventPublisher $events,
        AuditLogger $audit,
    ): void {
        /** @var object{id: string, requested_by: string, action: string, params: string|null, total: int, status: string}|null $job */
        $job = DB::table('ticket_bulk_jobs')->where('id', $this->bulkJobId)->first();

        if ($job === null || $job->status === 'completed') {
            // C-09 for the batch itself: a re-delivered job whose batch already
            // finished must not re-run items OR re-publish the summary.
            return;
        }

        /** @var User $actor */
        $actor = User::query()->findOrFail($job->requested_by);

        /** @var array<string, mixed> $params */
        $params = $job->params !== null ? json_decode($job->params, true, 512, JSON_THROW_ON_ERROR) : [];

        $store = app(\App\Domain\Tickets\Bulk\BulkJobStore::class);
        $store->markRunning($job->id);

        // Re-drive resumes at the pending rows (C-09): completed items are history.
        /** @var list<object{id: string, ticket_id: string, position: int}> $items */
        $items = DB::table('ticket_bulk_job_items')
            ->where('bulk_job_id', $job->id)
            ->where('status', 'pending')
            ->orderBy('position')
            ->get()
            ->all();

        $ok = (int) DB::table('ticket_bulk_job_items')->where('bulk_job_id', $job->id)->where('status', 'ok')->count();
        $failed = [];

        $cancelled = false;

        foreach ($items as $item) {
            // C15's Stop, observed LIVE before each item — the same
            // at-execution posture the permission checks carry (SEC-04): a
            // cancel latched between two items stops before the next one
            // runs. The items that never ran finish failed('cancelled') so
            // the drawer names what happened on every row.
            if (DB::table('ticket_bulk_jobs')->where('id', $job->id)->value('cancel_requested_at') !== null) {
                $cancelled = true;
                break;
            }

            try {
                /** @var Ticket $ticket */
                $ticket = Ticket::query()->findOrFail($item->ticket_id);

                // THE LIVE CHECK IS INSIDE EACH CALL (SEC-04/EC-12) — see the class
                // docblock. This match holds routing only, never judgement.
                match ($job->action) {
                    'assign' => $assign->assign($ticket, $this->userParam($params, 'assignee_id'), $actor),
                    'transfer' => $transfer->transfer($ticket, $this->stringParam($params, 'department_id'), $actor),
                    'tag' => $tags->add($ticket, $this->listParam($params, 'tags'), $actor),
                    'priority' => $priority->change($ticket, $this->stringParam($params, 'priority'), $actor),
                    'resolve' => $resolve->resolve($ticket, $actor, isset($params['note']) && is_string($params['note']) ? $params['note'] : null),
                    'close' => $transitions->transition($ticket, TicketStatus::Closed, TransitionContext::staff($actor)),
                    'spam' => $spam->mark($ticket, $actor, $this->stringParam($params, 'reason')),
                    default => throw new InvalidArgumentException(sprintf('unknown bulk action "%s"', $job->action)),
                };

                $this->finishItem($item->id, 'ok', null);
                $ok++;
                $outcome = ['result' => 'ok', 'reason' => null];
            } catch (TransitionRefused $e) {
                $failed[] = $this->failItem($item, $e->refusalCode);
                $outcome = ['result' => 'failed', 'reason' => $e->refusalCode];
            } catch (IllegalTransition $e) {
                $failed[] = $this->failItem($item, 'illegal_transition');
                $outcome = ['result' => 'failed', 'reason' => 'illegal_transition'];
            } catch (TransitionNotPermitted $e) {
                $failed[] = $this->failItem($item, 'transition_not_permitted');
                $outcome = ['result' => 'failed', 'reason' => 'transition_not_permitted'];
            } catch (AlreadyClaimed $e) {
                $failed[] = $this->failItem($item, 'already_claimed');
                $outcome = ['result' => 'failed', 'reason' => 'already_claimed'];
            } catch (AssigneeOutOfScope $e) {
                $failed[] = $this->failItem($item, 'assignee_out_of_scope');
                $outcome = ['result' => 'failed', 'reason' => 'assignee_out_of_scope'];
            } catch (SequenceContention $e) {
                $failed[] = $this->failItem($item, 'sequence_contention');
                $outcome = ['result' => 'failed', 'reason' => 'sequence_contention'];
            } catch (DomainException|InvalidArgumentException $e) {
                $failed[] = $this->failItem($item, 'operation_refused');
                $outcome = ['result' => 'failed', 'reason' => 'operation_refused'];
            } catch (Throwable $e) {
                // An unexpected failure is still ONE item's failure (per-item partial
                // success, FR-25) — logged loudly, never re-thrown into the batch.
                Log::error('bulk item failed unexpectedly', [
                    'bulk_job_id' => $job->id,
                    'ticket_id' => $item->ticket_id,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
                $failed[] = $this->failItem($item, 'error');
                $outcome = ['result' => 'failed', 'reason' => 'error'];
            }

            // NFR-04: per-item progress, over the requester's own channel.
            $realtime->toUser($job->requested_by, 'bulk.progress', [
                'tenant_id' => $this->tenantId,
                'bulk_job_id' => $job->id,
                'position' => $item->position,
                'total' => $job->total,
                'ticket_id' => $item->ticket_id,
                ...$outcome,
            ]);
        }

        if ($cancelled) {
            $store->failPending($job->id, 'cancelled');
        }

        $store->markCompleted($job->id);

        // Failures recorded by EARLIER attempts of a re-driven job belong in the
        // summary too — the event describes the batch, not this process run.
        /** @var list<object{ticket_id: string, failure_reason: string|null}> $allFailed */
        $allFailed = DB::table('ticket_bulk_job_items')
            ->where('bulk_job_id', $job->id)
            ->where('status', 'failed')
            ->orderBy('position')
            ->get(['ticket_id', 'failure_reason'])
            ->all();

        $failedPayload = array_map(
            static fn (object $row): array => ['ticket_id' => $row->ticket_id, 'reason' => $row->failure_reason],
            $allFailed,
        );

        $events->publish('ticket.bulk_completed', [
            'tenant_id' => $this->tenantId,
            'bulk_job_id' => $job->id,
            'action' => $job->action,
            'ok' => $ok,
            'failed' => $failedPayload,
            'requested_by' => $job->requested_by,
        ]);

        // SEC-03: "bulk summaries always audited."
        $audit->record(
            actionKey: 'ticket.bulk_completed',
            entity: 'ticket_bulk_job',
            entityId: $job->id,
            actorType: AuditActor::Staff,
            payload: [
                'bulk_job_id' => $job->id,
                'action' => $job->action,
                'ok' => $ok,
                'failed_count' => count($failedPayload),
            ],
            actorId: $job->requested_by,
        );
    }

    private function finishItem(string $itemId, string $status, ?string $reason): void
    {
        // Bulk-state writes live in BulkJobStore — the NFR-03 constraint: a file
        // holding Ticket context must not also assign a status key, ANY table's.
        app(\App\Domain\Tickets\Bulk\BulkJobStore::class)->finishItem($itemId, $status, $reason);
    }

    /**
     * @param  object{id: string, ticket_id: string, position: int}  $item
     * @return array{ticket_id: string, reason: string}
     */
    private function failItem(object $item, string $reason): array
    {
        $this->finishItem($item->id, 'failed', $reason);

        return ['ticket_id' => $item->ticket_id, 'reason' => $reason];
    }

    /** @param array<string, mixed> $params */
    private function stringParam(array $params, string $key): string
    {
        $value = $params[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException(sprintf('bulk action requires params.%s', $key));
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return list<string>
     */
    private function listParam(array $params, string $key): array
    {
        $value = $params[$key] ?? null;

        if (! is_array($value) || $value === []) {
            throw new InvalidArgumentException(sprintf('bulk action requires a non-empty params.%s', $key));
        }

        return array_values(array_map(static function (mixed $v) use ($key): string {
            if (! is_string($v) || trim($v) === '') {
                throw new InvalidArgumentException(sprintf('params.%s must be a list of non-empty strings', $key));
            }

            return $v;
        }, $value));
    }

    /** @param array<string, mixed> $params */
    private function userParam(array $params, string $key): User
    {
        /** @var User $user */
        $user = User::query()->findOrFail($this->stringParam($params, $key));

        return $user;
    }
}
