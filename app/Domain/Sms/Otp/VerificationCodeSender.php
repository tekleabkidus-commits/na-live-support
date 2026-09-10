<?php

declare(strict_types=1);

namespace App\Domain\Sms\Otp;

use App\Domain\Sms\Catalog\SendableEvent;
use App\Domain\Sms\Pipeline\EnqueueResult;
use App\Domain\Sms\Pipeline\EnqueueSms;
use App\Domain\Sms\Pipeline\SendRequest;
use Illuminate\Support\Facades\DB;

/**
 * The `verification_code` send door (BR-06: SMS SUPPORTS, never verifies — no
 * customer identity verification exists, and this door touches no customer).
 *
 * The PLATFORM owns the code: the caller mints it, this door refuses anything
 * but 4–10 digits (SendRequest's own check), and it renders only through the
 * published `verification_code` template's `{{code}}` slot. The phone is the
 * CALLER's to supply — no staff phone column exists anywhere (STOP-M: the
 * column and its opt-in are Doc 16's, [D16]); the Doc 02 SMS-2FA fallback that
 * would call this is NOT built here — the door is proven with a fixture
 * caller, which is the whole of its step-5 scope.
 */
final class VerificationCodeSender
{
    public function __construct(private readonly EnqueueSms $enqueue) {}

    public function send(string $userId, string $phoneE164, string $code): EnqueueResult
    {
        $request = new SendRequest(
            SendableEvent::VerificationCode,
            customerId: null,
            phoneE164Override: $phoneE164,
            code: $code,
        );

        // $userId is the audit trail's subject, not a lookup key — no user column is read.
        return DB::transaction(fn (): EnqueueResult => $this->enqueue->enqueue($request));
    }
}
