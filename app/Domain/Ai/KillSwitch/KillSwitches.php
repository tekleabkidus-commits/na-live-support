<?php

declare(strict_types=1);

namespace App\Domain\Ai\KillSwitch;

use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Events\EventPublisher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Symfony\Component\Uid\UuidV7;

/**
 * FR-18/US-07/AC-08/SEC-05: the kill switches — per surface × channel ×
 * endpoint, EXACT blast radii. isOff() reads the table DIRECTLY (no cache:
 * "≤10 s" is "now"); a scope is off when ITS row, its channel-wide row
 * (endpoint NULL) or the all-row (channel NULL, endpoint NULL) says so —
 * and never because a NEIGHBOR does (AC-08's both-directions proof;
 * sabotage #9 leaks a neighbor).
 *
 * flip() is high-visibility (SEC-05): audit with actor + old/new, the
 * ai.killswitch.changed event, and flipped_at as a PINNED-PROVENANCE
 * BYTE-COPY of the flip's own audit-entry DB stamp (sabotage #16's
 * control: a wall-clock write here must turn the byte-equality test red).
 * The scope-grid UNIQUE NULLS NOT DISTINCT is the upsert arbiter.
 */
final class KillSwitches
{
    public const SURFACES = ['chatbot', 'copilot', 'supervisor'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
        private readonly EventPublisher $events,
    ) {}

    public function isOff(string $surface, ?string $channel, ?string $endpointRef): bool
    {
        return DB::table('ai_kill_switches')
            ->where('surface', $surface)
            ->where('is_off', true)
            ->where(function (Builder $query) use ($channel, $endpointRef): void {
                // exact endpoint row
                $query->where(function (Builder $q) use ($channel, $endpointRef): void {
                    $q->where('channel', $channel)->where('endpoint_ref', $endpointRef);
                })
                // channel-wide row
                    ->orWhere(function (Builder $q) use ($channel): void {
                        $q->where('channel', $channel)->whereNull('endpoint_ref');
                    })
                // the all-row
                    ->orWhere(function (Builder $q): void {
                        $q->whereNull('channel')->whereNull('endpoint_ref');
                    });
            })
            ->exists();
    }

    /** @return string the switch row id */
    /**
     * FR-18's grid + AC-08's banner (S09 step 12): every switch row, off or
     * on, with the "OFF since {time} by {actor}" facts read from the row
     * (flipped_at is the audit stamp's byte-copy). Read-only.
     *
     * @return list<array{id: string, surface: string, channel: ?string, endpoint_ref: ?string, is_off: bool, reason: ?string, flipped_at: ?string, flipped_by: ?string, flipped_by_name: ?string}>
     */
    public function grid(): array
    {
        $rows = DB::table('ai_kill_switches as k')
            ->leftJoin('users as u', 'u.id', '=', 'k.flipped_by')
            ->orderBy('k.surface')->orderBy('k.channel')->orderBy('k.endpoint_ref')
            ->get(['k.id', 'k.surface', 'k.channel', 'k.endpoint_ref', 'k.is_off', 'k.reason', DB::raw('k.flipped_at::text AS flipped_at'), 'k.flipped_by', 'u.name AS flipped_by_name']);

        $grid = [];
        foreach ($rows as $row) {
            if (! is_string($row->id) || ! is_string($row->surface)) {
                continue;
            }
            $grid[] = [
                'id' => $row->id, 'surface' => $row->surface,
                'channel' => is_string($row->channel) ? $row->channel : null,
                'endpoint_ref' => is_string($row->endpoint_ref) ? $row->endpoint_ref : null,
                'is_off' => (bool) $row->is_off, 'reason' => is_string($row->reason) ? $row->reason : null,
                'flipped_at' => is_string($row->flipped_at) ? $row->flipped_at : null,
                'flipped_by' => is_string($row->flipped_by) ? $row->flipped_by : null,
                'flipped_by_name' => is_string($row->flipped_by_name) ? $row->flipped_by_name : null,
            ];
        }

        return $grid;
    }

    /**
     * AC-08 "bannered": the OFF rows only — what the workspace strip shows.
     *
     * @return list<array{id: string, surface: string, channel: ?string, endpoint_ref: ?string, is_off: bool, reason: ?string, flipped_at: ?string, flipped_by: ?string, flipped_by_name: ?string}>
     */
    public function bannerState(): array
    {
        return array_values(array_filter($this->grid(), static fn (array $row): bool => $row['is_off']));
    }

    public function flip(string $surface, ?string $channel, ?string $endpointRef, bool $off, ?string $reason, string $actorId): string
    {
        if (! in_array($surface, self::SURFACES, true)) {
            throw new InvalidArgumentException(sprintf('unknown AI surface "%s" (FR-18)', $surface));
        }
        if ($channel === null && $endpointRef !== null) {
            throw new InvalidArgumentException('an endpoint scope needs its channel (FR-18 scope grid)');
        }

        return DB::transaction(function () use ($surface, $channel, $endpointRef, $off, $reason, $actorId): string {
            $tenantId = $this->context->tenantId();

            $existing = DB::table('ai_kill_switches')
                ->where('surface', $surface)
                ->where('channel', $channel)
                ->where('endpoint_ref', $endpointRef)
                ->first();

            $switchId = is_object($existing) && is_string($existing->id) ? $existing->id : (string) new UuidV7;
            $wasOff = is_object($existing) && (bool) $existing->is_off;

            // SEC-05: audit FIRST — its DB stamp is the provenance source.
            $this->audit->record(
                'ai.killswitch.changed',
                'ai_kill_switch',
                $switchId,
                AuditActor::Staff,
                [
                    'surface' => $surface, 'channel' => $channel, 'endpoint_ref' => $endpointRef,
                    'old' => ['is_off' => $wasOff], 'new' => ['is_off' => $off], 'reason' => $reason,
                ],
                actorId: $actorId,
            );

            /** @var object{created_at: string}|null $stamp */
            $stamp = DB::table('audit_log')
                ->where('action_key', 'ai.killswitch.changed')
                ->where('entity_id', $switchId)
                ->orderByDesc('created_at')
                ->first(['created_at']);
            if ($stamp === null) {
                throw new \RuntimeException('the kill-switch audit entry did not land — no provenance stamp to copy');
            }

            // The byte-copy discipline (S08 blocked_at idiom): flipped_at IS
            // the audit entry's own DB stamp.
            $columns = [
                'is_off' => $off,
                'reason' => $reason,
                'flipped_by' => $actorId,
                'flipped_at' => $stamp->created_at,
                'updated_at' => DB::raw('clock_timestamp()'),
            ];

            if (is_object($existing)) {
                DB::table('ai_kill_switches')->where('id', $switchId)->update($columns);
            } else {
                DB::table('ai_kill_switches')->insert($columns + [
                    'id' => $switchId, 'tenant_id' => $tenantId,
                    'surface' => $surface, 'channel' => $channel, 'endpoint_ref' => $endpointRef,
                ]);
            }

            $this->events->publish('ai.killswitch.changed', [
                'tenant_id' => $tenantId,
                'surface' => $surface,
                'channel' => $channel ?? 'all',
                'endpoint_ref' => $endpointRef ?? 'all',
                'is_off' => $off,
                'actor_id' => $actorId,
            ]);

            return $switchId;
        });
    }
}
