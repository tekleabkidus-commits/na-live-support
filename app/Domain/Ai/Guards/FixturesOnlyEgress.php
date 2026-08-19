<?php

declare(strict_types=1);

namespace App\Domain\Ai\Guards;

/**
 * THE FIXTURES-ONLY EGRESS GUARD (S09 step 1 — built BEFORE any provider
 * call path exists, per the approved plan's safety ordering).
 *
 * Doc 09 is the platform's FIRST external egress of conversation text. The
 * Doc 05 PII incident's law at higher stakes: real customer text behind a
 * "staging" label is a STOP — so outside production, every provider-bound
 * payload must prove it is fixture text. This guard is a MECHANISM, not a
 * habit: the step-3 LLM client (ADR 0016) calls assertAllowed() on every
 * request before any HTTP leaves the process, and a step-3 assertion proves
 * no egress path skips it. Sabotage #11 of the approved enumeration removes
 * this guard and expects the plant control to go red.
 *
 * WHAT MUST CARRY THE TAG: customer-content slots — every `messages[]` entry
 * with role `user` (the conversation window's customer turns) and every
 * embeddings `input` string. System-role content is platform/operator
 * material (personas, guardrails, task templates — never customer text) and
 * is exempt; assistant-role content is provider output fed back, exempt for
 * the same reason. Non-string user content (future multimodal parts) is
 * JSON-serialized and held to the same bar — over-refusing is the safe
 * direction.
 *
 * WHY config('app.env') AND NOT $app->environment(): the runtime config is
 * the deployment's own declaration and lets the proving test exercise the
 * production carve-out honestly. No settings-registry key exists for it —
 * environment is env-file territory, so no operator surface can flip this
 * guard off (the N-16 no-bypass-parameter posture: assertAllowed() takes the
 * payload and nothing else).
 */
final class FixturesOnlyEgress
{
    /** The tag every fixture-sourced customer string carries (STEP 0's convention). */
    public const TAG = '[FIXTURE]';

    /**
     * @param  array<string, mixed>  $payload  the provider-bound request body
     *
     * @throws NonFixturePayloadRefused
     */
    public function assertAllowed(array $payload): void
    {
        if (config('app.env') === 'production') {
            return; // production is the only environment where real text flows
        }

        $messages = $payload['messages'] ?? [];
        if (is_array($messages)) {
            foreach ($messages as $index => $message) {
                if (! is_array($message) || ($message['role'] ?? null) !== 'user') {
                    continue;
                }

                $content = $message['content'] ?? '';
                $haystack = is_string($content)
                    ? $content
                    : (string) json_encode($content, JSON_UNESCAPED_UNICODE);

                if (! str_contains($haystack, self::TAG)) {
                    throw NonFixturePayloadRefused::forMessage($index, 'user');
                }
            }
        }

        $inputs = $payload['input'] ?? null;
        if ($inputs !== null) {
            foreach (is_array($inputs) ? $inputs : [$inputs] as $index => $input) {
                $haystack = is_string($input)
                    ? $input
                    : (string) json_encode($input, JSON_UNESCAPED_UNICODE);

                if (! str_contains($haystack, self::TAG)) {
                    throw NonFixturePayloadRefused::forInput($index);
                }
            }
        }
    }
}
