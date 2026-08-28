<?php

declare(strict_types=1);

namespace App\Http\Controllers\Kb;

use App\Domain\Kb\Versioning\Versions;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Http\CursorPagination;
use App\Support\Http\Exceptions\InvalidPaginationRequest;
use App\Support\Problem\ErrorCode;
use App\Support\Problem\ProblemDetails;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/** Doc 10 FR-08 / NFR-04 — history (cursor-paginated), a version, the block diff, restore-to-draft (S10 step 4; JSON only). */
final class KbVersionController extends Controller
{
    public function __construct(private readonly Versions $versions) {}

    public function history(Request $request, string $variantId): JsonResponse
    {
        try {
            $pagination = CursorPagination::fromRequest($request);
        } catch (InvalidPaginationRequest $e) {
            return ProblemDetails::response(ErrorCode::InvalidPagination, 400, $e->getMessage());
        }

        return new JsonResponse($this->versions->history($variantId, $pagination));
    }

    public function show(string $versionId): JsonResponse
    {
        try {
            return new JsonResponse($this->versions->show($versionId));
        } catch (InvalidArgumentException) {
            return ProblemDetails::response(ErrorCode::ResourceNotFound, 404, 'not found');
        }
    }

    public function diff(Request $request, string $versionId): JsonResponse
    {
        $to = $request->query('to');
        if (! is_string($to)) {
            return ProblemDetails::response(ErrorCode::ValidationFailed, 422, 'to=<version id> is required');
        }
        try {
            return new JsonResponse($this->versions->diffBetween($versionId, $to));
        } catch (InvalidArgumentException $e) {
            return ProblemDetails::response(ErrorCode::ResourceNotFound, 404, $e->getMessage());
        }
    }

    public function restore(Request $request, string $versionId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        try {
            return new JsonResponse($this->versions->restore($versionId, $user->identifier()));
        } catch (InvalidArgumentException) {
            return ProblemDetails::response(ErrorCode::ResourceNotFound, 404, 'not found');
        }
    }
}
