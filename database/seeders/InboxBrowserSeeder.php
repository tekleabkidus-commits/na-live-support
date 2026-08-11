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
        // Same self-healing for read markers (a thread spec run marks
        // conversations read and would eat the list spec's unread fixture).
        // UPDATE to NULL, not DELETE — the R-19b physics again; the writer's
        // LWW guard is NULL-safe so forward marks still land.
        DB::table('inbox_read_markers')->update(['last_read_at' => null, 'last_read_message_id' => null, 'updated_at' => DB::raw('clock_timestamp()')]);

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

        // An OWN-scoped agent (EC-01's browser proof: a REAL same-tenant
        // ticket outside this viewer's scope must 404 exactly like a missing
        // id). Holds the view keys; scope narrows to Own.
        if (User::query()->where('email', 'ownscope-fixture@fixture.example')->doesntExist()) {
            $ownRole = \App\Models\Role::query()->firstOrCreate(
                ['name' => 'Fixture Own Scope'],
                [
                    'color' => 'role-agent',
                    'permissions' => ['tickets.view', 'conversations.view', 'views.manage'],
                    'scopes' => ['tickets' => 'Own', 'conversations' => 'Own', 'customers' => 'Own', 'reports' => 'Own'],
                ],
            );
            User::query()->create([
                'name' => 'Own Scope Fixture',
                'email' => 'ownscope-fixture@fixture.example',
                'password' => \Illuminate\Support\Facades\Hash::make(BrowserFixtureSeeder::PASSWORD),
            ]);
            // Direct UPDATE (the BrowserFixtureSeeder idiom) — a model save
            // proved lossy for role_id here.
            User::query()->where('email', 'ownscope-fixture@fixture.example')->update([
                'role_id' => $ownRole->getKey(),
                'email_verified_at' => DB::raw('clock_timestamp()'),
                'password_set_at' => DB::raw('clock_timestamp()'),
            ]);
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

        // ── Step 10: the THREAD scene on its OWN customer (riding R1
        //    polluted the list specs' unread fixture — the thread spec's
        //    markRead consumed it). Two tickets for the boundary, assigned
        //    to the agent (My Open is the spec's door).
        [, $threadConv, $threadTicket] = $mk('Yonas Tesfaye', '@fixture_yonas_s10', [
            'priority' => 'high', 'assignee_id' => $agent->identifier(),
            'subject' => 'Bonus not credited', 'number' => 4820,
        ]);
        $this->seedThreadScene($tenantId, $agent, $threadConv, $threadTicket);
        $projector->recomputeTicket($threadTicket);
    }

    private function seedThreadScene(string $tenantId, User $agent, string $conversationId, string $openTicketId): void
    {
        $insert = function (array $attributes) use ($tenantId, $conversationId): string {
            $id = (string) Str::uuid7();
            DB::table('messages')->insert(array_merge([
                'id' => $id, 'tenant_id' => $tenantId, 'conversation_id' => $conversationId,
                'direction' => 'in', 'author_type' => 'customer', 'kind' => 'text',
                'is_internal' => false,
            ], $attributes));

            return $id;
        };

        // FR-14 boundary: a PRIOR closed ticket in the SAME conversation,
        // born before the open one (its created_at rewound below).
        /** @var string|null $closedId */
        $closedId = DB::table('tickets')->where('number', 4819)->value('id');
        if ($closedId === null) {
            $closedId = (string) Str::uuid7();
            DB::table('tickets')->insert([
                'id' => $closedId, 'tenant_id' => $tenantId, 'conversation_id' => $conversationId,
                'customer_id' => DB::table('tickets')->where('id', $openTicketId)->value('customer_id'),
                'number' => 4819, 'status' => 'closed', 'priority' => 'normal', 'source' => 'agent',
            ]);
        }
        // Fixture time-travel: tickets.created_at is R-19b pinned (the app
        // role's UPDATE is column-revoked — the first attempt was refused,
        // the physics working). The MIGRATOR session is the established
        // test-fixture idiom for backdating (tests/Pest.php precedent);
        // dev/ci-only by this seeder's environment guard.
        $migrator = DB::connection('pgsql_migrator');
        $migrator->statement('SELECT set_config(\'app.tenant_id\', ?, false)', [$tenantId]);
        $migrator->statement("UPDATE tickets SET created_at = clock_timestamp() - interval '3 days' WHERE id = ?", [$closedId]);

        // AC-08's card: the open ticket is an AI handoff with its packet.
        DB::table('tickets')->where('id', $openTicketId)->update(['source' => 'ai_handoff']);
        if (! DB::table('ticket_handoff_packets')->where('ticket_id', $openTicketId)->exists()) {
            DB::table('ticket_handoff_packets')->insert([
                'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'ticket_id' => $openTicketId,
                'schema_version' => 1,
                'payload' => json_encode([
                    'summary' => 'Customer requested a 2,500 ETB withdrawal 40 minutes ago and has not received it. Payment proof was shared.',
                    'intent' => 'withdrawal_delay', 'intent_confidence' => 0.91,
                    'sentiment' => 'negative', 'urgency' => 'high',
                    'customer_already_saw' => ['Withdrawal times', 'Verification steps'],
                ], JSON_THROW_ON_ERROR),
            ]);
        }

        if (DB::table('messages')->where('conversation_id', $conversationId)->where('body', 'like', 'SECRET-NOTE-S10%')->exists()) {
            return; // messages already seeded (idempotent re-run)
        }

        // AC-07's fixtures: outbound telegram in EVERY delivery state that
        // matters — including 'delivered', which the UI must draw as SENT.
        $insert(['direction' => 'out', 'author_type' => 'ai', 'body' => 'ሰላም! የመውጫ ጥያቄዎ ደርሶናል። አንድ ወኪል በቅርቡ ያግዝዎታል።', 'delivery_state' => 'sent', 'language' => 'am']);
        $insert(['direction' => 'out', 'author_type' => 'agent', 'author_id' => $agent->identifier(), 'body' => 'Thank you for the receipt. I have escalated this to our payments team.', 'delivery_state' => 'delivered', 'language' => 'en']);
        $insert(['direction' => 'out', 'author_type' => 'agent', 'author_id' => $agent->identifier(), 'body' => 'This one failed to send.', 'delivery_state' => 'failed_final', 'language' => 'en']);

        // US-03: the business owner's phone reply, attributed + marked.
        $insert(['direction' => 'in', 'author_type' => 'agent', 'author_id' => $agent->identifier(), 'body' => 'Handling this personally — the batch is stuck.', 'channel_meta' => json_encode(['reported_agent_type' => 'owner_phone', 'owner_phone_display' => 'KemerBet Owner'], JSON_THROW_ON_ERROR)]);

        // The note (never deliverable; print must NEVER show it — SEC-05).
        $insert(['author_type' => 'agent', 'author_id' => $agent->identifier(), 'kind' => 'note', 'is_internal' => true, 'body' => 'SECRET-NOTE-S10: receipt checks out, finance says the batch is stuck.']);

        // Three consecutive system events (the bulk collapse).
        foreach (['ticket.assigned', 'ticket.escalated', 'ticket.transferred'] as $code) {
            $insert(['author_type' => 'system', 'kind' => 'system', 'body' => null, 'channel_meta' => json_encode(['system_code' => $code], JSON_THROW_ON_ERROR)]);
        }

        // B12-S02: an EDITED customer message with its preserved revision.
        $edited = $insert(['body' => 'I sent 2,500 birr (edited: via telebirr)', 'language' => 'en']);
        DB::table('messages')->where('id', $edited)->update(['edited_at' => DB::raw('clock_timestamp()')]);
        DB::table('message_revisions')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId,
            'message_id' => $edited,
            'message_created_at' => DB::table('messages')->where('id', $edited)->value('created_at'),
            'conversation_id' => $conversationId,
            'revision_kind' => 'edit', 'source' => 'customer',
            'prior_body' => 'I sent 2,500 birr',
        ]);

        // B12-S01: a clean photo WITH description, and an INFECTED one.
        // A 1x1 PNG data URI: loads offline, no asset pipeline involved.
        $insert(['kind' => 'photo', 'body' => null, 'media' => json_encode([[
            'url' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            'kind' => 'photo', 'scan_status' => 'clean',
            'alt_text' => 'Payment receipt screenshot',
        ]], JSON_THROW_ON_ERROR)]);
        $insert(['kind' => 'document', 'body' => null, 'media' => json_encode([[
            'kind' => 'document', 'scan_status' => 'infected',
        ]], JSON_THROW_ON_ERROR)]);

        // EC-10: a 45-line message (clamps at 40).
        $insert(['body' => implode("\n", array_map(fn (int $line): string => "long message line {$line}", range(1, 45))), 'language' => 'en']);

        // Doc 04 EC-08: a reply referencing the FIRST message.
        /** @var string $firstId */
        $firstId = DB::table('messages')->where('conversation_id', $conversationId)->orderBy('created_at')->value('id');
        $insert(['body' => 'Replying to your first message.', 'reply_to_message_id' => $firstId]);

        app(\App\Domain\Inbox\ReadModels\ListProjector::class)->recomputeTicket($openTicketId);
    }
}
