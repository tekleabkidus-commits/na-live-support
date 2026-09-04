<?php

declare(strict_types=1);

use App\Domain\Webchat\Bundle\WidgetBundleResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Doc 13 FR-04 / FS-03 (S13 step 6): the BUNDLE POINTER. ref_widget_bundles
| is platform reference data (app SELECT only), so the rows are written on the
| platform connection and removed in `finally` — the default connection's
| rollback cannot see them. Fake immutables are placed under public/w for the
| test and removed after.
|--------------------------------------------------------------------------
*/

function bundleRow(string $channel, string $hash, int $pct, ?string $killed = null): string
{
    $id = (string) Str::uuid7();
    DB::connection('pgsql_platform')->table('ref_widget_bundles')->insert([
        'id' => $id, 'channel' => $channel, 'bundle_hash' => $hash, 'loader_hash' => $hash, 'rollout_pct' => $pct,
        'killed_at' => $killed, 'published_at' => DB::raw('clock_timestamp()'),
    ]);
    usleep(2000); // published_at ordering is DB time; two rows in the same microsecond would tie

    return $id;
}

it('FR-04: the pointer is the NEWEST live row of the widget\'s channel; a staged rollout serves the newest only inside the bucket; a KILL reverts platform-wide; a row whose file is gone falls back; no rows = the current build', function (): void {
    [$tenant, $widget] = widgetFixture();
    bindTenant($tenant);
    $resolver = app(WidgetBundleResolver::class);
    $build = $resolver->manifest();
    $files = [];
    foreach (['fxOLD00001', 'fxNEW00002', 'fxEARLY003'] as $h) {
        foreach (['panel', 'loader'] as $kind) {
            $f = public_path("w/{$kind}.{$h}.js");
            file_put_contents($f, "/* fixture immutable {$h} */");
            $files[] = $f;
        }
    }
    $ids = [];
    try {
        // No rows: the build is the pointer.
        expect($resolver->forWidget($widget))->toMatchArray(['panel' => $build['panel'], 'source' => 'build']);

        $ids[] = bundleRow('stable', 'fxOLD00001', 100);
        expect($resolver->forWidget($widget)['version'])->toBe('fxOLD00001');

        // A staged publish at 0 %: nobody is in the bucket → the previous row stays the pointer.
        $ids[] = $new = bundleRow('stable', 'fxNEW00002', 0);
        expect($resolver->forWidget($widget)['version'])->toBe('fxOLD00001');
        // The widget's own bucket decides: pct just above it → in; the stage advances platform-wide by one UPDATE.
        $bucket = crc32((string) $widget->getKey()) % 100;
        DB::connection('pgsql_platform')->table('ref_widget_bundles')->where('id', $new)->update(['rollout_pct' => $bucket + 1]);
        expect($resolver->forWidget($widget)['version'])->toBe('fxNEW00002');
        DB::connection('pgsql_platform')->table('ref_widget_bundles')->where('id', $new)->update(['rollout_pct' => $bucket]);
        expect($resolver->forWidget($widget)['version'])->toBe('fxOLD00001');
        DB::connection('pgsql_platform')->table('ref_widget_bundles')->where('id', $new)->update(['rollout_pct' => 100]);
        expect($resolver->forWidget($widget)['version'])->toBe('fxNEW00002');

        // The kill: the newest row leaves consideration; the pointer reverts to the previous live row.
        DB::connection('pgsql_platform')->table('ref_widget_bundles')->where('id', $new)->update(['killed_at' => DB::raw('clock_timestamp()')]);
        expect($resolver->forWidget($widget)['version'])->toBe('fxOLD00001');

        // The channel is the widget's: an `early` row never reaches a `stable` widget, and vice versa.
        $ids[] = bundleRow('early', 'fxEARLY003', 100);
        expect($resolver->forWidget($widget)['version'])->toBe('fxOLD00001');
        DB::table('widgets')->where('id', (string) $widget->getKey())->update(['bundle_channel' => 'early']);
        expect($resolver->forWidget(App\Models\Widget::query()->findOrFail((string) $widget->getKey()))['version'])->toBe('fxEARLY003');

        // A pointer whose immutable is gone is skipped (logged), never a broken script tag: the build is served.
        unlink(public_path('w/panel.fxEARLY003.js'));
        expect($resolver->forWidget(App\Models\Widget::query()->findOrFail((string) $widget->getKey())))->toMatchArray(['panel' => $build['panel'], 'source' => 'build']);
    } finally {
        DB::connection('pgsql_platform')->table('ref_widget_bundles')->whereIn('id', $ids)->delete();
        foreach ($files as $f) {
            @unlink($f);
        }
    }
});

it('C4: the pointer commands run on the platform connection in local/testing/ci only, log the actor, and publish EXACTLY the current build\'s hashes; rollout advances the stage; kill reverts', function (): void {
    $resolver = app(WidgetBundleResolver::class);
    $build = $resolver->manifest();
    $platform = DB::connection('pgsql_platform');
    $before = $platform->table('ref_widget_bundles')->where('channel', 'early')->pluck('id')->all();
    try {
        $this->artisan('widget:bundle', ['action' => 'publish', '--channel' => 'early', '--rollout' => 5, '--actor' => 'fixture-operator'])->assertExitCode(0);
        /** @var object{id: string, bundle_hash: string, loader_hash: string, rollout_pct: int|string, killed_at: ?string} $row */
        $row = $platform->table('ref_widget_bundles')->where('channel', 'early')->whereNotIn('id', $before)->orderByDesc('published_at')->first();
        expect($row->bundle_hash)->toBe(WidgetBundleResolver::hashOf($build['panel']))
            ->and($row->loader_hash)->toBe(WidgetBundleResolver::hashOf($build['loader']))
            ->and((int) $row->rollout_pct)->toBe(5);

        $this->artisan('widget:bundle', ['action' => 'rollout', '--channel' => 'early', '--pct' => 50])->assertExitCode(0);
        expect((int) $platform->table('ref_widget_bundles')->where('id', $row->id)->value('rollout_pct'))->toBe(50);

        $this->artisan('widget:bundle', ['action' => 'kill', '--channel' => 'early'])->assertExitCode(0);
        expect($platform->table('ref_widget_bundles')->where('id', $row->id)->value('killed_at'))->not->toBeNull();

        // The app role cannot write the table at all (grant physics, asserted again here on the pointer's own door).
        expect(fn () => DB::table('ref_widget_bundles')->where('id', $row->id)->update(['rollout_pct' => 100]))->toThrow(Illuminate\Database\QueryException::class);
    } finally {
        $platform->table('ref_widget_bundles')->where('channel', 'early')->whereNotIn('id', $before)->delete();
    }
});
