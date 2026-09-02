<?php

declare(strict_types=1);

use App\Domain\Automation\Actions\ActionRegistry;
use App\Domain\Automation\Actions\RunAiTaskAction;
use App\Domain\Automation\Ai\AutomationAiTasks;
use App\Domain\Automation\Rules\RuleWriter;
use App\Domain\Automation\Suppression\MarketingSuppression;
use App\Domain\Automation\Webhooks\WebhookDeliveries;
use App\Domain\Automation\Webhooks\WebhookDestinations;
use App\Jobs\Automation\RunAiTaskJob;
use App\Jobs\Automation\WebhookDeliveryJob;
use App\Support\Events\EventCatalog;
use App\Support\Settings\SettingsRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/*
|--------------------------------------------------------------------------
| Doc 12 FR-03's external arms (S12 step 13) — webhook_out, notify,
| send_sms_template, suppress, run_ai_task — each through its door, each
| with its refusals, the chain continuing around every one (AC-01)
|--------------------------------------------------------------------------
*/

function externalScene(): array
{
    [$tenant, , $ticket] = ticketFixture('open');
    $admin = grantAllPermissions(ticketActor([], 'External Arms Admin'));

    return [$tenant, $ticket, $admin];
}

it('every FR-03 action type is BOUND — the step-4 unbuilt_action posture is gone for good', function (): void {
    $types = ActionRegistry::TYPES;
    sort($types);
    expect(app(ActionRegistry::class)->boundTypes())->toBe($types);
});

it('webhook_out: names a destination BY ID from the allowlist, queues a signed delivery and answers queued — unknown/inactive destinations fail THAT action; the chain continues; a URL on the card cannot be saved', function (): void {
    [$tenant, $ticket, $admin] = externalScene();
    Queue::fake();
    $destinations = app(WebhookDestinations::class);
    $live = $destinations->create('CRM', 'https://hooks.example.test/live', $admin->identifier());
    $off = $destinations->create('Old CRM', 'https://hooks.example.test/old', $admin->identifier());
    $destinations->update($off, ['active' => false], $admin->identifier());

    $actions = [
        ['type' => 'webhook_out', 'destination_id' => (string) Illuminate\Support\Str::uuid7()],
        ['type' => 'webhook_out', 'destination_id' => $off],
        ['type' => 'webhook_out', 'destination_id' => $live],
        ['type' => 'add_note', 'body' => 'after the webhooks'],
    ];
    $executionId = automationActionScene($tenant, $ticket, $actions);
    $outcomes = runChain($executionId, $actions);

    expect($outcomes[0]['reason'])->toBe('unknown_destination')
        ->and($outcomes[1]['reason'])->toBe('destination_inactive')
        ->and($outcomes[2]['outcome'])->toBe('queued')
        ->and($outcomes[3]['outcome'])->toBe('ok', 'the chain died around a webhook — AC-01 isolation broken');
    Queue::assertPushed(WebhookDeliveryJob::class, 1);
    Queue::assertPushedOn('automation', WebhookDeliveryJob::class);
    $delivery = DB::table('automation_webhook_deliveries')->where('id', $outcomes[2]['delivery_id'])->first();
    /** @var array<string, mixed> $payload */
    $payload = json_decode((string) $delivery->payload, true, 8);
    expect($delivery->state)->toBe('queued')
        ->and($delivery->execution_id)->toBe($executionId)
        ->and($payload['ticket']['id'])->toBe($ticket->identifier())
        ->and($payload['ticket']['ticket_status'])->toBe('open')
        ->and(json_encode($payload))->not->toContain('body', 'a webhook payload carries identifiers and state, never message text');

    // The card grammar: a URL typed into a rule is not a thing that exists.
    $writer = app(RuleWriter::class);
    expect(fn () => $writer->create(['name' => 'Bad card', 'trigger' => 'ticket.created', 'conditions' => ['all' => []], 'actions' => [['type' => 'webhook_out', 'url' => 'https://hooks.example.test/x']]], $admin))
        ->toThrow(InvalidArgumentException::class, 'refuses key(s) url');
});

it('EC-13 drill: a failing target retries with backoff up to EXACTLY 8 attempts, then the delivery abandons, the destination AUTO-DISABLES and the alert publishes — a later rule finds it inactive; an admin re-enable clears the auto-disable', function (): void {
    [$tenant, $ticket, $admin] = externalScene();
    Queue::fake();
    Http::fake(['hooks.example.test/*' => Http::response('nope', 503)]);
    app(SettingsRepository::class)->set('tenant.outbound_webhook_secret', 'fixture-secret-0001');
    $destinations = app(WebhookDestinations::class);
    $deliveries = app(WebhookDeliveries::class);
    $id = $destinations->create('Flaky CRM', 'https://hooks.example.test/flaky', $admin->identifier());

    expect(WebhookDeliveries::MAX_ATTEMPTS)->toBe(8, "EC-13's number is the document's")
        ->and(WebhookDeliveries::BACKOFF_S)->toBe([60, 300, 900, 3600, 10800, 21600, 43200])
        ->and(WebhookDeliveries::delaySeconds(1))->toBe(60)
        ->and(WebhookDeliveries::delaySeconds(7))->toBe(43200)
        ->and(WebhookDeliveries::delaySeconds(99))->toBe(43200);

    $actions = [['type' => 'webhook_out', 'destination_id' => $id]];
    $executionId = automationActionScene($tenant, $ticket, $actions);
    $deliveryId = runChain($executionId, $actions)[0]['delivery_id'];

    // Attempt 1 through the JOB itself: a retry re-queues THIS delivery on the automation family with EC-13's first backoff.
    (new WebhookDeliveryJob($deliveryId))->handle($deliveries);
    Queue::assertPushed(WebhookDeliveryJob::class, fn (WebhookDeliveryJob $job): bool => $job->deliveryId === $deliveryId && $job->delay !== null);
    $results = ['retry'];
    for ($i = 2; $i <= 8; $i++) {
        $results[] = $deliveries->attempt($deliveryId);
    }
    expect($results)->toBe(array_merge(array_fill(0, 7, 'retry'), ['abandoned']));
    expect($deliveries->attempt($deliveryId))->toBe('skipped', 'a terminal delivery must never be re-sent');
    Http::assertSentCount(8);

    $delivery = DB::table('automation_webhook_deliveries')->where('id', $deliveryId)->first();
    $destination = DB::table('automation_webhook_destinations')->where('id', $id)->first();
    expect((int) $delivery->attempts)->toBe(8)
        ->and($delivery->state)->toBe('abandoned')
        ->and((int) $delivery->last_status_code)->toBe(503)
        ->and($destination->auto_disabled_at)->not->toBeNull()
        ->and((bool) $destination->active)->toBeTrue('auto-disable is ITS OWN stamp, not the admin switch')
        ->and((int) $destination->failure_streak)->toBe(8);
    $alert = DB::table('outbox')->where('name', 'automation.webhook.disabled')->orderByDesc('id')->first();
    /** @var array<string, mixed> $alertPayload */
    $alertPayload = json_decode((string) $alert->payload, true, 8);
    expect($alertPayload['destination_id'])->toBe($id)
        ->and($alertPayload['delivery_id'])->toBe($deliveryId)
        ->and((int) $alertPayload['attempts'])->toBe(8);
    $audit = DB::table('audit_log')->where('action_key', 'automation.webhook_destination.changed')->orderByDesc('id')->first();
    expect(json_decode((string) $audit->payload, true, 4)['kind'])->toBe('auto_disabled')
        ->and($audit->actor_type)->toBe('system');

    // A later rule finds it inactive (the arm's refusal), and the admin's re-enable heals it.
    $again = automationActionScene($tenant, $ticket, $actions);
    expect(runChain($again, $actions)[0]['reason'])->toBe('destination_inactive');
    $destinations->update($id, ['active' => true], $admin->identifier());
    $healed = DB::table('automation_webhook_destinations')->where('id', $id)->first();
    expect($healed->auto_disabled_at)->toBeNull()->and((int) $healed->failure_streak)->toBe(0);
});

it('notify: resolves users + role members from row truth, requires a REGISTERED category, and publishes identifiers only — no recipients / unregistered category fail THAT action; a card with nobody cannot be saved', function (): void {
    [$tenant, $ticket, $admin] = externalScene();
    $agentA = ticketActor(['tickets.view'], 'Notify Role A');
    $agentB = ticketActor(['tickets.view'], 'Notify Role A');
    $agentC = ticketActor(['tickets.view'], 'Notify Role C');
    $roleA = (string) $agentA->getAttribute('role_id');

    $actions = [
        ['type' => 'notify', 'user_ids' => [$agentC->identifier(), (string) Illuminate\Support\Str::uuid7()], 'role_ids' => [$roleA]],
        ['type' => 'notify', 'category' => 'notify.made.up', 'user_ids' => [$agentC->identifier()]],
        ['type' => 'notify', 'user_ids' => [(string) Illuminate\Support\Str::uuid7()]],
        ['type' => 'add_note', 'body' => 'after the notifies'],
    ];
    $executionId = automationActionScene($tenant, $ticket, $actions);
    $outcomes = runChain($executionId, $actions);

    expect($outcomes[0]['outcome'])->toBe('ok')
        ->and($outcomes[0]['recipients'])->toBe(3)
        ->and($outcomes[1]['reason'])->toBe('unregistered_category')
        ->and($outcomes[2]['reason'])->toBe('no_recipients')
        ->and($outcomes[3]['outcome'])->toBe('ok');
    $event = DB::table('outbox')->where('name', 'automation.notify.requested')->orderByDesc('id')->first();
    /** @var array<string, mixed> $payload */
    $payload = json_decode((string) $event->payload, true, 8);
    $expected = [$agentA->identifier(), $agentB->identifier(), $agentC->identifier()];
    sort($expected);
    expect($payload['user_ids'])->toBe($expected)
        ->and($payload['category'])->toBe('notify.automation.rule')
        ->and($payload['ticket_id'])->toBe($ticket->identifier())
        ->and(array_keys($payload))->toEqualCanonicalizing(['tenant_id', 'rule_id', 'execution_id', 'ticket_id', 'category', 'user_ids']);
    foreach (['en', 'am'] as $lang) {
        expect(__('workflow.notify.rule', [], $lang))->not->toBe('workflow.notify.rule');
    }

    $writer = app(RuleWriter::class);
    expect(fn () => $writer->create(['name' => 'Nobody', 'trigger' => 'ticket.created', 'conditions' => ['all' => []], 'actions' => [['type' => 'notify', 'user_ids' => []]]], $admin))
        ->toThrow(InvalidArgumentException::class, 'at least one user or role');
});

it('send_sms_template: a TEMPLATE REFERENCE only — the request event carries no text key, a body cannot be SAVED on the card nor smuggled into the payload (BR-04 as structure); RG-flagged and marketing-suppressed customers refuse (Doc 08 FR-15\'s [D12] word)', function (): void {
    [$tenant, $ticket, $admin] = externalScene();
    $customerId = (string) DB::table('tickets')->where('id', $ticket->identifier())->value('customer_id');

    $actions = [['type' => 'send_sms_template', 'template_ref' => 'ticket_resolved'], ['type' => 'add_note', 'body' => 'after sms']];
    $executionId = automationActionScene($tenant, $ticket, $actions);
    $outcomes = runChain($executionId, $actions);
    expect($outcomes[0]['outcome'])->toBe('ok')->and($outcomes[1]['outcome'])->toBe('ok');
    $event = DB::table('outbox')->where('name', 'automation.sms.requested')->orderByDesc('id')->first();
    /** @var array<string, mixed> $payload */
    $payload = json_decode((string) $event->payload, true, 8);
    expect($payload['template_ref'])->toBe('ticket_resolved')
        ->and($payload['customer_id'])->toBe($customerId)
        ->and(array_intersect(['body', 'text', 'message', 'content'], array_keys($payload)))->toBe([], 'a text key exists on the SMS request — BR-04 broken');
    expect(array_intersect(['body', 'text', 'message', 'content'], EventCatalog::definition('automation.sms.requested')['payload']))->toBe([]);

    // Structure, both walls: the card refuses a body at SAVE; the catalog refuses a smuggled body at PUBLISH.
    $writer = app(RuleWriter::class);
    expect(fn () => $writer->create(['name' => 'Free text', 'trigger' => 'ticket.created', 'conditions' => ['all' => []], 'actions' => [['type' => 'send_sms_template', 'template_ref' => 't', 'body' => 'Hello {{name}}']]], $admin))
        ->toThrow(InvalidArgumentException::class, 'no free-text SMS');
    expect(fn () => EventCatalog::assertPayloadMatches('automation.sms.requested', $payload + ['body' => 'smuggled']))->toThrow(InvalidArgumentException::class, 'Not declared: [body]');

    // Doc 08 FR-15: the suppressions, RG first.
    app(MarketingSuppression::class)->suppress($customerId, 'opted out of promotions', 'staff', null, null, $admin->identifier());
    $suppressed = automationActionScene($tenant, $ticket, $actions);
    expect(runChain($suppressed, $actions)[0]['reason'])->toBe('marketing_suppressed');
    DB::table('customers')->where('id', $customerId)->update(['rg_flag' => true]);
    $rg = automationActionScene($tenant, $ticket, $actions);
    expect(runChain($rg, $actions)[0]['reason'])->toBe('rg_suppressed');
});

it('suppress: sets the marketing-suppression flag on the ticket\'s customer (event + system audit + rule_ref), a live flag makes the second run already_suppressed, a staff clear closes it by outcome — history stays', function (): void {
    [$tenant, $ticket, $admin] = externalScene();
    $customerId = (string) DB::table('tickets')->where('id', $ticket->identifier())->value('customer_id');
    $suppression = app(MarketingSuppression::class);
    expect($suppression->isSuppressed($customerId))->toBeFalse();

    // An empty reason cannot even be SAVED (the card grammar) — the arm's own empty_reason refusal stands behind it.
    expect(fn () => app(RuleWriter::class)->create(['name' => 'No reason', 'trigger' => 'ticket.created', 'conditions' => ['all' => []], 'actions' => [['type' => 'suppress', 'reason' => '']]], $admin))
        ->toThrow(InvalidArgumentException::class, 'non-empty reason');
    $actions = [['type' => 'suppress', 'reason' => 'complaint escalated'], ['type' => 'add_note', 'body' => 'after']];
    $executionId = automationActionScene($tenant, $ticket, $actions);
    $outcomes = runChain($executionId, $actions);
    expect($outcomes[0]['outcome'])->toBe('ok')
        ->and($outcomes[1]['outcome'])->toBe('ok');
    expect($suppression->isSuppressed($customerId))->toBeTrue();
    $row = DB::table('customer_marketing_suppressions')->where('customer_id', $customerId)->first();
    expect($row->source)->toBe('rule')->and($row->rule_ref)->toBe('rule:'.$executionId)->and($row->reason)->toBe('complaint escalated');
    $event = DB::table('outbox')->where('name', 'customer.suppression.set')->orderByDesc('id')->first();
    expect(json_decode((string) $event->payload, true, 4)['customer_id'])->toBe($customerId);
    $audit = DB::table('audit_log')->where('action_key', 'customer.marketing_suppression.changed')->orderByDesc('id')->first();
    expect($audit->actor_type)->toBe('system')->and(json_decode((string) $audit->payload, true, 4)['kind'])->toBe('set');

    $again = automationActionScene($tenant, $ticket, [$actions[0]]);
    expect(runChain($again, [$actions[0]])[0]['reason'])->toBe('already_suppressed');

    expect($suppression->clear($customerId, $admin->identifier()))->toBeTrue()
        ->and($suppression->isSuppressed($customerId))->toBeFalse()
        ->and((int) DB::table('customer_marketing_suppressions')->where('customer_id', $customerId)->count())->toBe(1, 'history stays — never deleted')
        ->and($suppression->clear($customerId, $admin->identifier()))->toBeFalse();
});

it('run_ai_task: catalog-bound to the three names — chatbot_turn is unknown_ai_task; classify_refresh answers unbuilt_ai_task until P-CLS (asserted); summarize/translate QUEUE the ai-background job and the job lands an INTERNAL note through NotesWriter', function (): void {
    [$tenant, $ticket, $admin] = externalScene();
    expect(RunAiTaskAction::TASKS)->toBe(['summarize_thread', 'translate_last_message', 'classify_refresh']);
    // A real Doc 09 catalog task outside the three cannot even be SAVED on a card (the customer-visible tasks are out of a rule's reach).
    expect(fn () => app(RuleWriter::class)->create(['name' => 'Bot from a rule', 'trigger' => 'ticket.created', 'conditions' => ['all' => []], 'actions' => [['type' => 'run_ai_task', 'task' => 'chatbot_turn']]], $admin))
        ->toThrow(InvalidArgumentException::class, 'not one of the three');
    // …and the arm refuses it too, behind the grammar (an unknown name reaching the registry answers unknown_ai_task).
    expect(app(ActionRegistry::class)->execute('run_ai_task', ['type' => 'run_ai_task', 'task' => 'chatbot_turn'], automationActionScene($tenant, $ticket, [['type' => 'add_note', 'body' => 'x']]), 1)['reason'])->toBe('unknown_ai_task');
    Queue::fake();
    $actions = [
        ['type' => 'run_ai_task', 'task' => 'classify_refresh'],
        ['type' => 'run_ai_task', 'task' => 'summarize_thread'],
        ['type' => 'run_ai_task', 'task' => 'translate_last_message'],
    ];
    $executionId = automationActionScene($tenant, $ticket, $actions);
    $outcomes = runChain($executionId, $actions);
    expect($outcomes[0]['reason'])->toBe('unbuilt_ai_task')
        ->and($outcomes[1]['outcome'])->toBe('queued')
        ->and($outcomes[2]['outcome'])->toBe('queued');
    Queue::assertPushed(RunAiTaskJob::class, 2);
    Queue::assertPushedOn('ai-background', RunAiTaskJob::class);

    // The job, driven directly with the fake provider: the note lands, internal, system-authored (N-04).
    $conversation = App\Models\Conversation::query()->findOrFail($ticket->getAttribute('conversation_id'));
    copilotCustomerMessage($tenant->identifier(), $conversation, '[FIXTURE] ገንዘቤ አልደረሰም — my withdrawal has not arrived since Monday', 'am');
    copilotDriver(
        new App\Domain\Ai\Providers\ProviderResponse('Customer reports a withdrawal delayed since Monday; nothing verified yet; agent to check the payout queue.', 30, 20),
        new App\Domain\Ai\Providers\ProviderResponse('My money has not arrived — my withdrawal has not arrived since Monday', 20, 15),
    );
    (new RunAiTaskJob($executionId, 'summarize_thread', $conversation->identifier()))->handle(app(AutomationAiTasks::class));
    (new RunAiTaskJob($executionId, 'translate_last_message', $conversation->identifier()))->handle(app(AutomationAiTasks::class));

    $notes = DB::table('messages')->where('conversation_id', $conversation->identifier())->where('kind', 'note')->orderBy('created_at')->get(['body', 'author_type', 'is_internal']);
    expect($notes)->toHaveCount(2);
    expect($notes[0]->author_type)->toBe('system')->and((bool) $notes[0]->is_internal)->toBeTrue()
        ->and($notes[0]->body)->toContain('payout queue')
        ->and($notes[1]->body)->toContain('since Monday')->and($notes[1]->body)->toContain('English');
    foreach (['workflow.ai_task.summary_note', 'workflow.ai_task.translation_note'] as $key) {
        foreach (['en', 'am'] as $lang) {
            expect(__($key, ['summary' => 's', 'language' => 'l', 'text' => 't'], $lang))->not->toBe($key);
        }
    }
});
