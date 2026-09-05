<?php

declare(strict_types=1);

namespace App\Console\Commands\Widget;

use App\Domain\Conversations\Outbound\ComposeOutbound;
use App\Domain\Routing\Intake\TenantDefaultDepartment;
use App\Domain\Routing\Pipeline\RoutingPipeline;
use App\Domain\Tickets\Assignment\AssignTicket;
use App\Domain\Tickets\Assignment\RoutingCommit;
use App\Domain\Tickets\Creation\CreateTicket;
use App\Domain\Tickets\Creation\NewTicket;
use App\Domain\Tickets\Creation\TicketSource;
use App\Domain\Tickets\Resolution\ResolveTicket;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Tenancy\ResolutionSource;
use App\Support\Tenancy\RlsSession;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\BrowserFixtureSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Doc 13 SESSION-13 — THE HARNESS DRIVER (R-D13-1.1; local/testing/ci ONLY,
 * fixtures only). The browser suites cannot reach the staff side, so the
 * things a staff member or the platform would do to the harness visitor's
 * conversation are driven through the REAL doors from here — never a
 * shortcut past them:
 *
 *   widget:harness handoff      --visitor=<id>   the visitor's ticket through Doc 06's create door,
 *                                                routed by Doc 11 into the default queue (the queue card)
 *   widget:harness queue-jump                    an URGENT fixture ticket ahead of everyone (EC-06's test)
 *   widget:harness queue-clear                   every waiting ticket assigned away (a deterministic #1)
 *   widget:harness agent-join   --visitor=<id>   Doc 11's system assignment to the fixture agent
 *   widget:harness staff-reply  --visitor=<id> --text=…   a staff message through Doc 04's outbound door
 *   widget:harness resolve      --visitor=<id>   Doc 06's resolve door (Doc 12's CSAT armer follows)
 *   widget:harness setting      --key=widget.cache.content --value=false   a registered setting through the one writer
 *
 * `--latest` picks the tenant's newest webchat conversation. Actor = the
 * fixture tenant's primary Super Admin (BrowserFixtureSeeder).
 */
final class WidgetHarness extends Command
{
    protected $signature = 'widget:harness {action} {--visitor=} {--latest} {--text=} {--key=} {--value=}';

    protected $description = 'Doc 13 HARNESS driver: staff-side actions on the harness visitor through the real doors (local/testing/ci only)';

    public function handle(TenantContext $context, RlsSession $rls): int
    {
        if (! app()->environment(['local', 'testing', 'ci'])) {
            $this->error('widget:harness runs in local/testing/ci only');

            return self::FAILURE;
        }
        $row = DB::connection('pgsql_platform')->table('tenants')->where('name', 'Fixture Operator A')->first(['id']);
        if ($row === null || ! is_string($row->id)) {
            $this->error('run BrowserFixtureSeeder first');

            return self::FAILURE;
        }
        $context->clear();
        $context->bind($row->id, ResolutionSource::Console);
        $rls->set($row->id);
        // S13 Batch C: the driver heals its own fixture. On a fresh DB whose FIRST browser action is a staff-side
        // command (before any spec has fetched the key through the seeder) the harness widget row did not exist
        // (ModelNotFound on `config`) — found by the Batch C fresh-DB verdict's --last-failed subset. The seeder is
        // idempotent and clears the console context when it finishes, so the binding is re-applied after it.
        if (! \App\Models\Widget::query()->whereKey(\Database\Seeders\WidgetHarnessSeeder::WIDGET_ID)->exists()) {
            $this->callSilently('db:seed', ['--class' => \Database\Seeders\WidgetHarnessSeeder::class, '--force' => true]);
            $context->clear();
            $context->bind($row->id, ResolutionSource::Console);
            $rls->set($row->id);
        }
        // Same lesson, one layer down: on a fresh DB the default department/queue the driver routes into come from
        // Doc 07's InboxBrowserSeeder, which every inbox spec runs BEFORE the widget specs in a full battery (the
        // alphabetical order CI's Stage 4 has). A subset run had no such predecessor. The driver runs that same
        // seeder — the full battery's own precondition, not a new structure — when the default is missing.
        if (app(TenantDefaultDepartment::class)->resolve() === null) {
            $this->callSilently('db:seed', ['--class' => \Database\Seeders\InboxBrowserSeeder::class, '--force' => true]);
            $context->clear();
            $context->bind($row->id, ResolutionSource::Console);
            $rls->set($row->id);
        }
        try {
            return $this->run_((string) $this->argument('action'));
        } finally {
            $context->clear();
            $rls->clear();
        }
    }

    private function run_(string $action): int
    {
        $agent = User::query()->where('email', BrowserFixtureSeeder::PRIMARY_EMAIL)->firstOrFail();
        $general = app(TenantDefaultDepartment::class)->resolve();
        $department = $general['department_id'] ?? null;
        $queue = $general['queue_id'] ?? null;
        if (! is_string($department) || ! is_string($queue)) {
            $this->error('no default department/queue');

            return self::FAILURE;
        }

        if ($action === 'kb-seed') {
            // Doc 10's doors: a category + a PUBLISHED public article in en (the am fallback serves en — E07), reindexed.
            $slug = 'harness-payments-'.Str::lower(Str::random(5));
            $categoryId = app(\App\Domain\Kb\Categories\CategoryTree::class)->create(['en' => '[FIXTURE] Deposits & withdrawals', 'am' => '[FIXTURE] ተቀማጭ እና ማውጣት'], $slug, null, true, 'public', $agent->identifier());
            $article = app(\App\Domain\Kb\Articles\ArticleWriter::class)->create($categoryId, 'public', 'en', '[FIXTURE] How long do withdrawals take?', $agent->identifier());
            app(\App\Domain\Kb\Editor\WorkingCopy::class)->save($article['variant_id'], ['blocks' => [
                ['id' => 'b1', 'type' => 'paragraph', 'text' => '[FIXTURE] Withdrawals to telebirr and CBE Birr normally arrive within 15 minutes of approval. Bank transfers take up to one business day.'],
                ['id' => 'b2', 'type' => 'paragraph', 'text' => '[FIXTURE] Requests made after 17:00 are processed the next morning.'],
            ]]);
            app(\App\Domain\Kb\Workflow\Transitions::class)->publish($article['variant_id'], $agent, 'harness');
            app(\App\Domain\Kb\Search\KbSearchDocuments::class)->writeFor($article['variant_id']);
            $this->line('KB_CATEGORY='.$categoryId);
            $this->line('KB_ARTICLE='.$article['article_id']);
            $this->line('KB_VARIANT='.$article['variant_id']);

            return self::SUCCESS;
        }

        if ($action === 'config') {
            // A LIVE widget config version for the harness widget (the Designer's publish door lands at step 10 and
            // replaces this fixture write): pre-chat mode, the seeded forms, a proactive rule, the context allowlist.
            $widget = \App\Models\Widget::query()->findOrFail(\Database\Seeders\WidgetHarnessSeeder::WIDGET_ID);
            app(\App\Domain\Webchat\Seeding\WidgetFormSeeder::class)->seed($agent->identifier());
            $preChat = DB::table('forms')->where('name', \App\Domain\Webchat\Seeding\WidgetFormSeeder::PRE_CHAT)->value('id');
            $offline = DB::table('forms')->where('name', \App\Domain\Webchat\Seeding\WidgetFormSeeder::OFFLINE)->value('id');
            $mode = (string) ($this->option('value') ?: 'off');
            $messagesOnly = $mode === 'messages-only'; // B3-A01's matrix: Messages-only mode (no Home, no Help)
            $config = [
                'content' => ['tabs' => ['home' => ! $messagesOnly, 'help' => ! $messagesOnly], 'pre_chat_form_id' => $preChat, 'pre_chat_mode' => in_array($mode, ['off', 'optional', 'required'], true) ? $mode : 'off', 'offline_form_id' => $offline,
                    'shortcuts' => [['kind' => 'chat', 'label' => ['en' => 'Where is my withdrawal?', 'am' => 'ገንዘቤ የት ደረሰ?'], 'target' => 'withdrawal'], ['kind' => 'url', 'label' => ['en' => 'Payment methods', 'am' => 'የክፍያ ዘዴዎች'], 'target' => 'https://customer.harness.localhost:8443/payments.html']]],
                'behavior' => [
                    'proactive' => [['id' => 'deposit-help', 'condition' => ['kind' => 'url', 'match' => '/deposit'], 'action' => ['kind' => 'message', 'message' => ['en' => '[FIXTURE] Stuck? I can check how long your deposit should take.', 'am' => '[FIXTURE] ተጣብቀዋል? ተቀማጭዎ ምን ያህል ጊዜ እንደሚወስድ ማረጋገጥ እችላለሁ።']]], ['id' => 'payments-help', 'condition' => ['kind' => 'url', 'match' => '/payments'], 'action' => ['kind' => 'message', 'message' => ['en' => '[FIXTURE] Questions about a payment method? Ask here.', 'am' => '[FIXTURE] ስለ ክፍያ ዘዴ ጥያቄ አለዎት? እዚህ ይጠይቁ።']]]],
                    'context_allowlist' => ['page', 'plan'],
                ],
            ];
            // S13 step 10: the Designer's publish door (validation + the A-62 gate + the audit + the config push) replaces the fixture write.
            $published = app(\App\Domain\Webchat\Designer\WidgetConfigPublisher::class)->publish($widget, $config, $agent->identifier(), 'harness');
            $id = $published['version_id'];
            $this->line('CONFIG_VERSION='.$id.' pre_chat='.$mode);

            return self::SUCCESS;
        }

        if ($action === 'chatbot-off' || $action === 'chatbot-on') {
            // K04 / FS-04: Doc 09's kill switch for the webchat chatbot surface — the widget shows the straight-to-queue
            // experience (no AI bubble, the handoff line + the queue card); `capabilities.chatbot` answers false.
            $id = app(\App\Domain\Ai\KillSwitch\KillSwitches::class)->flip('chatbot', null, null, $action === 'chatbot-off', 'harness fixture', $agent->identifier()); // tenant-wide: the switch grid's channel names are Doc 09's (telegram|web), the surface switch covers webchat
            $this->line('SWITCH='.$id.' '.$action);

            return self::SUCCESS;
        }

        if ($action === 'hours-close' || $action === 'hours-open') {
            // Doc 12's calendar door: the tenant default calendar's weekdays — closed = a one-minute window at 00:00, open = all day.
            $writer = app(\App\Domain\Hours\CalendarWriter::class);
            $calendarId = DB::table('business_calendars')->where('is_tenant_default', true)->whereNull('department_id')->value('id');
            $weekdays = [];
            for ($d = 1; $d <= 7; $d++) {
                $weekdays[(string) $d] = $action === 'hours-open' ? [['open' => '00:00', 'close' => '24:00']] : [['open' => '00:00', 'close' => '00:01']];
            }
            if (is_string($calendarId)) {
                $writer->update($calendarId, ['weekdays' => $weekdays], $agent->identifier());
            } else {
                $calendarId = $writer->create('[FIXTURE] Harness hours', 'Africa/Addis_Ababa', $weekdays, $agent->identifier(), true, null);
            }
            $this->line('CALENDAR='.$calendarId.' '.$action);

            return self::SUCCESS;
        }

        if ($action === 'identity-sign') {
            // FR-07: a signed payload for THIS widget — the secret minted through the one minter when none exists.
            $widget = \App\Models\Widget::query()->findOrFail(\Database\Seeders\WidgetHarnessSeeder::WIDGET_ID);
            $secret = app(\App\Domain\Webchat\Identity\WidgetSecretMinter::class)->mint($widget, $agent->identifier());
            $payload = ['user_ref' => (string) ($this->option('key') ?: 'ab_4417'), 'name' => (string) ($this->option('text') ?: 'አበበ ታደሰ'), 'ts' => time()];
            ksort($payload);
            $sig = hash_hmac('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $secret);
            $this->line('SIGNED='.json_encode(['payload' => $payload, 'sig' => $sig], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        if ($action === 'setting') {
            $key = (string) $this->option('key');
            $raw = (string) $this->option('value');
            $value = match ($raw) {
                'true' => true, 'false' => false, default => is_numeric($raw) ? (int) $raw : $raw
            };
            app(\App\Support\Settings\SettingsRepository::class)->set($key, $value, \App\Support\Audit\AuditActor::PlatformAdmin, null, null, false);
            $this->line('SETTING='.$key.'='.$raw);

            return self::SUCCESS;
        }

        if ($action === 'queue-clear') {
            // Every waiting ticket of the default queue leaves it through Doc 11's system assignment
            // (fixture tickets from earlier runs) — the next handoff is #1 by construction.
            $waiting = app(\App\Domain\Routing\Waiting\WaitingSets::class);
            $n = 0;
            foreach ($waiting->head($queue, max(1, $waiting->depth($queue))) as $ticketId) {
                $ticket = Ticket::query()->find($ticketId);
                if ($ticket instanceof Ticket && $ticket->assignee_id === null) {
                    app(AssignTicket::class)->assignBySystem($ticket, $agent, new RoutingCommit((string) Str::uuid7(), $queue, 1000, false));
                    $n++;
                } else {
                    $waiting->remove($queue, $ticketId);
                }
            }
            $this->line('QUEUE_CLEARED='.$n);

            return self::SUCCESS;
        }

        if ($action === 'queue-jump') {
            // The URGENT jump lands in the VISITOR's queue, and jumps only where that queue's priority rules say so
            // (Doc 11 FR-12: `urgent_priority` is a per-queue boost). Found by CI run 33984364961 and reproduced
            // locally: once the Doc 11 structure specs swap the department order, the tenant default is the
            // secondary department, whose queue has NO priority rules — the urgent ticket queued BEHIND the visitor
            // by plain FIFO and the position never rose. The scene now guarantees its own precondition through the
            // real door (audited `org.queue.boosts_changed`), never by assuming which queue is the default.
            $visitorTicket = $this->conversation()?->open_ticket_id;
            $visitorQueue = is_string($visitorTicket) ? DB::table('tickets')->where('id', $visitorTicket)->value('queue_id') : null;
            if (is_string($visitorQueue)) {
                $queue = $visitorQueue;
                $dept = DB::table('queues')->where('id', $queue)->value('department_id');
                if (is_string($dept)) {
                    $department = $dept;
                }
            }
            $rawRules = DB::table('queues')->where('id', $queue)->value('priority_rules');
            $decoded = is_string($rawRules) ? json_decode($rawRules, true) : null;
            /** @var list<string> $rules */
            $rules = is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
            if (! in_array('urgent_priority', $rules, true)) {
                app(\App\Domain\Org\Structure\QueueWriter::class)->update($queue, ['priority_rules' => [...$rules, 'urgent_priority']], $agent->identifier());
                $this->line('QUEUE_RULES=urgent_priority added to '.$queue);
            }
            $customer = Customer::query()->create(['display_name' => 'Fixture Urgent Player', 'username' => '@fixture_urgent_'.Str::random(6), 'is_shell' => true, 'created_source' => 'staff']);
            $conversationId = (string) Str::uuid7();
            DB::table('conversations')->insert(['id' => $conversationId, 'tenant_id' => app(TenantContext::class)->tenantId(), 'channel' => 'fakechan', 'channel_ref_id' => 'harness', 'external_chat_id' => 'urgent-'.Str::random(8), 'customer_id' => $customer->getKey(), 'state' => 'active']);
            $conversation = Conversation::query()->findOrFail($conversationId);
            $ticket = app(CreateTicket::class)->create(new NewTicket($conversation, TicketSource::Agent, actor: $agent, priority: 'urgent', departmentId: $department));
            app(RoutingPipeline::class)->route($ticket, 'created', ['explicit_department_id' => $department, 'explicit_queue_id' => $queue]);
            $this->line('QUEUE_JUMP_TICKET='.$ticket->identifier());

            return self::SUCCESS;
        }

        $conversation = $this->conversation();
        if ($conversation === null) {
            $this->error('no webchat conversation for that visitor');

            return self::FAILURE;
        }
        $this->line('CONVERSATION='.$conversation->identifier());

        switch ($action) {
            case 'handoff':
                if (is_string($conversation->open_ticket_id)) {
                    // Doc 09 already handed off (the fixture-tagged message reached the mock AI): the open ticket IS the handoff.
                    $this->line('TICKET='.$conversation->open_ticket_id.' (existing)');

                    return self::SUCCESS;
                }
                try {
                    $ticket = app(CreateTicket::class)->create(new NewTicket($conversation, TicketSource::AiHandoff, departmentId: $department));
                } catch (\App\Domain\Conversations\Storage\OpenTicketExists) {
                    // The AI handoff raced this command (its queued turn created the ticket between our read and our create): same outcome.
                    $this->line('TICKET=(raced: the AI handoff created it)');

                    return self::SUCCESS;
                }
                app(RoutingPipeline::class)->route($ticket, 'created', ['explicit_department_id' => $department, 'explicit_queue_id' => $queue]);
                $this->line('TICKET='.$ticket->identifier());

                return self::SUCCESS;
            case 'agent-join':
                $ticket = $this->openTicket($conversation);
                app(AssignTicket::class)->assignBySystem($ticket, $agent, new RoutingCommit((string) Str::uuid7(), $queue, 1000, false)); // a fixture agent: the capacity is not the lesson here
                $this->line('ASSIGNED='.$agent->identifier());

                return self::SUCCESS;
            case 'staff-reply':
                $text = (string) $this->option('text');
                $message = app(ComposeOutbound::class)->compose($conversation, 'agent', $agent->identifier(), 'text', $text !== '' ? $text : 'Hello from the fixture agent.', 'harness:'.Str::uuid7());
                $this->line('MESSAGE='.$message->identifier());

                return self::SUCCESS;
            case 'block':
            case 'unblock':
                // G02: a blocked visitor sees the paused copy (vocabulary-gated), never a failure word; through Doc 04's own door.
                $blocking = app(\App\Domain\Conversations\Actions\ConversationBlocking::class);
                $conversation = $action === 'block' ? $blocking->block($conversation, null, 'harness fixture') : $blocking->unblock($conversation, null, 'harness fixture');
                $this->line('STATE='.$conversation->state);

                return self::SUCCESS;
            case 'note':
                // SEC-06's positive control: an internal note in the visitor's conversation — the staff thread sees it, the visitor never does.
                $text = (string) $this->option('text');
                $note = app(\App\Domain\Conversations\Notes\NotesWriter::class)->addSystemNote($conversation, $text !== '' ? $text : '[FIXTURE] STAFF ONLY note');
                $this->line('NOTE='.$note->identifier());

                return self::SUCCESS;
            case 'resolve':
                $ticket = $this->openTicket($conversation);
                app(ResolveTicket::class)->resolve($ticket, $agent, 'Resolved from the harness (fixture).');
                $this->line('RESOLVED='.$ticket->identifier());

                return self::SUCCESS;
            default:
                $this->error('action must be handoff|queue-jump|agent-join|staff-reply|resolve|block|unblock|note');

                return self::FAILURE;
        }
    }

    private function conversation(): ?Conversation
    {
        $visitor = $this->option('visitor');
        $q = Conversation::query()->where('channel', 'webchat');
        if (is_string($visitor) && $visitor !== '') {
            $q->where('external_chat_id', $visitor);
        } elseif (! $this->option('latest')) {
            return null;
        }
        $found = $q->orderByDesc('created_at')->first();

        return $found instanceof Conversation ? $found : null;
    }

    private function openTicket(Conversation $conversation): Ticket
    {
        $id = $conversation->open_ticket_id;
        if (! is_string($id)) {
            throw new \RuntimeException('the conversation has no open ticket — run handoff first');
        }

        return Ticket::query()->findOrFail($id);
    }
}
