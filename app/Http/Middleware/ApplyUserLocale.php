<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The C-08 chain's FIRST hop, per request (Doc 01 inherited task I-5; Doc 02 §14).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * TelemetricTranslator already walks user language → tenant default → English,
 * but until this middleware nothing ever SET the user hop: the chain's first
 * link only fired when a caller passed a locale explicitly, so an agent who
 * chose Amharic was served the tenant default on every request. This reads
 * `users.locale` from the authenticated principal and makes it the request
 * locale; guests and locale-less users keep the application default, and the
 * translator's remaining hops behave exactly as before.
 *
 * TWO DELIBERATE PROPERTIES:
 *
 * 1. THE PREVIOUS LOCALE IS RESTORED IN A `finally`. Under Octane the
 *    application instance outlives the request, so setLocale() is a write to
 *    WORKER state, not request state — skip the restore and the next request on
 *    this worker renders in the previous user's language. That is Doc 01
 *    R-01's exact failure shape (state outliving its request, invisible in
 *    every green single-request test), and the leak test exercises it with two
 *    sequential requests through one kernel.
 *
 * 2. THE VALUE IS SHAPE-CHECKED, NOT TRUSTED. `users.locale` is staff-set data,
 *    but it feeds the translation FileLoader's path, so only `xx` / `xx_YY`
 *    shapes are accepted. Anything else is skipped and counted — a malformed
 *    locale is a data bug that should surface in telemetry, never a request
 *    that dies or a path that traverses.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class ApplyUserLocale
{
    public function __construct(private readonly Application $app) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $previous = $this->app->getLocale();

        // The staff guard is the only principal-bearing surface today; the API
        // token step (FR-18/19) extends this to token owners when it lands.
        $locale = $request->user('staff')?->locale;

        if (is_string($locale) && $locale !== '' && $this->isWellFormed($locale)) {
            $this->app->setLocale($locale);
        } elseif (is_string($locale) && $locale !== '') {
            logger()->warning('user locale is malformed and was ignored', [
                'metric' => 'i18n.invalid_user_locale',
                'locale' => $locale,
            ]);
        }

        try {
            return $next($request);
        } finally {
            // See docblock property 1 — this line is the Octane leak control.
            $this->app->setLocale($previous);
        }
    }

    private function isWellFormed(string $locale): bool
    {
        return preg_match('/^[a-z]{2,3}(_[A-Z]{2})?$/', $locale) === 1;
    }
}
