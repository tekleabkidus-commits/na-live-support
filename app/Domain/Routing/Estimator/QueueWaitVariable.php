<?php

declare(strict_types=1);

namespace App\Domain\Routing\Estimator;

use App\Domain\Routing\Hours\HoursContract;
use App\Domain\Routing\Intake\TenantDefaultDepartment;
use Illuminate\Support\Facades\DB;

/**
 * Doc 11 FR-13 / FR-14 / SEC-05 — the `{{queue_wait}}` PROVIDER (P-C4, countersigned):
 * the localized BAND PHRASE for a conversation — never a count, never a name. The
 * conversation's open ticket names the queue (its own position when waiting); with no
 * ticket yet (the pre-handoff bot copy, Doc 05 FR-28's off-hours/handoff templates) the
 * tenant default department's default queue (A-55) answers for a new arrival.
 *
 * Phrases: `routing.estimate.band.{short|medium|long|off_hours}` (§14, en + am); the
 * off-hours phrase carries the opening time in the tenant timezone (A-52).
 */
final class QueueWaitVariable
{
    public function __construct(
        private readonly Estimator $estimator,
        private readonly HoursContract $hours,
        private readonly TenantDefaultDepartment $defaultDepartment,
    ) {}

    public function forConversation(string $conversationId, string $language): string
    {
        /** @var object{id: string, queue_id: ?string, assignee_id: ?string}|null $ticket */
        $ticket = DB::table('tickets')->where('conversation_id', $conversationId)
            ->whereIn('status', ['new', 'open', 'pending_customer', 'pending_internal', 'escalated'])
            ->orderByDesc('id')->first(['id', 'queue_id', 'assignee_id']);

        $queueId = $ticket?->queue_id;
        if (! is_string($queueId)) {
            $queueId = $this->defaultQueue();
        }
        if ($queueId === null) {
            return $this->phrase('long', $language, null);
        }
        $detail = is_string($ticket?->id) && $ticket->assignee_id === null
            ? $this->estimator->detail($queueId, $ticket->id)
            : $this->estimator->detail($queueId, null);

        return $this->phrase($detail['band'], $language, $detail['opens_at']);
    }

    /** The band phrase for a band key — the same vocabulary the endpoint's consumers localize. */
    public function phrase(string $band, string $language, ?string $opensAtAtom): string
    {
        $replace = [];
        if ($band === 'off_hours') {
            $opens = $opensAtAtom !== null ? new \DateTimeImmutable($opensAtAtom) : $this->hours->now();
            $replace['time'] = $opens->setTimezone($this->hours->timezone())->format('H:i');
        }
        $key = 'routing.estimate.band.'.$band;
        $text = __($key, $replace, $language);
        if (! is_string($text) || $text === $key) {
            $text = (string) __($key, $replace, 'en');
        }

        return $text;
    }

    private function defaultQueue(): ?string
    {
        return $this->defaultDepartment->resolve()['queue_id'] ?? null;
    }
}
