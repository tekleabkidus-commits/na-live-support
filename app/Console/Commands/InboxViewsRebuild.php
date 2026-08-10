<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Inbox\ReadModels\SystemViews;
use App\Domain\Inbox\ReadModels\ViewZsets;
use App\Domain\Inbox\Views\SavedViews;
use App\Support\Tenancy\PlatformActor;
use App\Support\Tenancy\PlatformConnection;
use App\Support\Tenancy\ResolutionSource;
use App\Support\Tenancy\RlsSession;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * FS-02's rebuild: view ZSET loss → rebuild from scoped queries per view,
 * BOUNDED and PRIORITIZED — system views first (R-01's shipping order is
 * also the recovery order), then per-user views for users who hold state,
 * then materialized saved views. Scoped A-30 variants deliberately rebuild
 * LAZILY on next read (ViewVariants::ensure is the one code path); this
 * command drops their stale indexes so nothing serves a half-rebuilt
 * variant. During the outage the read path already served rebuilt-on-miss
 * pages with the perf-degraded flag (ListReadModel); this command is the
 * bulk recovery.
 */
final class InboxViewsRebuild extends Command
{
    protected $signature = 'inbox:views-rebuild {--tenant= : one tenant id (default: every active tenant)}';

    protected $description = 'FS-02: rebuild every inbox view ZSET from the canonical rows, system views first';

    public function handle(TenantContext $context, ViewZsets $zsets, SavedViews $savedViews): int
    {
        /** @var list<string> $tenantIds */
        $tenantIds = $this->option('tenant') !== null
            ? [(string) $this->option('tenant')]
            : PlatformConnection::run(
                PlatformActor::RetentionJob,
                'FS-02 rebuild: enumerate active tenants',
                static fn (): array => DB::connection(PlatformConnection::NAME)
                    ->table('tenants')->where('state', 'active')->pluck('id')->all(),
            );

        foreach ($tenantIds as $tenantId) {
            $context->runFor($tenantId, ResolutionSource::Console, function () use ($tenantId, $zsets, $savedViews): void {
                // Capture-and-RESTORE (not clear): an in-process caller — the
                // test harness, a future scheduler chain — shares this DB
                // session; clearing would strip ITS binding (found by the
                // FS-02 drill, kept as the lesson).
                /** @var object{v: string|null}|null $previousRow */
                $previousRow = DB::selectOne("SELECT current_setting('app.tenant_id', true) AS v");
                $previous = $previousRow->v ?? '';
                app(RlsSession::class)->set($tenantId);

                try {
                    // 1 — SYSTEM BASE VIEWS FIRST (the priority FS-02 names).
                    foreach (SystemViews::BASE_VIEWS as $view) {
                        $this->rebuildFromQuery($zsets, $view, SystemViews::directQuery($view));
                        $this->line("rebuilt {$view}");
                    }

                    // 2 — per-user views, for the users who actually hold state.
                    foreach ($this->activeUserIds() as $userId) {
                        foreach (['my_open', 'following', 'mentions'] as $view) {
                            $this->rebuildFromQuery($zsets, SystemViews::userView($view, $userId), SystemViews::directQuery($view, $userId));
                        }
                    }
                    $this->line('rebuilt per-user views');

                    // 3 — materialized saved views (definitions re-run in full).
                    foreach ($savedViews->materializedIds() as $savedId) {
                        /** @var string|null $filtersJson */
                        $filtersJson = DB::table('inbox_saved_views')->where('id', $savedId)->whereNull('deleted_at')->value('filters');
                        if ($filtersJson === null) {
                            continue;
                        }
                        /** @var array<string, mixed> $filters */
                        $filters = json_decode($filtersJson, true, 16, JSON_THROW_ON_ERROR);
                        $savedViews->materialize($savedId, $filters);

                        // Stale scoped variants: drop; they lazily rebuild.
                        foreach (app(\App\Domain\Inbox\Views\ViewVariants::class)->signatures('saved:'.$savedId) as $sig) {
                            $zsets->flush('saved:'.$savedId.':scope:'.$sig);
                        }
                    }
                    foreach (SystemViews::BASE_VIEWS as $view) {
                        foreach (app(\App\Domain\Inbox\Views\ViewVariants::class)->signatures($view) as $sig) {
                            $zsets->flush($view.':scope:'.$sig);
                        }
                    }
                    $this->line('rebuilt saved views; scoped variants dropped for lazy rebuild');
                } finally {
                    $previous !== '' ? app(RlsSession::class)->set($previous) : app(RlsSession::class)->clear();
                }
            });
        }

        $this->info('inbox:views-rebuild complete');

        return self::SUCCESS;
    }

    private function rebuildFromQuery(ViewZsets $zsets, string $viewKey, \Illuminate\Database\Query\Builder $query): void
    {
        $zsets->flush($viewKey);
        /** @var list<object{id: string, sort_at: string}> $rows */
        $rows = $query->get()->all();
        foreach ($rows as $row) {
            $zsets->add($viewKey, $row->id, ViewZsets::toMicros($row->sort_at));
        }
    }

    /** @return list<string> users holding assignments, follows or unresolved mentions */
    private function activeUserIds(): array
    {
        /** @var list<string> $ids */
        $ids = DB::query()->fromSub(
            DB::table('tickets')->whereNotNull('assignee_id')->select('assignee_id AS user_id')
                ->union(DB::table('ticket_followers')->select('user_id'))
                ->union(DB::table('inbox_notifications')->where('category', 'notify.inbox.mention')->whereNull('read_at')->select('user_id')),
            'u',
        )->distinct()->pluck('user_id')->all();

        return $ids;
    }
}
