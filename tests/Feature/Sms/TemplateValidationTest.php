<?php

declare(strict_types=1);

use App\Support\Settings\SettingsRepository;

require_once __DIR__.'/SmsSupport.php';

/*
| Doc 14 FR-05 / SEC-05 / AC-10 — a template referencing a variable its event
| does not provide CANNOT publish; an attempted arbitrary-URL template FAILS
| publish (smishing-shape prevention); {{link}} needs the tenant's short-link
| domain; the editor's validate door answers the same code publish runs.
| Sabotage (k) — the URL check removed → this file's AC-10 legs go red.
*/

it('FR-05: a variable the event does not provide is refused at create, publish and validate — the allowed set is named', function (): void {
    [, $user] = smsScene();

    $refused = smsApi($user, 'POST', '/templates', ['event' => 'verification_code', 'language' => 'en', 'name' => 'Code', 'template_body' => 'Your code {{code}} for ticket {{ticket.number}}']);
    $refused->assertStatus(422)->assertJsonPath('errors.refusal.0', 'variable_not_allowed');
    expect($refused->json('errors.variables'))->toBe(['ticket.number'])
        ->and($refused->json('errors.allowed'))->toBe(['brand.name', 'code']);

    $ok = smsApi($user, 'POST', '/templates', ['event' => 'verification_code', 'language' => 'en', 'name' => 'Code', 'template_body' => '{{brand.name}}: your code is {{code}}.'])->assertStatus(201);
    smsApi($user, 'POST', '/templates/'.$ok->json('id').'/validate', ['template_body' => 'code {{code}} — {{customer.name}}'])
        ->assertStatus(422)->assertJsonPath('errors.refusal.0', 'variable_not_allowed');
    smsApi($user, 'POST', '/templates/'.$ok->json('id').'/publish', ['template_body' => 'code {{code}} — {{customer.name}}'])
        ->assertStatus(422)->assertJsonPath('errors.refusal.0', 'variable_not_allowed');
    smsApi($user, 'POST', '/templates/'.$ok->json('id').'/validate', ['template_body' => 'ኮድዎ {{code}} ነው።'])
        ->assertOk()->assertJsonPath('encoding', 'ucs2')->assertJsonPath('segments_estimate', 1);
});

it('AC-10 / SEC-05: an arbitrary URL in a template fails publish — http, https and www shapes; {{link}} is the only link and needs sms.link.domain', function (): void {
    [, $user] = smsScene();
    $t = smsApi($user, 'POST', '/templates', ['event' => 'ticket_resolved', 'language' => 'en', 'name' => 'Resolved', 'template_body' => 'Resolved: {{ticket.number}}'])->assertStatus(201)->json('id');

    foreach (['See https://evil.example/login', 'Go to http://phish.example', 'Visit www.phish.example now'] as $planted) {
        smsApi($user, 'POST', '/templates/'.$t.'/publish', ['template_body' => $planted])
            ->assertStatus(422)->assertJsonPath('errors.refusal.0', 'arbitrary_url');
    }

    // {{link}} without the domain configured: refused, with the reason
    smsApi($user, 'POST', '/templates/'.$t.'/publish', ['template_body' => 'Status: {{link}}'])
        ->assertStatus(422)->assertJsonPath('errors.refusal.0', 'link_domain_unset');

    // with the domain configured: publishes
    app(SettingsRepository::class)->set('sms.link.domain', 's.nabet.example');
    smsApi($user, 'POST', '/templates/'.$t.'/publish', ['template_body' => 'Status: {{link}}'])->assertStatus(201)->assertJsonPath('version', 2);
});

it('an unknown event, an empty body and an over-long body are refused; optout_confirmation is a valid TEMPLATE event with brand.name only', function (): void {
    [, $user] = smsScene();

    smsApi($user, 'POST', '/templates', ['event' => 'marketing_blast', 'language' => 'en', 'name' => 'x', 'template_body' => 'x'])
        ->assertStatus(422)->assertJsonPath('errors.refusal.0', 'unknown_event');
    smsApi($user, 'POST', '/templates', ['event' => 'ticket_created', 'language' => 'en', 'name' => 'x', 'template_body' => str_repeat('a', 1001)])
        ->assertStatus(422);

    smsApi($user, 'POST', '/templates', ['event' => 'optout_confirmation', 'language' => 'am', 'name' => 'መውጣት', 'template_body' => '{{brand.name}}፦ ተጨማሪ SMS አይደርስዎትም።'])
        ->assertStatus(201)->assertJsonPath('quiet_hours_policy', 'exempt');
    smsApi($user, 'POST', '/templates', ['event' => 'optout_confirmation', 'language' => 'en', 'name' => 'x', 'template_body' => 'Ticket {{ticket.number}}'])
        ->assertStatus(422)->assertJsonPath('errors.refusal.0', 'variable_not_allowed');
});
