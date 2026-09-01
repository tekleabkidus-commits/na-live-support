<?php

declare(strict_types=1);

namespace App\Domain\Routing\Pipeline;

use Illuminate\Support\Facades\DB;

/**
 * Doc 11 FR-12 — which boosts a ticket MATCHES (the queue's ordered list decides which
 * count and how much): urgent_priority (Doc 06 FR-17 high|urgent), vip (a `vip` customer
 * tag — EC-05: the config screen warns when the VIP source is an agent-editable tag; a
 * customer FIELD source is Doc 08's field values, consumed when configured), window_closing
 * (Doc 05 FR-22 — the projected reply-window stamp within 15 minutes), sla_at_risk (Doc 12's
 * warned/breached state — the ticket_list_items seam column, [D12]), reopened (Doc 06 FR-08's
 * reopened_count > 0). Reads truth rows; never a payload.
 */
final class BoostMatcher
{
    /**
     * @param  array<string, mixed>  $ticket  id, priority, reopened_count, customer_id
     * @return list<string>
     */
    public function matches(array $ticket): array
    {
        $matched = [];
        if (in_array($ticket['priority'] ?? null, ['high', 'urgent'], true)) {
            $matched[] = 'urgent_priority';
        }
        $customerId = $ticket['customer_id'] ?? null;
        $customerTags = is_string($customerId) ? DB::table('customers')->where('id', $customerId)->value('tags') : null;
        if (is_string($customerTags) && preg_match('/(^|[{,])"?vip"?([},]|$)/i', $customerTags) === 1) {
            $matched[] = 'vip';
        }
        $ticketId = $ticket['id'] ?? null;
        /** @var object{window_expires_at: ?string}|null $projected */
        $projected = is_string($ticketId) ? DB::table('ticket_list_items')->where('ticket_id', $ticketId)->first(['window_expires_at']) : null;
        if ($projected !== null && $projected->window_expires_at !== null) {
            /** @var object{closing: bool} $w */
            $w = DB::selectOne("SELECT (?::timestamptz - clock_timestamp()) BETWEEN interval '0' AND interval '15 minutes' AS closing", [$projected->window_expires_at]);
            if ($w->closing) {
                $matched[] = 'window_closing';
            }
        }
        // [D12] closing row 11 (S12 step 6): sla_at_risk reads REAL SLA instance
        // state — a live warned/breached stage (Doc 12 FR-10) — instead of the
        // fixture-fed projection field. The projection's chip feed is packet
        // P-CHIP's (Doc 07 render side).
        $atRisk = is_string($ticketId) && DB::table('sla_instance_stages')
            ->join('sla_instances', 'sla_instances.id', '=', 'sla_instance_stages.instance_id')
            ->where('sla_instances.ticket_id', $ticketId)
            ->whereNull('sla_instance_stages.cleared_at')
            ->where(static fn (\Illuminate\Database\Query\Builder $q): \Illuminate\Database\Query\Builder => $q->where('sla_instance_stages.warned', true)->orWhere('sla_instance_stages.breached', true))
            ->exists();
        if ($atRisk) {
            $matched[] = 'sla_at_risk';
        }
        $reopened = $ticket['reopened_count'] ?? 0;
        if (is_int($reopened) && $reopened > 0) {
            $matched[] = 'reopened';
        }

        return $matched;
    }
}
