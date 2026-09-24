<?php

declare(strict_types=1);

use App\Domain\Sms\Broadcasts\BroadcastWriter;
use App\Jobs\Sms\BroadcastChunkJob;
use App\Models\SmsBroadcast;
use App\Models\SmsTemplate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

require_once __DIR__.'/PipelineSupport.php';

/*
| Doc 14 FR-17 / AC-06 / EC-06 / BR-02 / A-64 — the broadcast lifecycle
| through the real doors: draft pins the PUBLISHED version; preview answers
| the SAME query the chunker runs; the pin survives a republish (the EC-06
| notice says so; the SENT body is the PIN's); cancel stops the un-sent
| remainder cold; the typed confirm arms at 1,000; the monthly cap counts
| DB-stamped tenant-month buckets; only service_announcement is
| broadcast-capable (the writer refuses, the DB trigger is the physics).
*/

/** @return array{scene: array<string, mixed>, extraCustomerIds: list<string>} the scene + N extra phone-bearing customers */
function smsBroadcastScene(int $extra = 3): array
{
    $scene = smsPipeScene(['sms.send', 'tickets.view', 'sms.templates.view', 'sms.gateways.manage']);
    Queue::fake();
    // pin a quiet-hours window that does NOT cover now, so announcement rows QUEUE (the hold path is QuietHoursTest's)
    $nowAddis = new DateTimeImmutable('now', new DateTimeZone('Africa/Addis_Ababa'));
    app(App\Support\Settings\SettingsRepository::class)->set('sms.quiet_hours', ['start' => $nowAddis->modify('+3 hours')->format('H:i'), 'end' => $nowAddis->modify('+4 hours')->format('H:i')]);
    $ids = [];
    for ($i = 0; $i < $extra; $i++) {
        $customer = App\Models\Customer::query()->create(['display_name' => 'Fixture Audience '.$i, 'username' => '@fixture_aud_'.Str::random(6), 'is_shell' => true, 'created_source' => 'staff']);
        app(App\Domain\Customers\Identity\LinkIdentity::class)->link($customer->identifier(), 'phone', sprintf('+2519000%05d', 4000 + $i), $scene['user']->identifier());
        $ids[] = $customer->identifier();
    }

    return ['scene' => $scene, 'extraCustomerIds' => $ids];
}

function smsBroadcastApi(App\Models\User $user, string $method, string $path, array $payload = []): Illuminate\Testing\TestResponse
{
    $url = 'https://localhost/admin/sms/api/broadcasts'.$path;

    return match ($method) {
        'GET' => test()->actingAs($user, 'staff')->getJson($url),
        'PATCH' => test()->actingAs($user, 'staff')->patchJson($url, $payload),
        default => test()->actingAs($user, 'staff')->postJson($url, $payload),
    };
}

it('AC-06 end to end: draft pins v1, preview = the query, a republished v2 raises the EC-06 notice but the SENT bodies are the PIN\'s; executed = preview − live skips, the completion figures agree', function (): void {
    $context = smsBroadcastScene(3);
    $scene = $context['scene'];
    /** @var SmsTemplate $template */
    $template = SmsTemplate::query()->where('event', 'service_announcement')->where('language', 'en')->firstOrFail();
    $pinnedVersion = $template->live_version_id;

    $created = smsBroadcastApi($scene['user'], 'POST', '/', ['name' => 'September maintenance window', 'template_id' => $template->identifier()]);
    bindTenant($scene['tenant']);
    $created->assertStatus(201);
    $broadcastId = $created->json('id');
    expect($created->json('pinned_version_id'))->toBe($pinnedVersion)
        ->and($created->json('audience_count_preview'))->toBe(4); // the scene customer + 3 extras

    // one recipient opts out AFTER the draft (the live-skip half of AC-06's ±)
    app(App\Domain\Sms\OptOut\OptOutRegistry::class)->addByStaff('+251900004001', 'fixture opt-out', $scene['user']->identifier());

    // v2 published after the pin → the EC-06 notice carries the newer live version
    app(App\Domain\Sms\Templates\TemplateWriter::class)->publish($template->refresh(), (string) DB::table('sms_template_versions')->where('id', $pinnedVersion)->value('body'), $scene['user']->identifier());
    $preview = smsBroadcastApi($scene['user'], 'GET', '/'.$broadcastId.'/preview');
    bindTenant($scene['tenant']);
    expect($preview->json('pinned_version_id'))->toBe($pinnedVersion)
        ->and($preview->json('template_live_version_id_if_newer'))->not->toBeNull('EC-06: the notice, never a silent swap');

    // launch (audience 4 < 1000: no typed confirm needed) and run the chunker by hand
    smsBroadcastApi($scene['user'], 'POST', '/'.$broadcastId.'/launch')->assertOk();
    bindTenant($scene['tenant']);
    (new BroadcastChunkJob($broadcastId, null))->handle(app(App\Domain\Sms\Broadcasts\AudienceQuery::class), app(BroadcastWriter::class), app(App\Domain\Sms\Pipeline\EnqueueSms::class), app(App\Support\Events\EventPublisher::class));

    /** @var SmsBroadcast $broadcast */
    $broadcast = SmsBroadcast::query()->findOrFail($broadcastId);
    expect($broadcast->state)->toBe('done')->and($broadcast->completed_at)->not->toBeNull()->and($broadcast->monthly_bucket)->not->toBeNull();
    $rows = DB::table('sms_messages')->where('broadcast_id', $broadcastId)->get(['customer_id', 'state', 'skip_reason', 'template_version_id', 'lane']);
    expect($rows)->toHaveCount(4)
        ->and($rows->where('state', 'queued')->count())->toBe(3)
        ->and($rows->where('skip_reason', 'opted_out')->count())->toBe(1, 'preview = executed ± live skips — the skip is ON THE BOOKS')
        ->and($rows->pluck('lane')->unique()->all())->toBe(['sms-broadcast'])
        ->and($rows->pluck('template_version_id')->filter()->unique()->all())->toBe([], 'version lands at render');

    // send one row: the body is the PIN's version, not v2
    $queuedRow = DB::table('sms_messages')->where('broadcast_id', $broadcastId)->where('state', 'queued')->first(['id']);
    smsPipeRunJob((string) $queuedRow->id);
    expect((string) DB::table('sms_messages')->where('id', $queuedRow->id)->value('template_version_id'))->toBe($pinnedVersion, 'AC-06/EC-06: the broadcast renders ITS PIN')
        ->and((string) DB::table('sms_messages')->where('id', $queuedRow->id)->value('state'))->toBe('sent');

    expect(smsPipeOutbox('sms.broadcast.started'))->toHaveCount(1)
        ->and(smsPipeOutbox('sms.broadcast.progress'))->toHaveCount(1)
        ->and(smsPipeOutbox('sms.broadcast.completed'))->toHaveCount(1);
});

it('cancel stops the un-sent remainder COLD: a launch whose chunk never ran cancels, the chunk job then lands nothing', function (): void {
    $context = smsBroadcastScene(2);
    $scene = $context['scene'];
    /** @var SmsTemplate $template */
    $template = SmsTemplate::query()->where('event', 'service_announcement')->where('language', 'en')->firstOrFail();

    $broadcast = app(BroadcastWriter::class)->create('Cancel me', $template->identifier(), [], $scene['user']);
    app(BroadcastWriter::class)->launch($broadcast, $scene['user'], null);
    smsBroadcastApi($scene['user'], 'POST', '/'.$broadcast->identifier().'/cancel')->assertOk();
    bindTenant($scene['tenant']);
    expect((string) DB::table('sms_broadcasts')->where('id', $broadcast->identifier())->value('state'))->toBe('canceled');

    (new BroadcastChunkJob($broadcast->identifier(), null))->handle(app(App\Domain\Sms\Broadcasts\AudienceQuery::class), app(BroadcastWriter::class), app(App\Domain\Sms\Pipeline\EnqueueSms::class), app(App\Support\Events\EventPublisher::class));
    expect(DB::table('sms_messages')->where('broadcast_id', $broadcast->identifier())->count())->toBe(0, 'the un-sent remainder never sends');
    expect(json_decode(smsPipeOutbox('sms.broadcast.canceled')[0]->payload, true, flags: JSON_THROW_ON_ERROR)['broadcast_id'])->toBe($broadcast->identifier());
});

it('BR-02 + the caps: a non-announcement template is refused; the typed confirm arms at 1,000; the monthly cap refuses the launch past it', function (): void {
    $context = smsBroadcastScene(0);
    $scene = $context['scene'];
    /** @var SmsTemplate $wrong */
    $wrong = SmsTemplate::query()->where('event', 'ticket_resolved')->where('language', 'en')->firstOrFail();
    smsBroadcastApi($scene['user'], 'POST', '/', ['name' => 'Wrong template kind', 'template_id' => $wrong->identifier()])
        ->assertStatus(422)->assertJsonPath('errors.refusal.0', 'broadcast_template_refused');
    bindTenant($scene['tenant']);

    // 1,000 phone-bearing customers by BULK insert (dev-scale; the typed-confirm threshold is the point)
    $customers = [];
    $identities = [];
    for ($i = 0; $i < 1000; $i++) {
        $id = (string) Str::uuid7();
        $customers[] = ['id' => $id, 'tenant_id' => $scene['tenant']->identifier(), 'display_name' => 'Bulk '.$i, 'username' => '@fixture_bulk_'.$i, 'is_shell' => true, 'created_source' => 'staff'];
        $identities[] = ['id' => (string) Str::uuid7(), 'tenant_id' => $scene['tenant']->identifier(), 'customer_id' => $id, 'channel' => 'sms', 'external_user_id' => 'bulk-'.$i, 'type' => 'phone', 'value' => sprintf('+2519%08d', 10000000 + $i), 'source' => 'staff'];
    }
    foreach (array_chunk($customers, 250) as $chunk) {
        DB::table('customers')->insert($chunk);
    }
    foreach (array_chunk($identities, 250) as $chunk) {
        DB::table('customer_identities')->insert($chunk);
    }

    /** @var SmsTemplate $template */
    $template = SmsTemplate::query()->where('event', 'service_announcement')->where('language', 'en')->firstOrFail();
    $big = app(BroadcastWriter::class)->create('The big one', $template->identifier(), [], $scene['user']);
    smsBroadcastApi($scene['user'], 'POST', '/'.$big->identifier().'/launch')
        ->assertStatus(422)->assertJsonPath('errors.refusal.0', 'broadcast_confirmation_required');
    bindTenant($scene['tenant']);
    smsBroadcastApi($scene['user'], 'POST', '/'.$big->identifier().'/launch', ['typed_confirmation' => 'the wrong name'])
        ->assertStatus(422);
    bindTenant($scene['tenant']);
    smsBroadcastApi($scene['user'], 'POST', '/'.$big->identifier().'/launch', ['typed_confirmation' => 'The big one'])->assertOk();
    bindTenant($scene['tenant']);

    // the monthly cap (tenant 4 / platform 30): three more launches fill the month, the fifth refuses
    for ($n = 0; $n < 3; $n++) {
        $b = app(BroadcastWriter::class)->create('Filler '.$n, $template->identifier(), [], $scene['user']);
        app(BroadcastWriter::class)->launch($b, $scene['user'], 'Filler '.$n);
    }
    $fifth = app(BroadcastWriter::class)->create('One too many', $template->identifier(), [], $scene['user']);
    smsBroadcastApi($scene['user'], 'POST', '/'.$fifth->identifier().'/launch', ['typed_confirmation' => 'One too many'])
        ->assertStatus(422)->assertJsonPath('errors.refusal.0', 'broadcast_monthly_cap');
    bindTenant($scene['tenant']);

    // A-64: sms.send alone cannot launch
    $sender = ticketActor(['sms.send', 'sms.templates.view'], 'Fixture Sender Only');
    bindTenant($scene['tenant']);
    test()->actingAs($sender, 'staff')->postJson('https://localhost/admin/sms/api/broadcasts/'.$fifth->identifier().'/launch')->assertForbidden();
});
