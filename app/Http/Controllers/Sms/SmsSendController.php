<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sms;

use App\Domain\Sms\Catalog\SendableEvent;
use App\Domain\Sms\Pipeline\EnqueueResult;
use App\Domain\Sms\Pipeline\EnqueueSms;
use App\Domain\Sms\Pipeline\SendRequest;
use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Problem\ErrorCode;
use App\Support\Problem\ProblemDetails;
use App\Support\Settings\SettingsRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The two manual send doors (FR-02, BR-01; `sms.send`, high): each takes a
 * TICKET and an implied EVENT — no text field exists on either (the closed
 * SendRequest shape; SmsNoFreeTextTest sweeps this controller's namespace).
 *
 *  reengage — `window_reengagement` on a ticket whose conversation's reply
 *             window is `expired` (Doc 05's column, READ ONLY here). EC-10:
 *             a duplicate inside the window answers 422 with the inline
 *             notice's data (`last_queued_at`), never a second row that sends.
 *  notify   — "notify by SMS" = `ticket_updated`, requiring a phone identity
 *             and the FR-01 per-event toggle (off by default, §21).
 */
final class SmsSendController extends Controller
{
    public function __construct(
        private readonly EnqueueSms $enqueue,
        private readonly SettingsRepository $settings,
        private readonly AuditLogger $audit,
    ) {}

    public function reengage(Request $request, string $ticketId): JsonResponse
    {
        $ticket = $this->ticket($ticketId);

        $windowState = DB::table('conversations')->where('id', $ticket->conversation_id)->value('reply_window_state');
        if ($windowState !== 'expired') {
            return $this->refused('window_not_expired', ['window_state' => is_string($windowState) ? $windowState : 'unknown']);
        }

        return $this->send($request, new SendRequest(
            SendableEvent::WindowReengagement,
            $ticket->customer_id,
            $ticket->identifier(),
            $ticket->conversation_id,
        ));
    }

    public function notify(Request $request, string $ticketId): JsonResponse
    {
        $ticket = $this->ticket($ticketId);

        if ($this->settings->get('sms.events.ticket_updated.enabled') !== true) {
            return $this->refused('event_disabled', ['event' => 'ticket_updated']);
        }

        return $this->send($request, new SendRequest(
            SendableEvent::TicketUpdated,
            $ticket->customer_id,
            $ticket->identifier(),
            $ticket->conversation_id,
        ));
    }

    private function send(Request $request, SendRequest $sendRequest): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var EnqueueResult $result */
        $result = DB::transaction(function () use ($sendRequest, $user): EnqueueResult {
            $result = $this->enqueue->enqueue($sendRequest);
            if ($result->smsMessageId !== null) {
                $this->audit->record('sms.message.manually_queued', 'sms_message', $result->smsMessageId, AuditActor::Staff, [
                    'ticket_id' => $sendRequest->ticketId,
                    'event' => $sendRequest->event->value,
                    'status' => $result->status,
                ], $user->identifier());
            }

            return $result;
        });

        if ($result->status === 'refused_no_phone') {
            return $this->refused('no_phone', []);
        }
        if ($result->status === 'skipped' && $result->reason === 'duplicate') {
            $lastQueuedAt = DB::table('sms_messages')
                ->where('event', $sendRequest->event->value)
                ->when($sendRequest->ticketId !== null, fn ($q) => $q->where('ticket_id', $sendRequest->ticketId))
                ->where('state', '!=', 'skipped')
                ->orderByDesc('queued_at')->value('queued_at');

            // EC-10: the inline notice's data — when the same event last went out
            return $this->refused('duplicate', ['last_queued_at' => is_string($lastQueuedAt) ? $lastQueuedAt : '']);
        }

        return new JsonResponse([
            'sms_message_id' => $result->smsMessageId,
            'status' => $result->status,
            'reason' => $result->reason,
        ], 201);
    }

    private function ticket(string $ticketId): Ticket
    {
        $ticket = Ticket::query()->find($ticketId);
        if ($ticket === null) {
            throw new NotFoundHttpException; // C-10: 404, never an existence oracle
        }

        return $ticket;
    }

    /** @param array<string, string> $extra */
    private function refused(string $code, array $extra): JsonResponse
    {
        return ProblemDetails::response(
            code: ErrorCode::ValidationFailed,
            status: 422,
            detail: (string) __('sms.refused.'.$code),
            errors: ['refusal' => [$code]] + array_map(static fn (string $v): array => [$v], $extra),
        );
    }
}
