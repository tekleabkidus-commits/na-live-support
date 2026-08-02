<?php

declare(strict_types=1);

namespace App\Domain\Auth\Password;

use App\Support\Problem\ErrorCode;
use App\Support\Problem\ProblemDetails;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A candidate password failed the tenant's policy (Doc 02 FR-15, FR-16, C-04).
 *
 * One exception for every rule, carrying PER-RULE errors — that structure is
 * FR-16's "live policy validation": the client learns exactly which rules the
 * candidate fails (length, classes, reuse, breached) and can render each next
 * to the field. Unlike LoginRefused there is nothing to keep generic here: by
 * the time a policy runs, the caller has already proven they may set this
 * account's password (a live token or an authenticated session), so precision
 * helps the legitimate user and gives an attacker nothing they don't have.
 *
 * The MESSAGES name rules, never the candidate. Echoing any part of the
 * attempted password back — "abc123 is too short" — would put a secret-in-
 * waiting into response logs and error trackers (SEC-08's spirit).
 */
final class PasswordPolicyViolation extends RuntimeException
{
    /**
     * @param  array<string, list<string>>  $errors  rule key => translated messages
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(sprintf(
            'Password policy violation: %s',
            implode(', ', array_keys($errors)),
        ));
    }

    public function render(Request $request): Response
    {
        $detail = __('auth.password.policy_failed');

        if ($request->expectsJson()) {
            return ProblemDetails::response(
                code: ErrorCode::PasswordPolicyViolation,
                status: 422,
                detail: is_string($detail) ? $detail : 'password policy violation',
                errors: $this->errors,
            );
        }

        return redirect()
            ->back()
            // SEC-08: the attempted password never survives a round trip.
            ->withInput($request->except(['password', 'password_confirmation']))
            ->withErrors(array_map(
                static fn (array $messages): string => implode(' ', $messages),
                $this->errors,
            ));
    }
}
