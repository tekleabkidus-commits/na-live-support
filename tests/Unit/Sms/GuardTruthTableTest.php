<?php

declare(strict_types=1);

use App\Domain\Sms\Catalog\SendableEvent;
use App\Domain\Sms\Guards\CustomerFacts;
use App\Domain\Sms\Guards\GuardFunction;
use App\Domain\Sms\Guards\GuardInput;
use App\Domain\Sms\Guards\GuardOutcome;
use App\Domain\Sms\Guards\GuardReason;
use App\Domain\Sms\Guards\ModuleFacts;
use App\Domain\Sms\Guards\QuietHoursPolicy;
use App\Domain\Sms\Guards\TemplateFacts;

/*
| Doc 14 T-06 / FR-03 / AC-01 — THE GUARD TRUTH TABLE.
|
| The guard is a PURE, ORDERED function over (customer, event, template, time)
| → proceed | hold | skip{reason}. Every FR-03 branch is a row here; the ORDER
| is proven by rows where two guards would both fire and exactly the earlier
| one names the reason. The module-level pre-guards (A-67: feature_disabled,
| hold_all_sends → held, no_gateway) run BEFORE FR-03's six.
|
| FR-03 as written: "no phone identity → skip; opted-out → skip (absolute);
| customer blocked (Doc 08 FR-18) → skip except security_notification;
| RG-flagged → skip all except window_reengagement and ticket_resolved (the
| Doc 08 FR-15 suppression contract — essential service continuity only);
| duplicate-suppression (same event + ticket + phone within 10 min) → skip;
| quiet hours (FR-12) → hold or skip per template flag." (FR-03's "FR-12"
| pointer is FR-16 — cited as written, never repaired.)
|
| No clock is read: `now` is an input (§8 no-clock rule).
*/

function smsGuardNow(): DateTimeImmutable
{
    return new DateTimeImmutable('2026-09-08T10:00:00+00:00');
}

/**
 * @param  array<string, mixed>  $customer
 * @param  array<string, mixed>  $template
 * @param  array<string, mixed>  $module
 */
function smsGuardInput(SendableEvent $event, array $customer = [], array $template = [], array $module = [], ?DateTimeImmutable $lastSameSendAt = null, bool $inQuietHours = false): GuardInput
{
    return new GuardInput(
        event: $event,
        customer: new CustomerFacts(
            hasPhone: $customer['hasPhone'] ?? true,
            optedOut: $customer['optedOut'] ?? false,
            blocked: $customer['blocked'] ?? false,
            rgFlagged: $customer['rgFlagged'] ?? false,
            marketingSuppressed: $customer['marketingSuppressed'] ?? false,
        ),
        template: new TemplateFacts(
            quietHours: $template['quietHours'] ?? QuietHoursPolicy::Hold,
        ),
        module: new ModuleFacts(
            featureEnabled: $module['featureEnabled'] ?? true,
            holdAllSends: $module['holdAllSends'] ?? false,
            gatewayAvailable: $module['gatewayAvailable'] ?? true,
        ),
        now: smsGuardNow(),
        lastSameSendAt: $lastSameSendAt,
        duplicateWindowMinutes: 10,
        inQuietHours: $inQuietHours,
    );
}

/**
 * The table. Each row: [label, input, expected outcome, expected reason].
 *
 * @return array<string, array{GuardInput, GuardOutcome, ?GuardReason}>
 */
function smsGuardTable(): array
{
    $nineMinAgo = smsGuardNow()->modify('-9 minutes');
    $elevenMinAgo = smsGuardNow()->modify('-11 minutes');

    return [
        // ── the happy path
        'clean customer, ticket_resolved → proceed' => [smsGuardInput(SendableEvent::TicketResolved), GuardOutcome::Proceed, null],

        // ── pre-guards (A-67), before FR-03's six
        'feature disabled → skip feature_disabled' => [smsGuardInput(SendableEvent::TicketResolved, module: ['featureEnabled' => false]), GuardOutcome::Skip, GuardReason::FeatureDisabled],
        'hold all sends → HELD hold_all_sends (a pause, never a drop)' => [smsGuardInput(SendableEvent::TicketResolved, module: ['holdAllSends' => true]), GuardOutcome::Hold, GuardReason::HoldAllSends],
        'no gateway → skip no_gateway' => [smsGuardInput(SendableEvent::TicketResolved, module: ['gatewayAvailable' => false]), GuardOutcome::Skip, GuardReason::NoGateway],
        'feature disabled beats an opted-out customer (pre-guard order)' => [smsGuardInput(SendableEvent::TicketResolved, customer: ['optedOut' => true], module: ['featureEnabled' => false]), GuardOutcome::Skip, GuardReason::FeatureDisabled],

        // ── FR-03 #1 no phone
        'no phone → skip no_phone' => [smsGuardInput(SendableEvent::TicketResolved, customer: ['hasPhone' => false]), GuardOutcome::Skip, GuardReason::NoPhone],
        'no phone beats opted-out (order #1 before #2)' => [smsGuardInput(SendableEvent::TicketResolved, customer: ['hasPhone' => false, 'optedOut' => true]), GuardOutcome::Skip, GuardReason::NoPhone],

        // ── FR-03 #2 opted-out — ABSOLUTE (FR-14, BR-03: "even security notifications respect opt-out")
        'opted out, ticket_resolved → skip opted_out' => [smsGuardInput(SendableEvent::TicketResolved, customer: ['optedOut' => true]), GuardOutcome::Skip, GuardReason::OptedOut],
        'opted out, security_notification → skip opted_out (no exception exists)' => [smsGuardInput(SendableEvent::SecurityNotification, customer: ['optedOut' => true]), GuardOutcome::Skip, GuardReason::OptedOut],
        'opted out, verification_code → skip opted_out' => [smsGuardInput(SendableEvent::VerificationCode, customer: ['optedOut' => true]), GuardOutcome::Skip, GuardReason::OptedOut],
        'opted out, window_reengagement → skip opted_out' => [smsGuardInput(SendableEvent::WindowReengagement, customer: ['optedOut' => true]), GuardOutcome::Skip, GuardReason::OptedOut],
        'opted out, service_announcement → skip opted_out' => [smsGuardInput(SendableEvent::ServiceAnnouncement, customer: ['optedOut' => true]), GuardOutcome::Skip, GuardReason::OptedOut],
        'opted out beats blocked (order #2 before #3)' => [smsGuardInput(SendableEvent::TicketResolved, customer: ['optedOut' => true, 'blocked' => true]), GuardOutcome::Skip, GuardReason::OptedOut],

        // ── FR-03 #3 blocked — except security_notification
        'blocked, ticket_resolved → skip blocked' => [smsGuardInput(SendableEvent::TicketResolved, customer: ['blocked' => true]), GuardOutcome::Skip, GuardReason::Blocked],
        'blocked, security_notification → PROCEED (the one exception)' => [smsGuardInput(SendableEvent::SecurityNotification, customer: ['blocked' => true]), GuardOutcome::Proceed, null],
        'blocked, window_reengagement → skip blocked' => [smsGuardInput(SendableEvent::WindowReengagement, customer: ['blocked' => true]), GuardOutcome::Skip, GuardReason::Blocked],
        'blocked beats rg-flagged (order #3 before #4)' => [smsGuardInput(SendableEvent::TicketCreated, customer: ['blocked' => true, 'rgFlagged' => true]), GuardOutcome::Skip, GuardReason::Blocked],

        // ── FR-03 #4 RG-flagged — except window_reengagement and ticket_resolved
        'rg, ticket_created → skip rg_flagged' => [smsGuardInput(SendableEvent::TicketCreated, customer: ['rgFlagged' => true]), GuardOutcome::Skip, GuardReason::RgFlagged],
        'rg, ticket_resolved → PROCEED (essential service continuity)' => [smsGuardInput(SendableEvent::TicketResolved, customer: ['rgFlagged' => true]), GuardOutcome::Proceed, null],
        'rg, window_reengagement → PROCEED (essential service continuity)' => [smsGuardInput(SendableEvent::WindowReengagement, customer: ['rgFlagged' => true]), GuardOutcome::Proceed, null],
        'rg, service_announcement → skip rg_flagged (never promotional-adjacent)' => [smsGuardInput(SendableEvent::ServiceAnnouncement, customer: ['rgFlagged' => true]), GuardOutcome::Skip, GuardReason::RgFlagged],
        'rg, security_notification → skip rg_flagged (FR-03 names only two exceptions)' => [smsGuardInput(SendableEvent::SecurityNotification, customer: ['rgFlagged' => true]), GuardOutcome::Skip, GuardReason::RgFlagged],
        'rg, ticket_assigned → skip rg_flagged' => [smsGuardInput(SendableEvent::TicketAssigned, customer: ['rgFlagged' => true]), GuardOutcome::Skip, GuardReason::RgFlagged],
        'rg beats duplicate (order #4 before #5)' => [smsGuardInput(SendableEvent::TicketCreated, customer: ['rgFlagged' => true], lastSameSendAt: $nineMinAgo), GuardOutcome::Skip, GuardReason::RgFlagged],

        // ── FR-03 #4b marketing suppression (Doc 08 FR-15's reader contract, the same essential-service exceptions)
        'suppressed, ticket_created → skip marketing_suppressed' => [smsGuardInput(SendableEvent::TicketCreated, customer: ['marketingSuppressed' => true]), GuardOutcome::Skip, GuardReason::MarketingSuppressed],
        'suppressed, ticket_resolved → PROCEED' => [smsGuardInput(SendableEvent::TicketResolved, customer: ['marketingSuppressed' => true]), GuardOutcome::Proceed, null],
        'suppressed, service_announcement → skip marketing_suppressed' => [smsGuardInput(SendableEvent::ServiceAnnouncement, customer: ['marketingSuppressed' => true]), GuardOutcome::Skip, GuardReason::MarketingSuppressed],
        'rg beats suppressed (RG first — SendSmsTemplateAction\'s order)' => [smsGuardInput(SendableEvent::TicketCreated, customer: ['rgFlagged' => true, 'marketingSuppressed' => true]), GuardOutcome::Skip, GuardReason::RgFlagged],

        // ── FR-03 #5 duplicate (same event + ticket + phone within 10 min)
        'same send 9 min ago → skip duplicate' => [smsGuardInput(SendableEvent::WindowReengagement, lastSameSendAt: $nineMinAgo), GuardOutcome::Skip, GuardReason::Duplicate],
        'same send 11 min ago → proceed' => [smsGuardInput(SendableEvent::WindowReengagement, lastSameSendAt: $elevenMinAgo), GuardOutcome::Proceed, null],
        'same send exactly 10 min ago → proceed (the window is "within 10 min")' => [smsGuardInput(SendableEvent::WindowReengagement, lastSameSendAt: smsGuardNow()->modify('-10 minutes')), GuardOutcome::Proceed, null],
        'duplicate beats quiet hours (order #5 before #6)' => [smsGuardInput(SendableEvent::TicketResolved, lastSameSendAt: $nineMinAgo, inQuietHours: true), GuardOutcome::Skip, GuardReason::Duplicate],

        // ── FR-03 #6 quiet hours (FR-16) — hold or skip per template flag; exempt by flag
        'quiet hours, template policy hold → HELD quiet_hours' => [smsGuardInput(SendableEvent::TicketResolved, template: ['quietHours' => QuietHoursPolicy::Hold], inQuietHours: true), GuardOutcome::Hold, GuardReason::QuietHours],
        'quiet hours, template policy skip → skip quiet_hours' => [smsGuardInput(SendableEvent::TicketResolved, template: ['quietHours' => QuietHoursPolicy::Skip], inQuietHours: true), GuardOutcome::Skip, GuardReason::QuietHours],
        'quiet hours, template exempt → proceed' => [smsGuardInput(SendableEvent::VerificationCode, template: ['quietHours' => QuietHoursPolicy::Exempt], inQuietHours: true), GuardOutcome::Proceed, null],
        'outside quiet hours, policy hold → proceed' => [smsGuardInput(SendableEvent::TicketResolved, template: ['quietHours' => QuietHoursPolicy::Hold], inQuietHours: false), GuardOutcome::Proceed, null],
    ];
}

test('the FR-03 truth table — every branch, every order interaction, exact skip reasons', function () {
    $guard = new GuardFunction;

    foreach (smsGuardTable() as $label => [$input, $outcome, $reason]) {
        $decision = $guard->evaluate($input);

        expect($decision->outcome)->toBe($outcome, "row [{$label}] outcome")
            ->and($decision->reason)->toBe($reason, "row [{$label}] reason");
    }
});

test('every decision carries an ordered guard_log naming each check evaluated, in FR-03 order', function () {
    $guard = new GuardFunction;

    $log = $guard->evaluate(smsGuardInput(SendableEvent::TicketResolved))->log;
    $checks = array_map(static fn (array $entry): string => $entry['check'], $log);

    expect($checks)->toBe([
        'feature_enabled', 'hold_all_sends', 'gateway_available',
        'has_phone', 'opted_out', 'blocked', 'rg_flagged', 'marketing_suppressed', 'duplicate', 'quiet_hours',
    ])->and(array_column($log, 'result'))->each->toBe('pass');

    // a skip stops the log at the skipping check — nothing after it is evaluated (pure, ordered)
    $skipped = $guard->evaluate(smsGuardInput(SendableEvent::TicketResolved, customer: ['optedOut' => true]))->log;
    expect(end($skipped)['check'])->toBe('opted_out')
        ->and(end($skipped)['result'])->toBe('skip')
        ->and(count($skipped))->toBe(5);
});

test('the guard is pure: the same input evaluates to the same decision and reads no clock', function () {
    $guard = new GuardFunction;
    $input = smsGuardInput(SendableEvent::TicketCreated, lastSameSendAt: smsGuardNow()->modify('-5 minutes'));

    expect($guard->evaluate($input))->toEqual($guard->evaluate($input))
        ->and((new ReflectionClass(GuardFunction::class))->getConstructor())->toBeNull();

    $source = (string) file_get_contents((new ReflectionClass(GuardFunction::class))->getFileName());
    expect($source)->not->toContain('now()')->not->toContain('time()')->not->toContain('Carbon::');
});

test('the exception sets are the document\'s and nothing more', function () {
    expect(array_map(static fn (SendableEvent $e): string => $e->value, array_values(array_filter(SendableEvent::cases(), static fn (SendableEvent $e): bool => $e->exemptFromBlocked()))))
        ->toBe(['security_notification'])
        ->and(array_map(static fn (SendableEvent $e): string => $e->value, array_values(array_filter(SendableEvent::cases(), static fn (SendableEvent $e): bool => $e->essentialServiceContinuity()))))
        ->toBe(['ticket_resolved', 'window_reengagement']);
});
