<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

require_once __DIR__.'/SmsSupport.php';

/*
| Doc 14 FR-04 / §8 / EC-06 — the draft→publish pattern with instant rollback,
| through the doors: create (head + v1 live), publish (v2 live; v1 untouched —
| INSERT-only by grant), rollback (a NEW v3 copying v1, status rolled_back),
| update (head-only fields), delete (soft; versions stay); the audit rows carry
| ids and version numbers, never the body; the permission split holds.
*/

it('creates a template (head + v1 live), publishes v2, rolls back to v1 as a NEW v3, updates the head, and soft-deletes — the history append-only', function (): void {
    [, $user] = smsScene();

    $created = smsApi($user, 'POST', '/templates', [
        'event' => 'ticket_resolved', 'language' => 'en', 'name' => 'Resolved',
        'template_body' => '{{brand.name}}: ticket {{ticket.number}} is resolved, {{customer.name}}.',
    ]);
    $created->assertStatus(201)->assertJsonPath('live_version', 1)->assertJsonPath('encoding', 'gsm7')->assertJsonPath('quiet_hours_policy', 'hold');
    $id = $created->json('id');

    $published = smsApi($user, 'POST', '/templates/'.$id.'/publish', ['template_body' => 'ትኬት {{ticket.number}} ተፈትቷል። {{brand.name}}', 'note' => 'Amharic pass']);
    $published->assertStatus(201)->assertJsonPath('version', 2)->assertJsonPath('encoding', 'ucs2');

    $show = smsApi($user, 'GET', '/templates/'.$id)->assertOk();
    expect($show->json('live_version'))->toBe(2)
        ->and($show->json('template_body'))->toContain('ተፈትቷል')
        ->and(count((array) $show->json('history')))->toBe(2)
        ->and(DB::table('sms_template_versions')->where('template_id', $id)->where('version', 1)->value('body'))->toContain('is resolved'); // v1 stays untouched (INSERT-only by grant) — toContain is variadic, the S0 lesson

    $rolled = smsApi($user, 'POST', '/templates/'.$id.'/rollback', ['to_version' => 1]);
    $rolled->assertStatus(201)->assertJsonPath('version', 3)->assertJsonPath('rolled_back_to', 1);
    $afterRollback = smsApi($user, 'GET', '/templates/'.$id)->assertOk();
    expect($afterRollback->json('live_version'))->toBe(3)
        ->and($afterRollback->json('template_body'))->toContain('is resolved')
        ->and(DB::table('sms_template_versions')->where('template_id', $id)->where('version', 3)->value('status_at_birth'))->toBe('rolled_back')
        ->and(DB::table('sms_template_versions')->where('template_id', $id)->count())->toBe(3, 'rollback is a NEW row, never a backwards move');

    smsApi($user, 'PATCH', '/templates/'.$id, ['name' => 'Resolved (v2 copy)', 'quiet_hours_policy' => 'skip', 'on_missing' => 'fallback_text', 'fallback_text' => 'Your ticket is resolved.'])
        ->assertOk()->assertJsonPath('name', 'Resolved (v2 copy)')->assertJsonPath('quiet_hours_policy', 'skip')->assertJsonPath('on_missing', 'fallback_text');

    smsApi($user, 'DELETE', '/templates/'.$id)->assertOk()->assertJsonPath('deleted', true);
    expect(DB::table('sms_templates')->where('id', $id)->value('deleted_at'))->not->toBeNull()
        ->and(DB::table('sms_template_versions')->where('template_id', $id)->count())->toBe(3, 'the versions stay');
    smsApi($user, 'GET', '/templates/'.$id)->assertNotFound();

    // the audit trail: ids + versions, never the body
    $actions = DB::table('audit_log')->where('action_key', 'like', 'sms.template.%')->orderBy('id')->pluck('action_key')->all();
    expect($actions)->toBe(['sms.template.created', 'sms.template.published', 'sms.template.rolled_back', 'sms.template.updated', 'sms.template.deleted']);
    foreach (DB::table('audit_log')->where('action_key', 'like', 'sms.template.%')->pluck('payload') as $payload) {
        expect((string) $payload)->not->toContain('resolved,')->not->toContain('ተፈትቷል');
    }
});

it('one template per event per language (FR-01); the list groups by event with per-language completeness dots and names enabled events lacking a template', function (): void {
    [, $user] = smsScene();

    smsApi($user, 'POST', '/templates', ['event' => 'ticket_created', 'language' => 'en', 'name' => 'Created', 'template_body' => '{{brand.name}}: ticket {{ticket.number}} is open.'])->assertStatus(201);
    smsApi($user, 'POST', '/templates', ['event' => 'ticket_created', 'language' => 'en', 'name' => 'Again', 'template_body' => 'x'])
        ->assertStatus(422)->assertJsonPath('errors.refusal.0', 'duplicate_template');

    $list = smsApi($user, 'GET', '/templates')->assertOk();
    $events = collect((array) $list->json('events'))->keyBy('event');
    expect($list->json('languages'))->toBe(['en', 'am'])
        ->and($events->get('ticket_created')['languages']['en']['complete'])->toBeTrue()
        ->and($events->get('ticket_created')['languages']['am']['complete'])->toBeFalse()
        ->and($events->get('optout_confirmation')['sendable'])->toBeFalse('optout_confirmation is a template event, never sendable')
        ->and($events->has('marketing_blast'))->toBeFalse();

    // §14: enabled events lacking a template are named — ticket_created/am and the other three §21 events in both languages
    $incomplete = collect((array) $list->json('incomplete'))->map(fn (array $r): string => $r['event'].'/'.$r['language'])->all();
    expect($incomplete)->toContain('ticket_created/am')->toContain('ticket_resolved/en')->not->toContain('ticket_created/en')->not->toContain('ticket_assigned/en');
});

it('the permission split: view cannot create or publish; create cannot delete; every door 403s without its key', function (): void {
    [, $viewer] = smsScene(['sms.templates.view']);
    smsApi($viewer, 'GET', '/templates')->assertOk();
    smsApi($viewer, 'POST', '/templates', ['event' => 'ticket_created', 'language' => 'en', 'name' => 'x', 'template_body' => 'x'])->assertForbidden();

    [, $creator] = smsScene(['sms.templates.view', 'sms.templates.create']);
    $created = smsApi($creator, 'POST', '/templates', ['event' => 'ticket_created', 'language' => 'en', 'name' => 'x', 'template_body' => '{{brand.name}} hello'])->assertStatus(201);
    smsApi($creator, 'POST', '/templates/'.$created->json('id').'/publish', ['template_body' => 'y'])->assertForbidden();
    smsApi($creator, 'DELETE', '/templates/'.$created->json('id'))->assertForbidden();
});
