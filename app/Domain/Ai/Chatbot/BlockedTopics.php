<?php

declare(strict_types=1);

namespace App\Domain\Ai\Chatbot;

use App\Domain\Ai\Config\ConfigVersions;

/**
 * FR-18's GUARDRAIL blocked-topic list (seeded: odds/betting advice, legal
 * advice, promises of payment or reversal, account-balance claims —
 * platform-seeded items are editable in WORDING, never deletable) + EC-04's
 * deterministic pre-screen: a matching message gets the deflection TEMPLATE
 * (never a generated reply); the third strike hands off with
 * blocked_topic_persistence. Operator additions ride the guardrails config.
 */
final class BlockedTopics
{
    /** @var array<string, string> topic => seeded pattern */
    private const SEEDED = [
        'betting_advice' => '/\b(what|which|who)\b[^.?!]{0,40}\b(should i bet|bet on|will win|to win|sure bet|tip|tips|odds are best)\b|\bguaranteed (win|odds)\b/iu',
        'legal_advice' => '/\b(sue|lawsuit|lawyer|legal action|take you to court|my rights under the law)\b/iu',
        'payment_promise' => '/\b(promise|guarantee)\b[^.?!]{0,30}\b(refund|reversal|payout|payment|money back)\b/iu',
        'balance_claim' => '/\b(tell me|what is|check)\b[^.?!]{0,20}\bmy (balance|account balance)\b/iu',
    ];

    public function __construct(private readonly ConfigVersions $configs) {}

    /** @return string|null the matched topic */
    public function matches(string $text): ?string
    {
        foreach ($this->patterns() as $topic => $pattern) {
            if (@preg_match($pattern, $text) === 1) {
                return $topic;
            }
        }

        return null;
    }

    /** @return array<string, string> */
    private function patterns(): array
    {
        $patterns = self::SEEDED;
        $guardrails = $this->configs->published('guardrails', 'default');
        if ($guardrails !== null) {
            $decoded = json_decode($guardrails->payload, true);
            $extra = is_array($decoded) && is_array($decoded['blocked_topics'] ?? null) ? $decoded['blocked_topics'] : [];
            foreach ($extra as $topic => $pattern) {
                if (is_string($topic) && is_string($pattern) && ! isset($patterns[$topic])) {
                    $patterns[$topic] = $pattern; // operator ADDITIONS; seeded entries are never removed
                }
            }
        }

        return $patterns;
    }
}
