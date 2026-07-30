<?php

declare(strict_types=1);

namespace App\Support\Audit;

use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The foundation's audit sink (Doc 01 FR-06, FR-07; invariant N-13).
 *
 * SCOPE, SO NOBODY MISTAKES THIS FOR THE AUDIT SYSTEM. Doc 17 owns the audit
 * engine: hash-chaining per tenant-day, anchors, the coverage harness, the
 * immutability-physics test, export and erasure. This class writes rows. Doc 01
 * needs two audit entries to exist — `tenant.provisioned` (FR-06) and the old→new
 * settings trail (FR-07) — and it needs them written somewhere that Doc 17 can
 * later harden without rewriting history. Deferral is recorded in
 * docs/BUILD-STATUS.md.
 *
 * WHAT IS ALREADY REAL, because it cannot be retrofitted: the app role holds no
 * UPDATE or DELETE privilege on audit_log (see the RLS migration). So these rows
 * are immutable now, before there is a chain to prove it. Adding integrity proofs
 * on top of mutable rows would be pointless; adding them on top of immutable rows
 * is Doc 17's job and it will start from a trustworthy base.
 *
 * REGISTRY-ENFORCED. Every action key must appear in registries/audit-coverage.php
 * with its required payload fields, and this class refuses anything else. A
 * mutation whose audit entry was never registered is a build failure at the gate
 * and a runtime error here — CLAUDE.md §2's rule made mechanical rather than
 * reviewed.
 */
final class AuditLogger
{
    /** @var array<string, array<string, mixed>>|null */
    private static ?array $registry = null;

    public function __construct(private readonly TenantContext $context) {}

    /**
     * Append one audit entry.
     *
     * @param  array<string, mixed>  $payload
     */
    public function record(
        string $actionKey,
        string $entity,
        ?string $entityId,
        AuditActor $actorType,
        array $payload,
        ?string $actorId = null,
        ?string $actorLabel = null,
        ?string $tenantId = null,
    ): void {
        $entry = $this->registryEntry($actionKey);

        $this->assertRequiredFields($actionKey, $entry, $payload);
        $this->assertActorAllowed($actionKey, $entry, $actorType);

        DB::table('audit_log')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId ?? $this->context->tenantId(),
            'action_key' => $actionKey,
            'entity' => $entity,
            'entity_id' => $entityId,
            'actor_type' => $actorType->value,
            'actor_id' => $actorId,
            'actor_label' => $actorLabel,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'correlation_id' => $this->context->correlationId(),
            // occurred_at and created_at default to now() IN THE DATABASE (EC-08):
            // an app-server wall clock must never order audit history.
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function registryEntry(string $actionKey): array
    {
        self::$registry ??= $this->loadRegistry();

        if (! array_key_exists($actionKey, self::$registry)) {
            throw new InvalidArgumentException(sprintf(
                'Audit action "%s" is not registered in registries/audit-coverage.php (CLAUDE.md §2). '
                .'Register it — with its entity, actor types and required payload fields — before '
                .'emitting it; an unregistered mutation is a build failure at the audit-coverage gate.',
                $actionKey,
            ));
        }

        return self::$registry[$actionKey];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function loadRegistry(): array
    {
        /** @var list<array<string, mixed>> $entries */
        $entries = require base_path('registries/audit-coverage.php');

        $byKey = [];
        foreach ($entries as $entry) {
            /** @var string $key */
            $key = $entry['action_key'];
            $byKey[$key] = $entry;
        }

        return $byKey;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>  $payload
     */
    private function assertRequiredFields(string $actionKey, array $entry, array $payload): void
    {
        /** @var list<string> $required */
        $required = $entry['required_fields'] ?? [];

        $missing = array_values(array_diff($required, array_keys($payload)));

        if ($missing !== []) {
            throw new InvalidArgumentException(sprintf(
                'Audit entry "%s" is missing required payload field(s): %s. The registry declares them '
                .'because a reader — an auditor, an incident responder, Doc 17\'s export — needs them to '
                .'reconstruct what changed.',
                $actionKey,
                implode(', ', $missing),
            ));
        }
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function assertActorAllowed(string $actionKey, array $entry, AuditActor $actor): void
    {
        /** @var list<string> $allowed */
        $allowed = $entry['actor_types'] ?? [];

        if (! in_array($actor->value, $allowed, true)) {
            throw new InvalidArgumentException(sprintf(
                'Audit entry "%s" was recorded with actor type "%s", which the registry does not allow '
                .'(permitted: %s). Either the caller is wrong about who it is, or the registry is out of '
                .'date — both are worth stopping for.',
                $actionKey,
                $actor->value,
                implode(', ', $allowed),
            ));
        }
    }

    /**
     * @internal Test seam: the registry is cached per process.
     */
    public static function forgetRegistry(): void
    {
        self::$registry = null;
    }
}
