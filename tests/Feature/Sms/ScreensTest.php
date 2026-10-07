<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

require_once __DIR__.'/PipelineSupport.php';

/*
|--------------------------------------------------------------------------
| Doc 14 §8/§9/§13/§14 (S14 step 13, [B14-batch]) — the six SMS pages render
| their Inertia props for a viewer holding the STOP-A read key; every page is
| GATED by its key (403 without); the settings WRITE door keeps the C-04
| problem shape on a refusal; the P-NAV-SMS home redirect stands.
|--------------------------------------------------------------------------
*/

it('renders each SMS page with its props for a viewer holding the key, and 403s a viewer without it', function (): void {
    $scene = smsPipeScene(['sms.send', 'tickets.view', 'sms.templates.view', 'sms.gateways.view', 'sms.optout.manage', 'settings.view', 'reports.view']);
    $user = $scene['user'];

    $pages = [
        ['/admin/sms/templates', 'Admin/Sms/Templates', 'doors.events', 'sms.templates.view'],
        ['/admin/sms/gateways', 'Admin/Sms/Gateways', 'doors.gateways', 'sms.gateways.view'],
        ['/admin/sms/queue', 'Admin/Sms/Queue', 'rows', 'sms.send'],
        ['/admin/sms/optouts', 'Admin/Sms/OptOuts', 'doors.optouts', 'sms.optout.manage'],
        ['/admin/sms/broadcasts', 'Admin/Sms/Broadcasts', 'doors.broadcasts', 'sms.send'],
        ['/admin/sms/settings', 'Admin/Sms/Settings', 'checklist', 'settings.view'],
    ];
    foreach ($pages as [$url, $component, $propPath, $key]) {
        $response = test()->actingAs($user, 'staff')->get('https://localhost'.$url);
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component($component, shouldExist: false)->has('copy')->has('can')->has($propPath));
    }

    // the home redirect (P-NAV-SMS "Channels · SMS" slot)
    test()->actingAs($user, 'staff')->get('https://localhost/admin/sms')->assertRedirect('/admin/sms/templates');

    // gating: a viewer WITHOUT the page's key is refused at the route
    bindTenant($scene['tenant']); // requests clear the test-process binding
    $bare = ticketActor(['tickets.view'], 'S14 Screens No Keys Role');
    foreach ($pages as [$url]) {
        test()->actingAs($bare, 'staff')->get('https://localhost'.$url)->assertForbidden();
    }
});

it('the templates page carries the cost row per routed gateway and the per-event variable palette', function (): void {
    $scene = smsPipeScene(['sms.send', 'tickets.view', 'sms.templates.view']);

    $response = test()->actingAs($scene['user'], 'staff')->get('https://localhost/admin/sms/templates');
    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->component('Admin/Sms/Templates', shouldExist: false)
        ->has('cost_cards.0.gateway')->has('cost_cards.0.per_segment')->has('cost_cards.0.currency')
        ->has('variables.ticket_resolved'));
});

it('the settings page states the checklist truthfully and the write door keeps the problem shape on a refusal', function (): void {
    $scene = smsPipeScene(['sms.send', 'tickets.view', 'settings.view', 'settings.edit']);
    $user = $scene['user'];

    // the scene seeds fixture gateways + the +251 route → gateway and route are TRUE
    $response = test()->actingAs($user, 'staff')->get('https://localhost/admin/sms/settings');
    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->component('Admin/Sms/Settings', shouldExist: false)
        ->where('checklist.gateway', true)->where('checklist.route', true)
        ->has('quiet.start')->has('quiet.tz')->has('events.ticket_resolved'));

    // refusal: a malformed quiet window is the C-04 problem, not a 500
    $bad = test()->actingAs($user, 'staff')->postJson('https://localhost/admin/sms/settings', ['quiet_hours' => ['start' => 'nonsense', 'end' => '08:00']]);
    $bad->assertStatus(422);
    $bad->assertJsonStructure(['type', 'title', 'status', 'code', 'detail', 'errors', 'trace_id']);

    // an unknown event toggle is refused by name
    test()->actingAs($user, 'staff')->postJson('https://localhost/admin/sms/settings', ['events' => ['not_an_event' => true]])->assertStatus(422);

    // the write lands: quiet hours move, a toggle flips, both through the registry
    test()->actingAs($user, 'staff')->postJson('https://localhost/admin/sms/settings', [
        'quiet_hours' => ['start' => '22:15', 'end' => '06:45'],
        'events' => ['ticket_closed' => true],
    ])->assertOk();
    bindTenant($scene['tenant']); // a request clears the test-process binding (the WidgetDesignerScreensTest pattern)
    expect(app(App\Support\Settings\SettingsRepository::class)->get('sms.quiet_hours'))->toEqualCanonicalizing(['start' => '22:15', 'end' => '06:45'])
        ->and((bool) app(App\Support\Settings\SettingsRepository::class)->get('sms.events.ticket_closed.enabled'))->toBeTrue();
});

it('the queue page masks every phone and the held view rides the held door', function (): void {
    $scene = smsPipeScene(['sms.send', 'tickets.view']);
    // one queued row through the one door
    $result = DB::transaction(fn () => app(App\Domain\Sms\Pipeline\EnqueueSms::class)->enqueue(
        new App\Domain\Sms\Pipeline\SendRequest(App\Domain\Sms\Catalog\SendableEvent::TicketResolved, $scene['ticket']->customer_id, $scene['ticket']->identifier()),
    ));
    expect($result->smsMessageId)->not->toBeNull();

    $response = test()->actingAs($scene['user'], 'staff')->get('https://localhost/admin/sms/queue');
    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->component('Admin/Sms/Queue', shouldExist: false)
        ->has('rows.0.phone_masked')->has('held.held')
        ->where('rows', fn ($rows) => collect($rows)->isNotEmpty() && collect($rows)->every(
            fn (array $row): bool => str_contains((string) $row['phone_masked'], '•') && ! array_key_exists('phone', $row),
        )));
});
