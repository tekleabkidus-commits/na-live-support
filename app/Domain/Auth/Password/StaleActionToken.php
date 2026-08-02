<?php

declare(strict_types=1);

namespace App\Domain\Auth\Password;

use App\Support\Problem\ErrorCode;
use App\Support\Problem\ProblemDetails;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The token died between resolution and consumption (Doc 02 SEC-02, FR-16).
 *
 * Reachable only by a race: two submissions of one link, where the loser's
 * compare-and-set in ActionTokenRedeemer::consume() found the stamp already
 * taken. The response is the same dead-link answer every other dead token
 * gets (SEC-01: one verdict for every kind of dead) — this class exists so
 * the race aborts the mutation instead of proceeding on a spent credential.
 */
final class StaleActionToken extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Action token was consumed between resolution and redemption.');
    }

    public function render(Request $request): Response
    {
        if ($request->expectsJson()) {
            return ProblemDetails::response(
                code: ErrorCode::ActionLinkInvalid,
                status: 422,
                detail: (string) __('auth.password.link_dead_body'),
            );
        }

        return redirect()->route('auth.password.forgot.form')->with('status', 'link_dead');
    }
}
