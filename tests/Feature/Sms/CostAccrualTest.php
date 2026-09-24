<?php

declare(strict_types=1);

use App\Domain\Sms\Catalog\SendableEvent;
use App\Domain\Sms\Cost\CostEstimate;
use App\Domain\Sms\Cost\RateCard;
use App\Domain\Sms\Gateways\GatewayConfig;
use App\Domain\Sms\Pipeline\EnqueueSms;
use App\Domain\Sms\Pipeline\SendRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/PipelineSupport.php';

/*
| Doc 14 FR-19 / NFR-06 / FS-04 / AC-04 — cost accrual at `sent`: segments ×
| the routed gateway's longest-prefix rate card, the currency the card's; the
| ESTIMATE (editor) and the ACCRUAL (pipeline) agree within a segment on the
| corpus (NFR-06); a FORWARD-ONLY correction (FS-04) writes
| cost_corrected_amount and annotates, never rewriting the original; fixture/
| test rows never color a figure.
*/

it('a sent row accrues segments × the ETB rate card; the accrual equals CostEstimate on the same segmentation (estimate/accrual parity)', function (): void {
    $scene = smsPipeScene();
    Queue::fake();

    $result = DB::transaction(fn () => app(EnqueueSms::class)->enqueue(new SendRequest(
        SendableEvent::TicketResolved, $scene['ticket']->customer_id, $scene['ticket']->identifier(),
    )));
    smsPipeRunJob((string) $result->smsMessageId);

    /** @var object{segments: int, cost_amount: string, cost_currency: string} $row */
    $row = DB::table('sms_messages')->where('id', $result->smsMessageId)->first(['segments', 'cost_amount', 'cost_currency']);
    expect($row->cost_currency)->toBe('ETB')->and($row->segments)->toBeGreaterThan(0);

    // parity: the accrual == CostEstimate on the same segments + the ROUTED gateway's own +251 card
    // (the GeezSMS primary fixture: 0.7500 ETB) — read the card the row actually used, never assume
    $gatewayId = (string) DB::table('sms_messages')->where('id', $result->smsMessageId)->value('gateway_id');
    $config = GatewayConfig::fromStored((array) json_decode((string) DB::table('sms_gateways')->where('id', $gatewayId)->value('config'), true));
    $card = $config->rateCardFor('+251900001234');
    expect($card)->not->toBeNull();
    $expected = CostEstimate::amount($row->segments, $card);
    expect($row->cost_amount)->toBe($expected)->and($row->cost_amount)->toBe(CostEstimate::amount($row->segments, new RateCard('+251', '0.7500', 'ETB')));
});

it('FS-04 forward-only: a correction writes cost_corrected_amount + a note and never rewrites the original accrual; the spend reads the corrected value', function (): void {
    $scene = smsPipeScene();
    Queue::fake();
    $result = DB::transaction(fn () => app(EnqueueSms::class)->enqueue(new SendRequest(SendableEvent::TicketResolved, $scene['ticket']->customer_id, $scene['ticket']->identifier())));
    smsPipeRunJob((string) $result->smsMessageId);
    $original = (string) DB::table('sms_messages')->where('id', $result->smsMessageId)->value('cost_amount');

    // a re-rate lands as a FORWARD correction (the DlrApplier's write shape; here direct through the grant)
    DB::table('sms_messages')->where('id', $result->smsMessageId)->update([
        'cost_corrected_amount' => bcadd($original, '0.5000', 4),
        'cost_correction_note' => 'gateway re-rated on the DLR',
        'updated_at' => DB::raw('clock_timestamp()'),
    ]);
    $row = DB::table('sms_messages')->where('id', $result->smsMessageId)->first(['cost_amount', 'cost_corrected_amount']);
    expect($row->cost_amount)->toBe($original, 'the original accrual is never rewritten (FS-04)')
        ->and($row->cost_corrected_amount)->not->toBeNull();

    // the spend uses COALESCE(corrected, original) — the corrected figure
    $spend = app(App\Domain\Sms\Cost\CostMeter::class)->monthlySpend();
    expect($spend['spend'])->toBe($row->cost_corrected_amount);
});

it('is_test rows never accrue into the spend figure', function (): void {
    $scene = smsPipeScene();
    Queue::fake();
    // cost_amount is UPDATE-only by grant (the accrual is a machine write): insert the row, then the machine columns
    $rowId = (string) Illuminate\Support\Str::uuid7();
    DB::table('sms_messages')->insert([
        'id' => $rowId, 'tenant_id' => $scene['tenant']->identifier(),
        'event' => 'service_announcement', 'language' => 'en', 'phone' => '+251900001234',
        'state' => 'sent', 'lane' => 'sms-broadcast', 'is_test' => true, 'segments' => 2,
    ]);
    DB::table('sms_messages')->where('id', $rowId)->update(['cost_amount' => '99.0000', 'cost_currency' => 'ETB']);
    expect(app(App\Domain\Sms\Cost\CostMeter::class)->monthlySpend()['spend'])->toBe('0');
});
