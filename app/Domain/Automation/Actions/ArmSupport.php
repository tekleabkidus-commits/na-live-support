<?php

declare(strict_types=1);

namespace App\Domain\Automation\Actions;

use App\Models\Ticket;
use Illuminate\Support\Facades\DB;

/**
 * Doc 12 FR-03/EC-01 — the shared arm plumbing: resolve the execution's ticket
 * from ROW TRUTH (never a payload), and answer EC-01's honesty question —
 * whether the fact an arm is about to act on has MOVED since the rule matched
 * (superseded_state=true on the outcome, visible in dry-run and the log).
 */
trait ArmSupport
{
    private function ticketOf(string $executionId): ?Ticket
    {
        /** @var object{ticket_id: ?string}|null $row */
        $row = DB::selectOne("SELECT context_ref->>'ticket_id' AS ticket_id FROM automation_rule_executions WHERE id = ?", [$executionId]);

        return is_string($row?->ticket_id) ? Ticket::query()->find($row->ticket_id) : null;
    }

    /** @param array<string, mixed> $basis */
    private function superseded(array $basis, string $fact, mixed $current): bool
    {
        return array_key_exists($fact, $basis) && $basis[$fact] !== $current;
    }
}
