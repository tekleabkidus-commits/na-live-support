<?php

declare(strict_types=1);

namespace App\Domain\Customers\Fields;

use App\Domain\Customers\Search\CustomerScope;
use App\Models\User;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Events\EventPublisher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Symfony\Component\Uid\UuidV7;

/**
 * FR-16's value writer and reader — the ONE door for entity=customer
 * custom-field values (SCHEMA AMENDMENT 2 addition 9, countersigned).
 * Definitions are Doc 12's; until it ships they come from the [D12] seam
 * fixture (`fixtures/customers/field-definitions.json`).
 *
 * EC-09: a RETIRED definition's value renders read-only ("the definition
 * went, the fact stayed" — B4-S02's own caption) and a write to it REFUSES.
 * History = audit_log old/new pairs (condition 1: no history affordance is
 * drawn for any agent tier — the check is filed in the amendment). The
 * event carries field NAMES only (Q7).
 *
 * SEC-05: the duplicate-player_id hint is COUNTS + SCOPE-CHECKED LINKS —
 * the count is honest platform-wide arithmetic, the link-outs are filtered
 * through CustomerScope (the one brain), and NAMES never leave the server
 * (sabotage #11's subject).
 */
final class CustomerFieldValues
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly EventPublisher $events,
        private readonly AuditLogger $audit,
        private readonly CustomerScope $scope,
    ) {}

    /** @return list<array{key: string, label_i18n: string, type: string, retired: bool}> */
    public static function definitions(): array
    {
        /** @var array{fields: list<array{key: string, label_i18n: string, type: string, retired: bool}>} $data */
        $data = json_decode(
            (string) file_get_contents(base_path('fixtures/customers/field-definitions.json')),
            true,
            8,
            JSON_THROW_ON_ERROR,
        );

        return $data['fields'];
    }

    /**
     * @param  array<string, ?string>  $values  key => value (null clears; the row stays)
     * @return list<string>|null changed keys; null = the A-37 noop
     */
    public function set(string $customerId, array $values, string $actorId): ?array
    {
        $definitions = collect(self::definitions())->keyBy('key');
        foreach (array_keys($values) as $key) {
            $definition = $definitions->get($key);
            if ($definition === null) {
                throw new InvalidArgumentException("unknown customer field: {$key}");
            }
            if ($definition['retired']) {
                // EC-09: retired = read-only. The definition went; the fact
                // stays exactly as it was.
                throw new FieldRetired($key);
            }
        }

        return DB::transaction(function () use ($customerId, $values, $actorId): ?array {
            /** @var array<string, ?string> $current */
            $current = DB::table('customer_field_values')->where('customer_id', $customerId)
                ->whereIn('field_key', array_keys($values))
                ->pluck('value', 'field_key')->all();

            $old = [];
            $new = [];
            foreach ($values as $key => $value) {
                $value = is_string($value) ? trim($value) : null;
                $value = $value === '' ? null : $value;
                $had = array_key_exists($key, $current);
                if ($had && $current[$key] === $value) {
                    continue; // unchanged
                }
                if (! $had && $value === null) {
                    continue; // clearing a field that never had a row writes nothing
                }
                $old[$key] = $current[$key] ?? null;
                $new[$key] = $value;

                DB::statement(
                    <<<'SQL'
                    INSERT INTO customer_field_values (id, tenant_id, customer_id, field_key, value, updated_by)
                    VALUES (?, ?, ?, ?, ?, ?)
                    ON CONFLICT (tenant_id, customer_id, field_key)
                        DO UPDATE SET value = EXCLUDED.value, updated_by = EXCLUDED.updated_by, updated_at = clock_timestamp()
                    SQL,
                    [(string) new UuidV7, $this->context->tenantId(), $customerId, $key, $value, $actorId],
                );
            }

            if ($new === []) {
                return null; // the A-37 noop: no change, no event, no audit
            }

            $changed = array_keys($new);
            $this->events->publish('customer.fields_changed', [
                'tenant_id' => $this->context->tenantId(),
                'customer_id' => $customerId,
                'field_keys' => $changed,
            ]);
            $this->audit->record('customer.fields_changed', 'customer', $customerId, AuditActor::Staff, ['old' => $old, 'new' => $new], actorId: $actorId);

            return $changed;
        });
    }

    /**
     * The curated card: every definition, with its stored value when one
     * exists — retired definitions render ONLY when a fact survives (EC-09).
     *
     * @return list<array{key: string, label_i18n: string, retired: bool, value: ?string, updated_by: ?string, updated_at: ?string}>
     */
    public function valuesFor(string $customerId): array
    {
        /** @var array<string, object{value: ?string, updated_by: ?string, updated_at: string}> $rows */
        $rows = DB::table('customer_field_values')->where('customer_id', $customerId)
            ->get(['field_key', 'value', 'updated_by', DB::raw('updated_at::text AS updated_at')])
            ->keyBy('field_key')->all();

        $card = [];
        foreach (self::definitions() as $definition) {
            $row = $rows[$definition['key']] ?? null;
            if ($definition['retired'] && $row === null) {
                continue; // a retired definition with no surviving fact renders nothing
            }
            $card[] = [
                'key' => $definition['key'],
                'label_i18n' => $definition['label_i18n'],
                'retired' => $definition['retired'],
                'value' => $row->value ?? null,
                'updated_by' => $row->updated_by ?? null,
                'updated_at' => $row->updated_at ?? null,
            ];
        }

        return $card;
    }

    /**
     * SEC-05: the duplicate-player_id hint — {count, links}. The count says
     * how many OTHER profiles carry this player_id (honest arithmetic); the
     * links are ONLY the ids inside the viewer's customers-module scope.
     * Names appear NOWHERE in this payload, by construction.
     *
     * @return array{count: int, links: list<string>}|null null = no player_id on this customer
     */
    public function duplicateHint(string $customerId, User $viewer): ?array
    {
        /** @var ?string $value */
        $value = DB::table('customer_field_values')->where('customer_id', $customerId)
            ->where('field_key', 'player_id')->value('value');
        if (! is_string($value) || $value === '') {
            return null;
        }

        $others = DB::table('customer_field_values')
            ->where('field_key', 'player_id')->where('value', $value)
            ->where('customer_id', '!=', $customerId);

        $count = (clone $others)->count();
        if ($count === 0) {
            return ['count' => 0, 'links' => []];
        }

        /** @var list<string> $links */
        $links = $this->scope->constrainQuery(
            DB::table('customers')->whereIn('customers.id', (clone $others)->select('customer_id')),
            $this->scope->signature($viewer),
        )->orderBy('customers.id')->pluck('customers.id')->all();

        return ['count' => $count, 'links' => $links];
    }
}
