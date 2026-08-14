<?php

declare(strict_types=1);

namespace App\Domain\Customers\Identity;

use Illuminate\Support\Facades\DB;

/**
 * THE NOTE-1 GATE (SCHEMA AMENDMENT 2, countersigned at `a23befe`): the
 * future contract-window migration that drops `customers.phone` /
 * `customers.username` MUST call verify() and REFUSE while it returns
 * violations. The amendment — not this docblock — is the constraint of
 * record; this class is its executable half.
 *
 * Two proofs, both value-preserving:
 *  - every non-null customers.phone is either that customer's own phone
 *    identity or recorded in customer_identity_conflicts (the permanent home,
 *    undeletable by grant — ruling 2);
 *  - every non-null customers.username is mirrored in that customer's
 *    telegram identity metadata.
 */
final class DeprecatedColumnDropGate
{
    /** @return list<string> human-readable violations; empty = safe to drop */
    public function verify(string $connection): array
    {
        $db = DB::connection($connection);
        $violations = [];

        /** @var list<object{id: string, phone: string}> $unpreservedPhones */
        $unpreservedPhones = $db->select(<<<'SQL'
            SELECT c.id, c.phone
              FROM customers c
             WHERE c.phone IS NOT NULL
               AND NOT EXISTS (
                    SELECT 1 FROM customer_identities ci
                     WHERE ci.customer_id = c.id
                       AND ci.type = 'phone'
                       AND ci.value = c.phone
                   )
               AND NOT EXISTS (
                    SELECT 1 FROM customer_identity_conflicts x
                     WHERE x.type = 'phone'
                       AND x.value = c.phone
                       AND x.loser_customer_id = c.id
                   )
            SQL);

        foreach ($unpreservedPhones as $row) {
            $violations[] = "customers.phone for {$row->id} has no identity and no conflict row — dropping would DESTROY the value";
        }

        /** @var list<object{id: string, username: string}> $unmirroredUsernames */
        $unmirroredUsernames = $db->select(<<<'SQL'
            SELECT c.id, c.username
              FROM customers c
             WHERE c.username IS NOT NULL
               AND NOT EXISTS (
                    SELECT 1 FROM customer_identities ci
                     WHERE ci.customer_id = c.id
                       AND ci.type = 'telegram'
                       AND ci.metadata ?? 'username'
                   )
            SQL);

        foreach ($unmirroredUsernames as $row) {
            $violations[] = "customers.username for {$row->id} is not mirrored in telegram identity metadata — dropping would DESTROY the value";
        }

        return $violations;
    }
}
