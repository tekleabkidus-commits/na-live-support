<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

require_once dirname(__DIR__).'/Feature/Sms/PipelineSupport.php';

/*
| Doc 14 SEC-06 — the SMS report feed (spend + counts) is reports.view-gated;
| the gateway rate card is reports.view-gated in the gateway read (step 4's
| SmsGatewaysController, re-asserted here). Fixture/test rows never color a
| figure at the source.
*/

it('the report summary is reports.view-gated: a viewer without the key is refused, one with it sees the spend', function (): void {
    $scene = smsPipeScene();
    Queue::fake();

    $noKey = ticketActor(['tickets.view'], 'Fixture No Reports');
    bindTenant($scene['tenant']);
    $reader = ticketActor(['reports.view'], 'Fixture Reports Reader'); // both actors created BEFORE any request clears context
    bindTenant($scene['tenant']);
    test()->actingAs($noKey, 'staff')->getJson('https://localhost/admin/sms/api/reports/summary')->assertForbidden();
    bindTenant($scene['tenant']);
    $summary = test()->actingAs($reader, 'staff')->getJson('https://localhost/admin/sms/api/reports/summary');
    bindTenant($scene['tenant']);
    $summary->assertOk();
    expect($summary->json('spend'))->toHaveKeys(['month', 'amount', 'currency'])
        ->and($summary->json())->toHaveKeys(['sent', 'delivered', 'opt_out_count', 'per_gateway', 'segments_used', 'segments_limit']);
});

it('the feed excludes is_test and never carries a phone or a body (B5 vocabulary — ids and figures only)', function (): void {
    $scene = smsPipeScene();
    Queue::fake();
    $rowId = (string) Illuminate\Support\Str::uuid7();
    DB::table('sms_messages')->insert([
        'id' => $rowId, 'tenant_id' => $scene['tenant']->identifier(),
        'event' => 'service_announcement', 'language' => 'en', 'phone' => '+251900007777', 'rendered_body' => 'SECRET BODY',
        'state' => 'sent', 'lane' => 'sms-broadcast', 'is_test' => true, 'segments' => 1,
    ]);
    DB::table('sms_messages')->where('id', $rowId)->update(['cost_amount' => '5.0000', 'cost_currency' => 'ETB']);

    $reader = ticketActor(['reports.view'], 'Fixture Reader');
    bindTenant($scene['tenant']);
    $summary = test()->actingAs($reader, 'staff')->getJson('https://localhost/admin/sms/api/reports/summary');
    bindTenant($scene['tenant']);
    $body = (string) $summary->getContent();
    expect($body)->not->toContain('907777')->not->toContain('SECRET BODY')
        ->and($summary->json('sent'))->toBe(0, 'the is_test sent row is excluded from the figure')
        ->and($summary->json('spend.amount'))->toBe('0');
});
