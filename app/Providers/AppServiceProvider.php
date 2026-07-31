<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Flags\FeatureRegistry;
use App\Support\I18n\TelemetricTranslator;
use Illuminate\Support\ServiceProvider;
use Illuminate\Translation\Translator;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // C-08's fallback chain (user → tenant default → English) with §14's
        // missing-translation telemetry, replacing the stock single-fallback translator.
        $this->app->extend('translator', function (Translator $translator): TelemetricTranslator {
            $replacement = new TelemetricTranslator($translator->getLoader(), $translator->getLocale());
            $replacement->setFallback($translator->getFallback());

            return $replacement;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Registry #7: every flag in registries/features.php becomes a Pennant
        // definition resolving its registered default (FR-11, ADR 0005).
        $this->app->make(FeatureRegistry::class)->defineAll();
    }
}
