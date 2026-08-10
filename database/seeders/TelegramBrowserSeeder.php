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

        // ── Step 11 fixtures (B6-S04/S05/S06) ──────────────────────────────

        // A second bot in the DOWN state so the S06 down-banner frames render
        // from REAL column data (F01 light) while the first stays healthy
        // (F01 dark) — the drawn light/dark narrative, data-driven.
        $downBot = TelegramBot::query()->firstWhere('username', 'fixture_promo_bot');
        if ($downBot === null) {
            $downBot = TelegramBot::query()->create([
                'telegram_bot_id' => 8_144_038_601,
                'username' => 'fixture_promo_bot',
                'display_name' => 'Fixture Promo Bot',
                'mode' => 'business',
                'bot_token' => '8144038601:FAKECREDbrowserfixturebrowserfixture',
            ]);
            DB::update(
                "UPDATE telegram_bots SET health_status = 'down', health_changed_at = clock_timestamp() - interval '41 minutes',
                        webhook_pending_updates = 912, last_inbound_at = clock_timestamp() - interval '41 minutes',
                        last_polled_at = clock_timestamp() WHERE id = ?",
                [$downBot->id],
            );
        }

        // A paused, reply-refused connection on the down bot (F03's second row).
        if (! DB::table('telegram_business_connections')->where('bot_id', $downBot->id)->exists()) {
            DB::table('telegram_business_connections')->insert([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $row->id,
                'bot_id' => $downBot->id,
                'connection_id' => 'fixture-conn-paused',
                'account_user_id' => 900_000_003,
                'account_name' => 'Fixture VIP Account',
                'account_username' => 'fixture_paused',
                'user_chat_id' => 900_000_003,
                'state' => 'paused',
                'can_reply' => false,
            ]);
            DB::update('UPDATE telegram_business_connections SET sends_blocked_count = 6 WHERE connection_id = ?', ['fixture-conn-paused']);
        }

        // The menu (E-frames): a published version + a draft, EN complete,
        // AM missing two labels (the drawn 4/6 completeness state).
        $menuId = DB::table('telegram_menus')->where('bot_id', $bot->id)->where('language', 'en')->value('id');
        if (! is_string($menuId)) {
            $definition = [
                ['row' => 1, 'buttons' => [
                    ['label' => 'Deposit help', 'label_am' => 'ገንዘብ ስለማስገባት', 'target_key' => 'kb-deposit'],
                    ['label' => 'Withdrawal status', 'label_am' => 'የወጪ ሁኔታ', 'target_key' => 'form-withdrawal'],
                ]],
                ['row' => 2, 'buttons' => [
                    ['label' => 'Bonus & promos', 'label_am' => 'ጉርሻ እና ማስተዋወቂያ', 'target_key' => 'kb-bonus'],
                    ['label' => 'Verify account', 'label_am' => null, 'target_key' => 'form-kyc'],
                    ['label' => 'Betting rules', 'label_am' => null, 'target_key' => 'link-rules'],
                ]],
                ['row' => 3, 'buttons' => [
                    ['label' => 'Talk to a person', 'label_am' => 'ሰው ማነጋገር', 'target_key' => 'handoff'],
                ]],
            ];
            $menuId = (string) Str::uuid7();
            DB::table('telegram_menus')->insert([
                'id' => $menuId, 'tenant_id' => $row->id, 'bot_id' => $bot->id,
                'language' => 'en', 'draft' => json_encode($definition),
            ]);
            foreach ([5, 6, 7] as $v) {
                DB::table('telegram_menu_versions')->insert([
                    'id' => (string) Str::uuid7(), 'tenant_id' => $row->id, 'menu_id' => $menuId,
                    'version' => $v, 'definition' => json_encode($definition),
                    'published_by' => (string) Str::uuid7(),
                ]);
            }
            $liveId = DB::table('telegram_menu_versions')->where('menu_id', $menuId)->where('version', 7)->value('id');
            DB::table('telegram_menus')->where('id', $menuId)->update(['active_version_id' => $liveId]);

            foreach ([
                ['kb-deposit', 'kb_flow', 'KB-118'], ['form-withdrawal', 'form', 'withdrawal_check'],
                ['kb-bonus', 'kb_flow', 'KB-121'], ['form-kyc', 'form', 'kyc_upload'],
                ['link-rules', 'url', 'https://fixture.example/rules'], ['handoff', 'human_handoff', null],
            ] as [$key, $type, $ref]) {
                DB::table('telegram_flow_targets')->insert([
                    'id' => (string) Str::uuid7(), 'tenant_id' => $row->id, 'bot_id' => $bot->id,
                    'key' => $key, 'target_type' => $type, 'target_ref' => $ref,
                ]);
            }
        }

        // Templates (E07–E11): EN complete with two customised; AM missing
        // window_guidance (the drawn 5/6 + "ጎደለ" state).
        if (! DB::table('telegram_templates')->where('bot_id', $bot->id)->exists()) {
            $bodies = [
                'en' => [
                    'greeting' => 'Hello {{first_name}}! You have reached fixture support. Tell me what happened and I will help, or tap a button below. Typical wait right now: {{queue_wait}}.',
                    'off_hours' => 'We are closed right now. Leave your message and we will reply when we open at 8:00.',
                    'handoff' => 'Connecting you with a person — creating your ticket now.',
                    'resolved_csat' => 'Glad we could help! How did we do today?',
                    'redirect' => 'For account help please message @fixture_account — tap to open t.me/fixture_account.',
                    'window_guidance' => 'You can reply here again after the customer writes back.',
                ],
                'am' => [
                    'greeting' => 'ሰላም {{first_name}}! የፊክስቸር ድጋፍ ደርሰዋል። ምን እንደተፈጠረ ይንገሩን፣ ወይም ከታች ያለውን አዝራር ይንኩ።',
                    'off_hours' => 'አሁን ዝግ ነን። መልእክትዎን ይተዉ፣ ስንከፍት እንመልስልዎታለን።',
                    'handoff' => 'ከሰው ጋር እያገናኘንዎ ነው — ትኬትዎ እየተፈጠረ ነው።',
                    'resolved_csat' => 'መርዳት በመቻላችን ደስ ብሎናል! ዛሬ እንዴት ነበርን?',
                    'redirect' => 'ለመለያ እርዳታ እባክዎ @fixture_account ይላኩ — t.me/fixture_account ይክፈቱ።',
                    // window_guidance deliberately ABSENT (the E11 missing case).
                ],
            ];
            $editor = DB::table('users')->where('tenant_id', $row->id)->value('id');
            foreach ($bodies as $language => $set) {
                foreach ($set as $key => $body) {
                    DB::table('telegram_templates')->insert([
                        'id' => (string) Str::uuid7(), 'tenant_id' => $row->id, 'bot_id' => $bot->id,
                        'language' => $language, 'template_key' => $key, 'body' => $body,
                        // greeting + handoff carry updated_by = customised
                        // (the "Edited" badge rows); the rest are shipped.
                        'updated_by' => in_array($key, ['greeting', 'handoff'], true) && is_string($editor) ? $editor : null,
                    ]);
                }
            }
        }

        // Raw updates for the F06 viewer — carries every masked-field shape
        // (phone, contact payload, forwarded origin) so the browser proof can
        // assert the masking on real read paths. Synthetic +251-9000 numbers.
        $log = app(\App\Domain\Telegram\Health\RawUpdateLog::class);
        if (count($log->read($downBot->id)) > 0) {
            app(TenantContext::class)->clear();
            app(RlsSession::class)->clear();

            return; // idempotent: the viewer fixtures are already in place
        }
        $log->push($downBot->id, [
            'update_id' => 900411,
            'business_connection' => ['id' => 'fixture-conn-paused', 'can_reply' => true, 'is_enabled' => true],
        ]);
        $log->push($downBot->id, [
            'update_id' => 900412,
            'business_message' => [
                'message_id' => 42,
                'business_connection_id' => 'fixture-conn-paused',
                'from' => ['id' => 900000003, 'first_name' => 'Fixture', 'phone_number' => '+251900001234'],
                'contact' => ['phone_number' => '+251900005678', 'first_name' => 'Shared Contact'],
                'forward_from' => ['id' => 900000009, 'first_name' => 'Forwarded Author'],
                'forward_sender_name' => 'Hidden Author',
                'text' => 'fixture message text that stays visible',
            ],
        ]);

        app(TenantContext::class)->clear();
        app(RlsSession::class)->clear();
    }
}
