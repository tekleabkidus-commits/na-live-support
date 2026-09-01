<?php

declare(strict_types=1);

namespace App\Domain\Csat;

use App\Domain\Conversations\Outbound\ComposeOutbound;
use App\Models\Conversation;
use App\Support\Events\EventPublisher;
use App\Support\Settings\SettingsRepository;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Doc 12 FR-24 / BR-07 / SEC-05 / T-08 — survey arming, BOTH resolution paths
 * (ticket.resolved with the actor's resolver_type; ai.resolved anchored to the
 * conversation per RIDER 4). ONE survey per resolution event — the
 * (tenant, resolution_event_id) unique is the C-09 idempotency anchor, so a
 * re-driven consumer changes nothing. Suppressions FIRST (measurement, not
 * marketing): cooldown (csat.cooldown.hours) and RG (Doc 08 FR-15's contract —
 * an RG-flagged player is never surveyed). The raw unguessable token is SENT,
 * never stored (token_hash only — the Doc 02 recovery-code precedent);
 * expires_at MATERIALIZES at arming (a later window change never re-targets).
 * Delivery rides the standard outbound path with the 1-tap scores as channel
 * meta — the adapter renders inline buttons (direct) or numbered text
 * (business) per ITS mode; the widget star row is [D13]'s render of the same
 * contract.
 */
final class SurveyArmer
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly SettingsRepository $settings,
        private readonly ComposeOutbound $outbound,
        private readonly EventPublisher $events,
    ) {}

    /**
     * @return array{armed: bool, reason?: string, survey_id?: string}
     */
    public function armFromEvent(string $eventId): array
    {
        /** @var object{name: string, payload: string}|null $event */
        $event = DB::table('outbox')->where('event_id', $eventId)->first(['name', 'payload']);
        if ($event === null) {
            return ['armed' => false, 'reason' => 'unknown_event'];
        }
        /** @var array<string, mixed> $payload */
        $payload = json_decode($event->payload, true, 16, JSON_THROW_ON_ERROR);

        if ($event->name === 'ticket.resolved') {
            $ticketId = is_string($payload['ticket_id'] ?? null) ? $payload['ticket_id'] : null;
            if ($ticketId === null) {
                return ['armed' => false, 'reason' => 'no_ticket'];
            }
            /** @var object{conversation_id: ?string, customer_id: ?string}|null $ticket */
            $ticket = DB::table('tickets')->where('id', $ticketId)->first(['conversation_id', 'customer_id']);
            if ($ticket === null || ! is_string($ticket->customer_id)) {
                return ['armed' => false, 'reason' => 'no_customer'];
            }
            $resolver = ($payload['actor_type'] ?? null) === 'ai' ? 'ai' : 'human';

            return $this->arm($eventId, $resolver, $ticket->customer_id, $ticketId, $ticket->conversation_id);
        }
        if ($event->name === 'ai.resolved') {
            $conversationId = is_string($payload['conversation_id'] ?? null) ? $payload['conversation_id'] : null;
            $customerId = $conversationId !== null
                ? DB::table('conversations')->where('id', $conversationId)->value('customer_id')
                : null;
            if ($conversationId === null || ! is_string($customerId)) {
                return ['armed' => false, 'reason' => 'no_customer'];
            }
            // RIDER 4: no ticket exists — the conversation is the anchor.
            $ticketId = DB::table('conversations')->where('id', $conversationId)->value('open_ticket_id');

            return $this->arm($eventId, 'ai', $customerId, is_string($ticketId) ? $ticketId : null, $conversationId);
        }

        return ['armed' => false, 'reason' => 'not_a_resolution'];
    }

    /** @return array{armed: bool, reason?: string, survey_id?: string} */
    private function arm(string $eventId, string $resolver, string $customerId, ?string $ticketId, ?string $conversationId): array
    {
        // C-09 first: a re-driven consumer must answer already_armed, never a
        // suppression verdict computed against its OWN earlier survey.
        if (DB::table('surveys')->where('resolution_event_id', $eventId)->exists()) {
            return ['armed' => false, 'reason' => 'already_armed'];
        }
        // BR-07's suppressions, in order of severity: RG first, then cooldown.
        $rg = DB::table('customers')->where('id', $customerId)->value('rg_flag');
        if ($rg === true) {
            return ['armed' => false, 'reason' => 'rg_suppressed'];
        }
        $cooldown = $this->settings->get('csat.cooldown.hours');
        $cooldownHours = is_numeric($cooldown) ? (int) $cooldown : 24;
        $recent = DB::table('surveys')->where('customer_id', $customerId)
            ->whereRaw('sent_at > clock_timestamp() - make_interval(hours => ?)', [$cooldownHours])->exists();
        if ($recent) {
            return ['armed' => false, 'reason' => 'cooldown_suppressed'];
        }

        $conversation = $conversationId !== null ? Conversation::query()->find($conversationId) : null;
        if (! $conversation instanceof Conversation) {
            return ['armed' => false, 'reason' => 'no_conversation'];
        }
        $token = Str::random(48);
        $window = $this->settings->get('csat.window.hours');
        $windowHours = is_numeric($window) ? (int) $window : 72;
        $surveyId = (string) Str::uuid7();
        $language = DB::table('customers')->where('id', $customerId)->value('language');
        $language = is_string($language) && $language !== '' ? $language : 'en';

        // Materialized at arming — SS-03's settings-change immunity.
        /** @var object{at: string} $expiry */
        $expiry = DB::selectOne('SELECT clock_timestamp() + make_interval(hours => ?) AS at', [$windowHours]);

        try {
            DB::transaction(function () use ($surveyId, $eventId, $resolver, $customerId, $ticketId, $conversation, $token, $expiry, $language): void {
                DB::table('surveys')->insert([
                    'id' => $surveyId, 'tenant_id' => $this->context->tenantId(),
                    'ticket_id' => $ticketId, 'conversation_id' => $conversation->identifier(),
                    'customer_id' => $customerId, 'resolution_event_id' => $eventId,
                    'resolver_type' => $resolver, 'token_hash' => hash('sha256', $token),
                    'channel' => $conversation->getAttribute('channel'),
                    'expires_at' => $expiry->at,
                ]);
                // Standard outbound path; the 1-tap contract is channel meta the
                // adapter renders per ITS mode (T-08). BR-07: one prompt, plain.
                $this->outbound->compose($conversation, 'system', null, 'text',
                    __('workflow.csat.prompt', [], $language),
                    'csat:'.$eventId,
                    ['csat' => ['token' => $token, 'scores' => [1, 2, 3, 4, 5]]],
                );
                $this->events->publish('csat.sent', [
                    'tenant_id' => $this->context->tenantId(), 'survey_id' => $surveyId,
                    'ticket_id' => $ticketId, 'customer_id' => $customerId, 'resolver_type' => $resolver,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // C-09: this resolution event already armed its one survey.
            return ['armed' => false, 'reason' => 'already_armed'];
        }

        return ['armed' => true, 'survey_id' => $surveyId];
    }
}
