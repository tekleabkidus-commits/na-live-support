<?php

declare(strict_types=1);

use App\Jobs\Sms\Consumers\AutomationSmsRequested;
use App\Jobs\Sms\Consumers\RgFlagSmsSuppression;
use App\Jobs\Sms\Consumers\SuppressionSetSms;
use App\Jobs\Sms\Consumers\TicketAssignedSms;
use App\Jobs\Sms\Consumers\TicketResolvedSms;
use App\Support\Settings\SettingsRepository;
use App\Support\Timers\DueSet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/PipelineSupport.php';

/*
| Doc 14 step 6 / C-09 / SEC-07 / FS-02 — the durable consumers: each event
| → ONE row through the one door; the per-event toggle FIRST (off = nothing
| exists); idempotent on event_id BY CAUSATION (a re-drive hours later finds
| its trace and stops — the kill-between-persist-and-send shape); the customer
| re-fetched by ticket_id; the suppression consumers skip HELD non-essential
| rows only.
*/

it('ticket.resolved → one queued row through the door; a re-drive of the SAME event lands nothing more (C-09 by causation — the FS-02 shape)', function (): void {
    $scene = smsPipeScene();
    Queue::fake();
    $eventId = inboxSeedEvent('ticket.resolved', [
        'tenant_id' => $scene['tenant']->identifier(),
        'ticket_id' => $scene['ticket']->identifier(),
        'conversation_id' => $scene['conversation']->identifier(),
        'actor_type' => 'staff',
        'actor_id' => $scene['user']->identifier(),
    ]);

    (new TicketResolvedSms($eventId))->handle();
    expect(DB::table('sms_messages')->where('event', 'ticket_resolved')->count())->toBe(1);
    /** @var object{state: string, customer_id: string} $row */
    $row = DB::table('sms_messages')->where('event', 'ticket_resolved')->first(['state', 'customer_id']);
    expect($row->state)->toBe('queued')
        ->and($row->customer_id)->toBe($scene['ticket']->customer_id, 'SEC-07: the customer came from the re-fetched ticket row');
    expect((string) DB::table('outbox')->where('name', 'sms.queued')->value('causation_id'))->toBe($eventId);

    // the kill/re-drive drill: the row persisted, the "killed" worker's job re-delivers — the causation trace stops it
    (new TicketResolvedSms($eventId))->handle();
    (new TicketResolvedSms($eventId))->handle();
    expect(DB::table('sms_messages')->where('event', 'ticket_resolved')->count())->toBe(1)
        ->and(DB::table('outbox')->where('name', 'sms.queued')->count())->toBe(1);
});

it('the per-event toggle is checked FIRST: ticket.assigned (off by §21) leaves NOTHING; toggled on, the same delivery leaves one row', function (): void {
    $scene = smsPipeScene();
    Queue::fake();
    $eventId = inboxSeedEvent('ticket.assigned', [
        'tenant_id' => $scene['tenant']->identifier(),
        'ticket_id' => $scene['ticket']->identifier(),
        'conversation_id' => $scene['conversation']->identifier(),
        'old_assignee_id' => null,
        'new_assignee_id' => $scene['user']->identifier(),
        'by' => $scene['user']->identifier(),
    ]);

    (new TicketAssignedSms($eventId))->handle();
    expect(DB::table('sms_messages')->count())->toBe(0, 'off means nothing exists — not a skipped row');

    app(SettingsRepository::class)->set('sms.events.ticket_assigned.enabled', true);
    (new TicketAssignedSms($eventId))->handle();
    expect(DB::table('sms_messages')->where('event', 'ticket_assigned')->count())->toBe(1);
});

it('automation.sms.requested: the template_ref names an FR-01 key → one row; an unknown ref is a LOGGED, COUNTED drop with no row (the physics-honest reading, flagged for B✓)', function (): void {
    $scene = smsPipeScene();
    Queue::fake();
    $records = &capturedLogsRef();

    $good = inboxSeedEvent('automation.sms.requested', [
        'tenant_id' => $scene['tenant']->identifier(),
        'rule_id' => (string) Illuminate\Support\Str::uuid7(),
        'execution_id' => (string) Illuminate\Support\Str::uuid7(),
        'ticket_id' => $scene['ticket']->identifier(),
        'customer_id' => $scene['ticket']->customer_id,
        'template_ref' => 'ticket_resolved',
    ]);
    (new AutomationSmsRequested($good))->handle();
    expect(DB::table('sms_messages')->where('event', 'ticket_resolved')->count())->toBe(1);

    $bad = inboxSeedEvent('automation.sms.requested', [
        'tenant_id' => $scene['tenant']->identifier(),
        'rule_id' => (string) Illuminate\Support\Str::uuid7(),
        'execution_id' => (string) Illuminate\Support\Str::uuid7(),
        'ticket_id' => $scene['ticket']->identifier(),
        'customer_id' => $scene['ticket']->customer_id,
        'template_ref' => 'marketing_blast',
    ]);
    (new AutomationSmsRequested($bad))->handle();
    expect(DB::table('sms_messages')->count())->toBe(1, 'no row can exist for a ref outside the CHECK');
    $line = json_encode(end($records), JSON_THROW_ON_ERROR);
    expect($line)->toContain('sms.automation.unknown_template_ref')->toContain('marketing_blast');
});

it('customer.suppression.set skips the customer\'s HELD non-essential rows only — the held essential row stays held, the queued row stays queued', function (): void {
    $scene = smsPipeScene();
    Queue::fake();
    app(SettingsRepository::class)->set('sms.events.service_announcement.enabled', true);
    app(SettingsRepository::class)->set('sms.hold_all_sends', true);

    // one non-essential held (service_announcement), one ESSENTIAL held (ticket_resolved)
    $announcement = DB::transaction(fn () => app(App\Domain\Sms\Pipeline\EnqueueSms::class)->enqueue(new App\Domain\Sms\Pipeline\SendRequest(App\Domain\Sms\Catalog\SendableEvent::ServiceAnnouncement, $scene['ticket']->customer_id)));
    $resolved = DB::transaction(fn () => app(App\Domain\Sms\Pipeline\EnqueueSms::class)->enqueue(new App\Domain\Sms\Pipeline\SendRequest(App\Domain\Sms\Catalog\SendableEvent::TicketResolved, $scene['ticket']->customer_id, $scene['ticket']->identifier())));
    expect($announcement->status)->toBe('held')->and($resolved->status)->toBe('held');

    $eventId = inboxSeedEvent('customer.suppression.set', [
        'tenant_id' => $scene['tenant']->identifier(),
        'customer_id' => $scene['ticket']->customer_id,
        'rule_id' => null,
        'reason' => 'fixture',
    ]);
    (new SuppressionSetSms($eventId))->handle();

    expect((string) DB::table('sms_messages')->where('id', $announcement->smsMessageId)->value('state'))->toBe('skipped')
        ->and((string) DB::table('sms_messages')->where('id', $announcement->smsMessageId)->value('skip_reason'))->toBe('marketing_suppressed')
        ->and((string) DB::table('sms_messages')->where('id', $resolved->smsMessageId)->value('state'))->toBe('held');

    $farFuture = (int) ((microtime(true) + 7200) * 1_000_000);
    expect(app(DueSet::class)->due('sms_hold_release', $farFuture))->not->toContain(DueSet::member($scene['tenant']->identifier(), (string) $announcement->smsMessageId))
        ->and(app(DueSet::class)->due('sms_hold_release', $farFuture))->toContain(DueSet::member($scene['tenant']->identifier(), (string) $resolved->smsMessageId));

    // a redelivery finds no held non-essential rows: idempotent by state
    (new SuppressionSetSms($eventId))->handle();
    expect(DB::table('outbox')->where('name', 'sms.skipped')->count())->toBe(1);
});

it('customer.rg_flag.set skips held non-essential rows as rg_flagged; customer.rg_flag.cleared revives NOTHING and logs the fact', function (): void {
    $scene = smsPipeScene();
    Queue::fake();
    $records = &capturedLogsRef();
    app(SettingsRepository::class)->set('sms.events.service_announcement.enabled', true);
    app(SettingsRepository::class)->set('sms.hold_all_sends', true);
    $announcement = DB::transaction(fn () => app(App\Domain\Sms\Pipeline\EnqueueSms::class)->enqueue(new App\Domain\Sms\Pipeline\SendRequest(App\Domain\Sms\Catalog\SendableEvent::ServiceAnnouncement, $scene['ticket']->customer_id)));

    $set = inboxSeedEvent('customer.rg_flag.set', [
        'tenant_id' => $scene['tenant']->identifier(),
        'customer_id' => $scene['ticket']->customer_id,
        'history_id' => (string) Illuminate\Support\Str::uuid7(),
        'source' => 'staff',
    ]);
    (new RgFlagSmsSuppression($set))->handle();
    expect((string) DB::table('sms_messages')->where('id', $announcement->smsMessageId)->value('skip_reason'))->toBe('rg_flagged');

    $cleared = inboxSeedEvent('customer.rg_flag.cleared', [
        'tenant_id' => $scene['tenant']->identifier(),
        'customer_id' => $scene['ticket']->customer_id,
        'history_id' => (string) Illuminate\Support\Str::uuid7(),
    ]);
    (new RgFlagSmsSuppression($cleared))->handle();
    expect((string) DB::table('sms_messages')->where('id', $announcement->smsMessageId)->value('state'))->toBe('skipped', 'nothing revived — the guard runs live at send anyway');
    expect(json_encode(end($records), JSON_THROW_ON_ERROR))->toContain('nothing revived');
});
