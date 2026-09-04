<?php

declare(strict_types=1);

namespace App\Console\Commands\Widget;

use App\Domain\Webchat\Bundle\WidgetBundleResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Doc 13 FR-04 — the bundle pointer's THREE platform commands (countersign C4:
 * platform connection, actor logged, local/testing/ci ONLY — production's
 * pointer is Doc 18's deploy step, [D18]):
 *
 *   widget:bundle publish --channel=stable --rollout=5 --actor=<who>   a new row from the CURRENT build's manifest
 *   widget:bundle rollout --channel=stable --pct=50 --actor=<who>      advance the newest live row's stage
 *   widget:bundle kill    --channel=stable --actor=<who>               kill the newest live row → the pointer reverts
 *
 * Every call logs `widget.bundle.pointer_changed` with the actor (structured
 * log; the platform table has no tenant, so the tenant audit chain does not
 * apply — stated in BUILD-STATUS).
 */
final class WidgetBundlePublish extends Command
{
    protected $signature = 'widget:bundle {action : publish|rollout|kill} {--channel=stable} {--rollout=5} {--pct=} {--actor=console}';

    protected $description = 'Doc 13 FR-04: publish / advance / kill a widget bundle pointer (platform connection; local/testing/ci only)';

    public function handle(WidgetBundleResolver $resolver): int
    {
        if (! app()->environment(['local', 'testing', 'ci'])) {
            $this->error('widget:bundle runs in local/testing/ci only (C4) — production pointers are Doc 18\'s deploy step');

            return self::FAILURE;
        }
        $channel = (string) $this->option('channel');
        if (! in_array($channel, ['stable', 'early'], true)) {
            $this->error('channel must be stable|early');

            return self::FAILURE;
        }
        $actor = (string) $this->option('actor');
        $db = DB::connection('pgsql_platform');
        $action = (string) $this->argument('action');

        if ($action === 'publish') {
            $m = $resolver->manifest();
            $id = (string) Str::uuid7();
            $db->table('ref_widget_bundles')->insert([
                'id' => $id, 'channel' => $channel,
                'bundle_hash' => WidgetBundleResolver::hashOf($m['panel']), 'loader_hash' => WidgetBundleResolver::hashOf($m['loader']),
                'rollout_pct' => max(0, min(100, (int) $this->option('rollout'))),
            ]);
            $this->log('publish', $channel, $id, $actor);
            $this->info("published {$channel} → {$id} ({$m['panel']}, rollout ".$this->option('rollout').'%)');

            return self::SUCCESS;
        }

        /** @var object{id: string}|null $newest */
        $newest = $db->table('ref_widget_bundles')->where('channel', $channel)->whereNull('killed_at')->orderByDesc('published_at')->first(['id']);
        if ($newest === null) {
            $this->error("no live bundle on {$channel}");

            return self::FAILURE;
        }
        if ($action === 'rollout') {
            $pct = max(0, min(100, (int) $this->option('pct')));
            $db->table('ref_widget_bundles')->where('id', $newest->id)->update(['rollout_pct' => $pct]);
            $this->log('rollout', $channel, $newest->id, $actor, ['pct' => $pct]);
            $this->info("{$channel} {$newest->id} → {$pct}%");

            return self::SUCCESS;
        }
        if ($action === 'kill') {
            $db->table('ref_widget_bundles')->where('id', $newest->id)->update(['killed_at' => $db->raw('clock_timestamp()')]);
            $this->log('kill', $channel, $newest->id, $actor);
            $this->info("killed {$channel} {$newest->id} — the pointer reverts on the next shim refresh (≤ 60 s)");

            return self::SUCCESS;
        }
        $this->error('action must be publish|rollout|kill');

        return self::FAILURE;
    }

    /** @param array<string, mixed> $extra */
    private function log(string $action, string $channel, string $id, string $actor, array $extra = []): void
    {
        Log::info('widget.bundle.pointer_changed', ['action' => $action, 'channel' => $channel, 'bundle_id' => $id, 'actor' => $actor] + $extra);
    }
}
