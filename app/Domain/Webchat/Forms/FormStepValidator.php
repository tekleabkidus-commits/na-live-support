<?php

declare(strict_types=1);

namespace App\Domain\Webchat\Forms;

use App\Domain\Customers\Identity\PhoneNumber;
use App\Domain\Forms\ConditionEval;

/**
 * Doc 13 FR-06 / FR-09 — a form STEP validated against Doc 12's immutable
 * version schema, the way the drawn frames work (B3-S05: whole steps, not
 * one field per message). Reads `visible_when` (the domain key — B12-F03's
 * `show_when` never reaches here), `required`, and the per-type basics the
 * visitor can fix inline; Doc 12's own writers re-validate at the landing
 * (SubmissionWriter / ValueWriter), so this is the INLINE refusal, never the
 * only one. Phones normalize ONCE here (ADR 0014, Doc 08's normalizer), so
 * what lands is E.164 or refused.
 *
 * Refusals are inline and neutral: `{field => reason_key}` — the widget
 * renders the i18n key; no failure vocabulary (R-D13-5.1).
 */
final class FormStepValidator
{
    /**
     * @param  array<string, mixed>  $schema  the version's schema
     * @param  array<string, mixed>  $answers  every answer so far (all steps)
     * @return array{errors: array<string, string>, answers: array<string, mixed>, next_step: ?int, complete: bool}
     */
    public function validateStep(array $schema, int $stepIndex, array $answers): array
    {
        $steps = is_array($schema['steps'] ?? null) ? array_values($schema['steps']) : [];
        $step = $steps[$stepIndex] ?? null;
        if (! is_array($step)) {
            return ['errors' => ['_step' => 'widget.form.unknown_step'], 'answers' => $answers, 'next_step' => null, 'complete' => false];
        }

        $errors = [];
        $clean = $answers;
        foreach (is_array($step['fields'] ?? null) ? $step['fields'] : [] as $field) {
            if (! is_array($field)) {
                continue;
            }
            /** @var array<string, mixed> $field */
            $key = ConditionEval::keyOf($field);
            if ($key === null || ! ConditionEval::visible($field, $answers)) {
                continue;
            }
            $value = $answers[$key] ?? null;
            $present = $value !== null && ! (is_string($value) && trim($value) === '');
            if (! $present) {
                if (($field['required'] ?? false) === true) {
                    $errors[$key] = 'widget.form.required';
                }

                continue;
            }
            $verdict = $this->check($field, $value);
            if (is_string($verdict)) {
                $errors[$key] = $verdict;
            } else {
                $clean[$key] = $verdict['value'];
            }
        }

        $next = null;
        for ($i = $stepIndex + 1; $i < count($steps); $i++) {
            $candidate = $steps[$i];
            if (! is_array($candidate)) {
                continue;
            }
            /** @var array<string, mixed> $candidate */
            if ($this->stepHasVisibleField($candidate, $clean)) {
                $next = $i;
                break;
            }
        }

        return ['errors' => $errors, 'answers' => $clean, 'next_step' => $errors === [] ? $next : null, 'complete' => $errors === [] && $next === null];
    }

    /**
     * @param  array<string, mixed>  $field
     * @return string|array{value: mixed} a reason key, or the canonical value
     */
    private function check(array $field, mixed $value): string|array
    {
        $type = is_string($field['type'] ?? null) ? $field['type'] : 'text';
        /** @var array<string, mixed> $validation */
        $validation = is_array($field['validation'] ?? null) ? $field['validation'] : [];

        return match ($type) {
            'phone' => $this->phone($value),
            'email' => is_string($value) && filter_var(trim($value), FILTER_VALIDATE_EMAIL) !== false ? ['value' => strtolower(trim($value))] : 'widget.form.invalid_email',
            'number' => is_numeric($value) ? ['value' => +$value] : 'widget.form.invalid_number',
            'radio', 'dropdown' => $this->option($field, $value),
            'multi_select' => is_array($value) ? ['value' => array_values($value)] : 'widget.form.invalid_choice',
            'checkbox' => is_bool($value) ? ['value' => $value] : 'widget.form.invalid_choice',
            'file' => is_string($value) && $value !== '' ? ['value' => $value] : 'widget.form.invalid_file', // a media reference from the upload door
            default => $this->text($value, $validation),
        };
    }

    /** @return string|array{value: string} */
    private function phone(mixed $value): string|array
    {
        if (! is_string($value)) {
            return 'widget.form.invalid_phone';
        }
        try {
            return ['value' => PhoneNumber::toE164(trim($value), 'ET')];
        } catch (\Throwable) {
            return 'widget.form.invalid_phone';
        }
    }

    /**
     * @param  array<string, mixed>  $field
     * @return string|array{value: mixed}
     */
    private function option(array $field, mixed $value): string|array
    {
        $options = is_array($field['options'] ?? null) ? $field['options'] : [];
        foreach ($options as $option) {
            if (is_array($option) && ($option['value'] ?? null) === $value) {
                return ['value' => $value];
            }
        }

        return 'widget.form.invalid_choice';
    }

    /**
     * @param  array<string, mixed>  $validation
     * @return string|array{value: string}
     */
    private function text(mixed $value, array $validation): string|array
    {
        if (! is_string($value)) {
            return 'widget.form.invalid_text';
        }
        $text = trim($value);
        if (mb_strlen($text) > 4000) {
            return 'widget.form.too_long';
        }
        $regex = $validation['regex'] ?? null;
        if (is_string($regex) && $regex !== '' && @preg_match('/'.str_replace('/', '\/', $regex).'/u', $text) !== 1) {
            return 'widget.form.invalid_format';
        }

        return ['value' => $text];
    }

    /**
     * @param  array<string, mixed>  $step
     * @param  array<string, mixed>  $answers
     */
    private function stepHasVisibleField(array $step, array $answers): bool
    {
        foreach (is_array($step['fields'] ?? null) ? $step['fields'] : [] as $field) {
            if (! is_array($field)) {
                continue;
            }
            /** @var array<string, mixed> $field */
            if (ConditionEval::visible($field, $answers)) {
                return true;
            }
        }

        return false;
    }
}
