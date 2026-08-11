<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Inbox\Health\ChannelHealthState;
use App\Domain\Inbox\Views\SavedViews;
use App\Models\User;
use App\Support\Tenancy\ResolutionSource;
use App\Support\Tenancy\RlsSession;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * S07 step 8 — fixtures for the B1-S01 shell specs (inbox-layout.spec.ts /
 * inbox-mobile.spec.ts). Everything goes through the REAL services (the
 * BrowserFixtureSeeder discipline): saved views through SavedViews (so the
 * materialization + index side effects are the production ones), health
 * through ChannelHealthState (step 3's store — the indicator renders
 * exactly what the consumer would have written).
 *
 * DELIBERATELY SMALL: the shell needs views to list, ONE owned + ONE shared
 * saved view, and a degraded health endpoint for the FR-03 banner proof.
 * Step 9's list spec extends this with projected tickets when the list
 * anatomy lands. All fixtures synthetic (CLAUDE.md §8).
 */
final class InboxBrowserSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing', 'ci'])) {
            throw new RuntimeException('InboxBrowserSeeder is a browser fixture and must never run outside local/testing/ci.');
        }

        $row = DB::connection('pgsql_platform')->table('tenants')->where('name', 'Fixture Operator A')->first();
        if ($row === null || ! is_string($row->id)) {
            throw new RuntimeException('Run BrowserFixtureSeeder first — the fixture tenant does not exist.');
        }

        app(TenantContext::class)->clear();
        app(TenantContext::class)->bind($row->id, ResolutionSource::Console);
        app(RlsSession::class)->set($row->id);

        /** @var User $agent */
        $agent = User::query()->where('email', BrowserFixtureSeeder::PRIMARY_EMAIL)->firstOrFail();

        // A prior AC-01 run that died mid-test leaves the shared account's
        // layout prefs dirty (collapsed rail, resized list) and every later
        // spec inherits it. Each seed starts the fixtures from the default
        // layout — the specs prove persistence WITHIN their own run.
        // UPDATE, not DELETE: the R-19b grant physics on this table revoke
        // DELETE from the app role (the first attempt was refused by
        // Postgres — the physics working), and an empty blob IS the default.
        DB::table('inbox_user_prefs')->update(['prefs' => '{}', 'updated_at' => DB::raw('clock_timestamp()')]);

        $savedViews = app(SavedViews::class);

        /** @var list<object{id: string, name: string, shared_to_type: string|null}> $existing */
        $existing = DB::table('inbox_saved_views')
            ->where('owner_user_id', $agent->identifier())
            ->whereNull('deleted_at')
            ->get(['id', 'name', 'shared_to_type'])
            ->all();
        $names = array_map(fn (object $view): string => $view->name, $existing);

        // "Mine": an owned personal view (the rail's ◉ section).
        if (! in_array('Fixture urgent open', $names, true)) {
            $savedViews->create($agent, 'Fixture urgent open', ['status' => ['open'], 'priority' => ['urgent']]);
        }

        // "Shared": owned by the agent, shared to a synthetic department id
        // (Doc 11 will narrow targeting; tenant-visible until then).
        if (! in_array('Fixture VIP pending', $names, true)) {
            $sharedId = $savedViews->create($agent, 'Fixture VIP pending', ['status' => ['pending_customer'], 'tags' => ['vip']]);
            $savedViews->share($agent, $sharedId, 'department', '0198f000-0000-7000-8000-000000000d08');
        }

        // FR-03: one degraded endpoint so the dot + banner-on-open render
        // from real rollup state (worst = degraded).
        app(ChannelHealthState::class)->set('telegram', 'fixture_brand_bot', 'degraded');

        app(RlsSession::class)->clear();
        app(TenantContext::class)->clear();
    }
}
