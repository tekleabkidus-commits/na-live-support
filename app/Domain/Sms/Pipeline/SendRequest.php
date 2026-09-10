<?php

declare(strict_types=1);

namespace App\Domain\Sms\Pipeline;

use App\Domain\Sms\Catalog\Lane;
use App\Domain\Sms\Catalog\SendableEvent;

/**
 * THE closed send shape (FR-02, BR-01; SmsNoFreeTextTest binds this namespace).
 *
 * An SMS send is an EVENT plus ids — the template is the event's (FR-01: one
 * key, one template per language), the words are the published template's, the
 * variables are resolved from the ticket at render. No text can enter here:
 * no property of this class or its callers may carry free text, and the sweep
 * fails the build if one appears.
 *
 * `code` is the single constrained exception (BR-06 verification codes): the
 * PLATFORM mints it, the constructor refuses anything but 4–10 digits, and it
 * renders only through the `verification_code` template's `{{code}}` slot.
 * `phoneE164Override` exists for the same door — a staff OTP has no customer
 * row to resolve a phone from (STOP-M: no staff phone column exists).
 */
final readonly class SendRequest
{
    public function __construct(
        public SendableEvent $event,
        public ?string $customerId,
        public ?string $ticketId = null,
        public ?string $conversationId = null,
        public ?string $phoneE164Override = null,
        public ?string $code = null,
    ) {
        if ($code !== null && preg_match('/^\d{4,10}$/', $code) !== 1) {
            throw new \InvalidArgumentException('a verification code is 4–10 digits minted by the platform (BR-06) — nothing else rides this field');
        }
        if ($code !== null && $event !== SendableEvent::VerificationCode) {
            throw new \InvalidArgumentException('only the verification_code event carries a code');
        }
        if ($phoneE164Override !== null && preg_match('/^\+[1-9]\d{6,14}$/', $phoneE164Override) !== 1) {
            throw new \InvalidArgumentException('the phone override is E.164 or nothing');
        }
        if ($customerId === null && $phoneE164Override === null) {
            throw new \InvalidArgumentException('a send needs a customer or an explicit E.164 override (the OTP door)');
        }
    }

    public function lane(): Lane
    {
        return $this->event->lane();
    }
}
