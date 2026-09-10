<?php

declare(strict_types=1);

use App\Domain\Sms\Catalog\SendableEvent;
use App\Domain\Sms\Pipeline\EnqueueSms;
use App\Domain\Sms\Pipeline\SendRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/PipelineSupport.php';

/*
| Doc 14 EC-10 / FR-03 — the 10-minute duplicate window live: same (event,
| ticket, phone) inside the window → skipped:duplicate, keyed exactly on that
| triple. The STRICT boundary arithmetic with an injected clock is step 1's
| GuardTruthTableTest (pure); the registry floors the live figure at 1 minute,
| so no zero-window leg can exist against real time — stated plainly.
*/

it('EC-10: the second identical send inside the window skips as duplicate; the window keys on (event, ticket, phone) — a different event or ticket queues', function (): void {
    $scene = smsPipeScene();
    Queue::fake();
    $request = new SendRequest(SendableEvent::TicketResolved, $scene['ticket']->customer_id, $scene['ticket']->identifier());

    $first = DB::transaction(fn () => app(EnqueueSms::class)->enqueue($request));
    expect($first->status)->toBe('queued');

    $second = DB::transaction(fn () => app(EnqueueSms::class)->enqueue($request));
    expect($second->status)->toBe('skipped')->and($second->reason)->toBe('duplicate');
    /** @var App\Models\SmsMessage $row */
    $row = App\Models\SmsMessage::query()->findOrFail($second->smsMessageId);
    expect(array_column($row->guard_log, 'check'))->toContain('duplicate');

    // the window's KEY, live: a different EVENT for the same ticket+phone is no duplicate…
    $created = DB::transaction(fn () => app(EnqueueSms::class)->enqueue(new SendRequest(SendableEvent::TicketCreated, $scene['ticket']->customer_id, $scene['ticket']->identifier())));
    expect($created->status)->toBe('queued');

    // …and the same event on a DIFFERENT ticket is no duplicate either
    $conversation = anotherConversation($scene['tenant']->identifier(), 'dup', $scene['ticket']->customer_id);
    $otherTicketId = (string) Illuminate\Support\Str::uuid7();
    DB::table('tickets')->insert([
        'id' => $otherTicketId, 'tenant_id' => $scene['tenant']->identifier(),
        'conversation_id' => $conversation->identifier(), 'customer_id' => $scene['ticket']->customer_id,
        'number' => random_int(1, 2_000_000_000), 'status' => 'open', 'priority' => 'normal', 'source' => 'agent',
    ]);
    $other = DB::transaction(fn () => app(EnqueueSms::class)->enqueue(new SendRequest(SendableEvent::TicketResolved, $scene['ticket']->customer_id, $otherTicketId)));
    expect($other->status)->toBe('queued');
});
