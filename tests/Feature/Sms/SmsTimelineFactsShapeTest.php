<?php

declare(strict_types=1);

use App\Domain\Sms\Catalog\SendableEvent;
use App\Domain\Sms\Pipeline\EnqueueSms;
use App\Domain\Sms\Pipeline\SendRequest;
use App\Domain\Sms\Timeline\SmsTimelineFacts;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/PipelineSupport.php';

/*
| P-TIMELINE-SMS countersign condition (Doc 07 core, STOP-F; Doc 14 FR-12 /
| AC-09 / §9 / SEC-02): SmsTimelineFacts::forMessageIds() returns EXACTLY
| {state, phone_masked, event, lane} per id — the LIVE state (so a later DLR
| turns the chip green), the DRAWN masked phone (never the full number), the
| event and the lane. Nothing else crosses to the Doc 07 timeline. This is the
| control the controller's merge (TicketActionsController) depends on; the key
| set is asserted to be EXACTLY these four, and the phone to be masked.
*/

it('forMessageIds returns exactly {state, phone_masked, event, lane} and never the full number', function (): void {
    $scene = smsPipeScene(['sms.send', 'tickets.view'], '+251900004417');
    $request = new SendRequest(SendableEvent::TicketResolved, $scene['ticket']->customer_id, $scene['ticket']->identifier());
    $result = DB::transaction(fn () => app(EnqueueSms::class)->enqueue($request));
    $id = $result->smsMessageId;
    expect($id)->not->toBeNull();

    $facts = app(SmsTimelineFacts::class)->forMessageIds([(string) $id]);

    expect($facts)->toHaveKey((string) $id);
    $row = $facts[(string) $id];

    // the key set is EXACTLY these four — nothing else crosses to the timeline
    expect(array_keys($row))->toEqualCanonicalizing(['state', 'phone_masked', 'event', 'lane']);

    // the phone is MASKED (SEC-02): the drawn form, never the full number, never a body
    expect($row['phone_masked'])
        ->not->toContain('900004417')
        ->toContain('••')
        ->and($row['event'])->toBe('ticket_resolved')
        ->and($row['lane'])->toBeString()
        ->and($row['state'])->toBeString();
});

it('returns [] for an empty id list and omits unknown ids', function (): void {
    smsPipeScene(['sms.send', 'tickets.view']);

    expect(app(SmsTimelineFacts::class)->forMessageIds([]))->toBe([])
        ->and(app(SmsTimelineFacts::class)->forMessageIds(['0192a3b4-0000-7000-8000-0000dead0000']))->toBe([]);
});
