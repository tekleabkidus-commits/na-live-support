<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Rbac\OverrideAdministration;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Support\Problem\ErrorCode;
use App\Support\Problem\ProblemDetails;
use App\Support\Rbac\CapabilityMapProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The user-override editor and effective-permissions explorer behind B6-S09
 * (§8: verdict + WHY with granter and expiry; add flow with mandatory
 * reason; active list with countdown chips).
 */
final class RbacOverridesController extends Controller
{
    public function __construct(private readonly OverrideAdministration $overrides) {}

    public function index(Request $request, string $userId): JsonResponse
    {
        $subject = User::query()->whereKey($userId)->firstOrFail();

        $live = UserPermissionOverride::query()
            ->where('user_id', $subject->identifier())
            ->live()
            ->orderBy('created_at')
            ->get()
            ->map(fn (UserPermissionOverride $override): array => [
                'id' => $override->identifier(),
                'permission' => $override->permission,
                'effect' => $override->effect,
                'expires_at' => $override->expires_at?->toIso8601String(),
                'granted_by' => $override->granted_by,
                'reason' => $override->reason,
            ]);

        return new JsonResponse(['overrides' => $live]);
    }

    /**
     * The §8 explorer: "search any key → verdict + why".
     */
    public function effective(Request $request, string $userId): JsonResponse
    {
        $subject = User::query()->whereKey($userId)->firstOrFail();
        $key = $request->string('key')->toString();

        $catalog = app(\App\Support\Rbac\PermissionCatalog::class);
        if (! $catalog->has($key)) {
            return new JsonResponse(['error' => 'unknown_key'], 404);
        }

        $verdict = app(CapabilityMapProvider::class)->verdictFor($subject, $key);

        return new JsonResponse(['verdict' => [
            'permission' => $key,
            'allowed' => $verdict->allowed,
            'source' => $verdict->source->value,
            'granted_by' => $verdict->grantedBy,
            'expires_at' => $verdict->expiresAt?->format(DATE_ATOM),
        ]]);
    }

    public function store(Request $request, string $userId): Response
    {
        $subject = User::query()->whereKey($userId)->firstOrFail();
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $expiresAtInput = $request->input('expires_at');
        $expiresAt = is_string($expiresAtInput) && $expiresAtInput !== ''
            ? new \DateTimeImmutable($expiresAtInput)
            : null;

        try {
            $result = $this->overrides->add(
                actor: $actor,
                subject: $subject,
                permission: $request->string('permission')->toString(),
                effect: $request->string('effect')->toString(),
                reason: $request->string('reason')->toString(),
                expiresAt: $expiresAt,
            );
        } catch (\App\Domain\Rbac\LastAdminProtected $blocked) {
            return ProblemDetails::response(
                code: ErrorCode::Forbidden,
                status: 403,
                detail: (string) __('rbac.blocked.last_admin'),
                extra: ['other_holders' => $blocked->otherHolders],
            );
        } catch (RuntimeException $e) {
            return ProblemDetails::response(
                code: ErrorCode::ValidationFailed,
                status: 422,
                detail: $e->getMessage(),
            );
        }

        return new JsonResponse([
            'override' => $result['override'] === null ? null : ['id' => $result['override']->identifier()],
            'pending' => $result['pending'],
            'warnings' => $result['warnings'],
        ], 201);
    }

    public function destroy(Request $request, string $userId, string $overrideId): Response
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $override = UserPermissionOverride::query()
            ->whereKey($overrideId)
            ->where('user_id', $userId)
            ->firstOrFail();

        try {
            $this->overrides->remove($actor, $override);
        } catch (RuntimeException $e) {
            return ProblemDetails::response(
                code: ErrorCode::ValidationFailed,
                status: 422,
                detail: $e->getMessage(),
            );
        }

        return new JsonResponse(['status' => 'removed']);
    }
}
