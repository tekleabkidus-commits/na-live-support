<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Yaml\Yaml;

/**
 * Validates the seven constitution registries (CLAUDE.md §2, ADR 0005) against
 * their schemas and cross-checks them. Exits non-zero on any violation — this
 * is the `registry-check` CI gate.
 */
final class RegistryCheck extends Command
{
    protected $signature = 'registry:check';

    protected $description = 'Validate the seven constitution registries and their cross-references';

    /** @var list<string> */
    private array $errors = [];

    private const SCOPES = ['Own', 'Team', 'Dept', 'All'];

    /** Doc 03 §4.1's fourteen modules — the only legal `module` values. */
    private const PERMISSION_MODULES = [
        'tickets', 'conversations', 'customers', 'telegram', 'webchat', 'sms',
        'ai', 'kb', 'automation', 'organization', 'users_roles', 'reports',
        'supervision', 'platform_settings',
    ];

    private const PERMISSION_RISKS = ['normal', 'high'];

    private const PERMISSION_SCOPE_DIMENSIONS = ['scoped', 'unscoped'];

    private const SETTING_TYPES = ['string', 'int', 'bool', 'json', 'encrypted-string'];

    private const CHANNELS = ['in_app', 'email', 'sms', 'telegram'];

    private const LAYERS = ['platform', 'tenant', 'user'];

    private const ACTOR_TYPES = ['staff', 'customer', 'system', 'platform_admin'];

    /**
     * Setting keys that must never exist (CLAUDE.md §8): no setting may
     * disable AI disclosure, the starvation cap, four-eyes erasure approval,
     * or the contrast validator.
     */
    private const FORBIDDEN_SETTING_PATTERNS = [
        '/disclosure.*(disable|off|hide)/i',
        '/(disable|off|hide).*disclosure/i',
        '/starvation.*(disable|off|null)/i',
        '/contrast.*(override|disable|off)/i',
        '/erasure.*(single|skip|bypass)/i',
        // Doc 10 (S10 step 2b): no setting may disable the one indexer door or the sanitizer.
        '/kb.*sanitiz.*(disable|off|skip|bypass)/i',
        '/kb.*index.*(disable|off|skip|bypass)/i',
    ];

    public function handle(): int
    {
        $base = base_path('registries');

        $this->checkPermissions($base.'/permissions.php');
        $this->checkSettings($base.'/settings.php');
        $this->checkNotifications($base.'/notifications.php');
        $this->checkAuditCoverage($base.'/audit-coverage.php');
        $this->checkFeatures($base.'/features.php');
        $gates = $this->checkGates($base.'/gates.yaml');
        $this->checkTraceability($base.'/traceability.yaml');
        $this->crossCheckGateCommands($gates);

        if ($this->errors !== []) {
            foreach ($this->errors as $error) {
                $this->error('registry-check: '.$error);
            }
            $this->error(sprintf('registry-check: FAIL (%d violation(s))', count($this->errors)));

            return self::FAILURE;
        }

        $this->info('registry-check: PASS (all seven registries well-formed and cross-consistent)');

        return self::SUCCESS;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadPhpRegistry(string $path, string $name): array
    {
        if (! is_file($path)) {
            $this->errors[] = sprintf('%s: file missing at %s', $name, $path);

            return [];
        }

        /** @var mixed $data */
        $data = require $path;

        if (! is_array($data) || ! array_is_list($data)) {
            $this->errors[] = sprintf('%s: must return a list of entries', $name);

            return [];
        }

        $entries = [];
        foreach ($data as $i => $entry) {
            if (! is_array($entry)) {
                $this->errors[] = sprintf('%s[%d]: entry must be an array', $name, (int) $i);

                continue;
            }
            /** @var array<string, mixed> $entry */
            $entries[] = $entry;
        }

        return $entries;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  list<string>  $fields
     */
    private function requireFields(array $entry, array $fields, string $ref): bool
    {
        $ok = true;
        foreach ($fields as $field) {
            if (! array_key_exists($field, $entry)) {
                $this->errors[] = sprintf('%s: missing required field "%s"', $ref, $field);
                $ok = false;
            }
        }

        return $ok;
    }

    private function checkOwningDoc(mixed $value, string $ref): void
    {
        if (! is_string($value) || preg_match('/^Doc(0[1-9]|1[0-9])$/', $value) !== 1) {
            $this->errors[] = sprintf('%s: owning_doc must be "Doc01"…"Doc19", got %s', $ref, var_export($value, true));
        }
    }

    /**
     * @param  list<string>  $allowed
     */
    private function checkStringListSubset(mixed $value, array $allowed, string $ref, string $field): void
    {
        if (! is_array($value) || $value === [] || ! array_is_list($value)) {
            $this->errors[] = sprintf('%s: %s must be a non-empty list', $ref, $field);

            return;
        }
        foreach ($value as $item) {
            if (! is_string($item) || ! in_array($item, $allowed, true)) {
                $this->errors[] = sprintf(
                    '%s: %s contains invalid value %s (allowed: %s)',
                    $ref,
                    $field,
                    var_export($item, true),
                    implode('|', $allowed),
                );
            }
        }
    }

    private function checkPermissions(string $path): void
    {
        $seen = [];
        foreach ($this->loadPhpRegistry($path, 'permissions') as $i => $entry) {
            $ref = sprintf('permissions[%d]', $i);
            if (! $this->requireFields($entry, ['key', 'label_i18n_key', 'description_i18n_key', 'group', 'module', 'scopes', 'scope_dimension', 'risk', 'owning_doc'], $ref)) {
                continue;
            }
            $key = $entry['key'];
            if (! is_string($key) || preg_match('/^[a-z0-9_]+(\.[a-z0-9_]+)+$/', $key) !== 1) {
                $this->errors[] = sprintf('%s: key must be dot-namespaced lowercase, got %s', $ref, var_export($key, true));
            } elseif (isset($seen[$key])) {
                $this->errors[] = sprintf('%s: duplicate key "%s"', $ref, $key);
            } else {
                $seen[$key] = true;
            }
            if (! is_string($entry['label_i18n_key']) || $entry['label_i18n_key'] === '') {
                $this->errors[] = sprintf('%s: label_i18n_key must be a non-empty string', $ref);
            }
            if (! is_string($entry['description_i18n_key']) || $entry['description_i18n_key'] === '') {
                $this->errors[] = sprintf('%s: description_i18n_key must be a non-empty string', $ref);
            }
            if (! is_string($entry['group']) || $entry['group'] === '') {
                $this->errors[] = sprintf('%s: group must be a non-empty string', $ref);
            }
            if (! is_string($entry['module']) || ! in_array($entry['module'], self::PERMISSION_MODULES, true)) {
                $this->errors[] = sprintf('%s: module must be one of the fourteen §4.1 modules', $ref);
            }
            if (! is_string($entry['risk']) || ! in_array($entry['risk'], self::PERMISSION_RISKS, true)) {
                $this->errors[] = sprintf('%s: risk must be one of %s', $ref, implode('|', self::PERMISSION_RISKS));
            }
            $this->checkStringListSubset($entry['scopes'], self::SCOPES, $ref, 'scopes');
            $dimension = $entry['scope_dimension'];
            if (! is_string($dimension) || ! in_array($dimension, self::PERMISSION_SCOPE_DIMENSIONS, true)) {
                $this->errors[] = sprintf('%s: scope_dimension must be one of %s', $ref, implode('|', self::PERMISSION_SCOPE_DIMENSIONS));
            } elseif (is_array($entry['scopes']) && array_is_list($entry['scopes'])) {
                // FR-01/FR-03 consistency: a scoped key offers all four levels for a
                // role to pick from; an unscoped key has no scope choice at all.
                $expected = $dimension === 'scoped' ? self::SCOPES : ['All'];
                if ($entry['scopes'] !== $expected) {
                    $this->errors[] = sprintf(
                        '%s: scopes must be exactly [%s] for a %s key',
                        $ref,
                        implode(', ', $expected),
                        $dimension,
                    );
                }
            }
            $this->checkOwningDoc($entry['owning_doc'], $ref);
        }
    }

    private function checkSettings(string $path): void
    {
        $seen = [];
        foreach ($this->loadPhpRegistry($path, 'settings') as $i => $entry) {
            $ref = sprintf('settings[%d]', $i);
            if (! $this->requireFields($entry, ['key', 'type', 'default', 'validation', 'tenant_editable', 'owning_doc'], $ref)) {
                continue;
            }
            $key = $entry['key'];
            if (! is_string($key) || preg_match('/^[a-z0-9_]+(\.[a-z0-9_]+)+$/', $key) !== 1) {
                $this->errors[] = sprintf('%s: key must be dot-namespaced lowercase, got %s', $ref, var_export($key, true));
            } elseif (isset($seen[$key])) {
                $this->errors[] = sprintf('%s: duplicate key "%s"', $ref, $key);
            } else {
                $seen[$key] = true;
                foreach (self::FORBIDDEN_SETTING_PATTERNS as $pattern) {
                    if (preg_match($pattern, $key) === 1) {
                        $this->errors[] = sprintf(
                            '%s: key "%s" matches forbidden pattern %s (CLAUDE.md §8 — this capability must not be a setting)',
                            $ref,
                            $key,
                            $pattern,
                        );
                    }
                }
            }
            $type = $entry['type'];
            if (! is_string($type) || ! in_array($type, self::SETTING_TYPES, true)) {
                $this->errors[] = sprintf('%s: type must be one of %s', $ref, implode('|', self::SETTING_TYPES));
            } else {
                $defaultOk = match ($type) {
                    'string', 'encrypted-string' => is_string($entry['default']),
                    'int' => is_int($entry['default']),
                    'bool' => is_bool($entry['default']),
                    // in_array() above guarantees the only remaining type is json
                    default => is_array($entry['default']),
                };
                if (! $defaultOk) {
                    $this->errors[] = sprintf('%s: default does not match declared type "%s"', $ref, $type);
                }
            }
            if (! is_string($entry['validation'])) {
                $this->errors[] = sprintf('%s: validation must be a Laravel rule string', $ref);
            }
            if (! is_bool($entry['tenant_editable'])) {
                $this->errors[] = sprintf('%s: tenant_editable must be a bool', $ref);
            }
            $this->checkOwningDoc($entry['owning_doc'], $ref);
        }
    }

    private function checkNotifications(string $path): void
    {
        $seen = [];
        foreach ($this->loadPhpRegistry($path, 'notifications') as $i => $entry) {
            $ref = sprintf('notifications[%d]', $i);
            if (! $this->requireFields($entry, ['category_key', 'label_i18n_key', 'default_channels', 'layer_model', 'owning_doc'], $ref)) {
                continue;
            }
            $key = $entry['category_key'];
            if (! is_string($key) || preg_match('/^[a-z0-9_]+(\.[a-z0-9_]+)+$/', $key) !== 1) {
                $this->errors[] = sprintf('%s: category_key must be dot-namespaced lowercase', $ref);
            } elseif (isset($seen[$key])) {
                $this->errors[] = sprintf('%s: duplicate category_key "%s"', $ref, $key);
            } else {
                $seen[$key] = true;
            }
            if (! is_string($entry['label_i18n_key']) || $entry['label_i18n_key'] === '') {
                $this->errors[] = sprintf('%s: label_i18n_key must be a non-empty string', $ref);
            }
            $this->checkStringListSubset($entry['default_channels'], self::CHANNELS, $ref, 'default_channels');
            if (! is_string($entry['layer_model']) || ! in_array($entry['layer_model'], self::LAYERS, true)) {
                $this->errors[] = sprintf('%s: layer_model must be one of %s', $ref, implode('|', self::LAYERS));
            }
            $this->checkOwningDoc($entry['owning_doc'], $ref);
        }
    }

    private function checkAuditCoverage(string $path): void
    {
        $seen = [];
        foreach ($this->loadPhpRegistry($path, 'audit-coverage') as $i => $entry) {
            $ref = sprintf('audit-coverage[%d]', $i);
            if (! $this->requireFields($entry, ['action_key', 'entity', 'actor_types', 'required_fields', 'owning_doc'], $ref)) {
                continue;
            }
            $key = $entry['action_key'];
            if (! is_string($key) || preg_match('/^[a-z0-9_]+(\.[a-z0-9_]+)+$/', $key) !== 1) {
                $this->errors[] = sprintf('%s: action_key must be dot-namespaced lowercase', $ref);
            } elseif (isset($seen[$key])) {
                $this->errors[] = sprintf('%s: duplicate action_key "%s"', $ref, $key);
            } else {
                $seen[$key] = true;
            }
            if (! is_string($entry['entity']) || $entry['entity'] === '') {
                $this->errors[] = sprintf('%s: entity must be a non-empty string', $ref);
            }
            $this->checkStringListSubset($entry['actor_types'], self::ACTOR_TYPES, $ref, 'actor_types');
            $requiredFields = $entry['required_fields'];
            if (! is_array($requiredFields) || ! array_is_list($requiredFields)) {
                $this->errors[] = sprintf('%s: required_fields must be a list (may be empty)', $ref);
            } else {
                foreach ($requiredFields as $field) {
                    if (! is_string($field) || $field === '') {
                        $this->errors[] = sprintf('%s: required_fields entries must be non-empty strings', $ref);
                    }
                }
            }
            $this->checkOwningDoc($entry['owning_doc'], $ref);
        }
    }

    private function checkFeatures(string $path): void
    {
        $seen = [];
        foreach ($this->loadPhpRegistry($path, 'features') as $i => $entry) {
            $ref = sprintf('features[%d]', $i);
            if (! $this->requireFields($entry, ['key', 'label_i18n_key', 'default', 'tenant_toggleable', 'owning_doc'], $ref)) {
                continue;
            }
            $key = $entry['key'];
            if (! is_string($key) || preg_match('/^[a-z0-9_]+(\.[a-z0-9_]+)+$/', $key) !== 1) {
                $this->errors[] = sprintf('%s: key must be dot-namespaced lowercase, got %s', $ref, var_export($key, true));
            } elseif (isset($seen[$key])) {
                $this->errors[] = sprintf('%s: duplicate key "%s"', $ref, $key);
            } else {
                $seen[$key] = true;
            }
            if (! is_string($entry['label_i18n_key']) || $entry['label_i18n_key'] === '') {
                $this->errors[] = sprintf('%s: label_i18n_key must be a non-empty string', $ref);
            }
            if (! is_bool($entry['default'])) {
                $this->errors[] = sprintf('%s: default must be a bool — a flag is a switch, not a setting (FR-07 owns values)', $ref);
            }
            if (! is_bool($entry['tenant_toggleable'])) {
                $this->errors[] = sprintf('%s: tenant_toggleable must be a bool', $ref);
            }
            $this->checkOwningDoc($entry['owning_doc'], $ref);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function checkGates(string $path): array
    {
        if (! is_file($path)) {
            $this->errors[] = sprintf('gates: file missing at %s', $path);

            return [];
        }

        /** @var mixed $data */
        $data = Yaml::parseFile($path);
        if (! is_array($data) || ! isset($data['gates']) || ! is_array($data['gates']) || ! array_is_list($data['gates'])) {
            $this->errors[] = 'gates: top-level "gates" must be a list';

            return [];
        }

        $valid = [];
        $seen = [];
        foreach ($data['gates'] as $i => $gate) {
            $ref = sprintf('gates[%d]', (int) $i);
            if (! is_array($gate)) {
                $this->errors[] = sprintf('%s: entry must be a mapping', $ref);

                continue;
            }
            /** @var array<string, mixed> $gate */
            if (! $this->requireFields($gate, ['gate_id', 'name', 'command', 'owning_doc', 'owning_ac', 'owner', 'added_date', 'blocking'], $ref)) {
                continue;
            }
            $id = $gate['gate_id'];
            if (! is_string($id) || preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $id) !== 1) {
                $this->errors[] = sprintf('%s: gate_id must be kebab-case', $ref);
            } elseif (isset($seen[$id])) {
                $this->errors[] = sprintf('%s: duplicate gate_id "%s"', $ref, $id);
            } else {
                $seen[$id] = true;
            }
            if (! is_string($gate['command']) || trim($gate['command']) === '') {
                $this->errors[] = sprintf('%s: command must be a non-empty string', $ref);
            }
            if ($gate['blocking'] !== true) {
                $this->errors[] = sprintf(
                    '%s: blocking must be true — a non-blocking gate is not a gate (Doc 18 FR-08); skips go through ci/skips/',
                    $ref,
                );
            }
            if (! is_string($gate['added_date']) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $gate['added_date']) !== 1) {
                $this->errors[] = sprintf('%s: added_date must be YYYY-MM-DD', $ref);
            }
            $this->checkOwningDoc($gate['owning_doc'], $ref);
            $valid[] = $gate;
        }

        return $valid;
    }

    private function checkTraceability(string $path): void
    {
        if (! is_file($path)) {
            $this->errors[] = sprintf('traceability: file missing at %s', $path);

            return;
        }

        /** @var mixed $data */
        $data = Yaml::parseFile($path);
        if (! is_array($data) || ! array_key_exists('entries', $data) || ! is_array($data['entries']) || ! array_is_list($data['entries'])) {
            $this->errors[] = 'traceability: top-level "entries" must be a list';

            return;
        }

        $tiers = ['unit', 'contract', 'integration', 'isolation', 'security', 'property', 'browser'];
        $types = ['automated', 'manual', 'drill'];
        $cadences = ['every-push', 'nightly', 'weekly', 'release'];
        $environments = ['local', 'ci', 'staging', 'production-smoke'];

        foreach ($data['entries'] as $i => $entry) {
            $ref = sprintf('traceability[%d]', (int) $i);
            if (! is_array($entry)) {
                $this->errors[] = sprintf('%s: entry must be a mapping', $ref);

                continue;
            }
            /** @var array<string, mixed> $entry */
            if (! $this->requireFields($entry, ['ac_ref', 'suite_path', 'type', 'tier', 'cadence', 'environment', 'owner'], $ref)) {
                continue;
            }
            $acRef = $entry['ac_ref'];
            // SS (§17 Success Scenarios) joined the list in S20: Doc 04 maps SS-02
            // and SS-04 to executed tests, and they are as much a numbered spec
            // family as FS. Widening WHICH ids may be mapped is not weakening the
            // gate — an unmapped id is still a build failure.
            if (! is_string($acRef) || preg_match('/^Doc(0[1-9]|1[0-9]) (AC|FR|NFR|BR|T|SEC|EC|FS|SS)-\d{2}$/', $acRef) !== 1) {
                $this->errors[] = sprintf('%s: ac_ref must look like "Doc01 AC-01", got %s', $ref, var_export($acRef, true));
            } else {
                // Cross-check: the referenced id must exist verbatim in the doc.
                // Appendices (Doc03-AppendixB-…) are addenda, not the spec
                // document — they must not shadow it in this glob.
                [$doc, $id] = explode(' ', $acRef);
                $docFiles = array_values(array_filter(
                    glob(base_path('docs/'.$doc.'-*.md')) ?: [],
                    static fn (string $file): bool => ! str_contains(basename($file), 'Appendix'),
                ));
                if ($docFiles === []) {
                    $this->errors[] = sprintf('%s: no spec file found for %s', $ref, $doc);
                } else {
                    $contents = (string) file_get_contents($docFiles[0]);
                    if (! str_contains($contents, $id)) {
                        $this->errors[] = sprintf('%s: %s does not contain "%s"', $ref, basename($docFiles[0]), $id);
                    }
                }
            }
            $type = $entry['type'];
            if (! is_string($type) || ! in_array($type, $types, true)) {
                $this->errors[] = sprintf('%s: type must be one of %s', $ref, implode('|', $types));
            }
            $suitePath = $entry['suite_path'];
            if (! is_string($suitePath) || $suitePath === '') {
                $this->errors[] = sprintf('%s: suite_path must be a non-empty string', $ref);
            } elseif ($type === 'automated' && ! is_file(base_path($suitePath))) {
                // Cross-check: an automated entry must name a real test file.
                $this->errors[] = sprintf('%s: suite_path "%s" does not exist', $ref, $suitePath);
            }
            if (! is_string($entry['tier']) || ! in_array($entry['tier'], $tiers, true)) {
                $this->errors[] = sprintf('%s: tier must be one of %s', $ref, implode('|', $tiers));
            }
            if (! is_string($entry['cadence']) || ! in_array($entry['cadence'], $cadences, true)) {
                $this->errors[] = sprintf('%s: cadence must be one of %s', $ref, implode('|', $cadences));
            }
            if (! is_string($entry['environment']) || ! in_array($entry['environment'], $environments, true)) {
                $this->errors[] = sprintf('%s: environment must be one of %s', $ref, implode('|', $environments));
            }
            if (! is_string($entry['owner']) || $entry['owner'] === '') {
                $this->errors[] = sprintf('%s: owner must be a non-empty string', $ref);
            }
        }
    }

    /**
     * Cross-check: every gate command references a file that exists (its
     * script), or an artisan command this application actually defines.
     *
     * @param  list<array<string, mixed>>  $gates
     */
    private function crossCheckGateCommands(array $gates): void
    {
        foreach ($gates as $gate) {
            $command = $gate['command'] ?? null;
            $id = is_string($gate['gate_id'] ?? null) ? $gate['gate_id'] : '?';
            if (! is_string($command)) {
                continue; // already reported by schema check
            }
            if (preg_match('/(?:php|bash)\s+((?:ci|scripts)\/[\w\/.-]+)/', $command, $m) === 1) {
                if (! is_file(base_path($m[1]))) {
                    $this->errors[] = sprintf('gates[%s]: command references missing file "%s"', $id, $m[1]);
                }
            } elseif (preg_match('/php artisan\s+([\w:._-]+)/', $command, $m) === 1) {
                if (! array_key_exists($m[1], $this->getApplication()?->all() ?? [])) {
                    $this->errors[] = sprintf('gates[%s]: artisan command "%s" is not registered', $id, $m[1]);
                }
            } else {
                $this->errors[] = sprintf('gates[%s]: unrecognized command shape "%s"', $id, $command);
            }
        }
    }
}
