<?php

declare(strict_types=1);

namespace App\Domain\Auth\Tokens;

use App\Support\Problem\ErrorCode;
use App\Support\Problem\ProblemDetails;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A bearer request was refused at the door (Doc 02 FR-20; step-9 ruling).
 *
 * FR-20's code set is CLOSED by owner ruling: exactly `invalid_token` (401),
 * `token_expired` (401), `insufficient_scope` (403 — raised by
 * RequirePermission, not here, because only the enforcement point knows which
 * key was missing). Everything that is not "expired" is `invalid_token`:
 * missing header, malformed, unknown, REVOKED, and DISABLED OWNER all render
 * byte-identically, because distinguishing revoked-from-unknown would confirm
 * a credential was once real, and distinguishing disabled-owner would leak an
 * account state through an unauthenticated surface. The door flags them apart
 * internally — $internalReason feeds the log line and the security metric,
 * never the response.
 *
 * The one deliberate distinction, `token_expired`, is FR-20's own: an
 * integration whose token aged out gets told so, because "rotate your token"
 * and "your token was never valid" have different runbooks (US-05, R-03).
 */
final class TokenRefused extends RuntimeException
{
    private function __construct(
        public readonly ErrorCode $errorCode,
        public readonly string $internalReason,
    ) {
        parent::__construct(sprintf('Bearer request refused: %s (%s)', $errorCode->value, $internalReason));
    }

    public static function invalid(string $internalReason): self
    {
        return new self(ErrorCode::InvalidToken, $internalReason);
    }

    public static function expired(): self
    {
        return new self(ErrorCode::TokenExpired, 'expired');
    }

    public function render(Request $request): Response
    {
        // One code path for every refusal of a given code — byte-identity is a
        // property of the shape, not a coincidence (the AC-01 lesson, applied
        // to tokens).
        return ProblemDetails::response(
            code: $this->errorCode,
            status: 401,
            detail: (string) __(sprintf('auth.token.%s', $this->errorCode === ErrorCode::TokenExpired ? 'expired' : 'invalid')),
        );
    }
}
