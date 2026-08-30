<?php

declare(strict_types=1);

namespace App\Domain\Routing\Intake;

use Illuminate\Support\Facades\DB;

/**
 * Doc 11 FR-08 step 2 — "rg intent/flag (Doc 09 FR-13, Doc 08 FR-15)": an intake is RG when
 * the customer carries Doc 08's rg_flag OR the ticket's Doc 09 handoff packet carries the
 * rg_risk reason. Read from truth (the rows), never from a payload (Q7).
 */
final class RgIntake
{
    /** @return array{is_rg: bool, source: ?string} */
    public function detect(string $ticketId, string $customerId): array
    {
        if ((bool) DB::table('customers')->where('id', $customerId)->value('rg_flag')) {
            return ['is_rg' => true, 'source' => 'flag'];
        }
        $payload = DB::table('ticket_handoff_packets')->where('ticket_id', $ticketId)->value('payload');
        if (is_string($payload)) {
            /** @var array{intent?: string, suggested?: array{reasons?: list<string>}} $packet */
            $packet = (array) json_decode($payload, true);
            $reasons = $packet['suggested']['reasons'] ?? [];
            if (($packet['intent'] ?? null) === 'rg_risk' || in_array('rg_risk', $reasons, true)) {
                return ['is_rg' => true, 'source' => 'ai'];
            }
        }

        return ['is_rg' => false, 'source' => null];
    }

    /**
     * The tenant's ONE is_rg queue (FR-03 physics) with its department, or null.
     *
     * @return array{queue_id: string, department_id: string}|null
     */
    public function rgQueue(): ?array
    {
        /** @var object{id: string, department_id: string}|null $row */
        $row = DB::table('queues')->where('is_rg', true)->whereNull('deleted_at')->where('is_active', true)->first(['id', 'department_id']);

        return $row === null ? null : ['queue_id' => $row->id, 'department_id' => $row->department_id];
    }
}
