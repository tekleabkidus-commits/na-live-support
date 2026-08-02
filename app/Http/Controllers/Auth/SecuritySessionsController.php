<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\User;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\Sessions\SessionMetadataStore;
use App\Support\Auth\Sessions\SessionRegistry;
use App\Support\Auth\Sessions\SessionRevoker;
use App\Support\Problem\ErrorCode;
use App\Support\Problem\ProblemDetails;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Security page's data and actions (Doc 02 FR-12, US-03, §8).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * SELF-SERVICE BY CONSTRUCTION. Every read and revoke here starts from the
 * AUTHENTICATED user's own session index — there is no parameter that names
 * another user, so "whose sessions" is never a decision this controller makes.
 * That is also why these routes carry no permission key: Doc 03's catalog is
 * closed and deliberately has no profile.* entry; a session's owner may always
 * see and end their own sessions, exactly as they may always log out (see
 * ci/permission-exempt-routes.php).
 *
 * RAW SESSION IDS NEVER LEAVE THE SERVER. A session id IS the credential, and
 * an API that hands a browser its sibling sessions' raw ids would turn one XSS
 * into a harvest of every session the user has. Rows are keyed by a SHA-256
 * digest instead; the revoke endpoints accept the digest and resolve it against
 * the caller's own live list. An unknown digest is a 404 — indistinguishable
 * from "expired meanwhile", which it usually is.
 *
 * "CURRENT" IS COMPUTED, NOT CLAIMED: the row whose digest matches the digest
 * of the id inside the caller's own cookie. The current session is refused by
 * the single-revoke route — ending it has a dedicated, CSRF-guarded verb
 * (logout) and a "you have been signed out" UX; silently sawing off the branch
 * the user is sitting on has neither.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class SecuritySessionsController extends Controller
{
    public function __construct(
        private readonly SessionRegistry $sessions,
        private readonly SessionMetadataStore $metadata,
        private readonly SessionRevoker $revoker,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->user();
        $tenantId = $user->tenantId();
        $currentDigest = self::digest($request->session()->getId());

        $rows = [];
        $withActivity = $this->sessions->liveSessionsWithActivity(
            $tenantId,
            $user->identifier(),
            assumeLive: $request->session()->getId(),
        );

        foreach ($withActivity as $sessionId => $lastActive) {
            $meta = $this->metadata->get($tenantId, $sessionId);
            $device = $meta !== null && $meta['device_id'] !== ''
                ? Device::query()->find($meta['device_id'])
                : null;

            $digest = self::digest($sessionId);

            $rows[] = [
                'digest' => $digest,
                'current' => $digest === $currentDigest,
                'device_label' => $meta['device_label'] ?? '',
                'ip' => $meta['ip'] ?? null,
                // Null is FS-04's "location unavailable" — the client renders
                // copy for it; the API states the fact.
                'location' => $meta['city'] ?? null,
                'country_code' => $meta['country_code'] ?? null,
                'signed_in_at' => $meta['signed_in_at'] ?? null,
                'first_seen_at' => $device?->first_seen_at?->toIso8601String(),
                'last_active_at' => CarbonImmutable::createFromTimestamp($lastActive, 'UTC')->toIso8601String(),
            ];
        }

        // §8: current device pinned first, then most recently active.
        usort($rows, static function (array $a, array $b): int {
            if ($a['current'] !== $b['current']) {
                return $a['current'] ? -1 : 1;
            }

            return strcmp((string) $b['last_active_at'], (string) $a['last_active_at']);
        });

        return new JsonResponse(['sessions' => $rows]);
    }

    public function destroy(Request $request, string $digest): JsonResponse
    {
        $user = $this->user();
        $tenantId = $user->tenantId();
        $currentSessionId = $request->session()->getId();

        if ($digest === self::digest($currentSessionId)) {
            return ProblemDetails::response(
                code: ErrorCode::ValidationFailed,
                status: 422,
                detail: __('auth.devices.cannot_revoke_current'),
            );
        }

        $sessionId = $this->resolveDigest($tenantId, $user->identifier(), $digest, $currentSessionId);

        if ($sessionId === null) {
            return ProblemDetails::response(
                code: ErrorCode::ValidationFailed,
                status: 404,
                detail: __('auth.devices.session_not_found'),
            );
        }

        $this->revoker->revoke(
            $tenantId,
            $user->identifier(),
            $sessionId,
            reason: 'revoked_from_devices_screen',
            actorId: $user->identifier(),
        );

        return new JsonResponse(['status' => 'revoked']);
    }

    public function destroyOthers(Request $request): JsonResponse
    {
        $user = $this->user();
        $tenantId = $user->tenantId();

        $revoked = $this->revoker->revokeAllFor(
            $tenantId,
            $user->identifier(),
            reason: 'revoked_others_from_devices_screen',
            exceptSessionId: $request->session()->getId(),
            actorId: $user->identifier(),
        );

        // The bulk action audits ONCE, always — including the empty case, where
        // the per-session trail is necessarily silent. "An admin pressed revoke
        // all others and nothing was there to revoke" is still a fact the trail
        // must be able to state (and DeclareAuditAction verifies it does).
        $this->audit->record(
            actionKey: 'auth.session.revoked_others',
            entity: 'user',
            entityId: $user->identifier(),
            actorType: AuditActor::Staff,
            payload: ['count' => count($revoked), 'reason' => 'devices_screen'],
            actorId: $user->identifier(),
            tenantId: $tenantId,
        );

        return new JsonResponse(['status' => 'revoked', 'count' => count($revoked)]);
    }

    private function resolveDigest(
        string $tenantId,
        string $userId,
        string $digest,
        string $currentSessionId,
    ): ?string {
        foreach ($this->sessions->liveSessionsFor($tenantId, $userId, assumeLive: $currentSessionId) as $sessionId) {
            if (hash_equals(self::digest($sessionId), $digest)) {
                return $sessionId;
            }
        }

        return null;
    }

    private static function digest(string $sessionId): string
    {
        return hash('sha256', $sessionId);
    }

    private function user(): User
    {
        $user = auth('staff')->user();

        if (! $user instanceof User) {
            throw new \LogicException('Security routes require the staff guard; no principal is present.');
        }

        return $user;
    }
}
