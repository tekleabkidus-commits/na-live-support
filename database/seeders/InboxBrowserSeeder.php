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
use Illuminate\Support\Str;
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

        // ── Step 9: the FR-09 list scene, projected through the REAL
        //    ListProjector (recompute-from-truth — the browser rows are the
        //    projector's own rows, not hand-written fixtures). Idempotent on
        //    the marker customer. All synthetic (@fixture…, CLAUDE.md §8).
        if (! DB::table('ticket_list_items')->where('number', 4801)->exists()) {
            $this->seedListScene($row->id, $agent);
        }

        // CLOCK-BEARING fixture state decays between runs (a 45-minute
        // window seeded this morning is expired by lunch — the capture run
        // caught exactly that). Every seed refreshes it, then reprojects.
        /** @var string|null $kidistTicket */
        $kidistTicket = DB::table('ticket_list_items')->where('number', 4802)->value('ticket_id');
        if (is_string($kidistTicket)) {
            DB::table('conversations')
                ->whereIn('id', DB::table('ticket_list_items')->where('ticket_id', $kidistTicket)->select('conversation_id'))
                ->update([
                    'reply_window_state' => 'closing',
                    'reply_window_expires_at' => DB::raw("clock_timestamp() + interval '45 minutes'"),
                ]);
            app(\App\Domain\Inbox\ReadModels\ListProjector::class)->recomputeTicket($kidistTicket);
        }

        app(RlsSession::class)->clear();
        app(TenantContext::class)->clear();
    }

    private function seedListScene(string $tenantId, User $agent): void
    {
        $projector = app(\App\Domain\Inbox\ReadModels\ListProjector::class);
        $number = 4801;

        $mk = function (string $name, string $username, array $ticket = [], array $conversation = []) use ($tenantId, &$number): array {
            $customer = \App\Models\Customer::query()->firstOrCreate(
                ['username' => $username],
                ['display_name' => $name, 'is_shell' => true],
            );
            $conversationId = (string) Str::uuid7();
            DB::table('conversations')->insert(array_merge([
                'id' => $conversationId, 'tenant_id' => $tenantId, 'channel' => 'telegram',
                'channel_ref_id' => 'fixture_brand_bot', 'external_chat_id' => 'chat-'.Str::random(10),
                'customer_id' => $customer->identifier(), 'state' => 'active',
            ], $conversation));
            $ticketId = (string) Str::uuid7();
            DB::table('tickets')->insert(array_merge([
                'id' => $ticketId, 'tenant_id' => $tenantId, 'conversation_id' => $conversationId,
                'customer_id' => $customer->identifier(), 'number' => $number++,
                'status' => 'open', 'priority' => 'normal', 'source' => 'agent',
            ], $ticket));

            return [$customer, $conversationId, $ticketId];
        };

        $message = function (string $conversationId, string $body, string $kind = 'text', ?string $language = null) use ($tenantId): void {
            DB::table('messages')->insert([
                'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'conversation_id' => $conversationId,
                'direction' => 'in', 'author_type' => 'customer', 'kind' => $kind,
                'body' => $kind === 'text' ? $body : null, 'is_internal' => false, 'language' => $language,
            ]);
        };

        // R1 — assigned (My Open), urgent, SLA breached (Doc 12 seam), 2
        // unread Amharic inbound, tags with overflow.
        [, $conv1, $ticket1] = $mk('አበበ ታደሰ', '@fixture_abebe_s09', [
            'priority' => 'urgent', 'assignee_id' => $agent->identifier(),
            'subject' => 'Withdrawal delayed', 'tags' => '{withdrawal,vip,telebirr}',
        ]);
        $message($conv1, 'ብር 2,500 ለማውጣት ሞክሬ ነበር ግን እስካሁን አልደረሰኝም', 'text', 'am');
        $message($conv1, 'እባክዎ በአስቸኳይ ይመልከቱልኝ', 'text', 'am');
        $projector->recomputeTicket($ticket1);
        DB::table('ticket_list_items')->where('ticket_id', $ticket1)->update([
            'sentiment' => 'negative', 'sla_state' => 'breached',
            'sla_due_at' => DB::raw("clock_timestamp() - interval '12 minutes'"),
        ]);

        // R2 — unassigned, closing window (the WindowChip), photo-only last
        // message (EC-11's localized placeholder), a live draft for the agent.
        [, $conv2, $ticket2] = $mk('Kidist Haile', '@fixture_kidist_s09', [], [
            'reply_window_state' => 'closing',
            'reply_window_expires_at' => DB::raw("clock_timestamp() + interval '45 minutes'"),
        ]);
        $message($conv2, '', 'photo', 'en');
        $projector->recomputeTicket($ticket2);
        DB::table('message_drafts')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId,
            'user_id' => $agent->identifier(), 'conversation_id' => $conv2,
            'body' => 'fixture draft in progress',
        ]);

        // R3 + R4 — Doc 05 EC-04: ONE customer, TWO conversations, both open
        // → dual-channel badges, the stale one flagged older-activity.
        [$hybrid, $conv3, $ticket3] = $mk('Hana Girma', '@fixture_hana_s09');
        $message($conv3, 'Thanks, the deposit arrived. One more question…', 'text', 'en');
        $conv4 = (string) Str::uuid7();
        DB::table('conversations')->insert([
            'id' => $conv4, 'tenant_id' => $tenantId, 'channel' => 'telegram',
            'channel_ref_id' => 'fixture_direct_bot', 'external_chat_id' => 'chat-'.Str::random(10),
            'customer_id' => $hybrid->identifier(), 'state' => 'active',
        ]);
        $ticket4 = (string) Str::uuid7();
        DB::table('tickets')->insert([
            'id' => $ticket4, 'tenant_id' => $tenantId, 'conversation_id' => $conv4,
            'customer_id' => $hybrid->identifier(), 'number' => 4899,
            'status' => 'open', 'priority' => 'normal', 'source' => 'agent',
        ]);
        $message($conv4, 'Following up here from the other bot', 'text', 'en');
        $projector->recomputeTicket($ticket3);
        $projector->recomputeTicket($ticket4); // fresher — recomputes the group

        // R5…R18 — volume for FR-12's scroll restoration (the pane must
        // actually overflow at 900 px).
        for ($i = 1; $i <= 14; $i++) {
            [, $convN, $ticketN] = $mk("Fixture Filler {$i}", "@fixture_filler_s09_{$i}");
            $message($convN, "Filler inquiry number {$i}", 'text', 'en');
            $projector->recomputeTicket($ticketN);
        }
    }
}
