<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Models\TenantKey;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Str;

/**
 * Issues tenant credentials (Doc 01 SEC-03).
 *
 * SEC-03: "Tenant keys (bot keys, widget keys, API keys, gateway keys) are ≥ 128-bit
 * random, stored hashed (SHA-256) with a lookup prefix; plaintext shown once at
 * creation."
 *
 * Each clause, and why:
 *
 *   ≥128-bit random   `random_bytes(32)` — 256 bits, from the CSPRNG. Not `Str::random`,
 *                     which is fine but this is a credential and the source should be
 *                     unambiguous at the call site.
 *   SHA-256, not bcrypt/argon2  Deliberate, and the opposite of the right answer for
 *                     PASSWORDS. A password is low-entropy and human-chosen, so it needs
 *                     a slow hash to survive offline cracking. A 256-bit random key
 *                     cannot be brute-forced regardless of hash speed, and it is verified
 *                     on every inbound webhook — a deliberately slow hash would put
 *                     ~100 ms in front of every Telegram update and blow NFR-02's 1 ms
 *                     budget by two orders of magnitude. Fast hash, high entropy.
 *   lookup prefix     So verification examines ONE row rather than scanning. The prefix
 *                     is not secret and appears in logs and the UI; the secret does not.
 *   shown once        Returned from this method and never stored. There is no "reveal
 *                     key" endpoint to build later, because there is nothing to reveal.
 */
final class TenantKeyIssuer
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return array{key: TenantKey, plaintext: string} plaintext is shown once and never stored
     */
    public function issue(
        string $kind,
        string $label,
        AuditActor $actor = AuditActor::Staff,
        ?string $actorId = null,
    ): array {
        $prefix = Str::lower(Str::random(12));
        $secret = bin2hex(random_bytes(32));

        $key = new TenantKey;
        $key->fill([
            'kind' => $kind,
            'prefix' => $prefix,
            'hash' => hash('sha256', $secret),
            'label' => $label,
        ]);
        $key->save();

        $keyId = $key->getKey();

        if (! is_string($keyId)) {
            throw new \LogicException('TenantKey was saved without a string id; HasUuids should have set one.');
        }

        $this->audit->record(
            actionKey: 'tenant.key_created',
            entity: 'tenant_key',
            entityId: $keyId,
            actorType: $actor,
            payload: ['kind' => $kind, 'prefix' => $prefix],
            actorId: $actorId,
        );

        return [
            'key' => $key,
            // The wire format the resolver splits on: non-secret handle, then secret.
            'plaintext' => sprintf('%s.%s', $prefix, $secret),
        ];
    }
}
