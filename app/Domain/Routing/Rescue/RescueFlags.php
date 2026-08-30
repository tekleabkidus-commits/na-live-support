<?php

declare(strict_types=1);

namespace App\Domain\Routing\Rescue;

use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Events\EventPublisher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Doc 11 FR-16 a/c / AC-06 — the `rescue_flags` writer (SCHEMA AMENDMENT 5 ADDITION 1).
 * ONE live flag per ticket (partial unique — a second `flag()` is a no-op that returns the
 * live id); flags are CLEARED, never deleted; `rescue.flagged{reason}` feeds Doc 07's
 * projector (P-C2) and Doc 16; `routing.rescue.flagged` audited as system.
 */
final class RescueFlags
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly EventPublisher $events,
        private readonly AuditLogger $audit,
    ) {}

    public function live(string $ticketId): ?string
    {
        $id = DB::table('rescue_flags')->where('ticket_id', $ticketId)->whereNull('cleared_at')->value('id');

        return is_string($id) ? $id : null;
    }

    /** Flag (idempotent on a live flag). Returns the live flag id. */
    public function flag(string $ticketId, string $reason): string
    {
        $live = $this->live($ticketId);
        if ($live !== null) {
            return $live;
        }
        $conversationId = DB::table('tickets')->where('id', $ticketId)->value('conversation_id');
        $id = (string) Str::uuid7();
        DB::transaction(function () use ($id, $ticketId, $reason, $conversationId): void {
            DB::table('rescue_flags')->insert(['id' => $id, 'tenant_id' => $this->context->tenantId(), 'ticket_id' => $ticketId, 'reason' => $reason]);
            $this->events->publish('rescue.flagged', ['tenant_id' => $this->context->tenantId(), 'ticket_id' => $ticketId, 'conversation_id' => is_string($conversationId) ? $conversationId : null, 'reason' => $reason]);
            $this->audit->record('routing.rescue.flagged', 'ticket', $ticketId, AuditActor::System, ['ticket_id' => $ticketId, 'reason' => $reason]);
        });

        return $id;
    }

    /** Clear the live flag (no-op when none). */
    public function clear(string $ticketId, bool $autoReturned = false): void
    {
        DB::table('rescue_flags')->where('ticket_id', $ticketId)->whereNull('cleared_at')
            ->update(['cleared_at' => DB::raw('clock_timestamp()'), 'auto_returned' => $autoReturned]);
    }
}
