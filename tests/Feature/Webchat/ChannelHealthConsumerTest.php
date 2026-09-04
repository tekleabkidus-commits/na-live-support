<?php

declare(strict_types=1);

use App\Jobs\Webchat\ChannelHealthConsumer;
use App\Support\Events\EventPublisher;
use Illuminate\Support\Facades\DB;

/**
 * A-45 / STOP-O: the catalog DECLARES `Doc13/ChannelSurfaces` on
 * channel.health.changed — a declared consumer is implemented, never replaced
 * by a hook. The consumer writes the widget's Health-tab channel indicator
 * (widget_health_counters.channel_status on the current day's row, C2's day
 * bucket) for the webchat channel; other channels' health is not its business
 * (data at the edge — the widget id IS the channel_ref_id, no channel literal
 * needed: a ref that names no widget writes nothing).
 */
it('writes the widget Health channel indicator from channel.health.changed; a ref naming no widget is a no-op; C-09 idempotent', function (): void {
    [$tenant, $widget] = widgetFixture();
    bindTenant($tenant);
    DB::transaction(fn (): string => app(EventPublisher::class)->publish('channel.health.changed', [
        'tenant_id' => $tenant->identifier(), 'channel' => 'webchat', 'channel_ref_id' => (string) $widget->getKey(), 'status' => 'degraded',
    ]));
    $eventId = relayOutboxRow('channel.health.changed'); // the relay's byte-copy (C-09 physics), the house helper
    bindTenant($tenant);
    app()->call([new ChannelHealthConsumer($eventId), 'handle']);
    app()->call([new ChannelHealthConsumer($eventId), 'handle']);

    bindTenant($tenant);
    $row = DB::table('widget_health_counters')->where('widget_id', $widget->getKey())->sole();
    expect($row->channel_status)->toBe('degraded')->and($row->channel_status_at)->not->toBeNull();

    DB::transaction(fn (): string => app(EventPublisher::class)->publish('channel.health.changed', [
        'tenant_id' => $tenant->identifier(), 'channel' => 'telegram', 'channel_ref_id' => 'bot-1', 'status' => 'down',
    ]));
    $other = relayOutboxRow('channel.health.changed');
    bindTenant($tenant);
    app()->call([new ChannelHealthConsumer($other), 'handle']);
    bindTenant($tenant);
    expect(DB::table('widget_health_counters')->count())->toBe(1)->and(DB::table('widget_health_counters')->sole()->channel_status)->toBe('degraded');
});
