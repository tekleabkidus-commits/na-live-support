<?php

declare(strict_types=1);

namespace App\Events\Webchat;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Doc 13 FR-20 / NFR-04: "a new config is live" on the PUBLIC per-widget config channel.
 * The frame carries the version only — no config, no tenant id; every open panel of the
 * widget re-reads `GET config` (the door is the record). Public because the channel
 * names nothing private (the widget id is already in the page's snippet) and a visitor
 * with no session yet must still hear it.
 */
final class WidgetConfigPush implements ShouldBroadcast
{
    use Dispatchable;

    public const CHANNEL_PREFIX = 'widget.';

    public const CHANNEL_SUFFIX = '.config';

    public function __construct(
        public readonly string $widgetId,
        public readonly int $version,
        public readonly string $versionId,
    ) {}

    public static function channelFor(string $widgetId): string
    {
        return self::CHANNEL_PREFIX.$widgetId.self::CHANNEL_SUFFIX;
    }

    public function broadcastOn(): Channel
    {
        return new Channel(self::channelFor($this->widgetId));
    }

    public function broadcastAs(): string
    {
        return 'widget.config.published';
    }

    /** @return array{v: int, version: int, version_id: string} */
    public function broadcastWith(): array
    {
        return ['v' => 1, 'version' => $this->version, 'version_id' => $this->versionId];
    }
}
