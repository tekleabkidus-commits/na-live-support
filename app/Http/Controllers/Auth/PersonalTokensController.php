<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Auth\Tokens\TokenMinter;
use App\Domain\Auth\Tokens\TokenRevoker;
use App\Http\Controllers\Controller;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Support\Problem\ErrorCode;
use App\Support\Problem\ProblemDetails;
use App\Support\Rbac\PermissionRegistry;
use App\Support\Settings\SettingsRepository;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Personal access tokens, self-service (Doc 02 FR-18).
 *
 * SELF-SERVICE MEANS SELF-SCOPED BY CONSTRUCTION: every query starts from
 * `$request->user()->tokens()`, so no parameter can name another user's token —
 * the same shape as the sessions routes, and the reason these routes are
 * permission-exempt (Doc 03 §4.1's catalog was checked per the step-9 ruling:
 * it contains no personal-token key; apikeys.machine.manage governs MACHINE
 * tokens only and is enforced on that controller).
 *
 * The plaintext appears in exactly one response, at creation (FR-18 "plaintext
 * shown once"): the row keeps only the SHA-256, so there is nothing to re-show.
 */
final class PersonalTokensController extends Controller
{
    public function __construct(
        private readonly TokenMinter $minter,
        private readonly TokenRevoker $revoker,
        private readonly PermissionRegistry $registry,
        private readonly SettingsRepository $settings,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->principal($request);

        $tokens = $user->tokens()
            ->orderByDesc('id')
            ->get()
            ->map(fn (PersonalAccessToken $token): array => $this->present($token))
            ->all();

        return new JsonResponse(['tokens' => $tokens]);
    }

    public function store(Request $request): Response
    {
        $user = $this->principal($request);

        $refusal = $this->validateTokenRequest($request);

        if ($refusal !== null) {
            return $refusal;
        }

        /** @var list<string> $abilities */
        $abilities = $request->input('abilities');

        $minted = $this->minter->mint(
            owner: $user,
            name: $request->string('name')->toString(),
            abilities: $abilities,
            expiryDays: $this->expiryDays($request),
            now: CarbonImmutable::now(),
        );

        return new JsonResponse([
            'token' => $this->present($minted->token),
            // The one appearance (FR-18). Not stored, not logged, not
            // retrievable — SEC-08's scrubber gate covers the log half.
            'plaintext' => $minted->plaintext,
        ], 201);
    }

    public function destroy(Request $request, string $tokenId): Response
    {
        $user = $this->principal($request);

        /** @var PersonalAccessToken|null $token */
        $token = $user->tokens()->whereKey($tokenId)->whereNull('revoked_at')->first();

        // Unknown, someone else's, and already-revoked are indistinguishable
        // (the C-10 posture, applied within a tenant).
        if ($token === null) {
            abort(404);
        }

        $this->revoker->revoke($token, 'self_revoked', CarbonImmutable::now(), $user->identifier());

        return new JsonResponse(['status' => 'token_revoked']);
    }

    /**
     * Shared request validation for FR-18's creation shape — also used by the
     * machine controller (FR-19 "same scope model").
     */
    public function validateTokenRequest(Request $request): ?Response
    {
        $errors = [];

        $name = $request->input('name');

        if (! is_string($name) || trim($name) === '' || mb_strlen($name) > 120) {
            $errors['name'] = [(string) __('validation.required', ['attribute' => 'name'])];
        }

        $abilities = $request->input('abilities');

        if (! is_array($abilities) || $abilities === []) {
            $errors['abilities'] = [(string) __('validation.required', ['attribute' => 'abilities'])];
        } else {
            foreach ($abilities as $ability) {
                // FR-18: scopes come from the catalog. Naming the offending
                // key is safe — the catalog is not a secret from the tenant's
                // own staff.
                if (! is_string($ability) || ! $this->registry->isRegistered($ability)) {
                    $errors['abilities'] = [(string) __('validation.in', ['attribute' => 'abilities'])];

                    break;
                }
            }
        }

        $expiry = $request->input('expiry');

        if (! in_array($expiry, ['30', '90', '365', 'none', 30, 90, 365], true)) {
            $errors['expiry'] = [(string) __('validation.in', ['attribute' => 'expiry'])];
        } elseif ($expiry === 'none' && $this->settings->get('auth.token.allow_no_expiry') !== true) {
            // FR-18's policy half: the tenant has turned non-expiring tokens off.
            $errors['expiry'] = [(string) __('validation.in', ['attribute' => 'expiry'])];
        }

        if ($errors !== []) {
            return ProblemDetails::response(
                code: ErrorCode::ValidationFailed,
                status: 422,
                detail: (string) __('foundation.error.validation_failed'),
                errors: $errors,
            );
        }

        return null;
    }

    public function expiryDays(Request $request): ?int
    {
        $expiry = $request->input('expiry');

        if ($expiry === 'none') {
            return null;
        }

        if (! is_numeric($expiry)) {
            // validateTokenRequest() ran first on every path; reaching here
            // without it is a programming error, not a user error.
            throw new \LogicException('expiryDays() called before validateTokenRequest().');
        }

        return (int) $expiry;
    }

    /**
     * @return array<string, mixed>
     */
    public function present(PersonalAccessToken $token): array
    {
        return [
            'id' => $token->identifier(),
            'name' => $token->name,
            'abilities' => $token->abilities,
            'last_used_at' => $token->last_used_at?->toIso8601String(),
            'expires_at' => $token->expires_at?->toIso8601String(),
            'revoked_at' => $token->revoked_at?->toIso8601String(),
            'created_at' => $token->created_at?->toIso8601String(),
        ];
    }

    private function principal(Request $request): User
    {
        $user = $request->user('staff');

        if (! $user instanceof User) {
            abort(401);
        }

        return $user;
    }
}
