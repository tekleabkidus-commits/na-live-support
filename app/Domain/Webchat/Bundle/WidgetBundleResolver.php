<?php

declare(strict_types=1);

namespace App\Domain\Webchat\Bundle;

use App\Models\Widget;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Doc 13 FR-04 / T-01 (SCHEMA AMENDMENT 7 item 6): the BUNDLE POINTER.
 *
 * `ref_widget_bundles` (platform reference data; app SELECT only — publishes,
 * rollout stages and the kill are the platform's console commands on the
 * platform connection, C4) holds one row per published build per channel
 * (`stable` | `early`). The pointer for a widget is the NEWEST live (not
 * killed) row of the widget's `bundle_channel`; a staged rollout
 * (`rollout_pct` < 100, the widget.bundle.rollout_stages dial: 5 → 50 → 100)
 * serves the newest row only to the widgets whose stable bucket
 * (crc32(widget id) mod 100) falls under the percentage and the PREVIOUS live
 * row to the rest; a kill (`killed_at`) removes a row from consideration, so
 * the pointer reverts platform-wide on the next 60 s shim refresh (FS-03's
 * "≤ 5 min"). With no rows at all (a fresh install) the CURRENT BUILD's
 * manifest is the pointer — honest, and the first `widget:bundle-publish`
 * starts the history.
 *
 * The immutable files stay on disk across builds (`emptyOutDir: false`) so a
 * revert always has its bytes; a row whose file is gone is skipped with a log
 * line, never a broken script tag (NFR-06).
 */
final class WidgetBundleResolver
{
    public const MANIFEST = 'w/.vite/manifest.json';

    /** @return array{panel: string, loader: string, version: string, source: string} file names under public/w */
    public function forWidget(Widget $widget): array
    {
        $manifest = $this->manifest();
        $current = ['panel' => $manifest['panel'], 'loader' => $manifest['loader'], 'version' => self::hashOf($manifest['panel']), 'source' => 'build'];

        $channel = $widget->getAttribute('bundle_channel');
        $channel = is_string($channel) && $channel !== '' ? $channel : 'stable';   // a model created without the column reads the DB default
        /** @var list<object{id: string, bundle_hash: string, loader_hash: string, rollout_pct: int|string}> $live */
        $live = DB::table('ref_widget_bundles')->where('channel', $channel)->whereNull('killed_at')->orderByDesc('published_at')->limit(2)->get(['id', 'bundle_hash', 'loader_hash', 'rollout_pct'])->all();
        if ($live === []) {
            return $current;
        }

        $newest = $live[0];
        $bucket = crc32(self::id($widget)) % 100;
        $chosen = ((int) $newest->rollout_pct >= 100 || $bucket < (int) $newest->rollout_pct || ! isset($live[1])) ? $newest : $live[1];

        $candidates = [$chosen->id => $chosen, $newest->id => $newest];   // the chosen first, the newest as the fallback
        foreach ($candidates as $row) {
            $panel = 'panel.'.$row->bundle_hash.'.js';
            $loader = 'loader.'.$row->loader_hash.'.js';
            if (is_file(public_path('w/'.$panel)) && is_file(public_path('w/'.$loader))) {
                return ['panel' => $panel, 'loader' => $loader, 'version' => $row->bundle_hash, 'source' => 'pointer:'.$row->id];
            }
            Log::warning('widget bundle pointer names a file that is not on disk — skipped', ['bundle_hash' => $row->bundle_hash, 'channel' => $channel]);
        }

        return $current;
    }

    /** @return array{panel: string, loader: string} the current build's entry files (the manifest is the only source) */
    public function manifest(): array
    {
        $path = public_path(self::MANIFEST);
        $raw = is_file($path) ? file_get_contents($path) : false;
        if ($raw === false) {
            throw new \RuntimeException('the widget build is missing (public/w/.vite/manifest.json) — run `npm run build:widget`');
        }
        /** @var array<string, array{file: string, name?: string, isEntry?: bool}> $entries */
        $entries = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        $panel = $loader = null;
        foreach ($entries as $entry) {
            if (($entry['isEntry'] ?? false) && ($entry['name'] ?? '') === 'panel') {
                $panel = $entry['file'];
            }
            if (($entry['isEntry'] ?? false) && ($entry['name'] ?? '') === 'loader') {
                $loader = $entry['file'];
            }
        }
        if ($panel === null || $loader === null) {
            throw new \RuntimeException('the widget manifest has no panel/loader entry');
        }

        return ['panel' => $panel, 'loader' => $loader];
    }

    /** The model's key, narrowed (Eloquent types it mixed). */
    public static function id(Widget $widget): string
    {
        $id = $widget->getKey();

        return is_string($id) ? $id : throw new \LogicException('a widget without a uuid key');
    }

    public static function hashOf(string $file): string
    {
        return preg_match('/^[a-z]+\.([A-Za-z0-9_-]+)\.js$/', $file, $m) === 1 ? $m[1] : $file;
    }
}
