<?php

declare(strict_types=1);

namespace App\Domain\Ai\Language;

use App\Domain\Ai\Config\ConfigVersions;
use InvalidArgumentException;

/**
 * Doc 09 §14's HARD RULE AS CODE: "an endpoint can't enable a language
 * missing its RG template". The thresholds record (per endpoint, FR-11)
 * carries `languages` — the languages the chatbot may speak there (default:
 * the launch pair). Its one publish door (BR-07's ThresholdPublisher) calls
 * this gate: every enabled language must have a COMPLETE template set —
 * the RG template (BR-03's operator-approved text, the rule's own words),
 * the handoff template (FR-12), the disclosure wording (FR-19a). A language
 * whose template resolves to nothing (no guardrails override, no platform
 * seed — __() hands the key back) is refused, naming the language and the
 * missing template. No setting, no flag, no parameter skips it.
 */
final class LanguageGate
{
    public const LAUNCH_LANGUAGES = ['en', 'am'];

    public function __construct(private readonly ConfigVersions $configs) {}

    /**
     * @param  list<string>  $languages
     *
     * @throws InvalidArgumentException
     */
    public function assertEnableable(array $languages): void
    {
        foreach ($languages as $language) {
            foreach ($this->missingTemplates($language) as $missing) {
                throw new InvalidArgumentException(sprintf(
                    '§14: language "%s" cannot be enabled — its %s template is missing (publish a guardrails override or ship the platform template first)',
                    $language, $missing,
                ));
            }
        }
    }

    /** @return list<string> the template names missing for the language */
    public function missingTemplates(string $language): array
    {
        $missing = [];
        if (! $this->overridePresent('rg_template', $language) && ! $this->seeded('ai.rg.template', $language)) {
            $missing[] = 'RG';
        }
        if (! $this->seeded('ai.handoff.template', $language)) {
            $missing[] = 'handoff';
        }
        if (! $this->overridePresent('disclosure', $language) && ! $this->seeded('ai.disclosure.sentence', $language)) {
            $missing[] = 'disclosure';
        }

        return $missing;
    }

    /**
     * A platform seed exists in the language's OWN file — the translator's
     * fallback to English is deliberately NOT consulted (a fallback would hand
     * an Amharic speaker English RG text and call it "present").
     */
    private function seeded(string $key, string $language): bool
    {
        return app('translator')->has($key, $language, false);
    }

    /** A non-blank guardrails override for the language. */
    private function overridePresent(string $field, string $language): bool
    {
        $guardrails = $this->configs->published('guardrails', 'default');
        if ($guardrails === null) {
            return false;
        }
        $decoded = json_decode($guardrails->payload, true);
        $values = is_array($decoded) && is_array($decoded[$field] ?? null) ? $decoded[$field] : [];

        return is_string($values[$language] ?? null) && trim($values[$language]) !== '';
    }

    /**
     * The thresholds record's enabled languages for an endpoint (the default record's, else the launch pair).
     *
     * @return list<string>
     */
    public function enabledFor(?string $endpointRef): array
    {
        foreach ([$endpointRef, 'default'] as $key) {
            if ($key === null) {
                continue;
            }
            $row = $this->configs->published('thresholds', $key);
            if ($row === null) {
                continue;
            }
            $decoded = json_decode($row->payload, true);
            $languages = is_array($decoded) && is_array($decoded['languages'] ?? null) ? array_values(array_filter($decoded['languages'], 'is_string')) : null;
            if ($languages !== null) {
                return $languages;
            }
        }

        return self::LAUNCH_LANGUAGES;
    }
}
