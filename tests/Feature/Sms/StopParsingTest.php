<?php

declare(strict_types=1);

use App\Domain\Sms\Gateways\GatewayCredentials;
use App\Models\SmsGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/PipelineSupport.php';

/*
| Doc 14 FR-15 / §14 — STOP parsing end-to-end through the REAL MO door: the
| English STOP (ASCII whitespace trimmed, the word itself untouched) and the
| Amharic word read FROM THE DOCUMENT AT TEST TIME (the ተወው law: its bytes
| are pinned by StopWordByteExactTest; here they ride the wire and land in
| `stop_word_matched` byte-identical). A ONE-BYTE mutation is NOT an opt-out
| — the red control. The capability gate: the GeezSMS profile declares no
| inbound_stop and cannot opt anyone out through this door.
*/

/**
 * The MO door rides url_secret (the plan's one route): the fixture is an
 * http_generic gateway with a URL-secret credential AND the inbound_stop
 * capability (the seeded reference profile SIGNS its callbacks and the
 * GeezSMS profile has no inbound_stop — neither fits alone).
 *
 * @return array{path: string, gateway: SmsGateway}
 */
function smsMoDoor(): array
{
    $gateway = app(App\Domain\Sms\Gateways\GatewayWriter::class)->create(
        'MO-capable (FIXTURE profile)', 'http_generic',
        App\Domain\Sms\Gateways\FixtureGatewaySeeder::referenceMapping('http://mock-sms:8082/reference'),
        'fixture-reference-token', '00000000-0000-7000-8000-0000000000aa', signingDlr: false, isFixture: true,
    );
    $dlrUrl = (string) app(GatewayCredentials::class)->callbackUrl($gateway, false);
    $path = str_replace([(string) config('sms.dlr_callback_base'), '/webhooks/sms/dlr/'], ['https://localhost', '/webhooks/sms/mo/'], $dlrUrl);

    return ['path' => $path, 'gateway' => $gateway];
}

it('en: " STOP \n" from the wire opts the sender out (whitespace trimmed, the word untouched); the audit and event carry ids, never the phone', function (): void {
    $scene = smsPipeScene();
    Queue::fake();
    $door = smsMoDoor();

    test()->postJson($door['path'], ['from' => '251900005511', 'text' => " STOP \n"])->assertStatus(202)->assertJsonPath('opted_out', true);
    bindTenant($scene['tenant']);

    /** @var object{phone: string, source: string, stop_word_matched: string} $row */
    $row = DB::table('sms_optouts')->first(['phone', 'source', 'stop_word_matched']);
    expect($row->phone)->toBe('+251900005511')->and($row->source)->toBe('inbound_stop')->and($row->stop_word_matched)->toBe('STOP');
    expect((string) DB::table('outbox')->where('name', 'sms.optout.added')->value('payload'))->not->toContain('5511');
    expect((string) DB::table('audit_log')->where('action_key', 'sms.optout.added')->value('payload'))->not->toContain('5511');
});

it('am: the document\'s own bytes ride the wire and land byte-identical in stop_word_matched; a ONE-BYTE mutation is a counted drop, never an opt-out', function (): void {
    $scene = smsPipeScene();
    Queue::fake();
    $door = smsMoDoor();

    $doc = (string) file_get_contents(base_path('docs/Doc14-SMS-Notification-Module.md'));
    expect(preg_match('/\(STOP, ([^,]+), configurable list\)/u', $doc, $m))->toBe(1);
    $word = $m[1];
    expect(strlen($word))->toBe(9, 'ተወው is three Ethiopic syllables, nine bytes');

    test()->postJson($door['path'], ['from' => '251900005522', 'text' => $word])->assertStatus(202)->assertJsonPath('opted_out', true);
    bindTenant($scene['tenant']);
    expect((string) DB::table('sms_optouts')->where('phone', '+251900005522')->value('stop_word_matched'))->toBe($word);

    // the RED control: flip the last byte — near-ተወው is NOT ተወው (no normalization may rescue it)
    $mutated = substr($word, 0, -1).chr(ord($word[strlen($word) - 1]) ^ 0x01);
    $records = &capturedLogsRef();
    test()->postJson($door['path'], ['from' => '251900005533', 'text' => $mutated])->assertStatus(202)->assertJsonPath('opted_out', false);
    bindTenant($scene['tenant']);
    expect(DB::table('sms_optouts')->where('phone', '+251900005533')->exists())->toBeFalse()
        ->and(json_encode($records[count($records) - 1] ?? [], JSON_THROW_ON_ERROR))->toContain('sms.mo.non_stop_dropped');
});

it('the capability gate: the GeezSMS profile (no inbound_stop in its mapping) cannot opt anyone out through this door — counted drop even for a perfect STOP', function (): void {
    $scene = smsPipeScene();
    Queue::fake();
    $geez = SmsGateway::query()->where('name', 'GeezSMS (FIXTURE profile)')->firstOrFail();
    $dlrUrl = (string) app(GatewayCredentials::class)->callbackUrl($geez, false);
    $path = str_replace([(string) config('sms.dlr_callback_base'), '/webhooks/sms/dlr/'], ['https://localhost', '/webhooks/sms/mo/'], $dlrUrl);

    test()->postJson($path, ['from' => '251900005544', 'text' => 'STOP'])->assertStatus(202)->assertJsonPath('opted_out', false);
    bindTenant($scene['tenant']);
    expect(DB::table('sms_optouts')->count())->toBe(0);
});
