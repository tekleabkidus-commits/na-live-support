<?php

declare(strict_types=1);

namespace App\Domain\Routing\Manual;

use App\Domain\Org\Membership\EffectiveMembers;
use App\Domain\Routing\RoutingRefused;
use App\Models\User;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Rbac\CapabilityMapProvider;
use Illuminate\Support\Facades\DB;

/**
 * Doc 11 FR-15 (broadcast) — "broadcast note to queue members (event to Doc 16
 * notifications)". P-C5 (ruled as filed, 2026-08-30): CONTRACT-ONLY, [D16]. Doc 11
 * resolves the recipients (FR-04's effective members) and returns the delivery contract
 * `{queue_id, recipients, category, note}` for Doc 16; it writes NO bell rows (Doc 07's
 * `inbox_notifications` is the projector's — a second delivery path is the
 * second-implementation class) and emits no event of its own (FR-19 names none). The
 * contract's shape is pinned by `fixtures/routing/contracts/doc16-broadcast-delivery.json`.
 *
 * Audited `routing.broadcast_sent` with `recipient_count` ONLY — never the note text
 * (the registry's own rule). SEC-01: `supervision.broadcast` and the queue within the
 * supervisor's scope.
 */
final class Broadcast
{
    public const CATEGORY = 'notify.routing.broadcast';

    public function __construct(
        private readonly EffectiveMembers $members,
        private readonly CapabilityMapProvider $maps,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array{queue_id: string, recipients: list<string>, category: string, note: string} */
    public function send(User $supervisor, string $queueId, string $note): array
    {
        /** @var object{department_id: string}|null $queue */
        $queue = DB::table('queues')->where('id', $queueId)->whereNull('deleted_at')->first(['department_id']);
        if ($queue === null) {
            throw new RoutingRefused('queue_not_found', 'no live queue');
        }
        if (! $this->maps->verdictFor($supervisor, 'supervision.broadcast')->allowed) {
            throw new RoutingRefused('forbidden', 'supervision.broadcast required');
        }
        if (! $this->members->reaches($supervisor, $queue->department_id)) {
            throw new RoutingRefused('queue_out_of_scope', 'the queue lies outside the supervisor\'s scope (SEC-01)');
        }

        $recipients = array_values(array_unique(array_map(static fn (array $m): string => $m['user_id'], $this->members->members($queueId))));
        sort($recipients);

        $this->audit->record('routing.broadcast_sent', 'queue', $queueId, AuditActor::Staff, [
            'queue_id' => $queueId, 'recipient_count' => count($recipients),
        ], actorId: $supervisor->identifier());

        return ['queue_id' => $queueId, 'recipients' => $recipients, 'category' => self::CATEGORY, 'note' => $note];
    }
}
