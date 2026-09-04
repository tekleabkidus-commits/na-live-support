<?php

declare(strict_types=1);

namespace App\Jobs\Webchat;

use App\Domain\Routing\Estimator\Estimator;
use App\Domain\Routing\Waiting\WaitingSets;
use App\Domain\Webchat\Realtime\WebchatPusher;
use App\Domain\Webchat\WebchatAdapter;
use App\Jobs\OutboxConsumer;
use App\Support\Cache\TenantCache;
use Illuminate\Support\Facades\DB;

/**
 * Doc13/QueuePositionPush (Doc 13 T-05 / FR-12; P-QPOS countersigned with
 * its fan-out condition; ADR 0011): on `ticket.waiting`, `ticket.assigned`
 * and `routing.starvation_boosted` the queue's WAITING webchat visitors
 * learn their own position — because positions of OTHER tickets move when
 * one is placed, assigned or boosted.
 *
 * BOUNDED FAN-OUT (the countersign's condition 1): ONE job per event; ONE
 * ordered read of the whole waiting set (`WaitingSets::head`, the ranks are
 * the list indexes); ONE query keeps the webchat members of that list; a
 * push goes ONLY to a visitor whose position, band or opens_at CHANGED since
 * the last push (fingerprint in TenantCache, TTL 1 h) — a busy queue with
 * nothing moved produces zero frames. The figure is recorded as a §12
 * dev-scale row at the step's commit.
 *
 * The WIDGET's rendering law (EC-06) is that the number never rises on
 * screen; this pushes the truth. Polling parity: the sync envelope's
 * `queue` object reads the same `Estimator::forWaitingTicket`.
 */
final class QueuePositionPush extends OutboxConsumer
{
    private const FINGERPRINT_TTL = 3600;

    protected function queueFamily(): string
    {
        return 'webchat-out';
    }

    /** @return int frames pushed (the fan-out figure; tests read it) */
    public function handle(Estimator $estimator, WaitingSets $waiting, WebchatPusher $pusher, TenantCache $cache): int
    {
        /** @var object{name: string, payload: string}|null $event */
        $event = DB::table('events')->where('id', $this->eventId)->first(['name', 'payload']);
        if ($event === null) {
            return 0;
        }
        /** @var array<string, mixed> $payload */
        $payload = json_decode($event->payload, true, 16, JSON_THROW_ON_ERROR);
        // D14 / FR-13: an ASSIGNMENT wakes the assigned visitor too — the queue card clears and the
        // "joined" line renders with the agent's identity per `widget.agent_identity` (the frame is the
        // wake-up; the cursor page stays the record). The waiting visitors are recomputed below.
        $pushed = 0;
        if ($event->name === 'ticket.assigned' && is_string($payload['new_assignee_id'] ?? null) && is_string($payload['ticket_id'] ?? null)) {
            $pushed += $this->pushJoined($pusher, $cache, $payload['ticket_id'], $payload['new_assignee_id']);
        }
        $queueId = $this->queueOf($payload);
        if ($queueId === null) {
            return $pushed;
        }

        $depth = $waiting->depth($queueId);
        if ($depth === 0) {
            return $pushed;
        }
        $ordered = $waiting->head($queueId, $depth);   // the ONE ordered read — index + 1 is the rank

        /** @var array<string, object{id: string, external_chat_id: string}> $webchat */
        $webchat = DB::table('tickets as t')
            ->join('conversations as c', 'c.id', '=', 't.conversation_id')
            ->whereIn('t.id', $ordered)->whereNull('t.assignee_id')
            ->where('c.channel', WebchatAdapter::CHANNEL)
            ->get(['t.id', 'c.external_chat_id'])->keyBy('id')->all();   // the ONE membership query

        foreach ($ordered as $index => $ticketId) {
            $row = $webchat[$ticketId] ?? null;
            if ($row === null) {
                continue;
            }
            $estimate = $estimator->forWaitingTicket($queueId, $ticketId);
            $position = $estimate['position'] ?? $index + 1;
            $fingerprint = $position.'|'.$estimate['band'].'|'.($estimate['opens_at'] ?? '');
            $key = 'webchat-queue-pushed:'.$ticketId;
            if ($cache->get($key) === $fingerprint) {
                continue;   // nothing moved for this visitor — no frame
            }
            $pusher->toVisitor($row->external_chat_id, WebchatPusher::SUFFIX_QUEUE, 'webchat.queue', [
                'ticket_ref' => $ticketId,
                'band' => $estimate['band'],
                'position' => $position,
                'opens_at' => $estimate['opens_at'],
            ]);
            $cache->put($key, $fingerprint, self::FINGERPRINT_TTL, 'ticket.assigned');
            $pushed++;
        }

        return $pushed;
    }

    /** The assigned webchat visitor's own frame: no position, no band, the agent's display per the identity setting. */
    private function pushJoined(WebchatPusher $pusher, TenantCache $cache, string $ticketId, string $assigneeId): int
    {
        /** @var object{external_chat_id: string}|null $row */
        $row = DB::table('tickets as t')->join('conversations as c', 'c.id', '=', 't.conversation_id')
            ->where('t.id', $ticketId)->where('c.channel', WebchatAdapter::CHANNEL)->first(['c.external_chat_id']);
        if ($row === null) {
            return 0;
        }
        $mode = app(\App\Support\Settings\SettingsRepository::class)->get('widget.agent_identity');
        $name = DB::table('users')->where('id', $assigneeId)->value('name');
        $display = null;
        if ($mode === 'team_name') {
            $team = DB::table('team_members as tm')->join('teams as tt', 'tt.id', '=', 'tm.team_id')->where('tm.user_id', $assigneeId)->value('tt.name');
            $display = is_string($team) ? $team : null;   // null → the client shows its team label
        } elseif (is_string($name) && trim($name) !== '') {
            // first_name; `alias` has no column of its own yet (Doc 03/07 own the profile) — the first name stands in, filed.
            $display = explode(' ', trim($name))[0];
        }
        $pusher->toVisitor($row->external_chat_id, WebchatPusher::SUFFIX_QUEUE, 'webchat.queue', [
            'ticket_ref' => $ticketId,
            'band' => null,
            'position' => null,
            'opens_at' => null,
            'joined' => ['display' => $display],
        ]);
        $cache->forget('webchat-queue-pushed:'.$ticketId);

        return 1;
    }

    /** @param array<string, mixed> $payload */
    private function queueOf(array $payload): ?string
    {
        foreach (['queue_id', 'queue'] as $key) {
            $queue = $payload[$key] ?? null;
            if (is_string($queue) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $queue) === 1) {
                return $queue;
            }
        }
        $ticketId = $payload['ticket_id'] ?? null;
        if (! is_string($ticketId)) {
            return null;
        }
        $queueId = DB::table('tickets')->where('id', $ticketId)->value('queue_id');

        return is_string($queueId) ? $queueId : null;
    }
}
