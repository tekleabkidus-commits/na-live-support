<?php

declare(strict_types=1);

namespace App\Domain\Forms;

use App\Domain\Conversations\Adapters\AdapterRegistry;
use Illuminate\Support\Facades\DB;

/**
 * Doc 12 FR-20 / FR-22 / EC-07 — the publish validation blocks, computed as
 * DATA (the publish dialog renders them; the publisher refuses on any).
 *
 * The versioned contract's shape: {languages[], steps[{key, title{lang}, fields[]}],
 * completion{route_tag?, tags?, priority?}}. A field is either a REF to a custom
 * field definition ({ref, required?, visible_when?, required_when_visible?}) or
 * INLINE form-only ({key, type, label{lang}, help?{lang}, required?, options?,
 * visible_when?, required_when_visible?, validation?}). Conditional logic
 * (`visible_when` = {field, op, value?}) may reference fields in the SAME or an
 * EARLIER step only — no cross-step forward references (R-03's scope wall).
 *
 * Blocks (each {code, ...context}): forward_reference, unknown_field,
 * retired_field, required_hidden (EC-07 — carries the auto-suggest fix),
 * incomplete_language, upload_unsupported, plus shape guards. `code` doubles as
 * the workflow.forms.block_* i18n key suffix (en+am).
 */
final class SchemaValidator
{
    private const INLINE_TYPES = ['text', 'number', 'email', 'phone', 'date', 'time', 'checkbox', 'radio', 'dropdown', 'multi_select', 'url', 'file', 'rich_text'];

    private const CONDITION_OPS = ['equals', 'not_equals', 'in', 'contains', 'gt', 'lt', 'between', 'is_empty', 'not_empty'];

    public function __construct(private readonly AdapterRegistry $adapters) {}

    /**
     * @param  array<string, mixed>  $schema
     * @return list<array<string, mixed>> the FR-22 blocks; empty = publishable
     */
    public function blocks(array $schema): array
    {
        $blocks = [];
        $languages = $schema['languages'] ?? null;
        if (! is_array($languages) || $languages === [] || array_filter($languages, 'is_string') !== $languages) {
            return [['code' => 'invalid_shape', 'detail' => 'languages must be a non-empty string list']];
        }
        /** @var list<string> $languages */
        $languages = array_values($languages);
        $steps = $schema['steps'] ?? null;
        if (! is_array($steps) || $steps === []) {
            return [['code' => 'invalid_shape', 'detail' => 'steps must be a non-empty list']];
        }

        $definitions = $this->customDefinitions();
        $seen = [];          // field key → step index (the R-03 wall's ledger)
        $requiredFile = false;

        foreach (array_values($steps) as $stepIndex => $step) {
            if (! is_array($step) || ! is_string($step['key'] ?? null) || ! is_array($step['fields'] ?? null) || $step['fields'] === []) {
                $blocks[] = ['code' => 'invalid_shape', 'detail' => "step {$stepIndex} needs key + non-empty fields"];

                continue;
            }
            $blocks = [...$blocks, ...$this->labelBlocks($step['title'] ?? null, $languages, 'step '.$step['key'])];
            foreach ($step['fields'] as $field) {
                if (! is_array($field)) {
                    $blocks[] = ['code' => 'invalid_shape', 'detail' => 'field entries must be objects'];

                    continue;
                }
                [$key, $type, $fieldBlocks] = $this->fieldIdentity($field, $definitions, $languages);
                $blocks = [...$blocks, ...$fieldBlocks];
                if ($key === null) {
                    continue;
                }
                if (isset($seen[$key])) {
                    $blocks[] = ['code' => 'invalid_shape', 'detail' => "duplicate field key {$key}"];
                }
                $seen[$key] = $stepIndex;
                if ($type === 'file' && ($field['required'] ?? false) === true) {
                    $requiredFile = true;
                }
                // EC-07: a required field behind a condition must be required-when-visible —
                // the block CARRIES the fix (the builder renders it as the auto-suggest).
                if (($field['required'] ?? false) === true && isset($field['visible_when']) && ($field['required_when_visible'] ?? false) !== true) {
                    $blocks[] = ['code' => 'required_hidden', 'field' => $key, 'suggest' => ['set' => ['required_when_visible' => true]]];
                }
            }
        }

        // Second pass: conditions, now that every field key is known (a SAME-step
        // later field is lawful; only cross-step FORWARD references are walled).
        foreach (array_values($steps) as $stepIndex => $step) {
            if (! is_array($step) || ! is_array($step['fields'] ?? null)) {
                continue;
            }
            $conditions = is_array($step['visible_when'] ?? null) ? [['when' => $step['visible_when'], 'at' => 'step '.(is_string($step['key'] ?? null) ? $step['key'] : (string) $stepIndex)]] : [];
            foreach ($step['fields'] as $field) {
                if (is_array($field) && is_array($field['visible_when'] ?? null)) {
                    [$key] = $this->fieldIdentity($field, $definitions, $languages);
                    $conditions[] = ['when' => $field['visible_when'], 'at' => 'field '.($key ?? '?')];
                }
            }
            foreach ($conditions as $condition) {
                $blocks = [...$blocks, ...$this->conditionBlocks($condition['when'], $condition['at'], $stepIndex, $seen, $definitions)];
            }
        }

        if ($requiredFile) {
            foreach ($this->channelsWithoutUpload() as $channel) {
                $blocks[] = ['code' => 'upload_unsupported', 'channel' => $channel];
            }
        }

        return $blocks;
    }

    /**
     * @param  array<mixed>  $field  untrusted builder JSON — every read is guarded
     * @param  array<string, array{active: bool, type: string}>  $definitions
     * @param  list<string>  $languages
     * @return array{0: ?string, 1: ?string, 2: list<array<string, mixed>>} key, type, blocks
     */
    private function fieldIdentity(array $field, array $definitions, array $languages): array
    {
        if (is_string($field['ref'] ?? null)) {
            $ref = $field['ref'];
            $definition = $definitions[$ref] ?? null;
            if ($definition === null) {
                return [null, null, [['code' => 'unknown_field', 'field' => $ref]]];
            }
            if (! $definition['active']) {
                // EC-14's publish half: a retired ref blocks the NEXT publish.
                return [$ref, $definition['type'], [['code' => 'retired_field', 'field' => $ref]]];
            }

            return [$ref, $definition['type'], []];
        }
        $key = is_string($field['key'] ?? null) ? $field['key'] : null;
        $type = is_string($field['type'] ?? null) ? $field['type'] : null;
        if ($key === null || ! in_array($type, self::INLINE_TYPES, true)) {
            return [null, null, [['code' => 'invalid_shape', 'detail' => 'inline fields need key + a known type']]];
        }
        $blocks = $this->labelBlocks($field['label'] ?? null, $languages, 'field '.$key);
        if (isset($field['help'])) {
            $blocks = [...$blocks, ...$this->labelBlocks($field['help'], $languages, 'field '.$key.' help')];
        }

        return [$key, $type, $blocks];
    }

    /**
     * @param  list<string>  $languages
     * @return list<array<string, mixed>>
     */
    private function labelBlocks(mixed $label, array $languages, string $at): array
    {
        $blocks = [];
        foreach ($languages as $language) {
            $text = is_array($label) ? ($label[$language] ?? null) : null;
            if (! is_string($text) || trim($text) === '') {
                $blocks[] = ['code' => 'incomplete_language', 'language' => $language, 'at' => $at];
            }
        }

        return $blocks;
    }

    /**
     * @param  array<mixed>  $when  untrusted builder JSON — every read is guarded
     * @param  array<string, int>  $seen
     * @param  array<string, array{active: bool, type: string}>  $definitions
     * @return list<array<string, mixed>>
     */
    private function conditionBlocks(array $when, string $at, int $stepIndex, array $seen, array $definitions): array
    {
        $target = is_string($when['field'] ?? null) ? $when['field'] : null;
        $op = is_string($when['op'] ?? null) ? $when['op'] : null;
        if ($target === null || ! in_array($op, self::CONDITION_OPS, true)) {
            return [['code' => 'invalid_shape', 'detail' => "condition at {$at} needs field + a known op"]];
        }
        if (! isset($seen[$target])) {
            $definition = $definitions[$target] ?? null;
            if ($definition !== null && ! $definition['active']) {
                return [['code' => 'retired_field', 'field' => $target, 'at' => $at]];
            }

            return [['code' => 'unknown_field', 'field' => $target, 'at' => $at]];
        }
        if ($seen[$target] > $stepIndex) {
            // R-03's wall: same-or-earlier step only.
            return [['code' => 'forward_reference', 'field' => $target, 'at' => $at]];
        }

        return [];
    }

    /** @return array<string, array{active: bool, type: string}> */
    private function customDefinitions(): array
    {
        /** @var list<object{key: string, type: string, active: bool}> $rows */
        $rows = DB::select("SELECT key, type, active FROM field_definitions WHERE entity = 'ticket'");
        $map = [];
        foreach ($rows as $row) {
            $map[$row->key] = ['active' => $row->active, 'type' => $row->type];
        }

        return $map;
    }

    /** @return list<string> registered channels whose capabilities carry no media kind at all */
    private function channelsWithoutUpload(): array
    {
        $lacking = [];
        foreach ($this->adapters->channels() as $channel) {
            if ($this->adapters->adapter($channel)->capabilities()->mediaTypes === []) {
                $lacking[] = $channel;
            }
        }

        return $lacking;
    }
}
