<?php

declare(strict_types=1);

namespace App\Support\Http\Exceptions;

use App\Support\Problem\ErrorCode;
use App\Support\Problem\ProblemDetails;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * T-08 / C-05 pagination misuse, rendered as the RFC 9457 problem it is (C-04).
 *
 * 400, not 422: nothing about the resource failed validation — the request's paging
 * grammar itself is wrong (an offset where none exists, a limit above the ceiling, a
 * cursor that decodes to nothing). `errors{}` stays empty for the same reason.
 */
final class InvalidPaginationRequest extends RuntimeException
{
    public function render(): JsonResponse
    {
        return ProblemDetails::response(
            code: ErrorCode::InvalidPagination,
            status: 400,
            detail: $this->getMessage(),
        );
    }
}
