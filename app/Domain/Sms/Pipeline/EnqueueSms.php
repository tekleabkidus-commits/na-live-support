<?php

declare(strict_types=1);

namespace App\Domain\Sms\Pipeline;

use App\Domain\Sms\Encoding\Segmenter;
use App\Domain\Sms\Guards\GuardFunction;
use App\Domain\Sms\Guards\GuardOutcome;
use App\Domain\Sms\Rendering\RenderContext;
use App\Domain\Sms\Rendering\TemplateRenderer;
use App\Domain\Sms\Templates\TemplateReader;
use App\Jobs\Sms\SendSmsJob;
use App\Models\SmsMessage;
use App\Support\Timers\DueSet;
use Illuminate\Support\Facades\DB;

/**
 * The ONE enqueue door (FR-02): runs INSIDE the caller's transaction — the
 * row, its lifecycle event and the caller's own write commit or roll back
 * together (Doc 01 T-03). The guard runs here with fresh facts and runs AGAIN
 * in SendSmsJob (EC-02); the pure GuardFunction is the only decider.
 *
 *  Proceed → row `queued` + `sms.queued` + SendSmsJob on the event's lane.
 *  Hold    → row `held` + `sms.held` + a DueSet re-check candidate
 *            (`sms_hold_release`) — held, never dropped.
 *  Skip    → row `skipped{reason}` + `sms.skipped` — the timeline's honesty.
 *  No phone → NO row (`phone` is NOT NULL by physics); the reason returns.
 *
 * Dispatch is the house's plain dispatch (queue `after_commit` is false
 * repo-wide; correctness rides the outbox, and a job that lands before its
 * row commits simply finds no `queued` row and returns — idempotent on state).
 */
final class EnqueueSms
{
    /** Held rows re-check the hold every this-many seconds until released or swept. */
    public const HOLD_RECHECK_SECONDS = 60;

    public function __construct(
        private readonly LiveFacts $facts,
        private readonly GuardFunction $guard,
        private readonly SmsLifecycle $lifecycle,
        private readonly DueSet $timers,
        private readonly TemplateReader $templates,
        private readonly TemplateRenderer $renderer,
        private readonly Segmenter $segmenter,
    ) {}

    public function enqueue(SendRequest $request): EnqueueResult
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('EnqueueSms runs inside the caller\'s transaction (Doc 01 T-03) — wrap the operation in DB::transaction().');
        }

        $phone = $request->phoneE164Override ?? $this->facts->phoneFor($request->customerId);
        if ($phone === null) {
            return EnqueueResult::refusedNoPhone();
        }

        $decision = $this->guard->evaluate($this->facts->input($request, $phone));
        $language = $this->facts->customerLanguage($request->customerId) ?? 'en';

        $row = new SmsMessage;
        $row->fill([
            'customer_id' => $request->customerId,
            'ticket_id' => $request->ticketId,
            'conversation_id' => $request->conversationId,
            'event' => $request->event->value,
            'language' => $language,
            'phone' => $phone,
            'state' => match ($decision->outcome) {
                GuardOutcome::Proceed => 'queued',
                GuardOutcome::Hold => 'held',
                GuardOutcome::Skip => 'skipped',
            },
            'skip_reason' => $decision->outcome === GuardOutcome::Skip ? $decision->reason?->value : null,
            'guard_log' => $decision->log,
            'lane' => $request->lane()->value,
        ]);

        // The OTP door renders AT ENQUEUE (the grant allows the INSERT): the code
        // never rides a queue payload, and a later release still has its body.
        if ($request->code !== null) {
            $this->renderOtpNow($row, $request, $language);
        }

        $row->save();

        return match ($decision->outcome) {
            GuardOutcome::Proceed => $this->queued($row, $request),
            GuardOutcome::Hold => $this->held($row, $decision->reason === null ? 'hold_all_sends' : $decision->reason->value),
            GuardOutcome::Skip => $this->skipped($row, $decision->reason === null ? 'unknown' : $decision->reason->value),
        };
    }

    private function queued(SmsMessage $row, SendRequest $request): EnqueueResult
    {
        $this->lifecycle->publish('sms.queued', $row);
        SendSmsJob::dispatch($row->identifier(), $row->lane);

        return EnqueueResult::queued($row->identifier());
    }

    private function held(SmsMessage $row, string $reason): EnqueueResult
    {
        // hold-all-sends has no computable release instant (release_at null);
        // the quiet-hours release instant is step 9's QuietHoursWindow.
        $this->lifecycle->publish('sms.held', $row, $reason, null);
        $this->timers->schedule(
            'sms_hold_release',
            DueSet::member($row->tenant_id, $row->identifier()),
            (int) ((microtime(true) + self::HOLD_RECHECK_SECONDS) * 1_000_000),
        );

        return EnqueueResult::held($row->identifier(), $reason);
    }

    private function renderOtpNow(SmsMessage $row, SendRequest $request, string $language): void
    {
        $resolved = $this->templates->resolveForSend($request->event->value, $language);
        if ($resolved === null) {
            return; // the job fails it honestly as no_template
        }
        $result = $this->renderer->render($resolved['version'], $resolved['template'], new RenderContext(language: $resolved['language'], code: $request->code));
        if (! $result->sends() || $result->text === null) {
            return; // the job's render path lands the honest skip
        }
        $segmentation = $this->segmenter->segment($result->text);
        $row->template_id = $resolved['template']->identifier();
        $row->template_version_id = $resolved['version']->identifier();
        $row->rendered_body = $result->text;
        $row->encoding = $segmentation->encoding->value;
        $row->segments = $segmentation->segments;
    }

    private function skipped(SmsMessage $row, string $reason): EnqueueResult
    {
        $this->lifecycle->publish('sms.skipped', $row, $reason);

        return EnqueueResult::skipped($row->identifier(), $reason);
    }
}
