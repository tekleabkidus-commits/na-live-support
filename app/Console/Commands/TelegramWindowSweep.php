<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Telegram\Window\WindowSweep;
use App\Support\Tenancy\RunsWithinTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * FR-22/FR-23's 5-minute sweep cadence, wired (Doc 05, S23 step 11).
 *
 * GAP DISCLOSURE: step 8 built and proved WindowSweep but never gave it a
 * scheduled entry point — the cadence existed only in tests. Filed in
 * BUILD-STATUS at step 11; this command closes it. Event-driven updates
 * bypass the cadence entirely (every inbound/outbound updates window state
 * immediately), so the sweep is the ≤5-min DRIFT bound, not the mechanism.
 *
 * Per-tenant: the windows ZSET is tenant-keyed (windows:{tenant}), so one
 * pass binds each tenant that has telegram conversations and runs the
 * domain sweep inside it. "now" is DB time (R-19/C-11) — the app clock
 * never scores an expiry.
 */
final class TelegramWindowSweep extends Command
{
    use RunsWithinTenant;

    protected $signature = 'telegram:window-sweep';

    protected $description = 'Sweep reply windows: closing/expired transitions (Doc 05 FR-22, 5-min drift bound)';

    public function handle(): int
    {
        /** @var list<object{tenant_id: string}> $tenants */
        $tenants = DB::select(
            "SELECT DISTINCT tenant_id FROM conversations WHERE channel = 'telegram' AND reply_window_expires_at IS NOT NULL",
        );

        $totals = ['closing' => 0, 'expired' => 0, 'paused' => 0];
        foreach ($tenants as $row) {
            /** @var array{closing: int, expired: int, paused: int} $counts */
            $counts = $this->withinTenant($row->tenant_id, function (): array {
                $nowRow = DB::selectOne('SELECT (extract(epoch from clock_timestamp()) * 1000000)::bigint AS m');
                $now = is_object($nowRow) && is_numeric($nowRow->m ?? null) ? (int) $nowRow->m : 0;

                return app(WindowSweep::class)->run($now);
            });
            foreach ($counts as $k => $v) {
                $totals[$k] += $v;
            }
        }

        $this->info(sprintf('closing %d, expired %d, paused %d (FS-01)', $totals['closing'], $totals['expired'], $totals['paused']));

        return self::SUCCESS;
    }
}
