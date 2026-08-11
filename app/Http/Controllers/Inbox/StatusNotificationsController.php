<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inbox;

use App\Domain\Inbox\Status\AgentStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * FR-26 (the bell) + FR-28 (agent status) doors — S07 step 12.
 *
 * Bell reads ride the audience key; mark-all-read is a per-user
 * workspace-STATE write and rides views.manage per the R-26 AMENDMENT (the
 * FIFTH sibling — cited, not re-ruled; flagged at the step-12 checkpoint).
 * Status set-own is the SAME class (SIXTH sibling, same citation) — a Read
 * Only principal is never routable, so its exclusion is coherent routing
 * truth, not just sweep hygiene. The FORCE door is Doc 03's
 * supervision.agent_status.force and AUDITS with attribution.
 */
final class StatusNotificationsController extends Controller
{
    public function __construct(
        private readonly AgentStatus $status,
        private readonly AuditLogger $audit,
    ) {}

    public function notifications(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var list<object> $rows */
        $rows = DB::table('inbox_notifications')
            ->where('user_id', $user->identifier())
            ->orderByDesc('created_at')
            ->limit(50)
            ->get(['id', 'category', 'ticket_id', 'payload', 'read_at', 'created_at'])
            ->all();

        return new JsonResponse([
            'notifications' => array_map(function (object $row): array {
                /** @var array<string, mixed> $data */
                $data = (array) $row;
                $data['payload'] = is_string($row->payload ?? null) ? json_decode((string) $row->payload, true, 8, JSON_THROW_ON_ERROR) : [];

                return $data;
            }, $rows),
            'unread' => DB::table('inbox_notifications')->where('user_id', $user->identifier())->whereNull('read_at')->count(),
        ]);
    }

    /** The R-19b grant allows UPDATE of read_at ONLY — physics, not manners. */
    public function markAllRead(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        DB::table('inbox_notifications')
            ->where('user_id', $user->identifier())
            ->whereNull('read_at')
            ->update(['read_at' => DB::raw('clock_timestamp()')]);

        return new JsonResponse(['ok' => true]);
    }

    public function currentStatus(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return new JsonResponse($this->status->current($user->identifier()));
    }

    public function setStatus(Request $request): JsonResponse
    {
        /** @var array{status: string, reason?: string} $input */
        $input = $request->validate([
            'status' => ['required', 'string', 'in:online,away,offline'],
            'reason' => ['sometimes', 'string', 'in:manual,auto_idle,logout'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $this->status->set($user, $input['status'], $input['reason'] ?? 'manual');

        return new JsonResponse($this->status->current($user->identifier()));
    }

    public function heartbeat(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->status->heartbeat($user);

        return new JsonResponse(['ok' => true]);
    }

    /** Doc 03 supervision.agent_status.force — audited, attributed. */
    public function forceStatus(Request $request, string $userId): JsonResponse
    {
        /** @var array{status: string} $input */
        $input = $request->validate(['status' => ['required', 'string', 'in:online,away,offline']]);

        /** @var User $supervisor */
        $supervisor = $request->user();

        if (! DB::table('users')->where('id', $userId)->exists()) {
            return new JsonResponse(['ok' => false], 404);
        }

        $this->status->force($supervisor, $userId, $input['status']);

        // SEC-03: actor + target + the forced state (the DeclareAuditAction
        // handler-writes-the-entry pattern).
        $this->audit->record(
            'agent.status_forced',
            'user',
            $userId,
            \App\Support\Audit\AuditActor::Staff,
            ['status' => $input['status']],
            $supervisor->identifier(),
        );

        return new JsonResponse($this->status->current($userId));
    }
}
