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
use App\Domain\Sms\OptOut\OptOutRegistry;
use App\Domain\Sms\QuietHours\QuietHoursWindow;
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
 * Quiet hours are LIVE since step 9: `QuietHoursWindow` evaluates the
 * tenant-timezone window (or the linked calendar's closed hours) at the same
 * DB instant the guard reasons about.
 */
final class LiveFacts
{
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly MarketingSuppression $suppression,
        private readonly GatewayAvailability $gateways,
        private readonly OptOutRegistry $optouts,
        private readonly QuietHoursWindow $quietHours,
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
        $now = $this->dbNow();

        return new GuardInput(
            $request->event,
            $this->customer($request, $phone),
            $this->template($request->event),
            $this->module(),
            $now,
            $this->lastSameSendAt($request, $phone, $excludeSmsMessageId),
            $this->duplicateWindowMinutes(),
            $this->quietHours->inQuietHours($now), // FR-16 live (step 9): the tenant-timezone window, or the calendar's closed hours
        );
    }

    private function customer(SendRequest $request, ?string $phone): CustomerFacts
    {
        if ($request->customerId === null) {
            // The OTP door: no customer row exists to be blocked or flagged (BR-06) —
            // but the opt-out REGISTRY is PHONE-keyed and N-16 is absolute: it reads
            // for every send, customer or not (the AC-05 suite caught the first draft
            // hardcoding false here).
            return new CustomerFacts($phone !== null, $phone !== null && $this->optouts->isOptedOut($phone), false, false, false);
        }

        /** @var object{blocked_at: ?string, rg_flag: bool}|null $customer */
        $customer = DB::table('customers')->where('id', $request->customerId)->first(['blocked_at', 'rg_flag']);

        $optedOut = $phone !== null && $this->optouts->isOptedOut($phone); // FR-14: THE registry read (N-16)

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
