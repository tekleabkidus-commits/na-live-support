<?php

declare(strict_types=1);

namespace App\Domain\Sms\State;

use DateTimeImmutable;

/**
 * Doc 14 FR-12 / FR-13 / T-08 / AC-02 / EC-04 / NFR-04 — the state machine as
 * a PURE unit over state values (the table half — `sms_messages` — is written
 * by the pipeline and the DLR job through this class from step 2b on).
 *
 * The laws, inherited by pattern from Doc 04 and owned here (R-D14-6.2):
 *   - `sent` NEVER rolls back: no edge leaves `sent` except delivered,
 *     undelivered, expired. A late channel fact is an annotation, never a move.
 *   - `delivered` ONLY on a real receipt (DlrStatus::Delivered from a mapping
 *     row that names a handset delivery). Nothing infers it — not acceptance,
 *     not `success`, not time.
 *   - precedence (FR-13): a late `sent` never downgrades a `delivered`; a
 *     `delivered` then a later `undelivered` holds `delivered` with the
 *     contradiction retained and flagged (EC-04); duplicates are no-ops.
 *   - `expired` is honest: no DLR within `sms.expired_after_hours` of `sent_at`
 *     (DB time, ADR 0010) → "sent — delivery unconfirmed". A receipt that
 *     arrives later is still the truth and is applied, flagged late.
 *
 * No clock is read: `isExpired` takes `now` as an argument (§8).
 */
final class SmsDeliveryStateMachine
{
    /** @var array<string, list<string>> */
    private const EDGES = [
        'queued' => ['held', 'sending', 'skipped', 'failed'],
        'held' => ['queued', 'skipped'],
        'sending' => ['sent', 'queued', 'failed'],
        'sent' => ['delivered', 'undelivered', 'expired'],
        'delivered' => [],
        'undelivered' => [],
        'expired' => [],
        'skipped' => [],
        'failed' => [],
    ];

    /** A pipeline transition (FR-11/FR-16/EC-12); refuses every edge FR-12 does not draw. */
    public function transition(SmsDeliveryState $from, SmsDeliveryState $to): SmsDeliveryState
    {
        if (! in_array($to->value, self::EDGES[$from->value], true)) {
            throw IllegalSmsTransition::from($from, $to);
        }

        return $to;
    }

    /** FR-13: apply one normalized DLR to the current state, precedence-safe and idempotent. */
    public function applyDlr(SmsDeliveryState $current, DlrStatus $dlr): DlrOutcome
    {
        if ($current->precedence() < 0 || $current === SmsDeliveryState::Queued || $current === SmsDeliveryState::Held) {
            // a receipt for something that never went out: logged, dropped, never a crash (EC-03's cousin)
            return new DlrOutcome($current, false, false, 'dlr_for_unsent');
        }

        if ($dlr === DlrStatus::Unknown) {
            return new DlrOutcome($current, false, false, 'unknown_status');
        }

        // submission-level receipts move nothing once we hold sent or beyond; before sent they are the
        // out-of-order cousins of our own write and still move nothing (sent is the pipeline's fact)
        if ($dlr === DlrStatus::Accepted || $dlr === DlrStatus::Sent) {
            if ($current === SmsDeliveryState::Delivered || $current === SmsDeliveryState::Undelivered) {
                return new DlrOutcome($current, false, false, 'late_sent_ignored');
            }

            return new DlrOutcome($current, false, false, 'submission_receipt');
        }

        $target = $dlr === DlrStatus::Delivered ? SmsDeliveryState::Delivered : SmsDeliveryState::Undelivered;

        if ($current === $target) {
            return new DlrOutcome($current, false, false, 'duplicate');
        }

        // EC-04: delivered, then undelivered → precedence HOLDS delivered; the contradiction is retained and flagged
        if ($current === SmsDeliveryState::Delivered && $target === SmsDeliveryState::Undelivered) {
            return new DlrOutcome($current, false, true, 'contradiction_undelivered_after_delivered');
        }

        // undelivered, then a delivered receipt: a handset receipt outranks (precedence 4 > 3) — applied, flagged
        if ($current === SmsDeliveryState::Undelivered && $target === SmsDeliveryState::Delivered) {
            return new DlrOutcome($target, true, true, 'contradiction_delivered_after_undelivered');
        }

        if ($current === SmsDeliveryState::Expired) {
            return new DlrOutcome($target, true, false, 'late_after_expired');
        }

        if ($current === SmsDeliveryState::Sending) {
            // our own `sent` write lost the race with the carrier: the receipt is real; sent is implied, never rolled back
            return new DlrOutcome($target, true, false, 'out_of_order');
        }

        return new DlrOutcome($target, true, false, null);
    }

    /** FR-12's 48 h: a DB-time comparison of `sent_at` against `now`, both supplied by the caller. */
    public function isExpired(DateTimeImmutable $sentAt, DateTimeImmutable $now, int $expiredAfterHours): bool
    {
        return $now->getTimestamp() - $sentAt->getTimestamp() >= $expiredAfterHours * 3600;
    }
}
