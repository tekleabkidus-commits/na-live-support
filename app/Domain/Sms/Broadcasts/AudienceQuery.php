<?php

declare(strict_types=1);

namespace App\Domain\Sms\Broadcasts;

use App\Domain\Customers\Search\CustomerScope;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * FR-18 — the audience is a QUERY, never a list (BR-02's scope wall):
 * Doc 08's `CustomerScope::constrainQuery` under the LAUNCHER's LIVE scope
 * (Doc 03 FR-06's job semantics — re-resolved at every execution, so a
 * demoted launcher's running broadcast shrinks with them); the filters the
 * document names (language / country / tags / has-open-ticket / a range on
 * `last_contact_at`, Doc 08's own sort key); **`WHERE rg_flag = false`
 * STRUCTURAL in the SQL** (SEC-03 — the wall is the column, asserted on the
 * compiled query by its own suite); a phone identity REQUIRED (nothing to
 * send to otherwise). Marketing suppression is per recipient THROUGH THE
 * GUARD (LiveFacts reads `MarketingSuppression::isSuppressed` at enqueue and
 * again at send): a suppressed recipient stays IN the audience and lands as
 * a VISIBLE `skipped:marketing_suppressed` row — AC-06's "preview = executed
 * ± live skips" arithmetic needs the skips on the books, never silently
 * pre-filtered.
 */
final class AudienceQuery
{
    public function __construct(private readonly CustomerScope $scope) {}

    /**
     * The constrained, RG-walled, phone-bearing audience as a QUERY (counts
     * and previews read it; the chunker pages it by id).
     *
     * @param  array<string, mixed>  $filters
     */
    public function query(array $filters, User $launcher): Builder
    {
        $query = DB::table('customers')
            ->where('customers.rg_flag', '=', false)                     // SEC-03: THE structural wall
            ->whereNull('customers.blocked_at')
            ->whereExists(function (Builder $sub): void {
                $sub->from('customer_identities')
                    ->whereColumn('customer_identities.customer_id', 'customers.id')
                    ->where('customer_identities.type', 'phone');
            });

        if (is_string($filters['language'] ?? null) && $filters['language'] !== '') {
            $query->where('customers.language', $filters['language']);
        }
        if (is_string($filters['country'] ?? null) && $filters['country'] !== '') {
            $query->where('customers.country', $filters['country']);
        }
        if (is_array($filters['tags'] ?? null) && $filters['tags'] !== []) {
            $tags = array_values(array_filter($filters['tags'], 'is_string'));
            $query->whereRaw('customers.tags @> ?', ['{'.implode(',', $tags).'}']);
        }
        if (($filters['has_open_ticket'] ?? null) === true) {
            $query->whereExists(function (Builder $sub): void {
                $sub->from('tickets')
                    ->whereColumn('tickets.customer_id', 'customers.id')
                    ->whereIn('tickets.status', ['new', 'open', 'pending_customer', 'pending_internal', 'escalated']);
            });
        }
        if (is_string($filters['last_contact_after'] ?? null) && $filters['last_contact_after'] !== '') {
            $query->where('customers.last_contact_at', '>=', $filters['last_contact_after']);
        }
        if (is_string($filters['last_contact_before'] ?? null) && $filters['last_contact_before'] !== '') {
            $query->where('customers.last_contact_at', '<', $filters['last_contact_before']);
        }

        // Doc 03 FR-06 job semantics: the LAUNCHER's scope, LIVE at this call
        return $this->scope->constrainQuery($query, $this->scope->signature($launcher));
    }

    /** @param array<string, mixed> $filters */
    public function count(array $filters, User $launcher): int
    {
        return (int) $this->query($filters, $launcher)->count();
    }

    /**
     * One materialized page (the chunker's unit) — ids only; the guard does
     * every per-recipient read (phone, opt-out, suppression) at enqueue.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array{customer_id: string}>
     */
    public function recipients(array $filters, User $launcher, int $limit = 500, ?string $afterCustomerId = null): array
    {
        $rows = $this->query($filters, $launcher)
            ->when($afterCustomerId !== null, fn (Builder $q) => $q->where('customers.id', '>', $afterCustomerId))
            ->orderBy('customers.id')
            ->limit($limit)
            ->pluck('customers.id');

        $out = [];
        foreach ($rows as $id) {
            if (is_string($id)) {
                $out[] = ['customer_id' => $id];
            }
        }

        return $out;
    }
}
