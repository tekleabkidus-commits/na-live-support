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
            case 'resolve':
                $ticket = $this->openTicket($conversation);
                app(ResolveTicket::class)->resolve($ticket, $agent, 'Resolved from the harness (fixture).');
                $this->line('RESOLVED='.$ticket->identifier());

                return self::SUCCESS;
            default:
                $this->error('action must be handoff|queue-jump|agent-join|staff-reply|resolve');

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
