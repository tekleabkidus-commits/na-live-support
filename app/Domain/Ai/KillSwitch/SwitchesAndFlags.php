<?php

declare(strict_types=1);

namespace App\Domain\Ai\KillSwitch;

use App\Domain\Ai\Config\ConfigVersions;
use App\Domain\Ai\Pipeline\ChatbotEnablement;

/**
 * The FR-07 enablement seam's real implementation (step 8): a surface is
 * enabled for (channel, endpoint) when its SCOPE config says so AND no kill
 * switch covering that scope is off.
 *
 * SCOPE (FR-18's "endpoints where chatbot is on"): the endpoint-keyed
 * 'thresholds' config record carries `chatbot_enabled` (FR-11 already keys
 * per-endpoint overrides this way — "a stricter bot for the VIP account" is
 * endpoint config); the 'default' record covers endpoints without their own.
 * DEFAULT IS OFF (§21 rec 2: launch conservative; the chatbot is never on
 * by accident).
 */
final class SwitchesAndFlags implements ChatbotEnablement
{
    public function __construct(
        private readonly ConfigVersions $configs,
        private readonly KillSwitches $switches,
    ) {}

    public function enabledFor(string $surface, string $channel, ?string $endpointRef): bool
    {
        if ($this->switches->isOff($surface, $channel, $endpointRef)) {
            return false;
        }

        if ($surface !== 'chatbot') {
            return true; // copilot/supervisor enablement is the switch alone (FR-18)
        }

        foreach ([$endpointRef, 'default'] as $key) {
            if ($key === null) {
                continue;
            }
            $published = $this->configs->published('thresholds', $key);
            if ($published === null) {
                continue;
            }
            $decoded = json_decode($published->payload, true);
            if (is_array($decoded) && array_key_exists('chatbot_enabled', $decoded)) {
                return $decoded['chatbot_enabled'] === true;
            }
        }

        return false;
    }
}
