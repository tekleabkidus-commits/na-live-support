<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Support\Tenancy\TenantAware;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * ADR 0011's consumer contract, as a TYPE rather than a convention.
 *
 * Every durable consumer the relay's dispatch map names extends this: constructed
 * from the EVENT ID ALONE (consumers re-read the event row — and re-fetch anything
 * it identifies — under their own tenant context, so nothing stale or private rides
 * the queue), TenantAware so the relay can stamp tenant + correlation from the
 * outbox row (C-14), and idempotent on event_id (C-09) — that last clause is the
 * one a type cannot carry, so each subclass's docblock must say HOW it is.
 *
 * The base class is what lets ConsumerRegistry be `list<class-string<self>>` and
 * the relay construct entries without reflection or hope.
 */
abstract class OutboxConsumer implements ShouldQueue
{
    use Queueable, TenantAware;

    /** The FS-05 posture messaging established: three attempts, then dead-letter. */
    public int $tries = 3;

    final public function __construct(public readonly string $eventId)
    {
        $this->captureTenant();
        $this->onQueue($this->queueFamily());
    }

    /** The Horizon family this consumer runs on (Doc 01's per-family supervisors). */
    abstract protected function queueFamily(): string;
}
