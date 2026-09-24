<?php

declare(strict_types=1);

use App\Domain\Sms\Cost\CostMeter;
use App\Support\Settings\SettingsRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/PipelineSupport.php';

/*
| Doc 14 FR-19 — the spend threshold fires the sms.cost.threshold event ONCE
| per tenant-month on the FIRST crossing (Doc 16 delivers via
| notify.sms.cost_threshold); the display-currency conversion is an
| informational line, never a second source of truth.
*/

it('crossing sms.cost.threshold emits the event once per month; a second sent row past the line emits nothing more', function (): void {
    $scene = smsPipeScene();
    Queue::fake();
    app(SettingsRepository::class)->set('sms.cost.threshold', ['amount' => '1.0000', 'currency' => 'ETB']);

    // two sent rows at 0.9000 each → 1.8000, over the 1.0000 line
    foreach ([1, 2] as $n) {
        $rowId = (string) Illuminate\Support\Str::uuid7();
        DB::table('sms_messages')->insert([
            'id' => $rowId, 'tenant_id' => $scene['tenant']->identifier(),
            'event' => 'ticket_resolved', 'language' => 'en', 'phone' => '+25190000'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
            'state' => 'sent', 'lane' => 'sms-transactional', 'segments' => 1,
        ]);
        DB::table('sms_messages')->where('id', $rowId)->update(['cost_amount' => '0.9000', 'cost_currency' => 'ETB']);
    }

    app(CostMeter::class)->recordSent(1, 'ETB');
    expect(smsPipeOutbox('sms.cost.threshold'))->toHaveCount(1);
    $payload = json_decode(smsPipeOutbox('sms.cost.threshold')[0]->payload, true, flags: JSON_THROW_ON_ERROR);
    expect($payload['threshold'])->toBe('1.0000')->and($payload['month'])->toMatch('/^\d{4}-\d{2}$/');

    // record again this month → no second event
    app(CostMeter::class)->recordSent(1, 'ETB');
    expect(smsPipeOutbox('sms.cost.threshold'))->toHaveCount(1);
});

it('the display-currency conversion is informational (bcmul), off when unset', function (): void {
    smsPipeScene();
    $meter = app(CostMeter::class);
    expect($meter->displaySpend('10.0000'))->toBeNull();

    app(SettingsRepository::class)->set('sms.cost.display_currency', 'USD');
    app(SettingsRepository::class)->set('sms.cost.display_rate', '0.0180');
    expect($meter->displaySpend('100.0000'))->toBe(['currency' => 'USD', 'amount' => '1.8000']);
});
