<?php

declare(strict_types=1);

use App\Domain\Customers\Identity\LinkIdentity;
use App\Domain\Sms\Gateways\FixtureGatewaySeeder;
use App\Domain\Sms\Templates\TemplateSeeder;
use App\Models\Conversation;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\Redis;

/*
| The step-5 pipeline scene: one ACTIVE tenant with a ticket, its customer
| carrying a FIXTURE phone identity (+251-90000xxxx only — CLAUDE.md §8), the
| 22 seeded templates, the two FIXTURE gateways + the +251 route, and a staff
| actor holding the named keys. Pest test-file functions are GLOBAL — every
| helper here wears the smsPipe prefix.
*/

/**
 * @return array{tenant: Tenant, conversation: Conversation, ticket: Ticket, user: User, phone: string}
 */
function smsPipeScene(array $permissions = ['sms.send', 'tickets.view'], string $phone = '+251900001234'): array
{
    [$tenant, $conversation, $ticket] = ticketFixture('open');
    activateTenant($tenant);
    bindTenant($tenant);
    Redis::connection('volatile')->command('flushdb');

    $user = ticketActor($permissions, 'Fixture SMS Send Role');
    bindTenant($tenant);

    app(LinkIdentity::class)->link($ticket->customer_id, 'phone', $phone, $user->identifier());
    app(TemplateSeeder::class)->seed('00000000-0000-7000-8000-0000000000aa');
    app(FixtureGatewaySeeder::class)->seed('00000000-0000-7000-8000-0000000000aa');

    return ['tenant' => $tenant, 'conversation' => $conversation, 'ticket' => $ticket, 'user' => $user, 'phone' => $phone];
}

/** Run the queued row's job the way the worker would (tenant already bound; release() is a safe no-op off-queue). */
function smsPipeRunJob(string $smsMessageId, string $lane = 'sms-transactional'): void
{
    (new App\Jobs\Sms\SendSmsJob($smsMessageId, $lane))->handle(app(App\Domain\Sms\Pipeline\SendPipeline::class));
}

/** The outbox rows for one event name, oldest first. @return list<object{name: string, payload: string}> */
function smsPipeOutbox(string $name): array
{
    /** @var list<object{name: string, payload: string}> $rows */
    $rows = Illuminate\Support\Facades\DB::table('outbox')->where('name', $name)->orderBy('id')->get(['name', 'payload'])->all();

    return $rows;
}
