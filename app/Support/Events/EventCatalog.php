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

        /*
        |----------------------------------------------------------------------
        | Doc 02 §4.8 — auth events
        |----------------------------------------------------------------------
        |
        | Doc 02 §21 says to freeze these names now, because Docs 15/17/18 build
        | reports, audit views and alerts against them.
        |
        | PAYLOADS ARE IDENTIFIERS ONLY (owner ruling; SEC-07, SEC-08, C-13).
        | No email addresses, no user agents, no tokens, no codes. An address is
        | itself identifying, and an event bus fans out to consumers with
        | different retention and access rules than the audit log — so anything
        | a consumer genuinely needs beyond an id, it fetches by id under its own
        | authorisation. The publisher REJECTS extra keys, so this is enforced
        | rather than encouraged.
        |
        | The one apparent exception is `ip_hash` on auth.login.failed, and it is
        | not an exception: it is a salted hash, present so security monitoring
        | (Doc 18) can correlate "same source, many accounts" without the bus
        | carrying a personal datum. The raw IP lives on the device record and in
        | the audit entry, both tenant-scoped and access-controlled.
        */

        'auth.login.succeeded' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'user_id', 'device_id', 'new_device'],
            'producers' => ['Doc02/LoginPipeline'],
            'consumers' => ['Doc17/Audit', 'Doc18/SecurityMonitoring'],
            'description' => 'A staff user completed authentication and a session was established (FR-04).',
        ],
        'auth.login.failed' => [
            'schema_version' => 1,
            // user_id is present ONLY when the attempt identified an account; a
            // miss on a non-existent address publishes nothing at all, because
            // there is no tenant to publish it under — that case is a metric.
            'payload' => ['tenant_id', 'user_id', 'reason', 'ip_hash'],
            'producers' => ['Doc02/LoginPipeline'],
            'consumers' => ['Doc17/Audit', 'Doc18/SecurityMonitoring'],
            'description' => 'A staff authentication attempt failed, with FR-04\'s reason category.',
        ],
        'auth.logout' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'user_id'],
            'producers' => ['Doc02/LoginPipeline'],
            'consumers' => ['Doc17/Audit'],
            'description' => 'A staff user ended their session deliberately (§4.8).',
        ],
        'auth.2fa.enabled' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'user_id'],
            'producers' => ['Doc02/TwoFactor'],
            'consumers' => ['Doc17/Audit', 'Doc18/SecurityMonitoring'],
            'description' => 'A user confirmed 2FA enrolment with a valid code (FR-06).',
        ],
        'auth.2fa.disabled' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'user_id'],
            'producers' => ['Doc02/TwoFactor'],
            'consumers' => ['Doc17/Audit', 'Doc18/SecurityMonitoring'],
            'description' => 'A user turned their own 2FA off, proving a current code first (§4.8).',
        ],
        'auth.2fa.reset_by_admin' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'user_id', 'admin_id'],
            'producers' => ['Doc02/TwoFactor'],
            'consumers' => ['Doc17/Audit', 'Doc18/SecurityMonitoring'],
            'description' => 'An admin cleared a user\'s 2FA; the user re-enrols at next login (FR-08).',
        ],
        'auth.2fa.challenge_failed' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'user_id', 'attempt'],
            'producers' => ['Doc02/TwoFactor'],
            'consumers' => ['Doc17/Audit', 'Doc18/SecurityMonitoring'],
            'description' => 'A 2FA challenge attempt failed; the fifth voids the login attempt (FR-07).',
        ],

        // ── Doc 02 §4.4: the password lifecycle (§4.8's three password events).
        // Identifiers only, per the LoginRecorder rule: never the address the
        // request named, never anything token-derived (SEC-07, SEC-08).
        'auth.password.reset_requested' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'user_id'],
            'producers' => ['Doc02/PasswordLifecycle'],
            'consumers' => ['Doc17/Audit', 'Doc18/SecurityMonitoring'],
            'description' => 'A reset was requested and a token minted for THIS account; one event per matched account (FR-16).',
        ],
        'auth.password.reset_completed' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'user_id', 'origin'],
            'producers' => ['Doc02/PasswordLifecycle'],
            'consumers' => ['Doc17/Audit', 'Doc18/SecurityMonitoring'],
            'description' => 'A reset token was redeemed: new password set, all sessions revoked, this account unlocked (FR-16, EC-11, R-6).',
        ],
        'auth.password.changed' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'user_id', 'origin'],
            'producers' => ['Doc02/PasswordLifecycle'],
            'consumers' => ['Doc17/Audit', 'Doc18/SecurityMonitoring'],
            'description' => 'A password was set outside the reset flow: authenticated self-change (FR-14) or an invitation\'s first password (FR-17); `origin` says which.',
        ],

        // ── Doc 02 §4.5: API tokens (§4.8's three token events). Identifier-only
        // BY RULING (step 9): tenant, user and token ids — never scope lists,
        // names or anything plaintext-derived. The one addition is `permission`
        // on scope_denied, because "denied WHAT" is the datum Doc 18's
        // monitoring alerts on (R-03 token-sprawl reviews consume it too), and
        // a permission KEY identifies a catalog entry, not a person.
        'auth.token.created' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'user_id', 'token_id'],
            'producers' => ['Doc02/TokenMinter'],
            'consumers' => ['Doc17/Audit', 'Doc18/SecurityMonitoring'],
            'description' => 'An API token was minted — personal, machine, or a rotation\'s replacement (FR-18/FR-19); `user_id` is the owner (a service account for machine tokens, BR-03).',
        ],
        'auth.token.revoked' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'user_id', 'token_id'],
            'producers' => ['Doc02/TokenRevoker'],
            'consumers' => ['Doc17/Audit', 'Doc18/SecurityMonitoring'],
            'description' => 'An API token was revoked — self-service, admin action, or BR-04\'s disable cascade; the audit entry carries which.',
        ],
        'auth.token.scope_denied' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'user_id', 'token_id', 'permission'],
            'producers' => ['Doc02/RequirePermission'],
            'consumers' => ['Doc17/Audit', 'Doc18/SecurityMonitoring'],
            'description' => 'A token-authenticated request failed the T-05 intersection for the named permission (FR-20 insufficient_scope, AC-05).',
        ],

        // ── Doc 03 — RBAC (FR-12, T-07; S19). Closed payloads, identifiers
        // only — old/new DIFFS live in the audit entries, never in the
        // 13-month event stream (SEC-07; same split as Doc 02's Q7 ruling).
        'rbac.role.created' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'role_id', 'actor_id'],
            'producers' => ['Doc03/RoleAdministration', 'Doc03/RoleSeeder'],
            'consumers' => ['Doc17/Audit'],
            'description' => 'A role was created — by an admin or by the tenant-provisioning seeder (FR-10).',
        ],
        'rbac.role.updated' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'role_id', 'actor_id', 'version'],
            'producers' => ['Doc03/RoleAdministration'],
            'consumers' => ['Doc17/Audit'],
            'description' => 'A role\'s grants, scopes or fields changed; version is EC-03\'s optimistic lock after the write. Capability caches for every holder are invalidated (FR-07).',
        ],
        'rbac.role.deleted' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'role_id', 'actor_id'],
            'producers' => ['Doc03/RoleAdministration'],
            'consumers' => ['Doc17/Audit'],
            'description' => 'A role was deleted (soft, ruling R-11 — grant physics forbid hard deletes) after FR-08c\'s nobody-assigned guard passed.',
        ],
        'rbac.user.role_changed' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'user_id', 'old_role_id', 'new_role_id', 'actor_id'],
            'producers' => ['Doc03/RoleAdministration'],
            'consumers' => ['Doc17/Audit', 'Doc18/SecurityMonitoring'],
            'description' => 'A user was moved between roles (BR-01: exactly one). Queued work re-resolves at execution, so post-change rights govern (SEC-06).',
        ],
        'rbac.override.added' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'user_id', 'permission', 'effect', 'actor_id'],
            'producers' => ['Doc03/OverrideAdministration'],
            'consumers' => ['Doc17/Audit', 'Doc18/SecurityMonitoring'],
            'description' => 'A per-user allow/deny exception was granted (FR-04); the mandatory reason is in the audit entry (SEC-04).',
        ],
        'rbac.override.removed' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'user_id', 'permission', 'actor_id'],
            'producers' => ['Doc03/OverrideAdministration'],
            'consumers' => ['Doc17/Audit'],
            'description' => 'An override was revoked by an admin before any expiry.',
        ],
        'rbac.override.expired' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'user_id', 'permission'],
            'producers' => ['Doc03/ExpireOverrides'],
            'consumers' => ['Doc17/Audit'],
            'description' => 'The cleanup pass tidied an override whose expires_at had passed (FR-09/AC-07). Enforcement never waited for this — the resolver\'s DB-time check already denied.',
        ],
        'rbac.highrisk.granted' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'permission', 'subject_type', 'subject_id', 'actor_id', 'pending'],
            'producers' => ['Doc03/RoleAdministration', 'Doc03/OverrideAdministration'],
            'consumers' => ['Doc17/Audit', 'Doc18/SecurityMonitoring', 'Doc15/Reporting'],
            'description' => 'A high-risk permission was granted (FR-08b): applied directly, or written pending when rbac.four_eyes is on — `pending` says which. Feeds SEC-03\'s audit view and US-05\'s monthly report.',
        ],
        'rbac.highrisk.confirmed' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'pending_grant_id', 'permission', 'actor_id'],
            'producers' => ['Doc03/FourEyes'],
            'consumers' => ['Doc17/Audit', 'Doc18/SecurityMonitoring'],
            'description' => 'A DISTINCT second admin approved a pending high-risk grant (T-06, ruling R-9) and it took effect.',
        ],
        'rbac.highrisk.rejected' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'pending_grant_id', 'permission', 'actor_id'],
            'producers' => ['Doc03/FourEyes'],
            'consumers' => ['Doc17/Audit', 'Doc18/SecurityMonitoring'],
            'description' => 'A second admin rejected a pending high-risk grant; nothing was ever applied (FS-04\'s fail-safe posture).',
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
