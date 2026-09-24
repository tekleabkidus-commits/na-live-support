<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sms;

use App\Domain\Sms\State\SmsDeliveryState;
use App\Domain\Sms\State\SmsDeliveryStateMachine;
use App\Domain\Sms\Support\PhoneMask;
use App\Http\Controllers\Controller;
use App\Jobs\Sms\SendSmsJob;
use App\Models\SmsMessage;
use App\Models\User;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Timers\DueSet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * FR-16's staff half (§8): the admin held view's DATA door (masked phones —
 * the B4-S08 form; the [D17] masked-path law) and the RELEASE-NOW door
 * (`sms.send`, audited `sms.message.released`): held→queued through the
 * machine, dispatched immediately — a staff decision outruns the window.
 */
final class SmsHeldController extends Controller
{
    public function __construct(
        private readonly SmsDeliveryStateMachine $machine,
        private readonly AuditLogger $audit,
        private readonly DueSet $timers,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $rows = [];
        foreach (SmsMessage::query()->where('state', 'held')->orderBy('queued_at')->limit(200)->get() as $row) {
            $rows[] = [
                'id' => $row->identifier(),
                'event' => $row->event,
                'phone_masked' => PhoneMask::display($row->phone),
                'lane' => $row->lane,
                'queued_at' => $row->queued_at->toIso8601String(),
                'hold_reason' => collect($row->guard_log)->last(fn (array $e): bool => in_array($e['check'] ?? '', ['hold_all_sends', 'quiet_hours'], true))['check'] ?? null,
            ];
        }

        return new JsonResponse(['held' => $rows]);
    }

    public function release(Request $request, string $smsMessageId): JsonResponse
    {
        /** @var SmsMessage|null $row */
        $row = SmsMessage::query()->find($smsMessageId);
        if ($row === null) {
            throw new NotFoundHttpException; // C-10
        }
        if ($row->state !== 'held') {
            return new JsonResponse(['released' => false, 'state' => $row->state], 409);
        }

        /** @var User $user */
        $user = $request->user();
        DB::transaction(function () use ($row, $user): void {
            $row->state = $this->machine->transition(SmsDeliveryState::Held, SmsDeliveryState::Queued)->value;
            $row->save();
            app(\App\Domain\Sms\Pipeline\SmsLifecycle::class)->publish('sms.queued', $row);
            $this->audit->record('sms.message.released', 'sms_message', $row->identifier(), AuditActor::Staff, ['sms_message_id' => $row->identifier()], $user->identifier());
        });
        $this->timers->cancel('sms_hold_release', DueSet::member($row->tenant_id, $row->identifier()));
        SendSmsJob::dispatch($row->identifier(), $row->lane);

        return new JsonResponse(['released' => true]);
    }
}
