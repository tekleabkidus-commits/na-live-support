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

        // The render data resolves from TRUTH at read time (the row stores
        // identifiers only — Q7): the ticket number from the canonical list
        // row, the mention's actor name from the minting event's author.
        /** @var list<object> $rows */
        $rows = DB::table('inbox_notifications as n')
            ->leftJoin('ticket_list_items as t', 't.ticket_id', '=', 'n.ticket_id')
            ->leftJoin('events as e', DB::raw("(n.payload->>'event_id')::uuid"), '=', 'e.id')
            ->leftJoin('users as a', DB::raw("(e.payload->>'author_id')::uuid"), '=', 'a.id')
            ->where('n.user_id', $user->identifier())
            ->orderByDesc('n.created_at')
            ->limit(50)
            ->get([
                'n.id', 'n.category', 'n.ticket_id', 'n.conversation_id', 'n.payload', 'n.read_at', 'n.created_at',
                't.number as ticket_number', 'a.name as actor_name',
            ])
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

        return new JsonResponse($this->withAttributionName($this->status->current($user->identifier())));
    }

    /**
     * FR-28's attribution render data: "set by Dawit" needs the NAME; the id
     * stays server-side.
     *
     * @param  array{status: string, reason: string|null, since: string|null, set_by_user_id: string|null}  $current
     * @return array<string, mixed>
     */
    private function withAttributionName(array $current): array
    {
        $setByName = null;
        if (is_string($current['set_by_user_id'])) {
            /** @var string|null $setByName */
            $setByName = DB::table('users')->where('id', $current['set_by_user_id'])->value('name');
        }

        return ['status' => $current['status'], 'reason' => $current['reason'], 'since' => $current['since'], 'set_by_name' => $setByName];
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

        return new JsonResponse($this->withAttributionName($this->status->current($user->identifier())));
    }

    public function heartbeat(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->status->heartbeat($user);

        // FS-01 (step 14): the staleness pill's live feed rides the 30 s
        // heartbeat the workspace already sends — no new request. Beat-age
        // ALONE lies on a quiet stack (no events ≠ a dead projector), so the
        // door also answers WHETHER THERE IS ANYTHING TO MISS: an unrelayed
        // outbox row older than the threshold, or an event newer than the
        // last applied beat. The pill shows only for staleness WITH pending
        // work — "projector down", never "nothing happened".
        $projector = app(\App\Domain\Inbox\ReadModels\ProjectorStatus::class);
        $stale = $projector->staleSeconds();
        $pending = false;
        if ($stale !== null && $stale > \App\Domain\Inbox\ReadModels\ProjectorStatus::STALE_AFTER_SECONDS) {
            $pending = \Illuminate\Support\Facades\DB::table('outbox')
                ->whereNull('relayed_at')
                ->where('occurred_at', '<', \Illuminate\Support\Facades\DB::raw(sprintf("clock_timestamp() - interval '%d seconds'", \App\Domain\Inbox\ReadModels\ProjectorStatus::STALE_AFTER_SECONDS)))
                ->exists()
                || \Illuminate\Support\Facades\DB::table('events')
                    ->where('occurred_at', '>', \Illuminate\Support\Facades\DB::raw(sprintf("clock_timestamp() - interval '%d seconds'", max(1, $stale))))
                    ->exists();
        }

        return new JsonResponse([
            'ok' => true,
            'projector_stale_seconds' => $stale,
            'projector_pending' => $pending,
        ]);
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
