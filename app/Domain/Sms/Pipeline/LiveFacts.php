<?php

declare(strict_types=1);

namespace App\Domain\Sms\Pipeline;

use App\Domain\Automation\Suppression\MarketingSuppression;
use App\Domain\Sms\Catalog\SendableEvent;
use App\Domain\Sms\Gateways\GatewayAvailability;
use App\Domain\Sms\Guards\CustomerFacts;
use App\Domain\Sms\Guards\GuardInput;
use App\Domain\Sms\Guards\ModuleFacts;
use App\Domain\Sms\Guards\QuietHoursPolicy;
use App\Domain\Sms\Guards\TemplateFacts;
use App\Models\SmsTemplate;
use App\Support\Settings\SettingsRepository;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

/**
 * The LIVE facts the guard runs on — read fresh at ENQUEUE and read fresh
 * AGAIN at send time (EC-02: an identity unlinked between queue and send is a
 * skip, not a stale send). Everything here is a read; the pure GuardFunction
 * stays the only decider (T-06).
 *
 * Quiet hours: `inQuietHours` is FALSE until step 9 lands `QuietHoursWindow`
 * (FR-16 with the tenant timezone and the optional Doc 12 calendar) — the
 * guard's quiet-hours branch is built and proven pure (step 1) and inert in
 * the live path until its own step, stated in the step-5 record.
 */
final class LiveFacts
{
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly MarketingSuppression $suppression,
        private readonly GatewayAvailability $gateways,
    ) {}

    /** The customer's phone identity (type=phone, E.164), or null (no identity = nothing to send to). */
    public function phoneFor(?string $customerId): ?string
    {
        if ($customerId === null) {
            return null;
        }
        $value = DB::table('customer_identities')
            ->where('customer_id', $customerId)->where('type', 'phone')
            ->orderByDesc('id')->value('value');

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function customerLanguage(?string $customerId): ?string
    {
        if ($customerId === null) {
            return null;
        }
        $language = DB::table('customers')->where('id', $customerId)->value('language');

        return is_string($language) && $language !== '' ? $language : null;
    }

    /** @param  ?string  $excludeSmsMessageId  the row being SENT — its own enqueue is never its duplicate */
    public function input(SendRequest $request, ?string $phone, ?string $excludeSmsMessageId = null): GuardInput
    {
        return new GuardInput(
            $request->event,
            $this->customer($request, $phone),
            $this->template($request->event),
            $this->module(),
            $this->dbNow(),
            $this->lastSameSendAt($request, $phone, $excludeSmsMessageId),
            $this->duplicateWindowMinutes(),
            false, // quiet-hours evaluation lands at step 9 (QuietHoursWindow); see the class doc
        );
    }

    private function customer(SendRequest $request, ?string $phone): CustomerFacts
    {
        if ($request->customerId === null) {
            // The OTP door: no customer row exists to be opted out, blocked or flagged (BR-06).
            return new CustomerFacts($phone !== null, false, false, false, false);
        }

        /** @var object{blocked_at: ?string, rg_flag: bool}|null $customer */
        $customer = DB::table('customers')->where('id', $request->customerId)->first(['blocked_at', 'rg_flag']);

        $optedOut = $phone !== null && DB::table('sms_optouts')->where('phone', $phone)->whereNull('restored_at')->exists();

        return new CustomerFacts(
            $phone !== null,
            $optedOut,
            $customer?->blocked_at !== null,
            $customer !== null && $customer->rg_flag,
            $this->suppression->isSuppressed($request->customerId),
        );
    }

    private function template(SendableEvent $event): TemplateFacts
    {
        $policy = SmsTemplate::query()->where('event', $event->value)->where('active', true)->whereNull('deleted_at')->value('quiet_hours_policy');

        return new TemplateFacts(match ($policy) {
            'exempt' => QuietHoursPolicy::Exempt,
            'skip' => QuietHoursPolicy::Skip,
            'hold' => QuietHoursPolicy::Hold,
            default => $event->quietHoursExemptByDefault() ? QuietHoursPolicy::Exempt : QuietHoursPolicy::Hold,
        });
    }

    private function module(): ModuleFacts
    {
        return new ModuleFacts(
            Feature::active('sms.enabled'),
            (bool) $this->settings->get('sms.hold_all_sends'),
            $this->gateways->exists(),
        );
    }

    /** EC-10's window is DB-time on the row's own index (tenant, event, ticket_id, phone, queued_at). */
    private function lastSameSendAt(SendRequest $request, ?string $phone, ?string $excludeSmsMessageId = null): ?\DateTimeImmutable
    {
        if ($phone === null) {
            return null;
        }
        $last = DB::table('sms_messages')
            ->when($excludeSmsMessageId !== null, fn ($q) => $q->where('id', '!=', $excludeSmsMessageId))
            ->where('event', $request->event->value)
            ->where('phone', $phone)
            ->when($request->ticketId !== null, fn ($q) => $q->where('ticket_id', $request->ticketId))
            ->where('state', '!=', 'skipped')
            ->orderByDesc('queued_at')
            ->value('queued_at');

        return is_string($last) ? new \DateTimeImmutable($last) : null;
    }

    private function duplicateWindowMinutes(): int
    {
        $minutes = $this->settings->get('sms.duplicate_window_minutes');

        return is_int($minutes) ? $minutes : 10;
    }

    private function dbNow(): \DateTimeImmutable
    {
        /** @var object{now: string} $row */
        $row = DB::selectOne('SELECT clock_timestamp() AS now');

        return new \DateTimeImmutable($row->now);
    }
}
