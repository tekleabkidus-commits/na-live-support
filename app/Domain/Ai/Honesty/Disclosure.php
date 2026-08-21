<?php

declare(strict_types=1);

namespace App\Domain\Ai\Honesty;

use App\Domain\Ai\Config\ConfigVersions;
use InvalidArgumentException;

/**
 * FR-19a / BR-05 / N-08: "the assistant discloses it is an AI in its first
 * greeting and whenever asked (WORDING configurable, PRESENCE not)".
 *
 * WORDING: the operator's guardrails may carry `disclosure.<lang>`; absent,
 * the platform default (lang/<lang>/ai.php disclosure.sentence) stands.
 * There is NO empty wording: an override that is blank or whitespace is
 * ignored in favour of the default — a blank is not a removal.
 *
 * PRESENCE (the gate, inside ConfigVersions::publish so no door bypasses
 * it): a PERSONA version publishes only if every per-language greeting it
 * declares CONTAINS that language's disclosure wording. A persona with no
 * greeting for a language falls back to the platform greeting, which
 * carries the disclosure by construction. Sabotage #10 removes the
 * disclosure from ONE persona greeting in ONE language and AC-05's gate +
 * first-greeting proofs go red.
 *
 * No setting key, no config kind and no Backoffice surface can switch the
 * presence off (HonestyTest asserts the absence — the N-08 pattern).
 */
final class Disclosure
{
    public const LANGUAGES = ['en', 'am'];

    public function __construct(private readonly ConfigVersions $configs) {}

    public function wording(string $language): string
    {
        $guardrails = $this->configs->published('guardrails', 'default');
        if ($guardrails !== null) {
            $decoded = json_decode($guardrails->payload, true);
            $override = is_array($decoded) && is_array($decoded['disclosure'] ?? null) ? ($decoded['disclosure'][$language] ?? null) : null;
            if (is_string($override) && trim($override) !== '') {
                return trim($override);
            }
        }

        return (string) __('ai.disclosure.sentence', [], $language);
    }

    /** The first-greeting text for a language: the persona's (gated) greeting, else the platform's. */
    public function greeting(string $language): string
    {
        $persona = $this->configs->published('persona', 'default');
        if ($persona !== null) {
            $decoded = json_decode($persona->payload, true);
            $greeting = is_array($decoded) && is_array($decoded['greeting'] ?? null) ? ($decoded['greeting'][$language] ?? null) : null;
            if (is_string($greeting) && trim($greeting) !== '') {
                return trim($greeting);
            }
        }

        return (string) __('ai.greeting.first', ['disclosure' => $this->wording($language)], $language);
    }

    /**
     * The publish gate for kind=persona.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws InvalidArgumentException when a declared greeting lacks its language's disclosure
     */
    public function gatePersona(array $payload): void
    {
        $greetings = $payload['greeting'] ?? null;
        if (! is_array($greetings)) {
            return; // no greetings declared → the platform greeting (disclosing) stands
        }
        foreach ($greetings as $language => $greeting) {
            if (! is_string($language) || ! is_string($greeting) || trim($greeting) === '') {
                continue;
            }
            $wording = $this->wording($language);
            if (! str_contains(mb_strtolower($greeting), mb_strtolower($wording))) {
                throw new InvalidArgumentException(sprintf(
                    'FR-19a/BR-05: the %s greeting does not contain the AI disclosure ("%s") — presence is not operator-removable; change the wording in guardrails.disclosure.%s instead',
                    $language, $wording, $language,
                ));
            }
        }
    }
}
