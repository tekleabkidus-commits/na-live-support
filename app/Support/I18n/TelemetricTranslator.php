<?php

declare(strict_types=1);

namespace App\Support\I18n;

use App\Support\Settings\SettingsRepository;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Log;
use Illuminate\Translation\Translator;
use Throwable;

/**
 * The §14 fallback chain and missing-translation telemetry (Doc 01 AC-06, C-08).
 *
 * C-08 fixes the chain: user language → tenant default → English. Laravel's stock
 * Translator knows exactly one fallback locale, which cannot express the middle hop — an
 * Amharic-default tenant whose agent chose Oromo must fall back to the TENANT's Amharic
 * before English, or the tenant's language choice silently means nothing.
 *
 * Every hop that misses is telemetry (§14: "missing-translation telemetry recorded per
 * key/language"): a structured log carrying `metric: i18n.missing_translation`, deduped
 * per process so a hot loop cannot flood the log stream. AC-06's bar is that this
 * telemetry is ZERO across every shipped key in every launch language — the sweep in
 * tests/Feature/Foundation/I18nFallbackTest.php walks the full `en` key space through
 * `am` proving exactly that.
 *
 * THE TENANT HOP CAN NEVER BREAK RENDERING. Resolving the tenant default reads the
 * settings engine, and a translator that throws because Redis blinked would turn every
 * page into a 500 — so that hop degrades to skipped on any failure, silently for the
 * user and visibly for telemetry. Rendering an English string on a bad day is the
 * designed behaviour (C-08's final fallback), not an error.
 */
final class TelemetricTranslator extends Translator
{
    private const FINAL_FALLBACK = 'en';

    /** @var array<string, true> */
    private array $reportedMisses = [];

    /**
     * @param  array<string, mixed>  $replace
     * @return string|array<mixed>
     */
    public function get($key, array $replace = [], $locale = null, $fallback = true): string|array
    {
        // Non-string results (whole groups) and explicit no-fallback calls keep stock
        // behaviour — the chain exists for user-visible strings, not for introspection.
        if ($fallback === false) {
            return parent::get($key, $replace, $locale, false);
        }

        foreach ($this->chain($locale) as $tryLocale) {
            $line = parent::get($key, $replace, $tryLocale, false);

            if (! is_string($line) || $line !== $key) {
                return $line;
            }

            $this->recordMiss($key, $tryLocale);
        }

        // Every hop missed: the key itself leaks to the surface — which AC-06 exists to
        // make impossible for shipped keys, and telemetry has already said so per locale.
        return $key;
    }

    /**
     * user language → tenant default → English, de-duplicated in order (C-08).
     *
     * @return list<string>
     */
    private function chain(?string $locale): array
    {
        return array_values(array_unique(array_filter([
            $locale ?? $this->locale,
            $this->tenantDefaultLocale(),
            self::FINAL_FALLBACK,
        ])));
    }

    private function tenantDefaultLocale(): ?string
    {
        try {
            if (! app(TenantContext::class)->isBound()) {
                return null;
            }

            $language = app(SettingsRepository::class)->get('tenant.default_language');

            return is_string($language) && $language !== '' ? $language : null;
        } catch (Throwable) {
            return null; // see the class docblock — this hop must never break rendering
        }
    }

    private function recordMiss(string $key, string $locale): void
    {
        $dedupe = $locale.'|'.$key;

        if (isset($this->reportedMisses[$dedupe])) {
            return;
        }

        $this->reportedMisses[$dedupe] = true;

        Log::warning('missing translation', [
            'key' => $key,
            'locale' => $locale,
            'metric' => 'i18n.missing_translation',
        ]);
    }
}
