<?php

declare(strict_types=1);

namespace App\Domain\Customers\Identity;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The ENFORCE release's data passes (SCHEMA AMENDMENT 2 item 2 + the step-1
 * checkpoint's RULING 1), run per tenant by the enforce migration BEFORE it
 * applies NOT NULL:
 *
 * 1. STRAGGLER RE-RUN (ruling 1's requirement): between the 1b backfill and
 *    the step-2 resolver rewrite, the legacy S20 writer produced NULL-typed
 *    rows that bypass the unique index (NULLs distinct). The full backfill
 *    re-runs — same keeper policy, same conflict rows, idempotent — so the
 *    NOT NULL that follows finds nothing to refuse. A channel the map does
 *    not know still fails loudly (inherited from the backfill).
 *
 * 2. PHONE RE-NORMALIZATION (amendment item 2's note; ADR 0014 now
 *    installed): backfilled phone identities carried VERBATIM values; this
 *    one-time pass normalizes them to E.164. When normalization changes a
 *    value, the ORIGINAL SPELLING is preserved in the identity's
 *    metadata.raw and the deprecated customers.phone mirror is updated to
 *    the normalized form so the DropGate's equality stays honest. Two
 *    refuse-loudly clauses, both the human-consolidation posture: a value
 *    libphonenumber cannot validate, and a normalization that would COLLIDE
 *    with an existing identity (fusing two customers is a human decision,
 *    never a migration's).
 */
final class LegacyIdentityEnforce
{
    public function run(string $connection): void
    {
        (new LegacyIdentityBackfill)->run($connection);

        $db = DB::connection($connection);

        /** @var \Illuminate\Support\Collection<int, object{id: string, customer_id: string, value: string, metadata: string}> $phones */
        $phones = $db->table('customer_identities')
            ->where('type', 'phone')
            ->get(['id', 'customer_id', 'value', 'metadata']);

        foreach ($phones as $identity) {
            try {
                $normalized = PhoneNumber::toE164($identity->value, 'ET');
            } catch (\InvalidArgumentException $e) {
                throw new RuntimeException(
                    "S08 enforce refuses: phone identity {$identity->id} holds '{$identity->value}', which does not normalize ({$e->getMessage()}) — resolve by hand before enforcing."
                );
            }

            if ($normalized === $identity->value) {
                continue;
            }

            $collision = $db->table('customer_identities')
                ->where('type', 'phone')
                ->where('value', $normalized)
                ->where('id', '!=', $identity->id)
                ->exists();

            if ($collision) {
                throw new RuntimeException(
                    "S08 enforce refuses: normalizing phone identity {$identity->id} would collide with an existing identity on '{$normalized}' — merging customers is a human decision (FR-08/BR-02), consolidate by hand first."
                );
            }

            /** @var array<string, mixed> $metadata */
            $metadata = (array) json_decode($identity->metadata, true);
            $metadata['raw'] = $identity->value;    // the original spelling, preserved

            $db->table('customer_identities')
                ->where('id', $identity->id)
                ->update([
                    'value' => $normalized,
                    'external_user_id' => $normalized,  // legacy mirror column, same window
                    'metadata' => json_encode($metadata),
                ]);

            // Keep the DropGate's equality honest: the deprecated column
            // mirrors its identity; the original spelling lives in metadata.raw.
            $db->table('customers')
                ->where('id', $identity->customer_id)
                ->where('phone', $identity->value)
                ->update(['phone' => $normalized]);
        }

        // The NOT NULL that follows must find nothing to refuse — say so
        // loudly here, with counts, rather than letting ALTER throw a bare
        // constraint error.
        $nullIdentities = $db->table('customer_identities')
            ->whereNull('type')->orWhereNull('value')->orWhereNull('source')
            ->count();
        $nullSources = $db->table('customers')->whereNull('created_source')->count();

        if ($nullIdentities > 0 || $nullSources > 0) {
            throw new RuntimeException(
                "S08 enforce refuses: {$nullIdentities} identity row(s) / {$nullSources} customer row(s) still un-typed after the straggler re-run — investigate before NOT NULL."
            );
        }
    }
}
