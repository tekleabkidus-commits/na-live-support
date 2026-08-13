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
        $sceneCreated = false;
        if (! DB::table('ticket_list_items')->where('number', 4801)->exists()) {
            $this->seedListScene($row->id, $agent);
            $sceneCreated = true;
        }

        // SELF-HEAL (advisor-ordered, 2026-08-13 — the step-9 window-refresh
        // precedent extended to ticket STATE): specs legitimately consume
        // scene fixtures through real doors (a resolve, a claim, a tag edit),
        // and the once-only create above never repaired them — one stray
        // resolve was enough to break every later spec's data assumptions on
        // a fresh battery. Every seed now restores each scene ticket's
        // canonical status/assignee/priority/tags and reprojects. The status
        // write lives HERE, in fixture plumbing outside app/ — the
        // ticket-status-writer gate and its structural twin police app code,
        // and the R-20 trigger still stamps these updates like any other.
        $healedTicketIds = $this->healListScene($agent);

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

        // T-04: the seeded scene must be SEARCHABLE. The projector's direct
        // recomputeTicket path (deliberately) does not dispatch the indexer —
        // only the event-consuming path does — so browser fixtures feed the
        // index through the REAL job, synchronously (it waits on each
        // Meilisearch task), upsert-idempotent (C-09 posture). Each scene
        // conversation's TEXT messages index too (FR-25's message hits).
        //
        // BOUNDED + DIRTY-DRIVEN (2026-08-13): the loop used to walk EVERY
        // ticket_list_items row on EVERY seed — it grew with the shift
        // seeder's accumulation until one seed took 3m09s (measured; 356
        // task-waits) and blew the browser suite's 30 s hook/test budgets —
        // the §8 first-run test's in-test reseed could never fit, in any
        // environment. The search specs query SCENE rows only, so: index the
        // whole scene (number < 4900) once at creation, and afterwards only
        // tickets the heal actually touched (their docs carry
        // assignee/status scope keys, so a heal must re-index them).
        $itemsQuery = DB::table('ticket_list_items');
        if ($sceneCreated) {
            $itemsQuery->where('number', '<', 4900);
        } else {
            $itemsQuery->whereIn('ticket_id', $healedTicketIds);
        }
        /** @var list<object{ticket_id: string, conversation_id: string}> $items */
        $items = $itemsQuery->get(['ticket_id', 'conversation_id'])->all();
        foreach ($items as $item) {
            dispatch_sync(new \App\Jobs\Inbox\IndexSearchDocuments($row->id, $item->ticket_id));
            /** @var list<string> $messageIds */
            $messageIds = DB::table('messages')->where('conversation_id', $item->conversation_id)
                ->where('kind', 'text')->where('is_internal', false)->pluck('id')->all();
            foreach ($messageIds as $messageId) {
                dispatch_sync(new \App\Jobs\Inbox\IndexSearchDocuments($row->id, $item->ticket_id, $messageId));
            }
        }

        // FR-26 bell fixtures for the browser proof: the REAL categories the
        // fanout mints (assignment — Pest-proven end-to-end) plus the Doc 12
        // sla_warning FIXTURE row (no producer until Doc 12 — the category
        // closes the bell's type set) and a bulk summary. Synthetic event ids
        // (uuid) satisfy the dedupe index; identifiers only (Q7).
        if (DB::table('inbox_notifications')->where('user_id', $agent->identifier())->count() === 0) {
            /** @var object{ticket_id: string, conversation_id: string}|null $bellTicket */
            $bellTicket = DB::table('ticket_list_items')->where('number', 4801)->first(['ticket_id', 'conversation_id']);
            if ($bellTicket !== null) {
                foreach ([
                    ['notify.inbox.assignment', $bellTicket->ticket_id, $bellTicket->conversation_id, []],
                    ['notify.inbox.sla_warning', $bellTicket->ticket_id, $bellTicket->conversation_id, []],
                    ['notify.inbox.bulk_completed', null, null, ['ok' => 18, 'failed' => 2]],
                ] as [$category, $ticketId, $conversationId, $extra]) {
                    DB::table('inbox_notifications')->insert([
                        'id' => (string) Str::uuid7(), 'tenant_id' => $row->id,
                        'user_id' => $agent->identifier(), 'category' => $category,
                        'ticket_id' => $ticketId, 'conversation_id' => $conversationId,
                        'payload' => json_encode(['event_id' => (string) Str::uuid7()] + $extra, JSON_THROW_ON_ERROR),
                    ]);
                }
            }
        }
        // Self-healing (the prefs idiom): a prior mark-all-read run leaves
        // the fixture rows read — every seed re-arms them. read_at is the
        // ONLY app-updatable column (R-19b), and null is a legal value of it.
        DB::table('inbox_notifications')->where('user_id', $agent->identifier())->update(['read_at' => null]);

        app(RlsSession::class)->clear();
        app(TenantContext::class)->clear();
    }

    /** @return list<string> the ticket ids whose state was actually repaired */
    private function healListScene(User $agent): array
    {
        $projector = app(\App\Domain\Inbox\ReadModels\ListProjector::class);

        /** @var array<int, array{status: string, assignee: string|null, priority: string, tags: string}> $canonical */
        $canonical = [
            4801 => ['status' => 'open', 'assignee' => $agent->identifier(), 'priority' => 'urgent', 'tags' => '{withdrawal,vip,telebirr}'],
            4802 => ['status' => 'open', 'assignee' => null, 'priority' => 'normal', 'tags' => '{}'],
            4803 => ['status' => 'open', 'assignee' => null, 'priority' => 'normal', 'tags' => '{}'],
            4899 => ['status' => 'open', 'assignee' => null, 'priority' => 'normal', 'tags' => '{}'],
            4830 => ['status' => 'open', 'assignee' => null, 'priority' => 'normal', 'tags' => '{}'],
            4831 => ['status' => 'open', 'assignee' => $agent->identifier(), 'priority' => 'normal', 'tags' => '{}'],
            4832 => ['status' => 'open', 'assignee' => $agent->identifier(), 'priority' => 'normal', 'tags' => '{}'],
            4819 => ['status' => 'closed', 'assignee' => null, 'priority' => 'normal', 'tags' => '{}'],
            4820 => ['status' => 'open', 'assignee' => $agent->identifier(), 'priority' => 'high', 'tags' => '{}'],
        ];
        foreach (range(1, 14) as $i) {
            $canonical[4803 + $i] = ['status' => 'open', 'assignee' => null, 'priority' => 'normal', 'tags' => '{}'];
        }

        $healed = [];
        foreach ($canonical as $number => $want) {
            /** @var object{id: string, status: string, assignee_id: string|null, priority: string, tags: string|null, merged_into: string|null}|null $ticket */
            $ticket = DB::table('tickets')->where('number', $number)
                ->first(['id', 'status', 'assignee_id', 'priority', 'tags', 'merged_into']);
            if ($ticket === null) {
                continue; // scene variant without this row — nothing to heal
            }
            $drifted = $ticket->status !== $want['status']
                || $ticket->assignee_id !== $want['assignee']
                || $ticket->priority !== $want['priority']
                || ($ticket->tags ?? '{}') !== $want['tags']
                || $ticket->merged_into !== null;
            if (! $drifted) {
                continue;
            }
            DB::table('tickets')->where('id', $ticket->id)->update([
                'status' => $want['status'],
                'assignee_id' => $want['assignee'],
                'priority' => $want['priority'],
                'tags' => $want['tags'],
                'merged_into' => null,
            ]);
            $projector->recomputeTicket($ticket->id);
            $healed[] = $ticket->id;
        }

        // Split children became scene state when the split surface landed
        // (step 14): live linked-work tickets on canon conversations would
        // trip FR-21's conversation cap on the NEXT run's first split.
        // Retire them (closed, never deleted — N-05) and converge their rows.
        $canonConversations = DB::table('tickets')
            ->whereIn('number', array_keys($canonical))
            ->pluck('conversation_id')->all();
        /** @var list<string> $liveChildren */
        $liveChildren = DB::table('tickets')
            ->whereIn('conversation_id', $canonConversations)
            ->where('linked_work', true)
            ->whereIn('status', ['new', 'open', 'pending_customer', 'pending_internal', 'escalated'])
            ->pluck('id')->all();
        if ($liveChildren !== []) {
            DB::table('tickets')->whereIn('id', $liveChildren)->update(['status' => 'closed']);
            foreach ($liveChildren as $childId) {
                $projector->recomputeTicket($childId);
            }
        }

        // Drafts became scene state the moment the composer learned to
        // HYDRATE them (FR-22's read half, step 14): a run's leftover draft
        // resurfaces in the next run's composer. Restore draft canon — R2's
        // fixture draft stands, every other agent row tombstones (the table
        // REVOKEs DELETE; same shape as DraftStore::clearOnSend).
        $kidistConversation = DB::table('conversations')
            ->join('customers', 'customers.id', '=', 'conversations.customer_id')
            ->where('customers.username', '@fixture_kidist_s09')
            ->value('conversations.id');
        DB::table('message_drafts')
            ->where('user_id', $agent->identifier())
            ->when(
                is_string($kidistConversation),
                static fn ($query) => $query->where('conversation_id', '!=', $kidistConversation),
            )
            ->update(['body' => null, 'cleared_at' => DB::raw('now()')]);
        if (is_string($kidistConversation)) {
            DB::table('message_drafts')
                ->where('user_id', $agent->identifier())
                ->where('conversation_id', $kidistConversation)
                ->update(['body' => 'fixture draft in progress', 'cleared_at' => null]);
        }

        // R1's hand-tuned projection columns (sentiment/SLA) do not survive a
        // recompute — re-apply them whenever the row exists, same values as
        // the scene's first seeding.
        DB::table('ticket_list_items')->where('number', 4801)->update([
            'sentiment' => 'negative', 'sla_state' => 'breached',
            'sla_due_at' => DB::raw("clock_timestamp() - interval '12 minutes'"),
        ]);

        return $healed;
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

        // Rescue view (Doc 05 US-04 closing row): a channel_unreachable
        // ticket so the rescue system view renders real membership.
        if (! DB::table('ticket_list_items')->where('number', 4830)->exists()) {
            [, $rescueConv, $rescueTicket] = $mk('Rescue Fixture Player', '@fixture_rescue_s12', [
                'number' => 4830, 'channel_unreachable' => true,
            ]);
            $message($rescueConv, 'Message stuck — the bot was disconnected.', 'text', 'en');
            $projector->recomputeTicket($rescueTicket);
        }

        // FR-20 REAL DATA (the advisor's step-13 order, delivered at step 12):
        // the composer's paused/blocked cards resolve from REAL substrate —
        // a PAUSED business connection row and a blocked_by_user delivery
        // error — through ThreadController::conversationMeta. No seam props.
        if (! DB::table('ticket_list_items')->where('number', 4831)->exists()) {
            $bot = \App\Models\TelegramBot::query()->firstWhere('username', 'fixture_brand_bot');
            if ($bot === null) {
                $bot = \App\Models\TelegramBot::query()->create([
                    'telegram_bot_id' => 8_144_038_600,
                    'username' => 'fixture_brand_bot',
                    'display_name' => 'Fixture Brand Bot',
                    'mode' => 'business',
                    'bot_token' => '8144038600:FAKECREDbrowserfixturebrowserfixture',
                ]);
            }
            if (! DB::table('telegram_business_connections')->where('connection_id', 'fixture-conn-paused-s12')->exists()) {
                DB::table('telegram_business_connections')->insert([
                    'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId,
                    'bot_id' => $bot->getKey(), 'connection_id' => 'fixture-conn-paused-s12',
                    'account_user_id' => 900_000_012, 'account_name' => 'Paused Fixture Account',
                    'user_chat_id' => 900_000_012, 'state' => 'paused', 'can_reply' => false,
                ]);
            }
            [, $pausedConv, $pausedTicket] = $mk('Paused Conn Player', '@fixture_paused_s12', [
                'number' => 4831, 'assignee_id' => $agent->identifier(), 'subject' => 'Paused connection fixture',
            ], ['business_connection_id' => 'fixture-conn-paused-s12']);
            $message($pausedConv, 'Still waiting on my bonus question.', 'text', 'en');
            $projector->recomputeTicket($pausedTicket);

            [, $blockedConv, $blockedTicket] = $mk('Blocked Fixture Player', '@fixture_blocked_s12', [
                'number' => 4832, 'assignee_id' => $agent->identifier(), 'subject' => 'Blocked by user fixture',
            ]);
            $message($blockedConv, 'Please stop contacting me.', 'text', 'en');
            // The Doc 04 shape: MessageRepository writes {"reason": ...} and
            // HealthRollup reads delivery_error->>'reason' — same key here.
            DB::table('messages')->insert([
                'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'conversation_id' => $blockedConv,
                'direction' => 'out', 'author_type' => 'agent', 'kind' => 'text',
                'body' => 'We have paused your promotions as requested.', 'is_internal' => false,
                'delivery_state' => 'failed_final',
                'delivery_error' => json_encode(['reason' => 'blocked_by_user'], JSON_THROW_ON_ERROR),
            ]);
            $projector->recomputeTicket($blockedTicket);
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
