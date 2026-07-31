<?php

declare(strict_types=1);

use App\Support\I18n\TelemetricTranslator;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Log;

/**
 * AC-06 / §14 / C-08 — every foundation string renders in Amharic with zero key leakage
 * and zero missing-translation telemetry; the fallback chain is user → tenant default →
 * English; misses are telemetry, not silence.
 *
 * The sweep walks the COMPLETE `en` key space from disk, so a key added anywhere under
 * lang/en/ is covered by AC-06 automatically — forgetting the `am` entry fails this
 * suite rather than shipping a key leak.
 */

/** @return list<string> every dot-notation key under lang/{locale}/ */
function allLangKeys(string $locale): array
{
    $keys = [];

    foreach (glob(base_path("lang/{$locale}/*.php")) ?: [] as $file) {
        $group = basename($file, '.php');
        /** @var array<string, mixed> $lines */
        $lines = require $file;

        foreach (Illuminate\Support\Arr::dot($lines) as $key => $value) {
            if (is_string($value)) {
                $keys[] = $group.'.'.$key;
            }
        }
    }

    return $keys;
}

it('renders EVERY shipped key in Amharic: no key leakage, telemetry = 0 (AC-06)', function () {
    Log::spy();

    $keys = allLangKeys('en');
    expect($keys)->not->toBeEmpty();

    foreach ($keys as $key) {
        $rendered = __($key, [], 'am');

        expect($rendered)->toBeString()
            ->and($rendered)->not->toBe($key, "key {$key} leaked raw in am");
    }

    // The other direction too: an am-only key would leak for English users.
    foreach (allLangKeys('am') as $key) {
        expect(__($key, [], 'en'))->not->toBe($key, "key {$key} leaked raw in en");
    }

    // "missing.translation telemetry = 0 for shipped keys" — the sweep above must not
    // have recorded a single miss in either launch language.
    Log::shouldNotHaveReceived('warning');
});

it('falls back user → TENANT DEFAULT → English, in that order (C-08)', function () {
    $tenant = createTenant(['default_language_code' => 'am']);

    // Provisioning materialises the wizard's language choice as a setting override; this
    // fixture tenant skipped provisioning, so write it the way provisioning would.
    asTenant($tenant, function (): void {
        app(App\Support\Settings\SettingsRepository::class)->set(
            key: 'tenant.default_language',
            value: 'am',
            actor: App\Support\Audit\AuditActor::System,
            tenantSurface: false,
        );

        // A user language with no translations at all: the middle hop must serve the
        // TENANT's Amharic, not jump straight to English.
        expect(__('foundation.provisioning.step.tenant_record', [], 'om'))
            ->toBe('የሥራ ቦታ በመፍጠር ላይ');
    });

    // No tenant bound: the same lookup from the same user locale lands on English.
    app(App\Support\Tenancy\TenantContext::class)->clear();
    expect(__('foundation.provisioning.step.tenant_record', [], 'om'))
        ->toBe('Creating workspace');
});

it('records telemetry per key and locale when a hop misses, then serves the fallback (§14)', function () {
    Log::spy();

    // A key that ships only in English — the am hop MUST miss, loudly, then fall back.
    Lang::addLines(['fixture.only_english' => 'English only'], 'en');

    expect(__('fixture.only_english', [], 'am'))->toBe('English only');

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context = []): bool => $message === 'missing translation'
            && ($context['key'] ?? null) === 'fixture.only_english'
            && ($context['locale'] ?? null) === 'am'
            && ($context['metric'] ?? null) === 'i18n.missing_translation');
});

it('dedupes telemetry per process so a hot loop cannot flood the logs', function () {
    Log::spy();

    Lang::addLines(['fixture.hot_loop' => 'English only'], 'en');

    foreach (range(1, 50) as $i) {
        __('fixture.hot_loop', [], 'am');
    }

    Log::shouldHaveReceived('warning')->once();
});

it('returns the key and records EVERY hop when nothing can serve it', function () {
    Log::spy();

    expect(__('fixture.does_not_exist_anywhere', [], 'am'))->toBe('fixture.does_not_exist_anywhere');

    // am missed, en missed — one telemetry line each.
    Log::shouldHaveReceived('warning')->twice();
});

it('is the translator actually bound — the chain is not an opt-in helper', function () {
    expect(app('translator'))->toBeInstanceOf(TelemetricTranslator::class);
});
