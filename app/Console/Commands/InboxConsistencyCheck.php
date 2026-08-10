<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Inbox\ReadModels\SystemViews;
use App\Domain\Inbox\ReadModels\ViewZsets;
use App\Support\Tenancy\PlatformActor;
use App\Support\Tenancy\PlatformConnection;
use App\Support\Tenancy\ResolutionSource;
use App\Support\Tenancy\RlsSession;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * NFR-07's consistency checker — A DELIVERABLE, NOT AN ASPIRATION (Doc 07
 * §5/§21: "stand it up the same week the projector lands"): view
 * membership/counts must EXACTLY match a direct scoped query; drift is
 * alerted, never absorbed.
 *
 * Mechanism: for every materialized view set, compare the ZSET's member set
 * against SystemViews::directQuery's ids — the SAME definition the replay
 * proof and FS-02's rebuild use. Any difference (missing member, extra
 * member) is drift: logged loudly with the view key and both diffs, counted
 * on the `inbox.consistency.drift` metric, and the command exits non-zero so
 * a scheduled staging run pages (Doc 18's alerting rides the log + exit
 * code, its own precedent).
 *
 * Per-user views are checked for every user that HAS a materialized set (the
 * lazy posture — a set that was never built cannot drift; the projector
 * builds on write). Runs per tenant, under that tenant's own context.
 */
final class InboxConsistencyCheck extends Command
{
    protected $signature = 'inbox:consistency-check {--tenant= : one tenant id (default: every active tenant)}';

    protected $description = 'NFR-07: compare every inbox view ZSET against its direct scoped query; alert on drift';

    public function handle(TenantContext $context, ViewZsets $zsets): int
    {
        /** @var list<string> $tenantIds */
        $tenantIds = $this->option('tenant') !== null
            ? [(string) $this->option('tenant')]
            : PlatformConnection::run(
                PlatformActor::RetentionJob,
                'NFR-07 checker: enumerate active tenants',
                static fn (): array => DB::connection(PlatformConnection::NAME)
                    ->table('tenants')->where('state', 'active')->pluck('id')->all(),
            );

        $driftCount = 0;

        foreach ($tenantIds as $tenantId) {
            $driftCount += $context->runFor($tenantId, ResolutionSource::Console, function () use ($tenantId, $zsets): int {
                app(RlsSession::class)->set($tenantId);

                try {
                    $drift = 0;

                    foreach (SystemViews::BASE_VIEWS as $view) {
                        $drift += $this->checkOne($zsets, $view, $view, null);
                    }

                    foreach (['my_open', 'following', 'mentions'] as $view) {
                        foreach ($this->materializedUsers($zsets, $view) as $userId) {
                            $drift += $this->checkOne($zsets, SystemViews::userView($view, $userId), $view, $userId);
                        }
                    }

                    return $drift;
                } finally {
                    app(RlsSession::class)->clear();
                }
            });
        }

        if ($driftCount > 0) {
            $this->error(sprintf('inbox:consistency-check FAIL — %d view(s) drifted (see log)', $driftCount));

            return self::FAILURE;
        }

        $this->info('inbox:consistency-check PASS — every materialized view matches its direct query');

        return self::SUCCESS;
    }

    private function checkOne(ViewZsets $zsets, string $viewKey, string $view, ?string $userId): int
    {
        $materialized = $zsets->allIds($viewKey);
        if ($materialized === [] && $userId !== null) {
            return 0; // never built — the lazy posture; nothing to drift
        }

        /** @var list<string> $truth */
        $truth = SystemViews::directQuery($view, $userId)->pluck('id')->all();

        $missing = array_values(array_diff($truth, $materialized));
        $extra = array_values(array_diff($materialized, $truth));

        if ($missing === [] && $extra === []) {
            return 0;
        }

        Log::error('inbox read-model DRIFT (NFR-07)', [
            'view_key' => $viewKey,
            'missing_from_zset' => $missing,
            'extra_in_zset' => $extra,
            'zcard' => count($materialized),
            'direct_count' => count($truth),
            'metric' => 'inbox.consistency.drift',
        ]);

        return 1;
    }

    /**
     * Users with a materialized set for a per-user view — from the key space.
     *
     * @return list<string>
     */
    private function materializedUsers(ViewZsets $zsets, string $view): array
    {
        /** @var list<string> $userIds */
        $userIds = DB::table('users')->pluck('id')->all();

        return array_values(array_filter(
            $userIds,
            fn (string $userId): bool => $zsets->count(SystemViews::userView($view, $userId)) > 0,
        ));
    }
}
