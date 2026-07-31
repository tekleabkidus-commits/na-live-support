<?php

declare(strict_types=1);

namespace App\Domain\Auth\Login;

use App\Support\Problem\ErrorCode;
use App\Support\Problem\ProblemDetails;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A login attempt was refused (Doc 02 FR-02, FR-05; C-04; AC-01).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY THIS EXISTS RATHER THAN ValidationException.
 *
 * The obvious implementation — `ValidationException::withMessages(['email' =>
 * …])` — produced a 302 REDIRECT here, not a 422, and the reason is a Doc 01
 * decision this document inherits: bootstrap/app.php renders JSON only for
 * `api/*` paths, so a web-route validation failure follows Laravel's default and
 * redirects back with a session error bag.
 *
 * That was not merely inconvenient, it was DANGEROUS, and it is worth recording
 * exactly how: the enumeration suite compares an existing-account failure with a
 * non-existing-account failure and asserts the bodies are byte-identical. Two
 * broken responses are also byte-identical. So the AC-01 suite passed green
 * while the login route was returning a redirect it should never have returned,
 * and it kept passing when the timing equalisation was deliberately removed —
 * because neither response was reaching the code being measured. A safety test
 * that compares two failures can only ever be as trustworthy as the proof that
 * the SUCCESS path works, which is why tests/Feature/Auth/LoginFlowTest.php
 * asserts that first and this class exists to make the failure path real.
 *
 * THE RESPONSE SHAPE IS THE POINT. Every refusal — wrong password, unknown
 * address, locked, disabled, suspended — renders through here, so "byte
 * identical" is a property of one code path rather than a coincidence between
 * several. The only thing that varies is the message, and FR-02 makes that
 * identical for every CREDENTIAL failure; FR-05's two distinct messages are
 * reachable only after the password verified.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class LoginRefused extends RuntimeException
{
    public function __construct(public readonly FailureReason $reason)
    {
        // The exception MESSAGE is for logs and stack traces, never for the
        // user: it names the category, which the response deliberately does not.
        parent::__construct(sprintf('Login refused: %s', $reason->value));
    }

    public function render(Request $request): Response
    {
        $detail = $this->reason->hasDistinctMessage()
            ? __($this->reason->messageI18nKey())
            // FR-02/AC-01: one message for every credential failure. Note it is
            // NOT the reason's own key — `locked` and `bad_credentials` both
            // render the generic text, so the response cannot distinguish them.
            : __('auth.failed.generic');

        if ($request->expectsJson()) {
            return ProblemDetails::response(
                code: ErrorCode::InvalidCredentials,
                status: 422,
                detail: $detail,
            );
        }

        // The browser flow: back to the form with the message in the error bag,
        // preserving input EXCEPT the password (which must never survive a
        // round trip — SEC-08).
        return redirect()
            ->back()
            ->withInput($request->except('password'))
            ->withErrors(['email' => $detail]);
    }
}
