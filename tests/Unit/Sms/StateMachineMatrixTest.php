<?php

declare(strict_types=1);

use App\Domain\Sms\State\DlrStatus;
use App\Domain\Sms\State\IllegalSmsTransition;
use App\Domain\Sms\State\SmsDeliveryState;
use App\Domain\Sms\State\SmsDeliveryStateMachine;

/*
| Doc 14 FR-12 / FR-13 / T-08 / AC-02 / EC-04 / NFR-04 — THE STATE MACHINE MATRIX.
|
| queued → sending → sent → delivered | undelivered (carrier-reported) |
| expired (no DLR after 48 h — "sent — delivery unconfirmed", never guessed
| delivered); plus held (queued(held), FR-16), skipped{reason} and
| failed{reason}. Doc 04's LAWS bind by pattern on Doc 14's OWN entity
| (R-D14-6.2): `sent` never rolls back; `delivered` only on a real receipt;
| a late `sent` never downgrades a `delivered`; a `delivered` then a later
| `undelivered` for the same id → precedence holds `delivered`, the raw
| contradiction retained and flagged (EC-04); duplicates and out-of-order
| DLRs are idempotent on (provider_id, status precedence).
|
| This is a PURE unit over state values. The table (`sms_messages`) lands at
| step 2b; the row-writing half is proven there. No clock is read.
*/

function smsMachine(): SmsDeliveryStateMachine
{
    return new SmsDeliveryStateMachine;
}

test('the pipeline transition matrix — every legal edge, every illegal edge', function () {
    $legal = [
        ['queued', 'held'],      // FR-16 quiet hours (and hold-all-sends)
        ['held', 'queued'],      // release at window end
        ['held', 'skipped'],     // re-evaluated at release: a guard now skips (e.g. RG set while held)
        ['queued', 'sending'],
        ['queued', 'skipped'],   // live guards at send time (EC-02)
        ['queued', 'failed'],    // EC-12: the 24 h TTL → failed:no_route_available
        ['sending', 'sent'],     // driver accepted, provider id stored
        ['sending', 'queued'],   // retryable classification → backoff (attempts jsonb carries the count)
        ['sending', 'failed'],   // non-retryable, or retries/failovers exhausted
        ['sent', 'delivered'],
        ['sent', 'undelivered'],
        ['sent', 'expired'],     // the 48 h sweep
    ];
    $all = SmsDeliveryState::cases();

    foreach ($all as $from) {
        foreach ($all as $to) {
            $isLegal = in_array([$from->value, $to->value], $legal, true);
            $attempt = static fn () => smsMachine()->transition($from, $to);

            if ($isLegal) {
                expect($attempt())->toBe($to, "{$from->value} → {$to->value} must be legal");
            } else {
                expect($attempt)->toThrow(IllegalSmsTransition::class, null, "{$from->value} → {$to->value} must be ILLEGAL");
            }
        }
    }
});

test('sent NEVER rolls back — no edge leaves sent except delivered, undelivered, expired', function () {
    foreach ([SmsDeliveryState::Queued, SmsDeliveryState::Held, SmsDeliveryState::Sending, SmsDeliveryState::Skipped, SmsDeliveryState::Failed] as $to) {
        expect(static fn () => smsMachine()->transition(SmsDeliveryState::Sent, $to))
            ->toThrow(IllegalSmsTransition::class, null, "sent → {$to->value} must be refused");
    }
    expect(SmsDeliveryState::Sent->isTerminal())->toBeFalse()
        ->and(SmsDeliveryState::Delivered->isTerminal())->toBeTrue()
        ->and(SmsDeliveryState::Undelivered->isTerminal())->toBeTrue()
        ->and(SmsDeliveryState::Expired->isTerminal())->toBeTrue()
        ->and(SmsDeliveryState::Skipped->isTerminal())->toBeTrue()
        ->and(SmsDeliveryState::Failed->isTerminal())->toBeTrue();
});

test('the DLR precedence matrix (FR-13) — idempotent, out-of-order safe, contradiction retained', function () {
    $m = smsMachine();

    // from sent: the ordinary receipts
    $d = $m->applyDlr(SmsDeliveryState::Sent, DlrStatus::Delivered);
    expect($d->state)->toBe(SmsDeliveryState::Delivered)->and($d->applied)->toBeTrue()->and($d->contradiction)->toBeFalse();

    $u = $m->applyDlr(SmsDeliveryState::Sent, DlrStatus::Undelivered);
    expect($u->state)->toBe(SmsDeliveryState::Undelivered)->and($u->applied)->toBeTrue();

    // a late `sent` / `accepted` DLR after sent: idempotent no-op
    foreach ([DlrStatus::Sent, DlrStatus::Accepted] as $late) {
        $r = $m->applyDlr(SmsDeliveryState::Sent, $late);
        expect($r->state)->toBe(SmsDeliveryState::Sent)->and($r->applied)->toBeFalse()->and($r->contradiction)->toBeFalse();
    }

    // a late `sent` NEVER downgrades a delivered (FR-13's own sentence)
    $r = $m->applyDlr(SmsDeliveryState::Delivered, DlrStatus::Sent);
    expect($r->state)->toBe(SmsDeliveryState::Delivered)->and($r->applied)->toBeFalse()->and($r->annotation)->toBe('late_sent_ignored');

    // a duplicate delivered: idempotent
    $r = $m->applyDlr(SmsDeliveryState::Delivered, DlrStatus::Delivered);
    expect($r->state)->toBe(SmsDeliveryState::Delivered)->and($r->applied)->toBeFalse()->and($r->annotation)->toBe('duplicate');

    // EC-04: delivered then a later undelivered → precedence HOLDS delivered, the contradiction FLAGGED
    $r = $m->applyDlr(SmsDeliveryState::Delivered, DlrStatus::Undelivered);
    expect($r->state)->toBe(SmsDeliveryState::Delivered)->and($r->applied)->toBeFalse()->and($r->contradiction)->toBeTrue()->and($r->annotation)->toBe('contradiction_undelivered_after_delivered');

    // out of order: a delivered receipt arrives while we still hold `sending` (our own sent write lost the race) →
    // delivered is a REAL receipt; sent is implied; never rolled back; flagged as out of order
    $r = $m->applyDlr(SmsDeliveryState::Sending, DlrStatus::Delivered);
    expect($r->state)->toBe(SmsDeliveryState::Delivered)->and($r->applied)->toBeTrue()->and($r->annotation)->toBe('out_of_order');

    // a delivered receipt after undelivered: delivered outranks (a real handset receipt) — flagged, never silent
    $r = $m->applyDlr(SmsDeliveryState::Undelivered, DlrStatus::Delivered);
    expect($r->state)->toBe(SmsDeliveryState::Delivered)->and($r->applied)->toBeTrue()->and($r->contradiction)->toBeTrue();

    // a late receipt after the honest `expired`: a real receipt is still the truth — applied and flagged late
    $r = $m->applyDlr(SmsDeliveryState::Expired, DlrStatus::Delivered);
    expect($r->state)->toBe(SmsDeliveryState::Delivered)->and($r->applied)->toBeTrue()->and($r->annotation)->toBe('late_after_expired');
    $r = $m->applyDlr(SmsDeliveryState::Expired, DlrStatus::Undelivered);
    expect($r->state)->toBe(SmsDeliveryState::Undelivered)->and($r->applied)->toBeTrue();

    // a DLR for a message that never went out (skipped/failed/queued/held): ignored, flagged — never a crash
    foreach ([SmsDeliveryState::Skipped, SmsDeliveryState::Failed, SmsDeliveryState::Queued, SmsDeliveryState::Held] as $never) {
        $r = $m->applyDlr($never, DlrStatus::Delivered);
        expect($r->state)->toBe($never)->and($r->applied)->toBeFalse()->and($r->annotation)->toBe('dlr_for_unsent');
    }

    // an unknown carrier status: retained raw, changes nothing
    $r = $m->applyDlr(SmsDeliveryState::Sent, DlrStatus::Unknown);
    expect($r->state)->toBe(SmsDeliveryState::Sent)->and($r->applied)->toBeFalse()->and($r->annotation)->toBe('unknown_status');
});

test('the GeezSMS profile\'s `success` maps to acceptance and NOTHING beyond (R-D14-1.5) — never delivered', function () {
    $r = smsMachine()->applyDlr(SmsDeliveryState::Sent, DlrStatus::Accepted);

    expect($r->state)->toBe(SmsDeliveryState::Sent)
        ->and($r->applied)->toBeFalse()
        ->and(DlrStatus::Accepted->impliesDelivery())->toBeFalse()
        ->and(DlrStatus::Delivered->impliesDelivery())->toBeTrue();
});

test('expiry is a DB-time fact computed from sent_at + the setting, never from a wall clock', function () {
    $m = smsMachine();
    $sentAt = new DateTimeImmutable('2026-09-01T00:00:00+00:00');

    expect($m->isExpired($sentAt, new DateTimeImmutable('2026-09-02T23:59:59+00:00'), 48))->toBeFalse()
        ->and($m->isExpired($sentAt, new DateTimeImmutable('2026-09-03T00:00:00+00:00'), 48))->toBeTrue();

    $source = (string) file_get_contents((new ReflectionClass(SmsDeliveryStateMachine::class))->getFileName());
    expect($source)->not->toContain('now()')->not->toContain('time()')->not->toContain('Carbon::');
});

test('the backing values are the column values and the event identifiers — frozen', function () {
    expect(array_map(static fn (SmsDeliveryState $s): string => $s->value, SmsDeliveryState::cases()))
        ->toBe(['queued', 'held', 'sending', 'sent', 'delivered', 'undelivered', 'expired', 'skipped', 'failed']);
});
