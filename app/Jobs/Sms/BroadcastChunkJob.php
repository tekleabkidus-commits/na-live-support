<?php

declare(strict_types=1);

namespace App\Jobs\Sms;

use App\Domain\Sms\Broadcasts\AudienceQuery;
use App\Domain\Sms\Broadcasts\BroadcastWriter;
use App\Domain\Sms\Catalog\SendableEvent;
use App\Domain\Sms\Pipeline\EnqueueSms;
use App\Domain\Sms\Pipeline\SendRequest;
use App\Jobs\Middleware\BindTenantContextToJob;
use App\Models\SmsBroadcast;
use App\Models\User;
use App\Support\Events\EventPublisher;
use App\Support\Tenancy\TenantAware;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * T-07 — the broadcast chunker (500/chunk on the `sms-broadcast` lane, the
 * four-supervisor physics keeping announcements out of every other queue):
 * each chunk re-reads the broadcast (a CANCEL stops the un-sent remainder
 * cold), re-resolves the LAUNCHER's live scope (Doc 03 FR-06 job semantics
 * — a demoted or deactivated launcher's broadcast stops), and walks every
 * recipient through the ONE EnqueueSms door — per-recipient LIVE guards
 * (EC-07: a STOP mid-run skips every later chunk's member; per-recipient
 * guard logs on the rows), idempotent per (broadcast, customer) so a
 * re-driven chunk lands nothing twice (C-09 by state). Progress publishes
 * ONCE PER CHUNK, never per recipient.
 *
 * EC-11's quota wall is the `BroadcastQuotaGate` seam: at step 10 it always
 * allows (the meter's SMS case is P-QUOTA-SMS, landing at step 11); the
 * pause → `paused_quota` → resume state machine is already provable through
 * the seam. At the wall the un-sent remainder waits; a later chunk resumes.
 */
final class BroadcastChunkJob implements ShouldQueue
{
    use Queueable, TenantAware;

    public const CHUNK = 500;

    public int $tries = 10;

    public function __construct(public readonly string $broadcastId, public readonly ?string $afterCustomerId)
    {
        $this->captureTenant();
        $this->onQueue('sms-broadcast');
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new BindTenantContextToJob];
    }

    public function handle(AudienceQuery $audience, BroadcastWriter $writer, EnqueueSms $enqueue, EventPublisher $events): void
    {
        /** @var SmsBroadcast|null $broadcast */
        $broadcast = SmsBroadcast::query()->find($this->broadcastId);
        if ($broadcast === null || $broadcast->state !== 'sending') {
            return; // canceled, paused or done between chunks: the remainder never sends
        }

        /** @var User|null $launcher */
        $launcher = $broadcast->launched_by === null ? null : User::query()->find($broadcast->launched_by);
        if ($launcher === null) {
            // the scope wall fails CLOSED: no launcher, no scope, no audience — the
            // system cancels (its own audit actor; the writer's door needs a staff actor)
            Log::warning('sms broadcast: launcher gone — canceled by the system (the scope wall fails closed)', ['broadcast_id' => $broadcast->identifier()]);
            DB::transaction(function () use ($broadcast, $writer, $events): void {
                $remaining = max(0, $broadcast->total - $broadcast->sent - $broadcast->skipped - $broadcast->failed);
                DB::table('sms_broadcasts')->where('id', $broadcast->getKey())->update(['state' => 'canceled', 'cancel_requested_at' => DB::raw('clock_timestamp()'), 'updated_at' => DB::raw('clock_timestamp()')]);
                $broadcast->refresh();
                app(\App\Support\Audit\AuditLogger::class)->record('sms.broadcast.canceled', 'sms_broadcast', $broadcast->identifier(), \App\Support\Audit\AuditActor::System, ['broadcast_id' => $broadcast->identifier(), 'remaining' => $remaining]);
                $events->publish('sms.broadcast.canceled', $writer->eventPayload($broadcast->refresh(), null));
            });

            return;
        }

        if (! app(\App\Domain\Sms\Broadcasts\BroadcastQuotaGate::class)->allows()) {
            DB::transaction(function () use ($broadcast): void {
                $broadcast->fill(['state' => 'paused_quota']);
                $broadcast->save();
            });
            Log::warning('sms broadcast: paused at the quota wall (EC-11)', ['broadcast_id' => $broadcast->identifier(), 'metric' => 'sms.broadcast.paused_quota']);

            return;
        }

        $recipients = $audience->recipients($writer->filters($broadcast), $launcher, self::CHUNK, $this->afterCustomerId);
        $last = null;
        foreach ($recipients as $recipient) {
            $last = $recipient['customer_id'];
            $broadcast->refresh();
            if ($broadcast->state !== 'sending') {
                return; // a mid-chunk cancel stops cold
            }
            if (DB::table('sms_messages')->where('broadcast_id', $broadcast->identifier())->where('customer_id', $recipient['customer_id'])->exists()) {
                continue; // a re-driven chunk lands nothing twice (C-09 by state)
            }
            DB::transaction(fn () => $enqueue->enqueue(new SendRequest(
                SendableEvent::ServiceAnnouncement,
                $recipient['customer_id'],
                broadcastId: $broadcast->identifier(),
            )));
        }

        $this->refreshCounters($broadcast);
        $events->publish('sms.broadcast.progress', $writer->eventPayload($broadcast->refresh(), null)); // ONCE per chunk

        if (count($recipients) < self::CHUNK) {
            DB::transaction(function () use ($broadcast, $writer, $events): void {
                $broadcast->fill(['state' => 'done']);
                $broadcast->save();
                $events->publish('sms.broadcast.completed', $writer->eventPayload($broadcast->refresh(), null));
            });

            return;
        }
        self::dispatch($this->broadcastId, $last);
    }

    /** The counters are AGGREGATES of the rows — recomputed, never incremented (a re-drive cannot double-count). */
    private function refreshCounters(SmsBroadcast $broadcast): void
    {
        /** @var object{sent: int, skipped: int, failed: int, cost: ?string} $agg */
        $agg = DB::table('sms_messages')->where('broadcast_id', $broadcast->identifier())
            ->selectRaw("count(*) FILTER (WHERE state IN ('sent','delivered','undelivered','expired')) AS sent, count(*) FILTER (WHERE state = 'skipped') AS skipped, count(*) FILTER (WHERE state = 'failed') AS failed, sum(cost_amount) AS cost")
            ->first();
        DB::transaction(function () use ($broadcast, $agg): void {
            $broadcast->fill([
                'sent' => (int) $agg->sent,
                'skipped' => (int) $agg->skipped,
                'failed' => (int) $agg->failed,
                'cost_accrued' => $agg->cost ?? '0',
            ]);
            $broadcast->save();
        });
    }
}
