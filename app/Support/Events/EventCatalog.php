<?php

declare(strict_types=1);

namespace App\Support\Events;

use InvalidArgumentException;

/**
 * The living event catalog (Doc 01 FR-08, §21; R-02; SEC-07).
 *
 * Doc 01 §21: "Document every published event in a living catalog (name, payload
 * schema, producers, consumers) checked in CI — this catalog becomes the
 * integration contract for Docs 04–17."
 *
 * Code is the source of truth and docs/EVENT-CATALOG.md is generated from it, not
 * the other way round. A catalog maintained by hand beside the code drifts from it
 * within weeks, and a drifted integration contract is worse than none — R-02's
 * "event bus becomes implicit coupling" begins exactly there. `ci/event-catalog.php`
 * regenerates and fails the build on any difference, so the document cannot rot.
 *
 * NOT A SEVENTH REGISTRY (owner ruling D-5). CLAUDE.md §2 fixes the registries at
 * six; this is a code-side contract with a generated document and a gate.
 *
 * SEC-07 IS ENFORCED HERE, NOT TRUSTED. Each event declares its exact payload
 * keys, and `publish()` rejects anything else — extra keys as well as missing
 * ones. "Outbox payloads exclude PII beyond ids wherever a subscriber can re-fetch
 * by id" is otherwise a rule every future publisher has to remember; declaring the
 * shape makes forgetting it a test failure
 * (tests/Security/OutboxPayloadMinimizationTest.php).
 *
 * `consumers` is documentation of intent — Docs 04–17 register themselves as they
 * land — and is why a producer can tell, before changing a payload, who will
 * notice.
 */
final class EventCatalog
{
    /**
     * @var array<string, array{
     *     schema_version: int,
     *     payload: list<string>,
     *     producers: list<string>,
     *     consumers: list<string>,
     *     description: string,
     * }>
     */
    public const EVENTS = [
        'tenant.provisioned' => [
            'schema_version' => 1,
            'payload' => ['tenant_id'],
            'producers' => ['Doc01/ProvisionTenant'],
            'consumers' => ['Doc03/RoleSeeder', 'Doc10/KnowledgeBaseSeeder', 'Doc17/Audit'],
            'description' => 'A tenant completed every provisioning step and became active.',
        ],
        'tenant.state_changed' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'old_state', 'new_state'],
            'producers' => ['Doc01/TenantLifecycle'],
            'consumers' => ['Doc02/SessionInvalidation', 'Doc16/Backoffice', 'Doc17/Audit'],
            'description' => 'A tenant moved between lifecycle states (Doc 01 FR-05).',
        ],
        'tenant.setting_changed' => [
            'schema_version' => 1,
            // No `old_value`/`new_value`: a setting value may be an
            // encrypted-string, and SEC-07 plus C-13 both say the transport is the
            // wrong place for it. The old→new pair lives in audit_log, which is
            // access-controlled and append-only; a subscriber that needs the value
            // reads it through the settings engine, by key.
            'payload' => ['tenant_id', 'key'],
            'producers' => ['Doc01/SettingsRepository'],
            'consumers' => ['Doc01/SettingsCache', 'Doc16/Backoffice', 'Doc17/Audit'],
            'description' => 'A tenant setting override was written; consumers should invalidate caches.',
        ],
        'tenant.quota_threshold_reached' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'quota', 'threshold_percent'],
            'producers' => ['Doc01/QuotaManager'],
            'consumers' => ['Doc16/Backoffice', 'Doc18/Alerting'],
            'description' => 'A tenant crossed a quota warning threshold (NFR-06: alert at 80%).',
        ],
        'tenant.quota_exceeded' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'quota'],
            'producers' => ['Doc01/QuotaManager'],
            'consumers' => ['Doc16/Backoffice', 'Doc18/Alerting'],
            'description' => 'A tenant hit a quota ceiling and a request was refused with 429.',
        ],
    ];

    /**
     * @return array{schema_version: int, payload: list<string>, producers: list<string>, consumers: list<string>, description: string}
     */
    public static function definition(string $name): array
    {
        if (! array_key_exists($name, self::EVENTS)) {
            throw new InvalidArgumentException(sprintf(
                'Event "%s" is not in the event catalog (Doc 01 §21). Add it to '
                .'App\Support\Events\EventCatalog::EVENTS with its payload keys, producers and '
                .'consumers, then run `php ci/event-catalog.php --write` to regenerate '
                .'docs/EVENT-CATALOG.md. An undocumented event is an integration contract nobody '
                .'downstream can see.',
                $name,
            ));
        }

        return self::EVENTS[$name];
    }

    /**
     * Reject a payload that does not match the declared shape exactly.
     *
     * BOTH directions matter. A missing key breaks consumers. An EXTRA key is how
     * PII leaks into the event stream: someone adds `customer_phone` "just for
     * convenience", it lands in `events` for 13 months (NFR-07) and in every
     * subscriber's logs, and SEC-07 is quietly gone. Neither is permitted.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function assertPayloadMatches(string $name, array $payload): void
    {
        $declared = self::definition($name)['payload'];
        $actual = array_keys($payload);

        sort($declared);
        sort($actual);

        if ($declared === $actual) {
            return;
        }

        $missing = array_values(array_diff($declared, $actual));
        $extra = array_values(array_diff($actual, $declared));

        throw new InvalidArgumentException(sprintf(
            'Event "%s" payload does not match its catalog entry.%s%s Declared: [%s]. '
            .'Extra keys are refused as firmly as missing ones — SEC-07 keeps PII out of the event '
            .'stream by declaring what each event may carry.',
            $name,
            $missing === [] ? '' : sprintf(' Missing: [%s].', implode(', ', $missing)),
            $extra === [] ? '' : sprintf(' Not declared: [%s].', implode(', ', $extra)),
            implode(', ', $declared),
        ));
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::EVENTS);
    }
}
