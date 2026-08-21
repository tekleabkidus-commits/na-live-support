<?php

declare(strict_types=1);

namespace App\Domain\Ai\Simulation;

use App\Domain\Ai\Config\ConfigVersions;
use InvalidArgumentException;

/**
 * BR-07 AS PHYSICS: a thresholds version publishes ONLY with the token of a
 * simulation run for EXACTLY its values (SimulationEngine::tokenFor) —
 * configuration by evidence, not vibes. The Backoffice door (step 12) hands
 * the token it received from the simulation panel; a publish without it is
 * refused at the service, whatever the door.
 */
final class ThresholdPublisher
{
    public function __construct(private readonly ConfigVersions $configs) {}

    public function publish(string $versionId, string $simulationToken, string $simulatedDayIso, string $publishedBy): void
    {
        $row = \Illuminate\Support\Facades\DB::table('ai_config_versions')->where('id', $versionId)->first();
        if ($row === null || $row->kind !== 'thresholds') {
            throw new InvalidArgumentException('only a thresholds draft publishes through the BR-07 door');
        }

        $decoded = json_decode(is_string($row->payload) ? $row->payload : '', true);
        /** @var array<string, mixed> $values */
        $values = is_array($decoded) ? $decoded : [];
        $thresholdKeys = array_intersect_key($values, array_flip(['rg_min', 'spam_min', 'handoff_below', 'auto_resolve_min']));
        $endpoint = $row->key === 'default' || ! is_string($row->key) ? null : $row->key;

        if (! hash_equals(SimulationEngine::tokenFor($simulatedDayIso, $thresholdKeys, $endpoint), $simulationToken)) {
            throw new InvalidArgumentException('BR-07: publish refused — no simulation was run for exactly these threshold values');
        }

        // §14's hard rule (S09 step 13): the languages this record enables
        // must each have their RG/handoff/disclosure templates — inside the
        // ONE thresholds publish door, so no surface routes around it.
        $languages = is_array($values['languages'] ?? null) ? array_values(array_filter($values['languages'], 'is_string')) : \App\Domain\Ai\Language\LanguageGate::LAUNCH_LANGUAGES;
        app(\App\Domain\Ai\Language\LanguageGate::class)->assertEnableable($languages);

        $this->configs->publish($versionId, $publishedBy);
    }
}
