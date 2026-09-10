<?php

declare(strict_types=1);

namespace App\Console\Commands\Sms;

use App\Domain\Sms\Failover\CircuitBreaker;
use App\Models\SmsGateway;
use App\Models\Tenant;
use App\Support\Tenancy\RunsWithinTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The missing-DLR-rate health signal (FR-09's quiet half, hourly): per
 * gateway, the fraction of rows that reached `sent` in the last 24 h and are
 * STILL `sent` an hour later (no receipt of any kind). Above the threshold —
 * on a real sample — the gateway degrades (transition-only; the
 * `sms.gateway.health_changed` event feeds `notify.sms.gateway_health`) and
 * the alert line carries the metric. Figures are consts, stated here: a
 * threshold setting is Doc 16's surface if one is ever wanted.
 */
final class MissingDlrRate extends Command
{
    /** Above this fraction of receipt-less sent rows, the gateway degrades. */
    public const THRESHOLD = 0.5;

    /** Below this many sent rows in the window, no verdict (a quiet hour is not evidence). */
    public const MIN_SAMPLE = 10;

    use RunsWithinTenant;

    protected $signature = 'sms:missing-dlr-rate {--now= : microseconds; defaults to the wall clock}';

    protected $description = 'Degrade gateways whose sent messages are not receiving DLRs (hourly health signal)';

    public function handle(CircuitBreaker $breaker): int
    {
        $nowMicros = is_numeric($this->option('now')) ? (int) $this->option('now') : (int) (microtime(true) * 1_000_000);
        $windowStart = date('Y-m-d H:i:s', intdiv($nowMicros, 1_000_000) - 86_400);
        $graceEnd = date('Y-m-d H:i:s', intdiv($nowMicros, 1_000_000) - 3_600);

        foreach (Tenant::query()->pluck('id') as $tenantId) {
            if (! is_string($tenantId)) {
                continue;
            }
            $this->withinTenant($tenantId, function () use ($breaker, $windowStart, $graceEnd): void {
                foreach (SmsGateway::query()->whereNull('deleted_at')->where('active', true)->get() as $gateway) {
                    /** @var object{total: int, silent: int}|null $counts */
                    $counts = DB::table('sms_messages')
                        ->where('gateway_id', $gateway->identifier())
                        ->where('is_test', false)
                        ->whereNotNull('sent_at')
                        ->where('sent_at', '>=', $windowStart)
                        ->selectRaw("count(*) AS total, count(*) FILTER (WHERE state = 'sent' AND sent_at < ?) AS silent", [$graceEnd])
                        ->first();
                    if ($counts === null || $counts->total < self::MIN_SAMPLE) {
                        continue;
                    }
                    $rate = $counts->silent / $counts->total;
                    if ($rate <= self::THRESHOLD) {
                        continue;
                    }
                    Log::warning('sms gateway: missing-DLR rate over threshold', [
                        'gateway_id' => $gateway->identifier(),
                        'rate' => round($rate, 3),
                        'sample' => $counts->total,
                        'metric' => 'sms.dlr.missing_rate',
                    ]);
                    $breaker->degradeForMissingDlr($gateway, $rate);
                }
            });
        }

        return self::SUCCESS;
    }
}
