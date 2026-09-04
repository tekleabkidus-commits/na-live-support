<?php

declare(strict_types=1);

namespace App\Jobs\Webchat;

use App\Jobs\OutboxConsumer;
use App\Support\Settings\SettingsRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Doc13/ChannelSurfaces — the catalog's DECLARED consumer of
 * channel.health.changed, implemented (A-45): the widget Health tab's
 * channel indicator. The event's channel_ref_id IS the widget id for the
 * webchat channel — a ref naming no widget row writes nothing, so no channel
 * literal is needed (N-20). Lands on the CURRENT day's counters row (C2's day
 * bucket, from the DB clock at the tenant's display timezone). C-09: the
 * same status re-applied is the same row.
 */
final class ChannelHealthConsumer extends OutboxConsumer
{
    protected function queueFamily(): string
    {
        return 'webchat-in';
    }

    public function handle(SettingsRepository $settings): void
    {
        /** @var object{name: string, payload: string}|null $event */
        $event = DB::table('events')->where('id', $this->eventId)->first(['name', 'payload']);
        if ($event === null || $event->name !== 'channel.health.changed') {
            return;
        }
        /** @var array<string, mixed> $payload */
        $payload = json_decode($event->payload, true, 16, JSON_THROW_ON_ERROR);
        $ref = $payload['channel_ref_id'] ?? null;
        $status = $payload['status'] ?? null;
        if (! is_string($ref) || ! is_string($status) || ! in_array($status, ['healthy', 'degraded', 'down'], true)) {
            return;
        }
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $ref) !== 1) {
            return; // not a widget id (UUIDv7 — Str::isUuid's version gate is not trusted here)
        }
        /** @var object{id: string, tenant_id: string}|null $widget */
        $widget = DB::table('widgets')->where('id', $ref)->first(['id', 'tenant_id']);
        if ($widget === null) {
            return;
        }

        $tz = $settings->get('tenant.display_timezone');
        $tz = is_string($tz) && $tz !== '' ? $tz : 'UTC';
        /** @var object{day: string} $bucket */
        $bucket = DB::selectOne('SELECT (clock_timestamp() AT TIME ZONE ?)::date AS day', [$tz]);

        $updated = DB::table('widget_health_counters')->where('widget_id', $widget->id)->where('day', $bucket->day)
            ->update(['channel_status' => $status, 'channel_status_at' => DB::raw('clock_timestamp()'), 'updated_at' => DB::raw('clock_timestamp()')]);
        if ($updated === 0) {
            DB::table('widget_health_counters')->insert([
                'id' => (string) Str::uuid7(), 'tenant_id' => $widget->tenant_id, 'widget_id' => $widget->id, 'day' => $bucket->day,
                'channel_status' => $status, 'channel_status_at' => DB::raw('clock_timestamp()'),
            ]);
        }
    }
}
