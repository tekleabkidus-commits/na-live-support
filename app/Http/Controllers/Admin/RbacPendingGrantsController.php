<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Rbac\FourEyes;
use App\Http\Controllers\Controller;
use App\Models\PendingGrant;
use App\Models\User;
use App\Support\Problem\ErrorCode;
use App\Support\Problem\ProblemDetails;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The four-eyes surface behind B6-S10 (FR-08b/T-06/FS-04): pending high-risk
 * grants, approve/reject by a DISTINCT second admin (ruling R-9's rights).
 */
final class RbacPendingGrantsController extends Controller
{
    public function __construct(private readonly FourEyes $fourEyes) {}

    public function index(Request $request): JsonResponse
    {
        $pending = PendingGrant::query()
            ->where('status', 'pending')
            ->whereRaw('expires_at > now()')
            ->orderBy('created_at')
            ->get()
            ->map(fn (PendingGrant $grant): array => [
                'id' => $grant->identifier(),
                'subject_type' => $grant->subject_type,
                'role_id' => $grant->role_id,
                'user_id' => $grant->user_id,
                'permission' => $grant->permission,
                'requested_by' => $grant->requested_by,
                'reason' => $grant->reason,
                'expires_at' => $grant->expires_at->toIso8601String(),
            ]);

        return new JsonResponse(['pending' => $pending]);
    }

    public function approve(Request $request, string $grantId): Response
    {
        return $this->decide($request, $grantId, approve: true);
    }

    public function reject(Request $request, string $grantId): Response
    {
        return $this->decide($request, $grantId, approve: false);
    }

    private function decide(Request $request, string $grantId, bool $approve): Response
    {
        $approver = $request->user();
        abort_unless($approver instanceof User, 401);

        $pending = PendingGrant::query()->whereKey($grantId)->firstOrFail();

        try {
            $approve
                ? $this->fourEyes->approve($pending, $approver)
                : $this->fourEyes->reject($pending, $approver);
        } catch (RuntimeException $e) {
            return ProblemDetails::response(
                code: ErrorCode::ValidationFailed,
                status: 422,
                detail: $e->getMessage(),
            );
        }

        return new JsonResponse(['status' => $approve ? 'approved' : 'rejected']);
    }
}
