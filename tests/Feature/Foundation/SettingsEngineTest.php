<?php

declare(strict_types=1);

use App\Support\Audit\AuditActor;
use App\Support\Settings\Exceptions\SettingNotEditable;
use App\Support\Settings\Exceptions\SettingWriteConflict;
use App\Support\Settings\Exceptions\UnregisteredSetting;
use App\Support\Settings\SettingsRepository;
use App\Support\Settings\SettingType;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The settings engine (Doc 01 FR-07, T-04, EC-06, FS-01).
 */
it('returns the registered default when a tenant has no override', function () {
    $tenant = createTenant();

    asTenant($tenant, function (): void {
        // 30 is BR-05's platform-configurable cutoff.
        expect(app(SettingsRepository::class)->get('tenant.suspended_inbound_retention_days'))->toBe(30);
    });
});

it('returns the override once written, and the default again after reset', function () {
    $tenant = createTenant();

    asTenant($tenant, function (): void {
        $settings = app(SettingsRepository::class);

        $settings->set('tenant.default_language', 'am', AuditActor::Staff);
        expect($settings->get('tenant.default_language'))->toBe('am');

        // §8's "reset to default" deletes the override row rather than writing the
        // default — so a tenant that explicitly chose today's default stays
        // distinguishable from one that never chose.
        $settings->reset('tenant.default_language');
        expect($settings->get('tenant.default_language'))->toBe('en')
            ->and(DB::table('tenant_settings')->where('key', 'tenant.default_language')->count())->toBe(0);
    });
});

it('refuses to read or write an unregistered key (FR-07)', function () {
    $tenant = createTenant();

    asTenant($tenant, function (): void {
        $settings = app(SettingsRepository::class);

        // Not null, not a silent pass. A typo would otherwise disable a feature quietly.
        expect(fn () => $settings->get('tenant.does_not_exist'))->toThrow(UnregisteredSetting::class);
        expect(fn () => $settings->set('tenant.does_not_exist', 'x'))->toThrow(UnregisteredSetting::class);
    });
});

it('refuses a tenant-surface write to a platform-owned setting', function () {
    $tenant = createTenant();

    asTenant($tenant, function (): void {
        $settings = app(SettingsRepository::class);

        // An operator raising their own quota ceiling would defeat NFR-06 entirely.
        expect(fn () => $settings->set('quota.messages_per_minute', 999_999))
            ->toThrow(SettingNotEditable::class);

        // The platform itself can, through the same engine, with tenantSurface: false.
        $settings->set('quota.messages_per_minute', 900, AuditActor::PlatformAdmin, tenantSurface: false);
        expect($settings->get('quota.messages_per_minute'))->toBe(900);
    });
});

it('validates against the registered rule', function () {
    $tenant = createTenant();

    asTenant($tenant, function (): void {
        $settings = app(SettingsRepository::class);

        // exists:ref_languages,code — a language with no translations is not a choice (FR-10).
        expect(fn () => $settings->set('tenant.default_language', 'jp'))
            ->toThrow(ValidationException::class);

        expect(fn () => $settings->set('quota.alert_threshold_percent', 150, AuditActor::PlatformAdmin, tenantSurface: false))
            ->toThrow(ValidationException::class);
    });
});

it('audits every change old to new (FR-07)', function () {
    $tenant = createTenant();

    asTenant($tenant, function (): void {
        app(SettingsRepository::class)->set('tenant.display_timezone', 'Africa/Nairobi');

        $entry = DB::table('audit_log')
            ->where('action_key', 'tenant.setting_changed')
            ->orderByDesc('occurred_at')
            ->first();

        expect($entry)->not->toBeNull();
        $payload = json_decode((string) $entry->payload, true);

        // Both halves are required fields in registries/audit-coverage.php precisely so a
        // change cannot be audited as merely having happened.
        expect($payload['key'])->toBe('tenant.display_timezone')
            ->and($payload['old_value'])->toBe('Africa/Addis_Ababa')
            ->and($payload['new_value'])->toBe('Africa/Nairobi');
    });
});

it('encrypts an encrypted-string at rest and never exposes it in bulk reads or audit', function () {
    $tenant = createTenant();

    asTenant($tenant, function (): void {
        $settings = app(SettingsRepository::class);
        $secret = 'super-secret-webhook-signing-value';

        $settings->set('tenant.outbound_webhook_secret', $secret);

        // At rest: the plaintext must not appear in the column.
        $stored = (string) DB::table('tenant_settings')
            ->where('key', 'tenant.outbound_webhook_secret')
            ->value('value');
        expect($stored)->not->toContain($secret);

        // By-key read decrypts.
        expect($settings->get('tenant.outbound_webhook_secret'))->toBe($secret);

        // Bulk read does not (C-13: a bulk read is what ends up in a log).
        expect($settings->all()['tenant.outbound_webhook_secret'])->toBe('[encrypted]');

        // Audit does not either.
        $entry = DB::table('audit_log')
            ->where('action_key', 'tenant.setting_changed')
            ->orderByDesc('occurred_at')
            ->first();
        $payload = json_decode((string) $entry->payload, true);
        expect($payload['new_value'])->toBe('[redacted]')
            ->and(json_encode($payload))->not->toContain($secret);
    });
});

it('keeps settings isolated between tenants', function () {
    $a = createTenant();
    $b = createTenant();

    asTenant($a, fn () => app(SettingsRepository::class)->set('tenant.display_timezone', 'Asia/Dubai'));

    asTenant($b, function (): void {
        // B must see the DEFAULT, not A's override. The cache is keyed per tenant
        // (`t:{tenant}:settings`) and RLS scopes the fallback read — a bug in either
        // would surface here.
        expect(app(SettingsRepository::class)->get('tenant.display_timezone'))->toBe('Africa/Addis_Ababa');
    });
});

/**
 * EC-06 — "last-write-wins with both writes audited and a conflict toast to the loser".
 */
it('detects a concurrent write, audits both, and signals the loser (EC-06)', function () {
    $tenant = createTenant();

    asTenant($tenant, function (): void {
        $settings = app(SettingsRepository::class);

        // Admin 1 and admin 2 both open the form and read the same updated_at.
        $settings->set('tenant.display_timezone', 'UTC');
        $staleVersion = $settings->version('tenant.display_timezone');

        // Admin 1 saves first and wins.
        $settings->set('tenant.display_timezone', 'Africa/Nairobi', expectedVersion: $staleVersion);

        // Admin 2 saves with the token they rendered from, and must be told rather than
        // silently clobbering admin 1's change.
        $conflict = null;
        try {
            $settings->set('tenant.display_timezone', 'Asia/Dubai', expectedVersion: $staleVersion);
        } catch (SettingWriteConflict $e) {
            $conflict = $e;
        }

        expect($conflict)->not->toBeNull('the loser was not told: EC-06 requires a conflict signal');
        expect($conflict->winningValue)->toBe('Africa/Nairobi')
            ->and($conflict->attemptedValue)->toBe('Asia/Dubai');

        // Last write wins — and the LOSER's write did not land.
        expect($settings->get('tenant.display_timezone'))->toBe('Africa/Nairobi');

        // BOTH writes audited: two successful changes plus the conflict attempt. Without
        // the third entry the trail would show one change where a human made two.
        expect(DB::table('audit_log')->where('action_key', 'tenant.setting_changed')->count())->toBe(2)
            ->and(DB::table('audit_log')->where('action_key', 'tenant.setting_write_conflict')->count())->toBe(1);

        $loser = DB::table('audit_log')->where('action_key', 'tenant.setting_write_conflict')->first();
        $payload = json_decode((string) $loser->payload, true);
        expect($payload['attempted_value'])->toBe('Asia/Dubai')
            ->and($payload['winning_value'])->toBe('Africa/Nairobi');
    });
});

/**
 * FS-01 — "Redis unavailable: settings fall back to DB reads (degraded latency, logged);
 * never failed."
 */
it('falls back to the database when Redis is unreachable, and never fails (FS-01)', function () {
    $tenant = createTenant();

    asTenant($tenant, function (): void {
        app(SettingsRepository::class)->set('tenant.display_timezone', 'Europe/London');
    });

    // Point the volatile connection at a closed port. This is a real connection failure,
    // not a mocked one — the whole value of FS-01 is that an unpredictable set of network
    // errors all degrade the same way, and a mock would only prove the one error we
    // thought of.
    config([
        'database.redis.volatile.host' => '127.0.0.1',
        'database.redis.volatile.port' => 1,
    ]);
    app('redis')->purge('volatile');

    asTenant($tenant, function (): void {
        $settings = app(SettingsRepository::class);

        // Degraded, not failed: the override is still correct because the database is the
        // source of truth (C-07).
        expect($settings->get('tenant.display_timezone'))->toBe('Europe/London')
            ->and($settings->get('tenant.suspended_inbound_retention_days'))->toBe(30)
            ->and($settings->all()['tenant.display_timezone'])->toBe('Europe/London');
    });

    // A write must also survive with Redis down — the invalidation cannot be reached, and
    // that must not roll back a committed change.
    asTenant($tenant, function (): void {
        $settings = app(SettingsRepository::class);
        $settings->set('tenant.display_timezone', 'Asia/Dubai');
        expect($settings->get('tenant.display_timezone'))->toBe('Asia/Dubai');
    });
});

it('encodes and decodes every registered type', function () {
    // T-04's five types, round-tripped through the JSONB column.
    expect(SettingType::String->decode(SettingType::String->encode('x')))->toBe('x')
        ->and(SettingType::Int->decode(SettingType::Int->encode(42)))->toBe(42)
        ->and(SettingType::Bool->decode(SettingType::Bool->encode(true)))->toBeTrue()
        ->and(SettingType::Json->decode(SettingType::Json->encode(['a' => 1])))->toBe(['a' => 1])
        ->and(SettingType::EncryptedString->decode(SettingType::EncryptedString->encode('s3cret')))->toBe('s3cret');

    // Type coercion is deliberately narrow: JSON and form input blur int/string, but
    // "abc" and 1.5 are mistakes, not representations.
    expect(SettingType::Int->encode('42'))->toBe(42)
        ->and(fn () => SettingType::Int->encode('abc'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => SettingType::Bool->encode('yes'))->toThrow(InvalidArgumentException::class);

    expect(SettingType::EncryptedString->isSecret())->toBeTrue()
        ->and(SettingType::String->isSecret())->toBeFalse();
});
