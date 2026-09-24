<?php

declare(strict_types=1);

use App\Domain\Sms\Gateways\GatewayCredentials;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

require_once dirname(__DIR__).'/Feature/Sms/PipelineSupport.php';

/*
| N-15 IS STRUCTURAL HERE (Doc 14 FR-15 / BR-01's neighbor): SMS is
| notification-only — the MO door PARSES and mints NOTHING conversational.
| The truth table: for a STOP, exactly one sms_optouts row appears and the
| conversations / messages / customer_identities / tickets tables do not
| move; for anything else, NOTHING moves anywhere. And no other inbound-SMS
| route exists that could reach a conversation: the route table is swept.
*/

it('N-15 truth table: STOP mints ONE optout row and nothing conversational; a non-STOP mints nothing anywhere', function (): void {
    $scene = smsPipeScene();
    Queue::fake();
    $gateway = app(App\Domain\Sms\Gateways\GatewayWriter::class)->create(
        'MO-capable (FIXTURE profile)', 'http_generic',
        App\Domain\Sms\Gateways\FixtureGatewaySeeder::referenceMapping('http://mock-sms:8082/reference'),
        'fixture-reference-token', '00000000-0000-7000-8000-0000000000aa', signingDlr: false, isFixture: true,
    );
    $dlrUrl = (string) app(GatewayCredentials::class)->callbackUrl($gateway, false);
    $path = str_replace([(string) config('sms.dlr_callback_base'), '/webhooks/sms/dlr/'], ['https://localhost', '/webhooks/sms/mo/'], $dlrUrl);

    $before = [
        'conversations' => DB::table('conversations')->count(),
        'messages' => DB::table('messages')->count(),
        'identities' => DB::table('customer_identities')->count(),
        'tickets' => DB::table('tickets')->count(),
        'customers' => DB::table('customers')->count(),
    ];

    test()->postJson($path, ['from' => '251900006611', 'text' => 'STOP'])->assertStatus(202)->assertJsonPath('opted_out', true);
    bindTenant($scene['tenant']);
    expect(DB::table('sms_optouts')->count())->toBe(1);

    test()->postJson($path, ['from' => '251900006622', 'text' => 'my bet did not settle, please help'])->assertStatus(202)->assertJsonPath('opted_out', false);
    bindTenant($scene['tenant']);

    $after = [
        'conversations' => DB::table('conversations')->count(),
        'messages' => DB::table('messages')->count(),
        'identities' => DB::table('customer_identities')->count(),
        'tickets' => DB::table('tickets')->count(),
        'customers' => DB::table('customers')->count(),
    ];
    expect($after)->toBe($before, 'the MO door minted something conversational — N-15 broken');
    expect(DB::table('sms_optouts')->count())->toBe(1, 'the non-STOP inbound must land nowhere');
});

it('no inbound-SMS route reaches a conversation controller: the route sweep (the N-15 structural half)', function (): void {
    $offenders = [];
    foreach (Illuminate\Support\Facades\Route::getRoutes() as $route) {
        $uri = $route->uri();
        if (! str_contains($uri, 'sms') || ! str_starts_with($uri, 'api/webhooks')) {
            continue;
        }
        $action = $route->getActionName();
        if (preg_match('/Conversation|Message|Inbound|Thread/i', $action) === 1) {
            $offenders[] = $uri.' → '.$action;
        }
    }
    expect($offenders)->toBe([]);

    // the POSITIVE control: the sweep DOES see the real webchat inbound route reaching its conversational controller
    $webchatConversational = false;
    foreach (Illuminate\Support\Facades\Route::getRoutes() as $route) {
        if (preg_match('/Webchat|Widget/i', $route->getActionName()) === 1 && preg_match('/Message|Conversation|Transport/i', $route->getActionName()) === 1) {
            $webchatConversational = true;
        }
    }
    expect($webchatConversational)->toBeTrue('the scanner must be able to see a conversational route where one legitimately exists');
});
