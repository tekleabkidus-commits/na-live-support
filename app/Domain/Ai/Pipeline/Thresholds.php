<?php

declare(strict_types=1);

namespace App\Domain\Ai\Pipeline;

use App\Domain\Ai\Config\ConfigVersions;

/**
 * FR-11's threshold set — a PUBLISHED 'thresholds' config version when one
 * exists, the platform defaults otherwise (per-endpoint overrides read the
 * endpoint-keyed config first, EC-07). Values are operator policy, versioned
 * and simulated (BR-07) — never settings-registry keys (FR-04's own shape).
 */
final class Thresholds
{
    /** The conservative-launch defaults (§21 rec 2). */
    private const DEFAULTS = [
        'rg_min' => 0.8,
        'handoff_below' => 0.6,
        'auto_resolve_min' => 0.85,
        'spam_min' => 0.9,
        'rg_screening_floor' => 0.3,
    ];

    public function __construct(private readonly ConfigVersions $configs) {}

    public function value(string $name, ?string $endpointRef = null): float
    {
        foreach ([$endpointRef, 'default'] as $key) {
            if ($key === null) {
                continue;
            }
            $published = $this->configs->published('thresholds', $key);
            if ($published === null) {
                continue;
            }
            $decoded = json_decode($published->payload, true);
            if (is_array($decoded) && is_numeric($decoded[$name] ?? null)) {
                return (float) $decoded[$name];
            }
        }

        return self::DEFAULTS[$name] ?? 0.0;
    }
}
