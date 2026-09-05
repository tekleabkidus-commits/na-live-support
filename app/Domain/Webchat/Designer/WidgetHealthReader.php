<?php

declare(strict_types=1);

namespace App\Domain\Webchat\Designer;

use App\Domain\Webchat\Bundle\WidgetBundleResolver;
use App\Domain\Webchat\Visitor\VisitorConversation;
use App\Models\Widget;
use Illuminate\Support\Facades\DB;

/**
 * Doc 13 FR-19 Health / T-08 / BR-07 (S13 step 10): what the Health tab reads — the
 * 7-day counters, the blocked-origins list (display-only, bounded), invalid signatures,
 * the active bundle version + rollout (FR-04), the two diagnoses the frozen frame draws
 * (CSP-blocked mounts with the fix line; the double install), the channel status. Reads
 * only; the counters are written by the beacon door.
 */
final class WidgetHealthReader
{
    public function __construct(private readonly WidgetBundleResolver $bundles) {}

    /**
     * @return array<string, mixed>
     */
    public function read(Widget $widget, \DateTimeImmutable $now, int $days = 7): array
    {
        $widgetId = VisitorConversation::widgetId($widget);
        $since = $now->modify(sprintf('-%d days', max(1, $days)))->format('Y-m-d');
        /** @var object{loads: int|string|null, opens: int|string|null, messages: int|string|null, errors: int|string|null, invalid_signatures: int|string|null, blocked_origin_attempts: int|string|null, mount_fail_csp: int|string|null, double_install: int|string|null}|null $sum */
        $sum = DB::table('widget_health_counters')->where('widget_id', $widgetId)->where('day', '>=', $since)
            ->selectRaw('sum(loads) as loads, sum(opens) as opens, sum(messages) as messages, sum(errors) as errors, sum(invalid_signatures) as invalid_signatures, sum(blocked_origin_attempts) as blocked_origin_attempts, sum(mount_fail_csp) as mount_fail_csp, sum(double_install) as double_install')
            ->first();
        $n = static fn (int|string|null $v): int => (int) ($v ?? 0);
        $loads = $n($sum?->loads);
        /** @var object{blocked_origins: string, channel_status: ?string, channel_status_at: ?string}|null $latest */
        $latest = DB::table('widget_health_counters')->where('widget_id', $widgetId)->orderByDesc('day')->first(['blocked_origins', 'channel_status', 'channel_status_at']);
        $blocked = [];
        if ($latest !== null) {
            $decoded = json_decode($latest->blocked_origins, true);
            $blocked = is_array($decoded) ? array_values($decoded) : [];
        }
        $bundle = $this->bundles->forWidget($widget);
        /** @var object{rollout_pct: int|string}|null $pointer */
        $pointer = str_starts_with($bundle['source'], 'pointer:')
            ? DB::table('ref_widget_bundles')->where('id', substr($bundle['source'], 8))->first(['rollout_pct'])
            : null;
        $liveVersion = is_string($widget->live_version_id)
            ? DB::table('widget_config_versions')->where('id', $widget->live_version_id)->value('version')
            : null;

        return [
            'days' => $days,
            'counters' => [
                'loads' => $loads, 'opens' => $n($sum?->opens), 'messages' => $n($sum?->messages), 'errors' => $n($sum?->errors),
                'error_rate' => $loads > 0 ? round($n($sum?->errors) / $loads, 4) : 0.0,
                'invalid_signatures' => $n($sum?->invalid_signatures),
                'blocked_origin_attempts' => $n($sum?->blocked_origin_attempts),
            ],
            'blocked_origins' => $blocked,
            'diagnoses' => [
                // The frozen frame's two: "Blocked by your site's CSP — N loads failed" (with the frame-src fix line) and "the snippet is on the page twice".
                'csp_blocked' => ['count' => $n($sum?->mount_fail_csp)],
                'double_install' => ['count' => $n($sum?->double_install)],
            ],
            'bundle' => [
                'version' => $bundle['version'],
                'channel' => (string) ($widget->bundle_channel ?? 'stable'),
                'rollout_pct' => $pointer !== null ? (int) $pointer->rollout_pct : 100,
                'source' => $bundle['source'],
            ],
            'config_version' => is_numeric($liveVersion) ? (int) $liveVersion : null,
            'test_mode' => (bool) $widget->test_mode,
            'channel' => ['status' => $latest?->channel_status, 'at' => $latest?->channel_status_at],
        ];
    }
}
