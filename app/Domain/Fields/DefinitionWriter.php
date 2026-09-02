<?php

declare(strict_types=1);

namespace App\Domain\Fields;

use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Events\EventPublisher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Doc 12 FR-17 / FR-18 / EC-08 / EC-14 — the ONE door for field definitions.
 *
 * The lifecycle physics live in the GRANTS (step 2b): key/type/entity are
 * immutable columns, keys never reuse (the unscoped unique), rows never delete.
 * This door adds the BEHAVIOR: 13-type validation vocabulary, choice types
 * demand options, deactivation captures the where-used answer in its audit
 * (EC-14's completeness travels with the act), reactivation restores, option
 * removal snapshots its label (EC-08 — historic renders keep the words).
 * `fields.manage` (HIGH) gates at the controller; field deactivation is the
 * SEC-06 high-risk class ([D17]).
 */
final class DefinitionWriter
{
    public const TYPES = ['text', 'number', 'email', 'phone', 'date', 'time', 'checkbox', 'radio', 'dropdown', 'multi_select', 'url', 'file', 'rich_text'];

    public const CHOICE_TYPES = ['radio', 'dropdown', 'multi_select'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly EventPublisher $events,
        private readonly AuditLogger $audit,
        private readonly WhereUsed $whereUsed,
    ) {}

    /**
     * @param  array{entity: string, key: string, label: array<string, string>, type: string, validation?: array<string, mixed>, agent_editable?: bool, customer_visible?: bool, options?: list<array{value: string, label: array<string, string>}>}  $definition
     */
    public function create(array $definition, string $actorId): string
    {
        if (! in_array($definition['type'], self::TYPES, true)) {
            throw new InvalidArgumentException(sprintf('unknown field type "%s" — FR-17 fixes thirteen.', $definition['type']));
        }
        $options = $definition['options'] ?? [];
        if (in_array($definition['type'], self::CHOICE_TYPES, true) && $options === []) {
            throw new InvalidArgumentException('a choice field needs options (FR-17).');
        }
        $fieldId = (string) Str::uuid7();
        $max = DB::table('field_definitions')->where('entity', $definition['entity'])->max('position');
        $position = (is_numeric($max) ? (int) $max : 0) + 1;

        DB::transaction(function () use ($fieldId, $definition, $options, $position, $actorId): void {
            DB::table('field_definitions')->insert([
                'id' => $fieldId, 'tenant_id' => $this->context->tenantId(),
                'entity' => $definition['entity'], 'key' => $definition['key'],
                'label' => json_encode($definition['label'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'type' => $definition['type'],
                'validation' => json_encode($definition['validation'] ?? [], JSON_THROW_ON_ERROR),
                'agent_editable' => $definition['agent_editable'] ?? true,
                'customer_visible' => $definition['customer_visible'] ?? false,
                'position' => $position,
            ]);
            foreach ($options as $i => $option) {
                DB::table('field_definition_options')->insert([
                    'id' => (string) Str::uuid7(), 'tenant_id' => $this->context->tenantId(),
                    'field_id' => $fieldId, 'value' => $option['value'],
                    'label' => json_encode($option['label'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'position' => $i,
                ]);
            }
            $this->announce($fieldId, 'created', $actorId);
        });

        return $fieldId;
    }

    /** @param array{label?: array<string, string>, validation?: array<string, mixed>, agent_editable?: bool, customer_visible?: bool, position?: int} $changes */
    public function update(string $fieldId, array $changes, string $actorId): void
    {
        $update = array_intersect_key($changes, array_flip(['agent_editable', 'customer_visible', 'position']));
        foreach (['label', 'validation'] as $jsonKey) {
            if (array_key_exists($jsonKey, $changes)) {
                $update[$jsonKey] = json_encode($changes[$jsonKey], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            }
        }
        if ($update === []) {
            return;
        }
        $update['updated_at'] = DB::raw('clock_timestamp()');
        DB::transaction(function () use ($fieldId, $update, $actorId): void {
            if (DB::table('field_definitions')->where('id', $fieldId)->update($update) === 0) {
                throw new InvalidArgumentException('unknown field');
            }
            $this->announce($fieldId, 'updated', $actorId);
        });
    }

    /**
     * FR-18/EC-14: deactivation PROCEEDS with the complete where-used answer in
     * its audit — rules keep evaluating (is-empty) flagged, the live form
     * version keeps rendering its snapshot, the form's NEXT publish blocks.
     *
     * @return array{forms: list<array{id: string, name: string}>, rules: list<array{id: string, name: string}>, views: list<array{id: string, name: ?string}>}
     */
    public function deactivate(string $fieldId, string $actorId): array
    {
        $field = $this->row($fieldId);
        $used = $this->whereUsed->forField($field->entity, $field->key);
        DB::transaction(function () use ($fieldId, $used, $actorId): void {
            DB::table('field_definitions')->where('id', $fieldId)->update([
                'active' => false, 'deactivated_at' => DB::raw('clock_timestamp()'),
                'updated_at' => DB::raw('clock_timestamp()'),
            ]);
            $this->announce($fieldId, 'deactivated', $actorId, ['where_used' => [
                'forms' => count($used['forms']), 'rules' => count($used['rules']), 'views' => count($used['views']),
            ]]);
        });

        return $used;
    }

    public function reactivate(string $fieldId, string $actorId): void
    {
        DB::transaction(function () use ($fieldId, $actorId): void {
            DB::table('field_definitions')->where('id', $fieldId)->update([
                'active' => true, 'deactivated_at' => null, 'updated_at' => DB::raw('clock_timestamp()'),
            ]);
            $this->announce($fieldId, 'reactivated', $actorId);
        });
    }

    /** EC-08: removal captures the label SNAPSHOT — stored values keep their words forever. */
    public function removeOption(string $optionId, string $actorId): void
    {
        /** @var object{field_id: string, label: string}|null $option */
        $option = DB::table('field_definition_options')->where('id', $optionId)->first(['field_id', 'label']);
        if ($option === null) {
            throw new InvalidArgumentException('unknown option');
        }
        DB::transaction(function () use ($optionId, $option, $actorId): void {
            DB::table('field_definition_options')->where('id', $optionId)->update([
                'active' => false, 'label_snapshot' => $option->label,
                'updated_at' => DB::raw('clock_timestamp()'),
            ]);
            $this->announce($option->field_id, 'option_changed', $actorId);
        });
    }

    /** @param array<string, mixed> $extra */
    private function announce(string $fieldId, string $kind, string $actorId, array $extra = []): void
    {
        $this->events->publish('field.changed', ['tenant_id' => $this->context->tenantId(), 'field_id' => $fieldId, 'kind' => $kind]);
        // Deactivation is the SEC-06 high-risk class ([D17]); the rest ride field.changed.
        $action = $kind === 'deactivated' ? 'field.deactivated' : 'field.changed';
        $this->audit->record($action, 'field_definition', $fieldId, AuditActor::Staff, ['field_id' => $fieldId, 'kind' => $kind] + $extra, actorId: $actorId);
    }

    /** @return object{id: string, entity: string, key: string, type: string} */
    private function row(string $fieldId): object
    {
        /** @var object{id: string, entity: string, key: string, type: string}|null $field */
        $field = DB::table('field_definitions')->where('id', $fieldId)->first(['id', 'entity', 'key', 'type']);
        if ($field === null) {
            throw new InvalidArgumentException('unknown field');
        }

        return $field;
    }
}
