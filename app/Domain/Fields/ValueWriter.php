<?php

declare(strict_types=1);

namespace App\Domain\Fields;

use App\Domain\Customers\Fields\CustomerFieldValues;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Doc 12 FR-19 (A-59, approved) — the ONE value door, per entity:
 *
 *   customer → the EXISTING Doc 08 `customer_field_values` door
 *              (CustomerFieldValues::set — its audit-history reading stands);
 *              until packet P-FIELDS swaps that door's fixture definitions to
 *              the real table, only its seeded keys accept values (stated).
 *   ticket   → Doc 06 FR-01's own jsonb: `tickets.form_data.custom.<key>`
 *              (app-writable by the Doc 06 grants; EventContext already reads
 *              these facts for FR-02 conditions).
 *
 * VALIDATION IS THE DEFINITION'S (per-type + the validation jsonb), applied
 * HERE for both entities — one vocabulary, one gate. A retired field refuses
 * writes (FR-18: read-only with the retired tag).
 */
final class ValueWriter
{
    // Deliberately not evented per write: field.changed covers DEFINITIONS; ticket
    // value writes ride the audit (keys only) — the amendment lists no value event.
    public function __construct(
        private readonly CustomerFieldValues $customerValues,
        private readonly AuditLogger $audit,
    ) {}

    public function setTicketField(string $ticketId, string $key, mixed $value, ?string $actorId, ?string $ruleRef = null): void
    {
        $definition = $this->liveDefinition('ticket', $key);
        $canonical = $this->validate($definition, $value);

        DB::transaction(function () use ($ticketId, $key, $canonical, $actorId, $ruleRef): void {
            // jsonb_set never creates INTERMEDIATE paths — the custom object is
            // merged explicitly (the first write's own lesson).
            $updated = DB::update(
                "UPDATE tickets
                    SET form_data = COALESCE(form_data, '{}'::jsonb)
                        || jsonb_build_object('custom',
                            COALESCE(form_data->'custom', '{}'::jsonb) || jsonb_build_object(?::text, to_jsonb(?::text))),
                        updated_at = clock_timestamp()
                  WHERE id = ?",
                [$key, $canonical, $ticketId],
            );
            if ($updated === 0) {
                throw new InvalidArgumentException('unknown ticket');
            }
            $this->audit->record('field.value.changed', 'ticket', $ticketId,
                $actorId === null ? AuditActor::System : AuditActor::Staff,
                ['entity' => 'ticket', 'entity_id' => $ticketId, 'keys' => [$key]] + ($ruleRef === null ? [] : ['rule_ref' => $ruleRef]),
                actorId: $actorId);
        });
    }

    public function setCustomerField(string $customerId, string $key, mixed $value, string $actorId): void
    {
        $definition = $this->liveDefinition('customer', $key);
        $canonical = $this->validate($definition, $value);
        // The Doc 08 door: its own event + audit-history reading stand (A-59).
        $this->customerValues->set($customerId, [$key => $canonical], $actorId);
    }

    /** @return object{key: string, type: string, validation: string, active: bool, id: string} */
    private function liveDefinition(string $entity, string $key): object
    {
        /** @var object{key: string, type: string, validation: string, active: bool, id: string}|null $definition */
        $definition = DB::table('field_definitions')->where('entity', $entity)->where('key', $key)
            ->first(['id', 'key', 'type', 'validation', 'active']);
        if ($definition === null) {
            throw new InvalidArgumentException(sprintf('unknown %s field "%s" (FR-17)', $entity, $key));
        }
        if (! $definition->active) {
            throw new InvalidArgumentException(sprintf('field "%s" is retired — read-only (FR-18)', $key));
        }

        return $definition;
    }

    /**
     * A-59's canonical-string serialization, validated per the definition's
     * type + its validation jsonb. Returns the canonical string.
     *
     * @param  object{key: string, type: string, validation: string, active: bool, id: string}  $definition
     */
    private function validate(object $definition, mixed $value): string
    {
        /** @var array{min?: int|float, max?: int|float, regex?: string, options?: bool} $rules */
        $rules = json_decode($definition->validation, true, 4) ?: [];
        $refuse = static function (string $why) use ($definition): never {
            throw new InvalidArgumentException(sprintf('field "%s" (%s): %s', $definition->key, $definition->type, $why));
        };

        $canonical = match ($definition->type) {
            'text', 'rich_text' => is_string($value) ? $value : $refuse('expects text'),
            'number' => is_numeric($value) ? (string) +$value : $refuse('expects a number'),
            'email' => (is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) !== false) ? $value : $refuse('expects an email address'),
            'phone' => (is_string($value) && preg_match('/^\+?[0-9\s\-]{7,20}$/', $value) === 1) ? $value : $refuse('expects a phone number'),
            'url' => (is_string($value) && filter_var($value, FILTER_VALIDATE_URL) !== false) ? $value : $refuse('expects a URL'),
            'date' => (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 && strtotime($value) !== false) ? $value : $refuse('expects YYYY-MM-DD'),
            'time' => (is_string($value) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1) ? $value : $refuse('expects HH:MM'),
            'checkbox' => is_bool($value) ? ($value ? 'true' : 'false') : $refuse('expects true/false'),
            'radio', 'dropdown' => $this->optionValue($definition, $value) ?? $refuse('is not one of the field\'s options (EC-08: stored values are stable option values)'),
            'multi_select' => $this->multiValue($definition, $value) ?? $refuse('expects a list of the field\'s option values'),
            'file' => (is_string($value) && $value !== '') ? $value : $refuse('expects a media reference (Doc 04 pipeline — SEC-04)'),
            default => $refuse('unknown type'),
        };

        if (is_numeric($canonical) && $definition->type === 'number') {
            if (isset($rules['min']) && +$canonical < $rules['min']) {
                $refuse('below min '.$rules['min']);
            }
            if (isset($rules['max']) && +$canonical > $rules['max']) {
                $refuse('above max '.$rules['max']);
            }
        }
        if (isset($rules['regex']) && in_array($definition->type, ['text', 'phone'], true)) {
            if (preg_match('~'.str_replace('~', '\~', $rules['regex']).'~u', $canonical) !== 1) {
                $refuse('does not match the required pattern');
            }
        }

        return $canonical;
    }

    /** @param object{id: string} $definition */
    private function optionValue(object $definition, mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $exists = DB::table('field_definition_options')
            ->where('field_id', $definition->id)->where('value', $value)->where('active', true)->exists();

        return $exists ? $value : null;
    }

    /** @param object{id: string} $definition */
    private function multiValue(object $definition, mixed $value): ?string
    {
        if (! is_array($value)) {
            return null;
        }
        foreach ($value as $item) {
            if ($this->optionValue($definition, $item) === null) {
                return null;
            }
        }

        return json_encode(array_values($value), JSON_THROW_ON_ERROR);
    }
}
