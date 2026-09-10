<?php

declare(strict_types=1);

use App\Support\Audit\AuditActor;
use App\Support\Settings\SettingsRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/PipelineSupport.php';

/*
| Doc 14 FR-02 / BR-01 / EC-10 — the two manual doors: `sms.send` gates both;
| neither carries a text field (the closed SendRequest shape — the sweep binds
| the controller's namespace); reengage requires the EXPIRED reply window
| (Doc 05's column, read only); notify requires the FR-01 toggle and a phone;
| EC-10's duplicate answers the inline notice's data; every success audits
| `sms.message.manually_queued`.
*/

function smsDoorPost(App\Models\User $user, string $path): Illuminate\Testing\TestResponse
{
    $response = test()->actingAs($user, 'staff')->postJson('https://localhost/inbox/'.$path);
    // the request middleware CLEARS the bound context on release (the S12 lesson):
    // every direct DB read/write after an HTTP call needs the tenant re-bound
    bindTenant($GLOBALS['__sms_door_tenant']);

    return $response;
}

it('reengage: 403 without sms.send; 422 while the window is open; 201 + audit once expired; the duplicate answers 422 with last_queued_at', function (): void {
    $scene = smsPipeScene();
    $GLOBALS['__sms_door_tenant'] = $scene['tenant']->identifier();
    Queue::fake();
    $ticketPath = 't/'.$scene['ticket']->identifier().'/sms/reengage';

    $viewer = ticketActor(['tickets.view'], 'Fixture Viewer Role');
    bindTenant($scene['tenant']);
    smsDoorPost($viewer, $ticketPath)->assertForbidden();

    DB::table('conversations')->where('id', $scene['conversation']->identifier())->update(['reply_window_state' => 'open']);
    smsDoorPost($scene['user'], $ticketPath)->assertStatus(422)->assertJsonPath('errors.refusal.0', 'window_not_expired');

    DB::table('conversations')->where('id', $scene['conversation']->identifier())->update(['reply_window_state' => 'expired']);
    $created = smsDoorPost($scene['user'], $ticketPath)->assertStatus(201);
    expect($created->json('status'))->toBe('queued');
    /** @var App\Models\SmsMessage $row */
    $row = App\Models\SmsMessage::query()->findOrFail($created->json('sms_message_id'));
    expect($row->event)->toBe('window_reengagement')->and($row->lane)->toBe('sms-reengagement');
    Queue::assertPushed(App\Jobs\Sms\SendSmsJob::class, fn ($job) => $job->queue === 'sms-reengagement');
    expect(DB::table('audit_log')->where('action_key', 'sms.message.manually_queued')->count())->toBe(1);

    $duplicate = smsDoorPost($scene['user'], $ticketPath)->assertStatus(422);
    expect($duplicate->json('errors.refusal.0'))->toBe('duplicate')
        ->and($duplicate->json('errors.last_queued_at.0'))->not->toBeEmpty('EC-10: the inline notice needs when it last went out');
});

it('notify: the FR-01 off-by-default toggle refuses first; toggled on it queues ticket_updated on the transactional lane; a phoneless customer answers no_phone', function (): void {
    $scene = smsPipeScene();
    $GLOBALS['__sms_door_tenant'] = $scene['tenant']->identifier();
    Queue::fake();
    $path = 't/'.$scene['ticket']->identifier().'/sms/notify';

    smsDoorPost($scene['user'], $path)->assertStatus(422)->assertJsonPath('errors.refusal.0', 'event_disabled');

    app(SettingsRepository::class)->set('sms.events.ticket_updated.enabled', true, AuditActor::Staff, $scene['user']->identifier());
    $created = smsDoorPost($scene['user'], $path)->assertStatus(201);
    expect($created->json('status'))->toBe('queued');
    expect((string) DB::table('sms_messages')->where('id', $created->json('sms_message_id'))->value('event'))->toBe('ticket_updated');

    // Doc 08's own unlink door — customer_identities has no app DELETE grant
    $identityId = (string) DB::table('customer_identities')->where('customer_id', $scene['ticket']->customer_id)->where('type', 'phone')->value('id');
    app(App\Domain\Customers\Identity\UnlinkIdentity::class)->unlink($identityId, 'fixture: number left with its owner', $scene['user']->identifier());
    // a fresh ticket for the same tenant so the duplicate window is not the refusal under test
    $conversation = anotherConversation($scene['tenant']->identifier(), 'nophone');
    $ticketId = (string) Illuminate\Support\Str::uuid7();
    DB::table('tickets')->insert([
        'id' => $ticketId, 'tenant_id' => $scene['tenant']->identifier(),
        'conversation_id' => $conversation->identifier(), 'customer_id' => $conversation->customer_id,
        'number' => random_int(1, 2_000_000_000), 'status' => 'open', 'priority' => 'normal', 'source' => 'agent',
    ]);
    smsDoorPost($scene['user'], 't/'.$ticketId.'/sms/notify')->assertStatus(422)->assertJsonPath('errors.refusal.0', 'no_phone');
});

it('an unknown ticket answers the 404 pattern, never an existence oracle (C-10)', function (): void {
    $scene = smsPipeScene();
    $GLOBALS['__sms_door_tenant'] = $scene['tenant']->identifier();
    smsDoorPost($scene['user'], 't/01900000-0000-7000-8000-000000000000/sms/reengage')->assertNotFound();
});
