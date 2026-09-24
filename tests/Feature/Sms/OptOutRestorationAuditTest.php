<?php

declare(strict_types=1);

use App\Domain\Sms\Catalog\SendableEvent;
use App\Domain\Sms\Pipeline\EnqueueSms;
use App\Domain\Sms\Pipeline\SendRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/PipelineSupport.php';

/*
| Doc 14 FR-14 / BR-03 / SEC-04 — the restore door: reason REQUIRED, the
| high-visibility audit carries it, `sms.optout.removed` publishes (ids
| only), the guard immediately allows sends again — and the registry stays
| APPEND-ONLY: a re-add after a restore is a NEW row, two rows of history.
| The manager doors' permission split and the masked list ride along.
*/

function smsOptOutApi(App\Models\User $user, string $method, string $path, array $payload = []): Illuminate\Testing\TestResponse
{
    $url = 'https://localhost/admin/sms/api/optouts'.$path;

    return $method === 'GET' ? test()->actingAs($user, 'staff')->getJson($url) : test()->actingAs($user, 'staff')->postJson($url, $payload);
}

it('add → restore(reason) → the audit + event trail with ids only; the guard allows again; a re-add is a NEW row (append-only history)', function (): void {
    $scene = smsPipeScene(['sms.send', 'tickets.view', 'sms.optout.manage', 'customers.export']);
    Queue::fake();

    $created = smsOptOutApi($scene['user'], 'POST', '/', ['phone' => $scene['phone'], 'reason' => 'customer asked at the counter']);
    bindTenant($scene['tenant']);
    $created->assertStatus(201);
    $optoutId = $created->json('optout_id');

    // an empty reason is refused before anything exists
    smsOptOutApi($scene['user'], 'POST', '/'.$optoutId.'/restore', ['reason' => ''])->assertStatus(422);
    bindTenant($scene['tenant']);

    $blocked = DB::transaction(fn () => app(EnqueueSms::class)->enqueue(new SendRequest(SendableEvent::TicketResolved, $scene['ticket']->customer_id, $scene['ticket']->identifier())));
    expect($blocked->reason)->toBe('opted_out');

    smsOptOutApi($scene['user'], 'POST', '/'.$optoutId.'/restore', ['reason' => 'customer asked to opt back in, verified at the counter'])->assertOk();
    bindTenant($scene['tenant']);
    /** @var object{actor_type: string, payload: string} $audit */
    $audit = DB::table('audit_log')->where('action_key', 'sms.optout.restored')->first(['actor_type', 'payload']);
    expect($audit->actor_type)->toBe('staff')
        ->and($audit->payload)->toContain('opt back in')
        ->and($audit->payload)->not->toContain(substr($scene['phone'], 1));
    expect(smsPipeOutbox('sms.optout.removed'))->toHaveCount(1);

    // the guard allows again — a different ticket so the duplicate window is not in play
    $conversation = anotherConversation($scene['tenant']->identifier(), 'restored', $scene['ticket']->customer_id);
    $ticketId = (string) Illuminate\Support\Str::uuid7();
    DB::table('tickets')->insert(['id' => $ticketId, 'tenant_id' => $scene['tenant']->identifier(), 'conversation_id' => $conversation->identifier(), 'customer_id' => $scene['ticket']->customer_id, 'number' => random_int(1, 2_000_000_000), 'status' => 'open', 'priority' => 'normal', 'source' => 'agent']);
    $allowed = DB::transaction(fn () => app(EnqueueSms::class)->enqueue(new SendRequest(SendableEvent::TicketResolved, $scene['ticket']->customer_id, $ticketId)));
    expect($allowed->status)->toBe('queued');

    // append-only: the re-add is a SECOND row; the restored row keeps its history
    smsOptOutApi($scene['user'], 'POST', '/', ['phone' => $scene['phone'], 'reason' => 'asked to stop again'])->assertStatus(201);
    bindTenant($scene['tenant']);
    expect(DB::table('sms_optouts')->where('phone', $scene['phone'])->count())->toBe(2)
        ->and(DB::table('sms_optouts')->where('phone', $scene['phone'])->whereNotNull('restored_at')->count())->toBe(1);

    // the masked list + the export split (SEC-04): manage sees the masked display form in the list
    $list = smsOptOutApi($scene['user'], 'GET', '/');
    bindTenant($scene['tenant']);
    expect($list->json('optouts.0.phone_masked'))->toContain('•••')->and((string) $list->getContent())->not->toContain(substr($scene['phone'], 1, 11));

    $csv = test()->actingAs($scene['user'], 'staff')->get('https://localhost/admin/sms/api/optouts/export');
    bindTenant($scene['tenant']);
    expect($csv->streamedContent())->toContain($scene['phone']); // this caller ALSO holds sms.optout.manage → full numbers

    $exporterOnly = ticketActor(['customers.export'], 'Fixture Exporter Role');
    bindTenant($scene['tenant']);
    $masked = test()->actingAs($exporterOnly, 'staff')->get('https://localhost/admin/sms/api/optouts/export');
    bindTenant($scene['tenant']);
    expect($masked->streamedContent())->not->toContain($scene['phone']); // customers.export alone → masked by default
    expect(DB::table('audit_log')->where('action_key', 'sms.optout.exported')->count())->toBe(2);
});

it('import: rows land as source api, idempotent against live opt-outs, invalid rows counted; audited with row counts', function (): void {
    $scene = smsPipeScene(['sms.send', 'tickets.view', 'sms.optout.manage']);
    Queue::fake();

    $csv = "phone\n+251900007001\n+251900007002\nnot-a-phone\n+251900007001\n";
    $imported = smsOptOutApi($scene['user'], 'POST', '/import', ['csv' => $csv]);
    bindTenant($scene['tenant']);
    $imported->assertStatus(201);
    expect($imported->json('added'))->toBe(2)->and($imported->json('already_opted_out'))->toBe(1)->and($imported->json('invalid'))->toBe(1);
    expect(DB::table('sms_optouts')->where('source', 'api')->count())->toBe(2);
    expect((string) DB::table('audit_log')->where('action_key', 'sms.optout.imported')->value('payload'))->toContain('"row_count": 2');
});
