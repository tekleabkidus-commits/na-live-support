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
 *
 * 3. GUESTS GET A COOKIE HOP (step 13, §8's language switcher; owner ruling).
 *    The login card has no principal to read a locale from, so the switcher
 *    writes a plain `locale` cookie the client controls. It is therefore held
 *    to a STRICTER bar than the staff value: exact membership in the launch
 *    set {en, am}, not a shape check — a client-controlled string never
 *    reaches the translator's file paths on pattern-match alone. A present
 *    cookie that is not exactly a supported locale is ignored and counted,
 *    through the same scrubbed log channel as everything else (SEC-08). An
 *    authenticated user's `users.locale` ALWAYS wins over the cookie.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class ApplyUserLocale
{
    public const LOCALE_COOKIE = 'locale';

    /**
     * The launch languages (C-08). Operator-side locale expansion is Doc 16's;
     * widening this list is a code change on purpose — each entry names a lang/
     * directory that must exist and have passed the AC-06 parity sweep.
     */
    private const SUPPORTED_GUEST_LOCALES = ['en', 'am'];

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
        } else {
            $this->applyGuestCookie($request);
        }

        try {
            return $next($request);
        } finally {
            // See docblock property 1 — this line is the Octane leak control.
            $this->app->setLocale($previous);
        }
    }

    /**
     * Docblock property 3: the guest cookie hop. Exact-match against the
     * launch set, never a shape check — see the docblock for why the bar is
     * stricter than the staff value's.
     */
    private function applyGuestCookie(Request $request): void
    {
        $cookie = $request->cookie(self::LOCALE_COOKIE);

        if (! is_string($cookie) || $cookie === '') {
            return;
        }

        if (in_array($cookie, self::SUPPORTED_GUEST_LOCALES, true)) {
            $this->app->setLocale($cookie);

            return;
        }

        logger()->warning('locale cookie is unsupported and was ignored', [
            'metric' => 'i18n.invalid_locale_cookie',
            'locale' => mb_substr($cookie, 0, 32),
        ]);
    }

    private function isWellFormed(string $locale): bool
    {
        return preg_match('/^[a-z]{2,3}(_[A-Z]{2})?$/', $locale) === 1;
    }
}
