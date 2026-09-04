<?php

declare(strict_types=1);

use App\Domain\Webchat\Seeding\WidgetFormSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Doc 13 T-03 `POST forms/{versionId}/steps` (FR-06 / FR-09 shortcut forms;
 * Doc 12's contract rendered): a STEP is validated against the immutable
 * version's schema — `visible_when` (the domain key; B12-F03's `show_when`
 * never reaches here), required, per-type validation — and the door answers
 * the verdict + the next visible step. State stays with the client (no
 * conversation may exist yet: FR-05); on completion the answers ride the next
 * message's envelope and Doc 13's consumer lands them (STOP-5C).
 */
it('validates a pre-chat step against the published schema: required blocks with the field named, optional skips, a hidden field (visible_when) is never required', function (): void {
    [$tenant, , $key] = widgetFixture();
    bindTenant($tenant);
    $agent = ticketActor([]); // a user of THIS tenant (formScene() would mint another tenant)
    bindTenant($tenant); // ticketActor() leaves the context unbound
    app(App\Domain\Org\Seeding\OrganizationSeeder::class)->seed(); // departments for Doc 12's seeded forms
    app(App\Domain\Forms\Seeding\FormSeeder::class)->seed($agent); // the Deposit form the visible_when leg reads
    app(WidgetFormSeeder::class)->seed($agent->identifier());
    $preChat = (string) DB::table('forms')->where('name', WidgetFormSeeder::PRE_CHAT)->value('current_version_id');
    resetWidgetClient();
    $session = widgetSession($key);
    $token = $session->json('token');
    $cookie = visitorCookieFrom($session);

    // Required blocks, field named.
    $r = widgetClient($key, $token, $cookie)->postJson('/api/widget/v1/forms/'.$preChat.'/steps', ['mode' => 'form', 'step_index' => 0, 'answers' => ['name' => 'Abebe']]);
    $r->assertStatus(422);
    expect($r->json('errors'))->toHaveKey('phone')->not->toHaveKey('name');

    // Complete step → ok + no next step (a one-step form); the contract's two fields exactly.
    $ok = widgetClient($key, $token, $cookie)->postJson('/api/widget/v1/forms/'.$preChat.'/steps', ['mode' => 'form', 'step_index' => 0, 'answers' => ['name' => 'Abebe', 'phone' => '+251900001234']])->assertOk()->json();
    expect($ok['valid'])->toBeTrue()->and($ok['next_step'])->toBeNull()->and($ok['complete'])->toBeTrue();

    // A visible_when field: the seeded Deposit form's evidence step is only asked after the payment step — the door reads visible_when.
    bindTenant($tenant); // the request cycle cleared the RLS setting
    $deposit = (string) DB::table('forms')->where('name', 'Deposit Issue')->value('current_version_id');
    $step0 = widgetClient($key, $token, $cookie)->postJson('/api/widget/v1/forms/'.$deposit.'/steps', ['mode' => 'form', 'step_index' => 0, 'answers' => ['amount' => 500, 'currency' => 'ETB', 'method' => 'telebirr', 'transaction_ref' => 'ABCDEFGH12']])->assertOk()->json();
    expect($step0['next_step'])->toBe(1);
    // FR-05: no customer, no conversation — validation minted nothing.
    bindTenant($tenant);
    expect(DB::table('customers')->count())->toBe(0)->and(DB::table('conversations')->count())->toBe(0);
});
