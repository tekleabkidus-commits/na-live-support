<?php

declare(strict_types=1);

namespace App\Domain\Automation\Actions;

use InvalidArgumentException;

/**
 * Doc 12 FR-03 / BR-04 — the CLOSED card grammar of the external arms,
 * enforced where a rule is SAVED (RuleWriter) so the dry-run and the live
 * chain read the same shape. The load-bearing line is send_sms_template:
 * its card admits a template reference and NOTHING ELSE — a `body`, `text`
 * or `message` key cannot be stored, so "no free-text SMS from rules" is a
 * property of the data, not of a check at send time (BR-04's "structurally
 * impossible"). The first family (status/priority/tags/…) keeps its
 * step-5 shapes; tightening those is a later filing, not a silent edit.
 */
final class ActionCards
{
    /** @var array<string, array{required: list<string>, allowed: list<string>}> */
    public const GRAMMAR = [
        'send_sms_template' => ['required' => ['template_ref'], 'allowed' => ['template_ref']],
        'webhook_out' => ['required' => ['destination_id'], 'allowed' => ['destination_id']],
        'notify' => ['required' => [], 'allowed' => ['category', 'user_ids', 'role_ids']],
        'run_ai_task' => ['required' => ['task'], 'allowed' => ['task']],
        'suppress' => ['required' => ['reason'], 'allowed' => ['reason']],
    ];

    /** @param array<array-key, mixed> $action */
    public static function assertValid(array $action): void
    {
        $type = is_string($action['type'] ?? null) ? $action['type'] : '';
        $grammar = self::GRAMMAR[$type] ?? null;
        if ($grammar === null) {
            return;
        }
        $keys = array_values(array_diff(array_keys($action), ['type']));
        $stray = array_values(array_diff($keys, $grammar['allowed']));
        if ($stray !== []) {
            throw new InvalidArgumentException(sprintf(
                '%s card refuses key(s) %s — the card admits only [%s] (FR-03%s)',
                $type, implode(', ', $stray), implode(', ', $grammar['allowed']),
                $type === 'send_sms_template' ? '; BR-04: no free-text SMS from rules' : '',
            ));
        }
        foreach ($grammar['required'] as $key) {
            $value = $action[$key] ?? null;
            if (! is_string($value) || trim($value) === '') {
                throw new InvalidArgumentException(sprintf('%s card needs a non-empty %s (FR-03)', $type, $key));
            }
        }
        if ($type === 'notify' && self::count($action['user_ids'] ?? null) + self::count($action['role_ids'] ?? null) === 0) {
            throw new InvalidArgumentException('notify card needs at least one user or role (FR-03)');
        }
        $task = $action['task'] ?? null;
        if ($type === 'run_ai_task' && (! is_string($task) || ! in_array($task, RunAiTaskAction::TASKS, true))) {
            throw new InvalidArgumentException(sprintf('run_ai_task card: "%s" is not one of the three catalog-bound tasks (FR-03)', is_string($task) ? $task : ''));
        }
    }

    private static function count(mixed $value): int
    {
        return is_array($value) ? count(array_filter($value, static fn (mixed $v): bool => is_string($v) && $v !== '')) : 0;
    }
}
