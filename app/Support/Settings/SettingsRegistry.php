<?php

declare(strict_types=1);

namespace App\Support\Settings;

use App\Support\Settings\Exceptions\UnregisteredSetting;
use Illuminate\Support\Facades\Validator;

/**
 * The code-side settings registry (Doc 01 FR-07, T-04; CLAUDE.md §2).
 *
 * FR-07: "registered defaults in code, per-tenant overrides in DB, validation rule per
 * key". This class is the code side, loaded from `registries/settings.php`.
 *
 * READING OR WRITING AN UNREGISTERED KEY IS AN ERROR, NOT A SILENT PASS. That single
 * rule is what makes the registry a constitution rather than documentation. Without it,
 * `settings('sms.retries')` would return null for a typo, some caller would treat null
 * as "off", and a feature would be quietly disabled in production with nothing to find.
 * With it, the typo is an exception on the first call, in development.
 *
 * `tenant_editable` is the platform/operator boundary (Doc 16 renders the editable
 * ones). A platform-only setting is still readable by tenant code — it configures
 * behaviour — but no tenant-facing write path may touch it.
 *
 * R-03 (settings sprawl) is mitigated by this file being the only door: a new key is a
 * visible diff in a registry the owner reviews, and `registry:check` validates its
 * shape.
 */
final class SettingsRegistry
{
    /** @var array<string, array<string, mixed>>|null */
    private ?array $entries = null;

    /**
     * @return array{key: string, type: SettingType, default: mixed, validation: string, tenant_editable: bool, owning_doc: string}
     */
    public function definition(string $key): array
    {
        $entries = $this->entries();

        if (! array_key_exists($key, $entries)) {
            throw UnregisteredSetting::forKey($key, array_keys($entries));
        }

        /** @var array{key: string, type: string, default: mixed, validation: string, tenant_editable: bool, owning_doc: string} $entry */
        $entry = $entries[$key];

        return [
            'key' => $entry['key'],
            'type' => SettingType::from($entry['type']),
            'default' => $entry['default'],
            'validation' => $entry['validation'],
            'tenant_editable' => $entry['tenant_editable'],
            'owning_doc' => $entry['owning_doc'],
        ];
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->entries());
    }

    public function type(string $key): SettingType
    {
        return $this->definition($key)['type'];
    }

    public function default(string $key): mixed
    {
        return $this->definition($key)['default'];
    }

    public function isTenantEditable(string $key): bool
    {
        return $this->definition($key)['tenant_editable'];
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->entries());
    }

    /**
     * Validate a candidate value against the key's registered rule.
     *
     * The rule is a Laravel validation string in the registry, so a setting's
     * constraints live beside its default rather than in whatever controller happens to
     * write it. A quota that must be positive says so once.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function validate(string $key, mixed $value): void
    {
        $definition = $this->definition($key);

        Validator::make(
            ['value' => $value],
            ['value' => $definition['validation']],
            [],
            ['value' => $key],
        )->validate();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function entries(): array
    {
        if ($this->entries !== null) {
            return $this->entries;
        }

        /** @var list<array<string, mixed>> $raw */
        $raw = require base_path('registries/settings.php');

        $byKey = [];
        foreach ($raw as $entry) {
            /** @var string $key */
            $key = $entry['key'];
            $byKey[$key] = $entry;
        }

        return $this->entries = $byKey;
    }
}
