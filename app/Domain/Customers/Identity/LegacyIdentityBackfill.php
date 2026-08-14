<?php

declare(strict_types=1);

namespace App\Domain\Customers\Identity;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Uid\UuidV7;

/**
 * The SCHEMA AMENDMENT 2 backfill (correction B2), runnable per tenant and
 * IDEMPOTENT — the migration calls it for every tenant; IdentityBackfillTest
 * drives it directly against seeded legacy-shaped rows.
 *
 * Three passes, in order:
 *
 * 1. TYPE the legacy channel identities: type/value/source/first_seen_at
 *    filled from (channel, external_user_id, created_at) via the
 *    MIGRATION-LOCAL channel map (deliberately NOT ChannelIdentityMap/A-40 —
 *    the backfill must not depend on the runtime ruling's class; the two maps
 *    agree today and the fail-loud posture is the same). An unmapped channel
 *    THROWS with the channel named — the amendment's loud-failure clause.
 * 2. Mirror customers.username into the telegram identity's metadata (the
 *    deprecated column's permanent home; the drop gate verifies it).
 * 3. Phone → identity with the KEEPER POLICY: per shared (tenant, phone)
 *    value the identity goes to the OLDEST customer by created_at, tiebreak
 *    LOWEST UUIDv7; every loser gets a customer_identity_conflicts row — the
 *    PERMANENT HOME (ruling 2: resolution is a marker, rows are undeletable
 *    by grant). Values are copied VERBATIM; step 2's enforce re-normalizes
 *    via ADR 0014 once the library lands (amendment item 2's note).
 *
 * Runs on the caller's connection under the caller's tenant binding — the
 * migration binds per tenant on pgsql_migrator; the test binds via its
 * normal app-context harness.
 */
final class LegacyIdentityBackfill
{
    /** Migration-local by design — see the class docblock. */
    private const CHANNEL_TYPE_MAP = [
        'telegram' => 'telegram',
        'fake' => 'web',
        'webchat' => 'web',
    ];

    public function run(string $connection): void
    {
        $db = DB::connection($connection);

        // ── Loud-failure clause: no identity row may carry a channel the map
        //    does not know.
        $unmapped = $db->table('customer_identities')
            ->whereNull('type')
            ->whereNotIn('channel', array_keys(self::CHANNEL_TYPE_MAP))
            ->distinct()
            ->pluck('channel');

        if ($unmapped->isNotEmpty()) {
            throw new RuntimeException(
                'S08 identity backfill refuses: unmapped channel(s) '.$unmapped->implode(', ')
                .' — extend the migration-local map deliberately (SCHEMA AMENDMENT 2).'
            );
        }

        // ── Pass 1: type the channel identities (idempotent: WHERE type IS NULL).
        foreach (self::CHANNEL_TYPE_MAP as $channel => $type) {
            $db->table('customer_identities')
                ->whereNull('type')
                ->where('channel', $channel)
                ->update([
                    'type' => $type,
                    'value' => $db->raw('external_user_id'),
                    'source' => 'channel_auto',
                    'first_seen_at' => $db->raw('created_at'),
                ]);
        }

        // ── Pass 2: mirror customers.username into telegram identity metadata
        //    (only where the metadata does not already carry one — idempotent).
        $db->statement(<<<'SQL'
            UPDATE customer_identities ci
               SET metadata = jsonb_set(ci.metadata, '{username}', to_jsonb(c.username))
              FROM customers c
             WHERE ci.customer_id = c.id
               AND ci.type = 'telegram'
               AND c.username IS NOT NULL
               AND NOT (ci.metadata ?? 'username')
            SQL);

        // ── Pass 3: phone → identity, keeper policy + conflict rows.
        /** @var \Illuminate\Support\Collection<int, object{id: string, phone: string, tenant_id: string}> $phones */
        $phones = $db->table('customers')
            ->whereNotNull('phone')
            ->orderBy('created_at')
            ->orderBy('id')                     // UUIDv7 tiebreak: lowest wins
            ->get(['id', 'phone', 'tenant_id']);

        /** @var array<string, object{id: string, phone: string, tenant_id: string}> $keeperByValue */
        $keeperByValue = [];

        foreach ($phones as $customer) {
            $value = (string) $customer->phone;
            // RLS already scopes each run to one tenant; the composite key is
            // belt-and-braces so the policy is correct even unscoped.
            $groupKey = $customer->tenant_id.'|'.$value;

            if (! isset($keeperByValue[$groupKey])) {
                $keeperByValue[$groupKey] = $customer;

                // Idempotency: the keeper's identity may exist from a prior run.
                $exists = $db->table('customer_identities')
                    ->where('type', 'phone')
                    ->where('value', $value)
                    ->exists();

                if (! $exists) {
                    $db->table('customer_identities')->insert([
                        'id' => (string) new UuidV7,
                        'tenant_id' => $customer->tenant_id,
                        'customer_id' => $customer->id,
                        'channel' => 'phone',       // legacy column, NOT NULL until dropped
                        'external_user_id' => $value,
                        'type' => 'phone',
                        'value' => $value,
                        'source' => 'channel_auto',
                        'first_seen_at' => $db->raw('clock_timestamp()'),
                        'metadata' => '{}',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                continue;
            }

            // A loser: the value's permanent home is the conflict row.
            $keeper = $keeperByValue[$groupKey];
            $exists = $db->table('customer_identity_conflicts')
                ->where('type', 'phone')
                ->where('value', $value)
                ->where('loser_customer_id', $customer->id)
                ->exists();

            if (! $exists) {
                $db->table('customer_identity_conflicts')->insert([
                    'id' => (string) new UuidV7,
                    'tenant_id' => $customer->tenant_id,
                    'type' => 'phone',
                    'value' => $value,
                    'keeper_customer_id' => $keeper->id,
                    'loser_customer_id' => $customer->id,
                ]);
            }
        }

        // ── created_source, identity-derived first…
        $db->statement(<<<'SQL'
            UPDATE customers c
               SET created_source = sub.src
              FROM (
                    SELECT DISTINCT ON (customer_id) customer_id,
                           CASE type WHEN 'telegram' THEN 'telegram' ELSE 'webchat' END AS src
                      FROM customer_identities
                     WHERE type IN ('telegram', 'web')
                     ORDER BY customer_id, created_at ASC
                   ) sub
             WHERE c.id = sub.customer_id
               AND c.created_source IS NULL
            SQL);

        // …then CONVERSATION-derived for identity-less customers (the FILED
        //    RIDER, citing the step-2 acceptance ruling: seeder-minted
        //    customers were "created by the resolver on first inbound" in the
        //    fiction — the oldest conversation's channel is that inbound's
        //    channel). Identities are NOT minted here (EC-05: zero-identity
        //    profiles are legal; a chat id is not a user id).
        $unmappedConvo = $db->table('conversations')
            ->join('customers', 'customers.id', '=', 'conversations.customer_id')
            ->whereNull('customers.created_source')
            ->whereNotIn('conversations.channel', ['telegram', 'fake', 'fakechan', 'minchan', 'webchat'])
            ->distinct()
            ->pluck('conversations.channel');

        if ($unmappedConvo->isNotEmpty()) {
            throw new RuntimeException(
                'S08 backfill rider refuses: unmapped conversation channel(s) '.$unmappedConvo->implode(', ')
                .' — extend the map deliberately (SCHEMA AMENDMENT 2 rider).'
            );
        }

        $db->statement(<<<'SQL'
            UPDATE customers c
               SET created_source = sub.src
              FROM (
                    SELECT DISTINCT ON (customer_id) customer_id,
                           CASE channel WHEN 'telegram' THEN 'telegram' ELSE 'webchat' END AS src
                      FROM conversations
                     ORDER BY customer_id, created_at ASC
                   ) sub
             WHERE c.id = sub.customer_id
               AND c.created_source IS NULL
            SQL);

        $sourceless = $db->table('customers')
            ->whereNull('created_source')
            ->count();

        if ($sourceless > 0) {
            throw new RuntimeException(
                "S08 backfill refuses: {$sourceless} customer(s) have no channel identity to derive created_source from — investigate before inventing a source."
            );
        }
    }
}
