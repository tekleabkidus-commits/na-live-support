<?php

declare(strict_types=1);

namespace App\Support\Quota;

use App\Models\Tenant;
use App\Support\Cache\TenantCache;
use App\Support\Settings\SettingsRepository;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Foundation quota enforcement (Doc 01 NFR-06, EC-09, FS-05, SEC-05).
 *
 * SEC-05 fixes WHERE this runs: before expensive work. allow() is called at the top of a
 * handler, never after the AI call it meters. The counter increments first and compensates
 * on refusal, so two concurrent requests cannot both pass a read-then-check against the
 * same remaining headroom.
 *
 * EC-09 (limit lowered below current usage) needs no code of its own, and that is worth
 * stating: the check is `counted so far + this request > limit`, so lowering the limit
 * blocks FUTURE work and destroys nothing — there is no reconcile-to-limit, no truncation,
 * nothing that touches what was already counted.
 *
 * WINDOW BUCKETS use wall-clock epoch division. That is a deliberate, bounded exception to
 * EC-08's no-app-clock rule: EC-08 forbids app clocks for ORDERING decisions, and a rate
 * window is not an ordering — a few seconds of node skew moves a request between adjacent
 * windows, which changes nothing an operator can observe at these limits. DB time here
 * would put a database round-trip in front of every metered request, spending NFR-02's
 * budget to defend against nothing.
 *
 * The 80% alert (NFR-06) is a structured log carrying `metric: quota.warning_80`, fired
 * exactly once per window by an atomic first-crosser check. It becomes a domain event when
 * an alerting consumer exists (Doc 18); logging it now keeps the threshold proven while
 * event schemas stay owned by the documents that consume them.
 */
final class QuotaMeter
{
    private const WARN_FRACTION = 0.8;

    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantCache $cache,
        private readonly SettingsRepository $settings,
    ) {}

    /**
     * Admit or refuse `amount` units of work under a quota. Call BEFORE the work (SEC-05).
     *
     * @throws QuotaExceeded the 429 NFR-06 specifies, naming the quota
     * @throws QuotaCheckUnavailable metering unreachable on a fail-closed surface (FS-01)
     */
    public function allow(
        Quota $quota,
        int $amount = 1,
        QuotaSurface $surface = QuotaSurface::PublicIngestion,
    ): void {
        $limit = $this->limit($quota);
        $window = $quota->windowSeconds();

        if ($window === null) {
            $this->allowCumulative($quota, $amount, $limit);

            return;
        }

        try {
            $bucket = intdiv(time(), $window) * $window;
            $suffix = sprintf('quota:%s:%d', $quota->value, $bucket);

            // TTL twice the window: the counter must outlive its window long enough for
            // quota:reconcile to persist it (FS-05), not so long that dead windows pile up.
            $counted = $this->cache->increment($suffix, $amount, $window * 2, 'quota.window_expired');

            if ($counted > $limit) {
                $this->cache->decrement($suffix, $amount);

                throw QuotaExceeded::forQuota($quota, $limit);
            }

            $this->warnOnFirstCrossing($quota, $counted, $limit, $suffix, $window);
        } catch (QuotaExceeded $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->degrade($quota, $surface, $e);
        }
    }

    /**
     * READ-ONLY headroom for the current window (RULED CORE EDIT, S09 step 8 —
     * Doc 09 EC-12/NFR-06's 5% reserve semantics): {used, limit} in the quota's
     * own units. NEVER increments, NEVER refuses — the gate that refuses stays
     * allow(), SEC-05's pre-call posture unchanged. Cumulative quotas answer
     * from the ledger exactly as allowCumulative() reads it. A metering
     * outage PROPAGATES (the AI gate treats "unknown headroom" as reserve
     * mode — refuse new turns, never bill blind); allow()'s per-surface
     * degrade policy is untouched because this method is not a gate.
     *
     * @return array{used: int, limit: int}
     */
    public function headroom(Quota $quota): array
    {
        $limit = $this->limit($quota);
        $window = $quota->windowSeconds();

        if ($window === null) {
            $used = (int) DB::table('tenant_quota_usage')
                ->where('tenant_id', $this->context->tenantId())
                ->where('quota', $quota->value)
                ->sum('used');

            return ['used' => $used, 'limit' => $limit * $quota->limitUnitMultiplier()];
        }

        $bucket = intdiv(time(), $window) * $window;
        // increment-by-ZERO, not get(): the cache client's get() is FAIL-SOFT
        // on a dead connection (null, no throw — proven by probe at S09
        // step 8), while the add/increment path THROWS — and "outage
        // propagates" is this method's contract. Same key, same TTL as
        // allow(); a zero increment creates nothing allow() would not.
        $counted = $this->cache->increment(
            sprintf('quota:%s:%d', $quota->value, $bucket),
            0,
            $window * 2,
            'quota.window_expired',
        );

        return ['used' => $counted, 'limit' => $limit];
    }

    /**
     * Cumulative quotas (storage) check the durable ledger — there is no window to reset,
     * so the database row IS the counter and Redis is not involved. The writers arrive
     * with the modules that store bytes (Doc 04 media onward); until then the ledger rows
     * exist and this check enforces against whatever they record.
     */
    private function allowCumulative(Quota $quota, int $amount, int $limit): void
    {
        $used = (int) DB::table('tenant_quota_usage')
            ->where('tenant_id', $this->context->tenantId())
            ->where('quota', $quota->value)
            ->sum('used');

        $limitInUsageUnits = $limit * $quota->limitUnitMultiplier();

        if ($used + $amount > $limitInUsageUnits) {
            throw QuotaExceeded::forQuota($quota, $limit);
        }
    }

    /**
     * The platform default from the settings registry, unless the platform console has
     * written a per-tenant override into tenants.quota_overrides (NFR-06). The override
     * path is a different column from tenant-editable settings ON PURPOSE: quota keys are
     * tenant_editable: false, and this keeps "operator cannot raise their own ceiling"
     * enforceable by the settings engine rather than by review.
     */
    private function limit(Quota $quota): int
    {
        $tenant = Tenant::query()->find($this->context->tenantId());

        /** @var array<string, mixed> $overrides */
        $overrides = $tenant?->getAttribute('quota_overrides') ?? [];
        $override = $overrides[$quota->settingKey()] ?? null;

        if (is_int($override)) {
            return $override;
        }

        $default = $this->settings->get($quota->settingKey());

        if (! is_int($default)) {
            // Unreachable while the registry declares these keys `int` — reaching it
            // means the registry entry changed shape, which is worth a loud stop.
            throw new \RuntimeException(sprintf(
                'Quota setting "%s" did not decode to an int — check its registry entry.',
                $quota->settingKey(),
            ));
        }

        return $default;
    }

    private function warnOnFirstCrossing(Quota $quota, int $counted, int $limit, string $suffix, int $window): void
    {
        if ($counted < (int) ceil($limit * self::WARN_FRACTION)) {
            return;
        }

        // increment() as an atomic once-guard: only the first crosser sees 1.
        if ($this->cache->increment($suffix.':warned', 1, $window * 2, 'quota.window_expired') === 1) {
            Log::warning('tenant quota at 80 percent', [
                'quota' => $quota->value,
                'counted' => $counted,
                'limit' => $limit,
                'metric' => 'quota.warning_80',
            ]);
        }
    }

    private function degrade(Quota $quota, QuotaSurface $surface, Throwable $e): void
    {
        if ($surface === QuotaSurface::Staff) {
            // FS-01: fail-open for staff, and say so — a silent open is a metering gap
            // nobody investigates.
            Log::warning('quota check degraded open: metering unreachable on a staff surface', [
                'quota' => $quota->value,
                'exception' => $e::class,
                'metric' => 'quota.check_degraded',
            ]);

            return;
        }

        throw new QuotaCheckUnavailable(sprintf(
            'Quota "%s" cannot be checked (metering unreachable) and this surface fails '
            .'closed (FS-01). Ingestion should apply its bounded-queue backpressure.',
            $quota->value,
        ), previous: $e);
    }
}
