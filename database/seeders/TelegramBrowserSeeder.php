<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\TelegramBot;
use App\Support\Tenancy\ResolutionSource;
use App\Support\Tenancy\RlsSession;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Telegram admin-screen fixtures for the B6-S01/S02/S03 browser proof
 * (Doc 05 step 10): one registered bot, an ACTIVE connection and a PENDING
 * one (so the S02 confirm gate renders). Runs against the BrowserFixtureSeeder
 * tenant; idempotent. All content synthetic (CLAUDE.md §8): @fixture usernames.
 */
final class TelegramBrowserSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing', 'ci'])) {
            throw new RuntimeException('TelegramBrowserSeeder is a browser fixture and must never run outside local/testing/ci.');
        }

        $row = DB::connection('pgsql_platform')->table('tenants')->where('name', 'Fixture Operator A')->first();
        if ($row === null || ! is_string($row->id)) {
            throw new RuntimeException('Run BrowserFixtureSeeder first — the fixture tenant does not exist.');
        }

        app(TenantContext::class)->clear();
        app(TenantContext::class)->bind($row->id, ResolutionSource::Console);
        app(RlsSession::class)->set($row->id);

        $bot = TelegramBot::query()->firstWhere('username', 'fixture_brand_bot');
        if ($bot === null) {
            $bot = TelegramBot::query()->create([
                'telegram_bot_id' => 8_144_038_600,
                'username' => 'fixture_brand_bot',
                'display_name' => 'Fixture Brand Bot',
                'mode' => 'business',
                'bot_token' => '8144038600:FAKECREDbrowserfixturebrowserfixture',
            ]);
        }

        foreach ([
            ['fixture-conn-active', 'KemerBet Fixture', 'active', true, 900_000_001],
            ['fixture-conn-pending', 'Pending Fixture Account', 'pending', true, 900_000_002],
        ] as [$connId, $name, $state, $canReply, $userId]) {
            $exists = DB::table('telegram_business_connections')
                ->where('bot_id', $bot->id)->where('connection_id', $connId)->exists();
            if (! $exists) {
                DB::table('telegram_business_connections')->insert([
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $row->id,
                    'bot_id' => $bot->id,
                    'connection_id' => $connId,
                    'account_user_id' => $userId,
                    'account_name' => $name,
                    'account_username' => 'fixture_'.$state,
                    'user_chat_id' => $userId,
                    'state' => $state,
                    'can_reply' => $canReply,
                ]);
            }
        }

        app(TenantContext::class)->clear();
        app(RlsSession::class)->clear();
    }
}
