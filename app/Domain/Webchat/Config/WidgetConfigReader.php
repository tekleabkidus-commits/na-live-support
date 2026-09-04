<?php

declare(strict_types=1);

namespace App\Domain\Webchat\Config;

use App\Models\Widget;
use Illuminate\Support\Facades\DB;

/**
 * Doc 13 FR-20 / T-03 `GET config` — the ONE object that publishes, previews
 * and renders. The live version's `config` (widget_config_versions, pointed
 * at by widgets.live_version_id) merged over the platform DEFAULTS, so a
 * widget with no publish yet renders the neutral default the frozen Batch 3
 * files draw (B3-S00: "every screen is drawn in the neutral default").
 *
 * Defaults are DATA here — never i18n strings (those ride lang/{en,am}/widget.php
 * and the language chain) and never colours the design system does not own
 * (the neutral default's hexes are the frozen system's tokens, proven by the
 * hex-extraction script at the panel build).
 */
final class WidgetConfigReader
{
    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'appearance' => [
                'position' => 'bottom_right',
                'offset' => ['side' => 16, 'bottom' => 20],
                'theme' => 'auto',
                'font' => 'inter_noto_ethiopic',
                'launcher_icon' => 'chat',
                'brand' => null, // null = the neutral default tokens; a published brand is validated by the contrast gate (A-62)
            ],
            'content' => [
                'welcome' => [],           // language → text; empty = the platform greeting key
                'shortcuts' => [],         // list<{kind: article|form|chat|url, label: {lang: text}, target}>
                'tabs' => ['home' => true, 'help' => true],
                'default_language' => null,
                'pre_chat_form_id' => null,
                'pre_chat_mode' => 'off',  // off | optional | required
                'offline_form_id' => null,
            ],
            'behavior' => [
                'hours_source' => 'tenant', // tenant | department:<id>
                'file_upload' => true,
                'sound' => null,            // null = the widget.sound.default setting
                'csat' => true,
                'proactive' => [],          // list<{condition, action}> (FR-14; evaluated in-loader / in-panel)
                'context_allowlist' => [],  // FR-24 capture() keys the server keeps
            ],
        ];
    }

    /**
     * @return array{version: ?int, version_id: ?string, config: array<string, mixed>}
     */
    public function live(Widget $widget): array
    {
        /** @var object{id: string, version: int|string, config: string}|null $row */
        $row = is_string($widget->live_version_id)
            ? DB::table('widget_config_versions')->where('id', $widget->live_version_id)->first(['id', 'version', 'config'])
            : null;

        /** @var array<string, mixed> $published */
        $published = [];
        if ($row !== null) {
            $decoded = json_decode($row->config, true);
            if (is_array($decoded)) {
                /** @var array<string, mixed> $decoded */
                $published = $decoded;
            }
        }

        /** @var array<string, mixed> $merged */
        $merged = array_replace_recursive(self::defaults(), $published);

        return [
            'version' => $row !== null ? (int) $row->version : null,
            'version_id' => $row !== null ? $row->id : null,
            'config' => $merged,
        ];
    }
}
