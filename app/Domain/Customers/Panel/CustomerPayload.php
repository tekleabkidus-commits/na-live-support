<?php

declare(strict_types=1);

namespace App\Domain\Customers\Panel;

use Illuminate\Support\Facades\DB;

/**
 * THE CURATED PAYLOAD ALLOWLIST (the B12 idiom, this document's PII floor) —
 * every read door that serves a customer to a client builds its array HERE,
 * from a NAMED column list. Nothing serializes a model; nothing selects *.
 *
 * SEC-01 IS QUERY-LEVEL, AND THIS CLASS IS WHERE THAT IS TRUE: when the
 * viewer lacks customers.rg_flag.view the RG columns are NEVER NAMED IN THE
 * SQL — not loaded-then-stripped — and the returned array carries NO rg keys
 * (ABSENT, never null: a null where a key used to be is the inference
 * channel BR-03 forbids). Two agents comparing screens must not be able to
 * tell which of them can see more. SABOTAGE #1 moves the gate to the
 * serializer (select everything, unset after) and RgVisibilityTest's
 * never-names-the-columns assertion goes red.
 *
 * The blocked block is NOT gated here: §8 draws the blocked banner for every
 * customers.view holder — blocking is operational state, the RG flag is
 * care (B4-S05's closing line: "the two states look different on purpose").
 */
final class CustomerPayload
{
    private const BASE_COLUMNS = [
        'id', 'display_name', 'is_shell', 'language', 'language_source',
        'country', 'country_source', 'tags', 'created_source',
        'blocked', 'blocked_reason', 'blocked_by', 'blocked_at',
        'tickets_count', 'open_tickets', 'contacts_30d_count',
        'satisfaction_avg', 'satisfaction_count', 'last_contact_at',
        'merged_into_id', 'created_at',
    ];

    private const RG_COLUMNS = [
        'rg_flag', 'rg_flag_set_by', 'rg_flag_set_at', 'rg_flag_reason',
    ];

    /**
     * @param  bool  $viewerSeesRg  the DOOR's resolved customers.rg_flag.view
     *                              verdict (capability-cache-BYPASSED for this
     *                              key — Doc 03's R-04 list)
     * @return array<string, mixed>|null null = not found (the door 404s, C-10)
     */
    public function build(string $customerId, bool $viewerSeesRg): ?array
    {
        $columns = $viewerSeesRg
            ? array_merge(self::BASE_COLUMNS, self::RG_COLUMNS)
            : self::BASE_COLUMNS;

        /** @var object|null $row */
        $row = DB::table('customers')->where('id', $customerId)->first($columns);

        if ($row === null) {
            return null;
        }

        /** @var array<string, mixed> $payload */
        $payload = get_object_vars($row);

        return $payload;
    }
}
