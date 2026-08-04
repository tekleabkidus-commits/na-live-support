<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Support\Auth\Sessions\TenantHint;
use App\Support\Flags\FeatureRegistry;
use App\Support\I18n\TelemetricTranslator;
use App\Support\Rbac\EffectivePermissions;
use App\Support\Rbac\ResolvedEffectivePermissions;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Illuminate\Translation\Translator;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Stateless by construction: it reads the CURRENT request from the
        // container on every call and caches nothing, so a long-lived instance
        // cannot serve one request's tenant to the next. See the class docblock
        // for the bug that produced this design.
        $this->app->singleton(TenantHint::class, fn (Application $app): TenantHint => new TenantHint($app));

        // THE T-05 SEAM (Doc 02 ↔ Doc 03), CLOSED at S19 step 7: the real
        // capability-map resolver replaced the registered-keys stand-in (its
        // class was deleted, as its docblock ordered). TokenScopeGate
        // consumes the interface unchanged.
        $this->app->bind(EffectivePermissions::class, ResolvedEffectivePermissions::class);

        // C-08's fallback chain (user → tenant default → English) with §14's
        // missing-translation telemetry, replacing the stock single-fallback translator.
        // Doc 03 T-02/T-03: enforcement points resolve maps through the
        // provider seam — bound to the CACHED decorator (TTL 10 s + explicit
        // invalidation; R-04 keys bypass); Team/Dept membership is EMPTY
        // until Doc 11 binds real sources — fail closed, never open.
        $this->app->bind(
            \App\Support\Rbac\CapabilityMapProvider::class,
            \App\Support\Rbac\CachedCapabilityMaps::class,
        );
        $this->app->bind(
            \App\Support\Rbac\Scopes\MembershipSource::class,
            \App\Support\Rbac\Scopes\EmptyMembership::class,
        );

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

        // Doc 03 T-01/FS-03: boot-time DB-grants ⊆ catalog check — orphans
        // logged and ignored, never enforced. Skipped under the test harness
        // (each test boots a fresh app; the FS-03 suite drives run() directly)
        // — under Octane this is genuine once-per-worker boot work.
        if (! $this->app->runningUnitTests()) {
            $this->app->make(\App\Support\Rbac\CatalogBootCheck::class)->run();
        }

        // Doc 02 FR-18/FR-19: Sanctum runs on the platform's tenant-scoped,
        // UUIDv7 token model — the stock model is tenant-blind and bigint-keyed
        // (see the personal_access_tokens migration for the full argument).
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        // The guard-layer belt behind the resolve_api_token door: the door has
        // already refused revoked tokens and disabled owners with FR-20's
        // codes, but the guard must not ACCEPT what the door would refuse if
        // the two ever disagree (BR-04's per-request backstop, second layer).
        Sanctum::authenticateAccessTokensUsing(
            static function (mixed $accessToken, bool $isValid): bool {
                if (! $accessToken instanceof PersonalAccessToken) {
                    return false;
                }

                $owner = $accessToken->tokenable;

                return $isValid
                    && ! $accessToken->isRevoked()
                    && $owner instanceof User
                    && ! $owner->isDisabled();
            },
        );
    }
}
