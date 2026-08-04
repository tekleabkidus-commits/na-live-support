<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Auth\Tokens\ServiceAccountProvisioner;
use App\Domain\Auth\Tokens\TokenMinter;
use App\Domain\Auth\Tokens\TokenRevoker;
use App\Http\Controllers\Controller;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Support\Problem\ErrorCode;
use App\Support\Problem\ProblemDetails;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Machine tokens, admin-managed (Doc 02 FR-19, BR-03, SS-04).
 *
 * Every route carries `permission:apikeys.machine.manage` — Doc 03 §4.1's own
 * key (high risk), declared now and enforced for token principals already;
 * staff-session enforcement lands with Doc 03's resolver like every other
 * declaration.
 *
 * ROTATION IS CREATE-THEN-OVERLAP-THEN-REVOKE, exactly as FR-19 words it: the
 * rotate endpoint mints a REPLACEMENT (same service account, same abilities,
 * audited with `rotation_of`) and deliberately leaves the old token alive —
 * the overlap window is however long the operator needs to deploy the new
 * credential, and closing it is the explicit revoke call. SS-04's zero-failed-
 * calls property falls out: at no instant between "replacement exists" and
 * "operator chose to revoke" is either credential dead.
 *
 * Scoped to machine tokens BY QUERY: every lookup joins "owner is a service
 * account", so a personal token's id presented here 404s — this surface
 * cannot manage what it does not govern.
 */
final class MachineTokensController extends Controller
{
    public function __construct(
        private readonly TokenMinter $minter,
        private readonly TokenRevoker $revoker,
        private readonly ServiceAccountProvisioner $serviceAccounts,
        private readonly PersonalTokensController $shared,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tokens = $this->machineTokens()
            ->orderByDesc('id')
            ->get()
            ->map(function (PersonalAccessToken $token): array {
                $owner = $token->tokenable;

                return $this->shared->present($token) + [
                    'service_account' => $owner instanceof User ? $owner->name : null,
                ];
            })
            ->all();

        return new JsonResponse(['tokens' => $tokens]);
    }

    public function store(Request $request): Response
    {
        $admin = $this->admin($request);

        $refusal = $this->shared->validateTokenRequest($request);

        if ($refusal !== null) {
            return $refusal;
        }

        $serviceAccountName = $request->string('service_account')->toString();

        if (trim($serviceAccountName) === '' || mb_strlen($serviceAccountName) > 80) {
            return ProblemDetails::response(
                code: ErrorCode::ValidationFailed,
                status: 422,
                detail: (string) __('foundation.error.validation_failed'),
                errors: ['service_account' => [(string) __('validation.required', ['attribute' => 'service_account'])]],
            );
        }

        $now = CarbonImmutable::now();
        $account = $this->serviceAccounts->findOrCreate($serviceAccountName, $now, $admin->identifier());

        // BR-07 (S19): a service account holds a REAL role like anyone. A new
        // account without one would mint dead tokens (implicit deny × T-05
        // intersection = nothing), so creation requires `role_id`; reuse keeps
        // the current role unless the caller names a different one.
        $roleId = $request->string('role_id')->toString();
        $role = $roleId === '' ? null : \App\Models\Role::query()->whereKey($roleId)->first();
        if ($role === null && ($roleId !== '' || $account->getAttribute('role_id') === null)) {
            return ProblemDetails::response(
                code: ErrorCode::ValidationFailed,
                status: 422,
                detail: (string) __('foundation.error.validation_failed'),
                errors: ['role_id' => [(string) __('validation.required', ['attribute' => 'role_id'])]],
            );
        }
        if ($role !== null) {
            $this->serviceAccounts->assignRole($account, $role, $admin->identifier());
        }

        /** @var list<string> $abilities */
        $abilities = $request->input('abilities');

        $minted = $this->minter->mint(
            owner: $account,
            name: $request->string('name')->toString(),
            abilities: $abilities,
            expiryDays: $this->shared->expiryDays($request),
            now: $now,
            actorId: $admin->identifier(),
        );

        return new JsonResponse([
            'token' => $this->shared->present($minted->token),
            'service_account' => $account->name,
            'plaintext' => $minted->plaintext,
        ], 201);
    }

    /**
     * FR-19's rotation: mint the replacement, keep the old one alive for the
     * overlap window. The response names the predecessor so the caller knows
     * exactly which token to revoke once the new credential is deployed.
     */
    public function rotate(Request $request, string $tokenId): Response
    {
        $admin = $this->admin($request);

        /** @var PersonalAccessToken|null $old */
        $old = $this->machineTokens()->whereKey($tokenId)->whereNull('revoked_at')->first();

        if ($old === null) {
            abort(404);
        }

        $owner = $old->tokenable;

        if (! $owner instanceof User) {
            abort(404);
        }

        /** @var list<string> $abilities */
        $abilities = is_array($old->abilities) ? $old->abilities : [];

        // Same expiry DURATION the old token was created with (or none), so a
        // 90-day token rotates into a 90-day token without re-stating policy.
        $expiryDays = null;

        if ($old->expires_at !== null && $old->created_at !== null) {
            $expiryDays = (int) round($old->created_at->diffInDays($old->expires_at));
        }

        $minted = $this->minter->mint(
            owner: $owner,
            name: (string) $old->name,
            abilities: $abilities,
            expiryDays: $expiryDays,
            now: CarbonImmutable::now(),
            actorId: $admin->identifier(),
            rotationOf: $old->identifier(),
        );

        return new JsonResponse([
            'token' => $this->shared->present($minted->token),
            'plaintext' => $minted->plaintext,
            'replaces' => $old->identifier(),
            // The old token is NOT revoked here — that is the overlap window.
            'overlap_note' => 'rotation_overlap_active',
        ], 201);
    }

    public function destroy(Request $request, string $tokenId): Response
    {
        $admin = $this->admin($request);

        /** @var PersonalAccessToken|null $token */
        $token = $this->machineTokens()->whereKey($tokenId)->whereNull('revoked_at')->first();

        if ($token === null) {
            abort(404);
        }

        $this->revoker->revoke($token, 'revoked_by_admin', CarbonImmutable::now(), $admin->identifier());

        return new JsonResponse(['status' => 'token_revoked']);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<PersonalAccessToken>
     */
    private function machineTokens(): \Illuminate\Database\Eloquent\Builder
    {
        return PersonalAccessToken::query()
            ->where('tokenable_type', (new User)->getMorphClass())
            ->whereIn('tokenable_id', User::query()->whereNotNull('service_account_at')->select('id'));
    }

    private function admin(Request $request): User
    {
        $user = $request->user('staff');

        if (! $user instanceof User) {
            abort(401);
        }

        return $user;
    }
}
