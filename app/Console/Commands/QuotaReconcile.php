<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Tenancy\PlatformActor;
use App\Support\Tenancy\PlatformConnection;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/**
 * FS-05 — "quota counter corruption: counters are Redis with periodic DB reconciliation;
 * reconciliation job repairs drift and logs deltas."
 *
 * Two directions, one truth rule:
 *
 *   Redis ahead of DB   the normal case — usage accrued since the last run. The DB row is
 *                       brought up to the counter and stamped, so tenant_quota_usage is the
 *                       durable ledger a usage dispute is answered from (R-04).
 *
 *   DB ahead of Redis   the FS-05 case — the counter was flushed, evicted or corrupted.
 *                       The counter is RESTORED from the ledger, because an under-counting
 *                       quota is a quota bypass. The delta lands in last_drift and a log
 *                       line, so corruption is an investigated event, not a silent repair.
 *
 * Platform-wide by nature (it walks every tenant's counters), so it runs under
 * PlatformConnection with a logged actor (SEC-02) and REFUSES to start with a tenant bound.
 */
final class QuotaReconcile extends Command
{
    protected $signature = 'quota:reconcile';

    protected $description = 'Reconcile Redis quota counters against tenant_quota_usage, repairing drift (Doc 01 FS-05)';

    public function handle(): int
    {
        $reconciled = PlatformConnection::run(
            PlatformActor::ReconciliationJob,
            'FS-05 quota counter reconciliation',
            fn (): int => $this->reconcileAll(),
        );

        $this->info(sprintf('quota:reconcile: %d counter(s) reconciled.', $reconciled));

        return self::SUCCESS;
    }

    private function reconcileAll(): int
    {
        $client = Redis::connection('volatile')->client();

        if (! $client instanceof \Redis) {
            throw new \RuntimeException(
                'quota:reconcile requires the phpredis client (REDIS_CLIENT=phpredis) — '
                .'its raw SCAN iterator is what returns unprefixed key names.',
            );
        }

        $count = 0;

        // SCAN, never KEYS: this walks a live instance that is also serving rate limits.
        // The raw phpredis iterator, because it returns RAW key names — the OPT_PREFIX
        // convenience would hide keys written under other env prefixes from the pattern.
        // The :warned once-guards are skipped below — they are alert state, not usage.
        // phpredis returns false when the iteration is exhausted, which ends the loop.
        $iterator = null;
        while (false !== ($keys = $client->scan($iterator, '*t:*:quota:*', 200))) {
            foreach ($keys as $fullKey) {
                if (str_ends_with($fullKey, ':warned')) {
                    continue;
                }

                if ($this->reconcileKey($fullKey)) {
                    $count++;
                }
            }
        }

        return $count + $this->restoreFlushedCounters();
    }

    /**
     * The half SCAN cannot see: a FLUSHED counter leaves no key to find. Walk the
     * ledger's still-live windows instead and restore any counter that has fallen
     * below what the ledger already witnessed. This is the actual FS-05 scenario —
     * an eviction or flush loses the whole key, not part of its value.
     */
    private function restoreFlushedCounters(): int
    {
        $db = \Illuminate\Support\Facades\DB::connection(PlatformConnection::NAME);
        $restored = 0;

        /** @var \Illuminate\Support\Collection<int, object{id: string, tenant_id: string, quota: string, window_start: string, used: int|string}> $rows */
        $rows = $db->table('tenant_quota_usage')
            ->where('window_end', '>', now())
            ->whereNotNull('reconciled_at')
            ->get(['id', 'tenant_id', 'quota', 'window_start', 'used']);

        foreach ($rows as $row) {
            $bucket = Carbon::parse($row->window_start)->getTimestamp();
            $cacheKey = sprintf('t:%s:quota:%s:%d', $row->tenant_id, $row->quota, $bucket);

            $counted = Cache::get($cacheKey);
            $counted = is_numeric($counted) ? (int) $counted : 0;
            $ledger = (int) $row->used;

            if ($counted >= $ledger) {
                continue;
            }

            $remainingTtl = max(60, $bucket + 2 * $this->windowOf($row->quota) - time());
            Cache::put($cacheKey, $ledger, $remainingTtl);

            $db->table('tenant_quota_usage')->where('id', $row->id)->update([
                'reconciled_at' => now(),
                'last_drift' => $counted - $ledger,
                'updated_at' => now(),
            ]);

            Log::warning('quota counter repaired from ledger', [
                'tenant_id' => $row->tenant_id,
                'quota' => $row->quota,
                'window_start' => $row->window_start,
                'counter' => $counted,
                'ledger' => $ledger,
                'drift' => $counted - $ledger,
                'metric' => 'quota.counter_repaired',
            ]);

            $restored++;
        }

        return $restored;
    }

    private function reconcileKey(string $fullKey): bool
    {
        // Full key shape: {redis_prefix}{cache_prefix}t:{tenant}:quota:{name}:{bucket}.
        // The laravel-side prefixes vary by env; the tail is ours and fixed.
        if (preg_match('/t:([0-9a-f-]{36}):quota:([a-z_]+):(\d+)$/', $fullKey, $m) !== 1) {
            return false;
        }

        [, $tenantId, $quotaName, $bucket] = $m;

        $cacheKey = sprintf('t:%s:quota:%s:%d', $tenantId, $quotaName, (int) $bucket);
        $counted = Cache::get($cacheKey);
        $counted = is_numeric($counted) ? (int) $counted : 0;

        $windowStart = Carbon::createFromTimestampUTC((int) $bucket);
        $db = \Illuminate\Support\Facades\DB::connection(PlatformConnection::NAME);

        // A counter can outlive its tenant (archival, or another environment's leftovers
        // sharing the instance in dev). The ledger has an FK; skip rather than crash the
        // whole sweep on one orphan.
        if (! $db->table('tenants')->where('id', $tenantId)->exists()) {
            return false;
        }

        /** @var object{id: string, used: int|string}|null $row */
        $row = $db->table('tenant_quota_usage')
            ->where('tenant_id', $tenantId)
            ->where('quota', $quotaName)
            ->where('window_start', $windowStart)
            ->first(['id', 'used']);

        $ledger = $row === null ? 0 : (int) $row->used;
        $drift = $counted - $ledger;

        if ($drift < 0) {
            // FS-05 proper: the ledger has seen usage the counter no longer holds.
            // Restore the counter — under-counting is a quota bypass.
            $remainingTtl = max(60, ((int) $bucket) + 2 * $this->windowOf($quotaName) - time());
            Cache::put($cacheKey, $ledger, $remainingTtl);

            Log::warning('quota counter repaired from ledger', [
                'tenant_id' => $tenantId,
                'quota' => $quotaName,
                'window_start' => $windowStart->toIso8601String(),
                'counter' => $counted,
                'ledger' => $ledger,
                'drift' => $drift,
                'metric' => 'quota.counter_repaired',
            ]);
        }

        $persisted = max($counted, $ledger);

        if ($row === null) {
            $db->table('tenant_quota_usage')->insert([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'quota' => $quotaName,
                'window_start' => $windowStart,
                'window_end' => $windowStart->copy()->addSeconds($this->windowOf($quotaName)),
                'used' => $persisted,
                'reconciled_at' => now(),
                'last_drift' => $drift,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $db->table('tenant_quota_usage')->where('id', $row->id)->update([
                'used' => $persisted,
                'reconciled_at' => now(),
                'last_drift' => $drift,
                'updated_at' => now(),
            ]);
        }

        return true;
    }

    private function windowOf(string $quotaName): int
    {
        return match ($quotaName) {
            'ai_tokens_per_day' => 86_400,
            default => 60,
        };
    }
}
