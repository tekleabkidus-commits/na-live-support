<?php

declare(strict_types=1);

namespace App\Jobs\Conversations;

use App\Domain\Conversations\Adapters\AdapterRegistry;
use App\Domain\Conversations\Identity\CustomerResolver;
use App\Domain\Conversations\Inbound\BatchTailHint;
use App\Domain\Conversations\Inbound\DeadLetters;
use App\Domain\Conversations\Inbound\InboundDedupeStore;
use App\Domain\Conversations\Inbound\InboundPersister;
use App\Domain\Foundation\Retention\ColdSummaryStore;
use App\Domain\Foundation\Retention\InboundAccrual;
use App\Domain\Foundation\Retention\InboundAccrualPolicy;
use App\Models\Tenant;
use App\Support\Tenancy\TenantAware;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * FR-10 steps 2–4, off the request path: normalize via the adapter, dedupe,
 * resolve identity, persist-with-outbox. The webhook controller's ONLY job was to
 * ACK ≤ 250 ms and enqueue this (step 1, never inline) — everything that can be
 * slow, fail, or retry lives here, on the channel's inbound family queue.
 *
 * tries = 3 is FS-05's number: a normalizer crash loop gets three attempts and
 * then parks in the dead-letter with full payload via failed() → DeadLetters
 * (step 9b). The framework failed_jobs row still exists underneath — harmless
 * duplication; message_dead_letters is the canonical FS-05 store the ops
 * re-drive view reads.
 *
 * $claimantOverride exists FOR the re-drive: the parked job may have claimed
 * its dedupe slot (EC-03) before dying, and a re-driven job presenting a fresh
 * claimant would read as a duplicate and silently drop. The dead letter stores
 * the parked claimant; DeadLetters::redrive() passes it back in here.
 *
 * BR-05 (Doc 01's frozen contract) sits between dedupe and identity: a suspended
 * tenant past its accrual window gets the message ACKed and COUNTED
 * (ColdSummaryStore) but never STORED — no body, no shell customer, no
 * conversation. Deduped first so channel redeliveries do not inflate the count.
 */
final class NormalizeInbound implements ShouldQueue
{
    use Queueable, TenantAware;

    /** FS-05: ×3, then the dead-letter path (step 9). */
    public int $tries = 3;

    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $channel,
        public readonly string $channelRefId,
        public readonly array $raw,
        public readonly ?string $claimantOverride = null,
    ) {
        $this->captureTenant();
    }

    public function handle(
        AdapterRegistry $registry,
        InboundDedupeStore $dedupe,
        InboundAccrualPolicy $accrual,
        ColdSummaryStore $coldSummaries,
        CustomerResolver $identity,
        InboundPersister $persister,
        BatchTailHint $batchTail,
    ): void {
        $adapter = $registry->adapter($this->channel);

        // Step 2a: normalize. A malformed payload throws here — that is the FS-05
        // poison path (tries ×3), never a silent partial persist.
        $inbound = $adapter->handleWebhook($this->raw);

        // Step 2b: dedupe (EC-03). Claimant = this job's uuid, constant across
        // retries — see InboundDedupeStore for why that must be true. A re-driven
        // job carries the PARKED job's claimant instead (FS-05 — see the class
        // docblock), or its own retries would drop against the parked claim.
        $claimant = $this->claimantOverride
            ?? (is_string($this->job?->uuid()) ? $this->job->uuid() : (string) Str::uuid7());

        if (! $dedupe->firstSight($this->channel, $inbound->dedupeKey, $claimant)) {
            Log::info('inbound duplicate dropped', [
                'metric' => 'messaging.inbound.duplicate_dropped',
                'channel' => $this->channel,
                'dedupe_key_hash' => hash('sha256', $inbound->dedupeKey),
            ]);

            return;
        }

        // BR-05: suspended-tenant accrual (Doc 01's frozen contract, consumed —
        // never reimplemented). ColdSummary → count + pointer, body never stored.
        $tenant = Tenant::query()->findOrFail($this->tenantId);

        if ($accrual->modeFor($tenant, CarbonImmutable::now('UTC')) === InboundAccrual::ColdSummary) {
            $coldSummaries->record(
                $this->channel,
                sprintf('%s:%s:%s', $this->channel, $inbound->externalChatId, $inbound->externalMessageId),
            );

            return;
        }

        // FR-21: an inbound whose external id we already hold is a channel-
        // originated EDIT — it becomes a revision, never a second row.
        $existing = $persister->messages()->findByExternalMessageId($inbound->externalMessageId);

        if ($existing !== null) {
            app(\App\Domain\Conversations\Revisions\MessageEditing::class)
                ->customerEdited($existing, $inbound->body);

            return;
        }

        // Step 3: identity (the Doc 08 seam — match or create shell).
        $customerId = $identity->resolve($this->channel, $inbound->sender);

        // Step 4: one transaction — row + outbox event (or FR-11 quarantine+audit).
        $message = $persister->persist($this->channel, $this->channelRefId, $inbound, $customerId);

        // EC-01: the batch-tail debounce — fan-out territory, so quarantined rows
        // (FR-11: no fan-out except audit) never reach it.
        if (! $message->quarantined) {
            // FR-25: the ephemeral realtime push (post-commit, at-most-once —
            // EC-12's sync endpoint is the recovery, the bus is the durable
            // truth). One producer proves the path; the remaining producers
            // (state changes, edits) wire with Doc 07's live inbox (bucket).
            app(\App\Domain\Conversations\Realtime\RealtimePusher::class)
                ->toConversation($message->conversation_id, 'message.received', [
                    'tenant_id' => $this->tenantId,
                    'message_id' => $message->identifier(),
                    'conversation_id' => $message->conversation_id,
                    'kind' => $message->kind,
                ]);

            $batchTail->recordArrival($message->conversation_id, $message->identifier());

            ConfirmBatchTail::dispatch($message->conversation_id, $message->identifier())
                ->onQueue($registry->inboundFamily($this->channel))
                ->delay(2);

            // FR-10 step 5: one media job PER ITEM, on the isolated media family
            // (FS-03). Quarantined messages keep their placeholders unfetched —
            // spam bytes are never downloaded, scanned, or stored (FR-11's
            // no-fan-out extended to the one place fan-out costs money).
            foreach (array_keys($message->media) as $index) {
                FetchInboundMedia::dispatch($message->identifier(), (int) $index)->onQueue('media');
            }
        }
    }

    /**
     * FS-05: the third crash lands here — park with the FULL payload so the ops
     * view can list and re-drive it while the conversation continues around it.
     * The worker calls failed() OUTSIDE the job middleware, so no tenant is
     * bound; DeadLetters::park() binds and clears its own context.
     */
    public function failed(?Throwable $error): void
    {
        if (! is_string($this->tenantId) || $this->tenantId === '') {
            // FR-03f's victim: dispatched with no tenant, refused by the job
            // middleware. There is no tenant to park under — the framework
            // failed_jobs row is the record, and the dispatcher is the bug.
            return;
        }

        $claimant = $this->claimantOverride
            ?? (is_string($this->job?->uuid()) ? $this->job->uuid() : null);

        app(DeadLetters::class)->park(
            tenantId: $this->tenantId,
            correlationId: $this->correlationId,
            channel: $this->channel,
            channelRefId: $this->channelRefId,
            payload: $this->raw,
            claimant: $claimant,
            attempts: (int) ($this->job?->attempts() ?? $this->tries),
            error: $error ?? new RuntimeException('normalizer failed with no exception recorded'),
        );
    }
}
