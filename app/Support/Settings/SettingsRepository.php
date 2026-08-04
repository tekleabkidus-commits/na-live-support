<?php

declare(strict_types=1);

namespace App\Support\Settings;

use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Events\EventPublisher;
use App\Support\Settings\Exceptions\SettingNotEditable;
use App\Support\Settings\Exceptions\SettingWriteConflict;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The settings engine (Doc 01 FR-07, T-04, NFR-03, EC-06, FS-01).
 *
 * Read path: Redis hash per tenant, `settings:{tenant}` (T-04), falling back to the
 * database when Redis is unavailable. Write path: validate, audit old → new, persist,
 * invalidate, publish.
 *
 * ────────────────────────────────────────────────────────────────────────────────
 * WHY A HASH PER TENANT RATHER THAN A KEY PER SETTING
 *
 * T-04 specifies `settings:{tenant}` as a hash, and the reason is the read pattern. A
 * request typically reads several settings; with a key per setting that is N round trips,
 * and NFR-03's ≤0.5 ms p99 budget is per read. One hash means one `HGETALL` warms every
 * setting for the request, and invalidation is one `DEL` rather than N.
 *
 * The cost is that invalidation is coarse — changing one setting drops the tenant's whole
 * hash — and that is the right trade: settings change rarely and are read constantly.
 * ────────────────────────────────────────────────────────────────────────────────
 *
 * FS-01: "Redis unavailable: settings fall back to DB reads (degraded latency, logged);
 * never failed." Implemented by catching connection failures around the cache and
 * continuing to the database. A settings read must never be the reason a request fails —
 * the platform would become unusable the moment the cache blinked, and the DB is the
 * source of truth anyway (C-07: cache is never the source of truth).
 */
final class SettingsRepository
{
    public function __construct(
        private readonly SettingsRegistry $registry,
        private readonly SettingsCache $cache,
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
        private readonly EventPublisher $events,
    ) {}

    /**
     * Read one setting for the bound tenant.
     *
     * Resolution order: tenant override → registered default. There is no third source,
     * and no null: an unregistered key throws (FR-07) rather than returning null, so a
     * caller never has to distinguish "not set" from "set to false".
     */
    public function get(string $key): mixed
    {
        $definition = $this->registry->definition($key);
        $tenantId = $this->context->tenantId();

        $overrides = $this->cache->overrides($tenantId, fn (): array => $this->readOverridesFromDatabase($tenantId));

        if (! array_key_exists($key, $overrides)) {
            return $definition['default'];
        }

        return $definition['type']->decode($overrides[$key]);
    }

    /**
     * Read every setting for the bound tenant, defaults merged with overrides.
     *
     * FR-07's `settings.read` API for modules. Secret values are NOT decoded here — a
     * bulk read is exactly the shape that ends up in a log line or an API response, and
     * C-13 says a secret must not serialise into either. Callers that genuinely need an
     * encrypted-string ask for it by key.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $tenantId = $this->context->tenantId();
        $overrides = $this->cache->overrides($tenantId, fn (): array => $this->readOverridesFromDatabase($tenantId));

        $out = [];
        foreach ($this->registry->keys() as $key) {
            $definition = $this->registry->definition($key);

            if ($definition['type']->isSecret()) {
                // Present, so a caller can see the key exists and whether it is set,
                // without the value ever leaving the engine.
                $out[$key] = array_key_exists($key, $overrides) ? '[encrypted]' : null;

                continue;
            }

            $out[$key] = array_key_exists($key, $overrides)
                ? $definition['type']->decode($overrides[$key])
                : $definition['default'];
        }

        return $out;
    }

    /** @var array<string, callable(mixed): void> */
    private static array $writeGuards = [];

    /**
     * Register a dynamic precondition for writes to one setting key. The
     * guard receives the validated value and THROWS to refuse the write.
     * REPLACE semantics per key: providers re-register on every boot (the
     * test harness boots one app per test), and accumulating closures would
     * pin stale container references.
     */
    public static function registerWriteGuard(string $key, callable $guard): void
    {
        self::$writeGuards[$key] = $guard;
    }

    public static function flushWriteGuards(): void
    {
        self::$writeGuards = [];
    }

    /**
     * Write a per-tenant override.
     *
     * @param  int|null  $expectedVersion  the row version the caller last read, for EC-06's
     *                                     optimistic check. Null skips the check —
     *                                     legitimate for system writes (provisioning
     *                                     defaults) where no human holds a stale form.
     *                                     A version, not a timestamp: see the
     *                                     add_version_to_tenant_settings migration on why
     *                                     EC-08 rules out the wall-clock variant.
     *
     * @throws SettingWriteConflict when another write landed first (EC-06)
     */
    public function set(
        string $key,
        mixed $value,
        AuditActor $actor = AuditActor::Staff,
        ?string $actorId = null,
        ?int $expectedVersion = null,
        bool $tenantSurface = true,
    ): void {
        $definition = $this->registry->definition($key);

        if ($tenantSurface && ! $definition['tenant_editable']) {
            throw SettingNotEditable::forKey($key, $definition['owning_doc']);
        }

        $this->registry->validate($key, $value);

        // Per-key WRITE GUARDS: dynamic preconditions a static validation
        // rule cannot express (first consumer: Doc 03 EC-08 — rbac.four_eyes
        // refuses to enable without ≥2 approval-rights holders). A guard
        // throws to refuse; registration is a boot-time act by the owning
        // module's provider.
        if (isset(self::$writeGuards[$key])) {
            (self::$writeGuards[$key])($value);
        }

        $tenantId = $this->context->tenantId();
        $encoded = $definition['type']->encode($value);

        try {
            $this->write($key, $value, $encoded, $definition, $tenantId, $actor, $actorId, $expectedVersion);
        } catch (SettingWriteConflict $conflict) {
            // EC-06: "both writes audited". The loser's attempt gets its own entry in a
            // FRESH transaction, because the one it was attempted in has been rolled back.
            // Without this the trail would show one change where a human made two, and the
            // only record that the other edit ever existed would be a toast the user has
            // already dismissed.
            DB::transaction(function () use ($key, $definition, $conflict, $actor, $actorId): void {
                $this->audit->record(
                    actionKey: 'tenant.setting_write_conflict',
                    entity: 'tenant_setting',
                    entityId: null,
                    actorType: $actor,
                    payload: [
                        'key' => $key,
                        'attempted_value' => $definition['type']->isSecret() ? '[redacted]' : $conflict->attemptedValue,
                        'winning_value' => $definition['type']->isSecret() ? '[redacted]' : $conflict->winningValue,
                    ],
                    actorId: $actorId,
                );
            });

            throw $conflict;
        }

        // Invalidate AFTER commit. Before, and a concurrent reader could repopulate the
        // cache from the pre-commit database state and then nothing would evict it.
        $this->cache->forget($tenantId);
    }

    /**
     * The write itself, in one transaction.
     *
     * @param  array{key: string, type: SettingType, default: mixed, validation: string, tenant_editable: bool, owning_doc: string}  $definition
     *
     * @throws SettingWriteConflict
     */
    private function write(
        string $key,
        mixed $value,
        mixed $encoded,
        array $definition,
        string $tenantId,
        AuditActor $actor,
        ?string $actorId,
        ?int $expectedVersion,
    ): void {
        DB::transaction(function () use ($key, $value, $encoded, $definition, $tenantId, $actor, $actorId, $expectedVersion): void {
            // Row lock for the duration of the transaction, so the read-compare-write
            // below is atomic. Without it, EC-06's "optimistic check" would itself be a
            // race: two writers could both read the same updated_at, both find it
            // matching, and both write.
            $existing = DB::table('tenant_settings')
                ->where('tenant_id', $tenantId)
                ->where('key', $key)
                ->lockForUpdate()
                ->first();

            $oldRaw = $existing === null ? null : json_decode($this->stringField($existing, 'value'), true);
            $oldValue = $existing === null
                ? $definition['default']
                : $definition['type']->decode($oldRaw);

            // EC-06's optimistic check, against the database-side version.
            //
            // NOTE THE AUDIT IS NOT WRITTEN HERE. Throwing from inside DB::transaction()
            // rolls the transaction back — including any audit row written first. The
            // original version of this method did audit here, and the loser's entry
            // vanished with the rollback: EC-06 requires BOTH writes audited, and the test
            // counted zero. An audit written inside a transaction you are about to abort
            // is not an audit.
            //
            // So the conflict details travel out with the exception and are recorded by the
            // caller-side handler below, in its own committed transaction.
            if ($expectedVersion !== null && $existing !== null && $this->intField($existing, 'version') !== $expectedVersion) {
                throw SettingWriteConflict::forKey($key, $value, $oldValue);
            }

            DB::table('tenant_settings')->upsert(
                [[
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $tenantId,
                    'key' => $key,
                    'value' => json_encode($encoded, JSON_THROW_ON_ERROR),
                    'updated_by' => $actorId,
                    'version' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]],
                ['tenant_id', 'key'],
                // `version` is incremented BY THE DATABASE rather than supplied, so the
                // counter cannot be reset or skipped by a caller and needs no clock.
                ['value', 'updated_by', 'updated_at', 'version' => DB::raw('tenant_settings.version + 1')],
            );

            // FR-07: "audit entry on every change (old → new)". Both halves are required
            // fields in registries/audit-coverage.php precisely so that a change cannot
            // be audited as merely having happened.
            $this->audit->record(
                actionKey: 'tenant.setting_changed',
                entity: 'tenant_setting',
                entityId: null,
                actorType: $actor,
                payload: [
                    'key' => $key,
                    // C-13/SEC-07: an encrypted-string's value never reaches the audit
                    // payload. That the key changed is auditable; what it changed to is
                    // readable only through the engine.
                    'old_value' => $definition['type']->isSecret() ? '[redacted]' : $oldValue,
                    'new_value' => $definition['type']->isSecret() ? '[redacted]' : $value,
                ],
                actorId: $actorId,
            );

            $this->events->publish(
                name: 'tenant.setting_changed',
                payload: ['tenant_id' => $tenantId, 'key' => $key],
            );
        });
    }

    /**
     * Remove an override, restoring the registered default.
     *
     * Doc 01 §8's "reset to default" is a DELETE of the override row rather than a write
     * of the default value — which is why `tenant_settings` is the one business table
     * where the app role keeps DELETE (see the RLS migration). Writing the default in
     * would make a tenant that has explicitly chosen today's default indistinguishable
     * from one that never chose, and the two diverge the moment the default changes.
     */
    public function reset(string $key, AuditActor $actor = AuditActor::Staff, ?string $actorId = null): void
    {
        $definition = $this->registry->definition($key);
        $tenantId = $this->context->tenantId();

        DB::transaction(function () use ($key, $definition, $tenantId, $actor, $actorId): void {
            $existing = DB::table('tenant_settings')
                ->where('tenant_id', $tenantId)
                ->where('key', $key)
                ->lockForUpdate()
                ->first();

            if ($existing === null) {
                return; // already at default; nothing changed, so nothing to audit
            }

            $oldValue = $definition['type']->decode(json_decode($this->stringField($existing, 'value'), true));

            DB::table('tenant_settings')
                ->where('tenant_id', $tenantId)
                ->where('key', $key)
                ->delete();

            $this->audit->record(
                actionKey: 'tenant.setting_changed',
                entity: 'tenant_setting',
                entityId: null,
                actorType: $actor,
                payload: [
                    'key' => $key,
                    'old_value' => $definition['type']->isSecret() ? '[redacted]' : $oldValue,
                    'new_value' => $definition['type']->isSecret() ? '[redacted]' : $definition['default'],
                ],
                actorId: $actorId,
            );

            $this->events->publish(
                name: 'tenant.setting_changed',
                payload: ['tenant_id' => $tenantId, 'key' => $key],
            );
        });

        $this->cache->forget($tenantId);
    }

    /**
     * The row version a client must echo back for EC-06's optimistic check.
     *
     * Null means there is no override row yet, so there is nothing to conflict with — the
     * first writer wins by definition.
     */
    public function version(string $key): ?int
    {
        $this->registry->definition($key); // rejects unregistered keys here too

        $row = DB::table('tenant_settings')
            ->where('tenant_id', $this->context->tenantId())
            ->where('key', $key)
            ->first();

        return $row === null ? null : $this->intField($row, 'version');
    }

    /**
     * Read one field of a query-builder row as an int.
     */
    private function intField(object $row, string $field): int
    {
        $value = $row->{$field} ?? null;

        if (is_int($value)) {
            return $value;
        }

        // Postgres bigint arrives as a string through PDO on 64-bit builds.
        if (is_string($value) && (string) (int) $value === $value) {
            return (int) $value;
        }

        throw new \LogicException(sprintf(
            'tenant_settings.%s came back as %s, not an integer.',
            $field,
            get_debug_type($value),
        ));
    }

    /**
     * Read one field of a query-builder row as a string.
     *
     * The builder returns stdClass with `mixed` properties, so every read is a potential
     * mixed-to-string cast. Casting would turn a null column into the empty string and a
     * missing column into a silent ''; this fails loudly instead, naming the column.
     */
    private function stringField(object $row, string $field): string
    {
        $value = $row->{$field} ?? null;

        if (! is_string($value)) {
            throw new \LogicException(sprintf(
                'tenant_settings.%s came back as %s, not a string. All three columns read here are NOT '
                .'NULL, so this means the query selected something unexpected.',
                $field,
                get_debug_type($value),
            ));
        }

        return $value;
    }

    /**
     * @return array<string, mixed> key => raw stored (still encoded) value
     */
    private function readOverridesFromDatabase(string $tenantId): array
    {
        $rows = DB::table('tenant_settings')
            ->where('tenant_id', $tenantId)
            ->get(['key', 'value']);

        $out = [];
        foreach ($rows as $row) {
            $out[$this->stringField($row, 'key')] = json_decode($this->stringField($row, 'value'), true);
        }

        return $out;
    }
}
