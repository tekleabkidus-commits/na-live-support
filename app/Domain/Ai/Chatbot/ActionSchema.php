<?php

declare(strict_types=1);

namespace App\Domain\Ai\Chatbot;

/**
 * THE CLOSED ACTION ENUM's validator (Doc 09 FR-08, T-03, EC-05; the
 * injection blast-radius bound — sabotage #2's subject).
 *
 * The model's output is untrusted text. This validator is the ONLY door from
 * that text to platform behavior: an action outside the enum, malformed
 * JSON, a missing required field, or an `answer` without a citation (BR-02,
 * AC-02 — schema-ENFORCED, not convention) is DISCARDED — never executed.
 * EC-05's protocol (single repair retry, then handoff invalid_action, both
 * logged) is the caller's; this class answers valid-or-why.
 *
 * The tool surface is CLOSED (T-03): there are exactly five actions in v1
 * and no other capability, whatever the model claims.
 */
final class ActionSchema
{
    public const ACTIONS = ['answer', 'run_form', 'continue_flow', 'handoff', 'resolve'];

    /** Closed handoff reason categories (FR-12/EC-04/EC-05/EC-11 + [D11] contract). */
    public const HANDOFF_REASONS = [
        'low_confidence', 'customer_request', 'blocked_intent', 'form_route',
        'no_grounding', 'message_limit', 'post_resolution_loop', 'invalid_action',
        'blocked_topic_persistence', 'unsupported_language', 'provider_refusal',
        'rg_risk', 'quota_exhausted', 'degraded',
    ];

    /**
     * @param  mixed  $decoded  the model's decoded output (untrusted)
     */
    public function validate(mixed $decoded): ValidatedAction|DiscardedAction
    {
        if (! is_array($decoded)) {
            return new DiscardedAction('malformed', 'output is not a JSON object');
        }

        /** @var array<string, mixed> $data honestly narrowed: string keys only */
        $data = [];
        foreach ($decoded as $key => $value) {
            if (is_string($key)) {
                $data[$key] = $value;
            }
        }

        $action = $data['action'] ?? null;
        if (! is_string($action) || ! in_array($action, self::ACTIONS, true)) {
            return new DiscardedAction('out_of_enum', 'action outside the closed enum');
        }

        $problem = match ($action) {
            'answer' => $this->validateAnswer($data),
            'run_form' => $this->requireNonEmptyString($data, 'form_id', 'run_form requires form_id'),
            'continue_flow' => $this->requireNonEmptyString($data, 'state', 'continue_flow requires state'),
            'handoff' => $this->validateHandoff($data),
            'resolve' => $this->requireNonEmptyString($data, 'closing_text', 'resolve requires closing_text'),
        };

        if ($problem !== null) {
            return new DiscardedAction('missing_field', $problem);
        }

        return new ValidatedAction($action, $data);
    }

    /** @param array<string, mixed> $data */
    private function requireNonEmptyString(array $data, string $field, string $problem): ?string
    {
        $value = $data[$field] ?? null;

        return is_string($value) && trim($value) !== '' ? null : $problem;
    }

    /** @param array<string, mixed> $data */
    private function validateAnswer(array $data): ?string
    {
        if ($this->requireNonEmptyString($data, 'text', 'answer requires text') !== null) {
            return 'answer requires text';
        }

        // BR-02/AC-02: >=1 valid citation, SCHEMA-enforced. "The model
        // probably knows" is never sufficient in a money domain — an
        // uncited answer is not an answer, it is a handoff waiting to
        // happen, and the CALLER maps this discard to no_grounding.
        $citations = $data['citations'] ?? null;
        if (! is_array($citations) || $citations === []) {
            return 'answer requires >=1 citation (BR-02)';
        }
        foreach ($citations as $citation) {
            if (! is_string($citation) || trim($citation) === '') {
                return 'citations must be non-empty article ids';
            }
        }

        return null;
    }

    /** @param array<string, mixed> $data */
    private function validateHandoff(array $data): ?string
    {
        $reason = $data['reason'] ?? null;

        // The STEP 0 capability check saw free-prose reasons; the closed
        // category list is the contract (Q7's reason-category posture).
        if (! is_string($reason) || ! in_array($reason, self::HANDOFF_REASONS, true)) {
            return 'handoff requires a reason from the closed category list';
        }

        return null;
    }
}
