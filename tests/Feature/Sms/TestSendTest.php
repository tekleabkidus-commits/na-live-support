<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

require_once __DIR__.'/SmsSupport.php';

/*
| Doc 14 FR-05 (STOP-M ruled) — test-send: a typed number, never stored on a
| customer (customer_id NULL), sandbox-tagged (is_test = true — excluded at the
| source by every Doc 14 figure), rendered with the editor's sample values,
| queued on the transactional lane; outside production only a +251-90000xxxx
| fixture number is accepted (the 0l / §14 law); audited without the number.
*/

it('queues a sandbox-tagged row for a fixture number and refuses a non-fixture number outside production', function (): void {
    [, $user] = smsScene();
    $t = smsApi($user, 'POST', '/templates', ['event' => 'ticket_resolved', 'language' => 'am', 'name' => 'ተፈትቷል', 'template_body' => '{{brand.name}}፦ ትኬት {{ticket.number}} ተፈትቷል።'])->assertStatus(201)->json('id');

    $sent = smsApi($user, 'POST', '/templates/'.$t.'/test-send', ['phone' => '+251900001234']);
    $sent->assertStatus(201)->assertJsonPath('is_test', true)->assertJsonPath('state', 'queued')->assertJsonPath('encoding', 'ucs2');

    /** @var object{customer_id: ?string, is_test: bool, lane: string, rendered_body: string, phone: string, guard_log: string} $row */
    $row = DB::table('sms_messages')->where('id', $sent->json('sms_message_id'))->first();
    expect($row->customer_id)->toBeNull('a test-send is never a customer\'s')
        ->and($row->is_test)->toBeTrue()
        ->and($row->lane)->toBe('sms-transactional')
        ->and($row->rendered_body)->toContain('#4812')->not->toContain('{{')
        ->and($row->phone)->toBe('+251900001234');

    // the audit row carries the template + the tag — never the number
    $audit = (string) DB::table('audit_log')->where('action_key', 'sms.template.test_sent')->value('payload');
    $decoded = json_decode($audit, true, 8, JSON_THROW_ON_ERROR);
    expect($decoded['is_test'])->toBeTrue()->and($audit)->not->toContain('251900001234');

    // STOP-M: a real-looking number is refused outside production, before any row exists
    $before = DB::table('sms_messages')->count();
    smsApi($user, 'POST', '/templates/'.$t.'/test-send', ['phone' => '+251911234567'])
        ->assertStatus(422)->assertJsonPath('errors.refusal.0', 'fixture_phone_required');
    expect(DB::table('sms_messages')->count())->toBe($before);

    // no phone identity, no customer row was minted anywhere (A-63)
    expect(DB::table('customer_identities')->where('type', 'phone')->count())->toBe(0);
});
