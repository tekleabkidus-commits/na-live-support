<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Inbound;

use App\Domain\Conversations\Adapters\AdapterRegistry;
use App\Jobs\Conversations\NormalizeInbound;
use App\Models\MessageDeadLetter;
use App\Models\User;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\ResolutionSource;
use App\Support\Tenancy\RlsSession;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * FS-05's parking and re-drive (Doc 04): ×3 is the queue's job (`tries = 3` on
 * NormalizeInbound); what happens AFTER the third crash is this class. The full
 * raw payload parks in `message_dead_letters`, the ops view lists it, and
 * re-drive dispatches a fresh NormalizeInbound carrying the PARKED JOB'S
 * claimant — without it, a poison that died after claiming its dedupe slot
 * (EC-03) would re-drive into a silent duplicate-drop, which is a zero-loss
 * violation dressed up as success (the trap InboundDedupeStore's docblock
 * records for exactly this flow).
 *
 * park() runs from a failed job — OUTSIDE BindTenantContextToJob, whose
 * try/finally has already cleared the worker's context by the time failed()
 * fires. So park() binds tenant context itself, mirrors the middleware's
 * resolve-then-bind discipline, and clears in a finally: a parking write that
 * leaked its tenant onto the worker connection would be R-01's Octane hazard
 * reborn in the one place the middleware cannot police.
 */
final class DeadLetters
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly AdapterRegistry $registry,
    ) {}

    /**
     * Park a tries-exhausted inbound with its full payload. Called from
     * NormalizeInbound::failed() — never from a request.
     *
     * @param  array<string, mixed>  $payload
     */
    public function park(
        string $tenantId,
        ?string $correlationId,
        string $channel,
        string $channelRefId,
        array $payload,
        ?string $claimant,
        int $attempts,
        Throwable $error,
    ): void {
        $context = app(TenantContext::class);
        $rls = app(RlsSession::class);

        $resolved = app(TenantResolver::class)
            ->resolveTrustedTenantId($tenantId, ResolutionSource::QueuedJob);

        $context->clear();
        $context->bind($resolved->tenantId, $resolved->source, $correlationId);
        $rls->set($resolved->tenantId);

        try {
            // Capture the family NOW: the parking worker knows the channel; the
            // process serving a later re-drive may not (test fixtures, or an
            // adapter deregistered between park and re-drive).
            try {
                $family = $this->registry->inboundFamily($channel);
            } catch (\DomainException) {
                $family = null;
            }

            $letter = MessageDeadLetter::query()->create([
                'channel' => $channel,
                'channel_ref_id' => $channelRefId,
                'inbound_family' => $family,
                'payload' => $payload,
                'claimant' => $claimant,
                'error_class' => $error::class,
                'error_message' => mb_substr($error->getMessage(), 0, 2000),
                'attempts' => $attempts,
                'status' => MessageDeadLetter::STATUS_PARKED,
            ]);

            $this->audit->record(
                actionKey: 'conversation.message_parked',
                entity: 'message_dead_letter',
                entityId: $letter->identifier(),
                actorType: AuditActor::System,
                payload: [
                    'dead_letter_id' => $letter->identifier(),
                    'channel' => $channel,
                    'error_class' => $error::class,
                ],
            );

            Log::warning('inbound message parked in dead letter', [
                'metric' => 'messaging.inbound.parked',
                'dead_letter_id' => $letter->identifier(),
                'channel' => $channel,
                'error_class' => $error::class,
                'attempts' => $attempts,
            ]);
        } finally {
            $rls->clear();
            $context->clear();
        }
    }

    /**
     * Re-drive a parked item: flip it to `redriven` (audited, DB time) and
     * dispatch a fresh NormalizeInbound onto the channel's inbound family with
     * the parked claimant. Dispatch is afterCommit (C-06): the job only exists
     * if the status flip committed, so a lost race parks nothing twice.
     *
     * @throws DeadLetterNotParked when the row is not (or no longer) parked
     */
    public function redrive(string $deadLetterId, User $actor): MessageDeadLetter
    {
        return DB::transaction(function () use ($deadLetterId, $actor): MessageDeadLetter {
            /** @var MessageDeadLetter $letter */
            $letter = MessageDeadLetter::query()
                ->whereKey($deadLetterId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($letter->status !== MessageDeadLetter::STATUS_PARKED) {
                throw new DeadLetterNotParked($letter->identifier(), $letter->status);
            }

            // DB time, never the app clock (C-11) — the house idiom.
            MessageDeadLetter::query()->whereKey($letter->identifier())->update([
                'status' => MessageDeadLetter::STATUS_REDRIVEN,
                'redriven_by' => $actor->identifier(),
                'redriven_at' => DB::raw('now()'),
            ]);
            $letter->refresh();

            $this->audit->record(
                actionKey: 'conversation.message_redriven',
                entity: 'message_dead_letter',
                entityId: $letter->identifier(),
                actorType: AuditActor::Staff,
                payload: [
                    'dead_letter_id' => $letter->identifier(),
                    'channel' => $letter->channel,
                ],
                actorId: $actor->identifier(),
            );

            NormalizeInbound::dispatch(
                $letter->channel,
                $letter->channel_ref_id,
                $letter->payload,
                $letter->claimant,
            )->onQueue($letter->inbound_family ?? $this->registry->inboundFamily($letter->channel));

            return $letter;
        });
    }
}
