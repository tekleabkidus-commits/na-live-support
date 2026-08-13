<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inbox;

use App\Domain\Inbox\ReadModels\ListProjector;
use App\Domain\Inbox\ReadModels\ListReadModel;
use App\Domain\Tickets\Merge\MergeTickets;
use App\Domain\Tickets\Transitions\TransitionRefused;
use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\TicketListItem;
use App\Models\User;
use App\Support\Problem\ErrorCode;
use App\Support\Problem\ProblemDetails;
use App\Support\Rbac\Scopes\ScopeSets;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * B1-S04's merge surfaces (S07 step 14) — Doc 06 FR-20 through the owning
 * MergeTickets service, never beside it. Two doors:
 *
 *  - PREVIEW (the "What happens" panel, SERVER-COMPUTED per the ruling):
 *    the dialog renders facts the machinery itself derives — same-customer
 *    truth (SEC-06's hard rule, read-only here), the source/target cards,
 *    the tag/follower unions, whose SLA governs, the 24 h reversal window.
 *    The TARGET is the most recently active of the selection; the drawn
 *    dialog states it, the service re-validates everything at execution.
 *
 *  - MERGE: the execution door. Every verdict, snapshot, link rewrite,
 *    banner pointer and audit row is the service's own.
 *
 * Scope: EVERY id must survive the requester's ScopeSets — one miss and the
 * whole request answers the C-10 404-pattern (no partial oracle).
 */
final class MergeController extends Controller
{
    public function __construct(
        private readonly MergeTickets $merges,
        private readonly ListProjector $projector,
        private readonly ScopeSets $scopes,
    ) {}

    public function preview(Request $request): JsonResponse
    {
        try {
            /** @var array{ids: list<string>} $input */
            $input = $request->validate(['ids' => ['required', 'array', 'min:2', 'max:25'], 'ids.*' => ['string', 'uuid']]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            /** @var array<string, list<string>> $errors */
            $errors = $e->errors();

            return ProblemDetails::response(ErrorCode::ValidationFailed, 422, 'invalid input', errors: $errors);
        }

        /** @var User $user */
        $user = $request->user();
        $tickets = $this->resolveAllScoped($user, $input['ids']);
        if ($tickets === null) {
            return $this->notFound();
        }

        // The most recently active survives — the drawn dialog's own order.
        usort($tickets, static fn (object $a, object $b): int => strcmp((string) $b->last_activity_at, (string) $a->last_activity_at));
        $target = $tickets[0];
        $sources = array_slice($tickets, 1);

        $customerIds = array_unique(array_map(static fn (object $t): string => (string) $t->customer_id, $tickets));

        $card = function (object $item): array {
            /** @var object{ticket_id: string, conversation_id: string, number: int|string, subject: string|null, ticket_created_at: string, sla_state: string|null, tags: string|null} $item */
            $messages = (int) DB::table('messages')->where('conversation_id', $item->conversation_id)->where('is_internal', false)->count();
            /** @var list<string> $tags */
            $tags = is_string($item->tags ?? null)
                ? (array) json_decode((string) $item->tags, true, 4)
                : [];

            return [
                'ticket_id' => $item->ticket_id,
                'number' => $item->number,
                'subject' => $item->subject,
                'created_at' => $item->ticket_created_at,
                'message_count' => $messages,
                'tags' => $tags,
                'sla_state' => $item->sla_state,
            ];
        };

        $sourceCards = array_map($card, $sources);
        $targetCard = $card($target);
        /** @var list<string> $tagsJoining */
        $tagsJoining = array_values(array_unique(array_merge(...array_map(static fn (array $c): array => $c['tags'], $sourceCards ?: [['tags' => []]]))));
        $followerCount = (int) DB::table('ticket_followers')
            ->whereIn('ticket_id', array_map(static fn (object $t): string => (string) $t->ticket_id, $sources))
            ->distinct('user_id')->count('user_id');

        return new JsonResponse([
            'same_customer' => count($customerIds) === 1,
            'customer_name' => (string) $target->customer_display_name,
            'target' => $targetCard,
            'sources' => $sourceCards,
            'effects' => [
                'tags_joining' => $tagsJoining,
                'followers_joining' => $followerCount,
                'sla_governs_number' => $targetCard['number'],
                'reversible_hours' => 24,
            ],
            // The drawn gate: more than two sources → type MERGE to confirm.
            'confirm_phrase_required' => count($sourceCards) > 1,
        ]);
    }

    public function merge(Request $request): JsonResponse
    {
        try {
            /** @var array{target_id: string, source_ids: list<string>} $input */
            $input = $request->validate([
                'target_id' => ['required', 'string', 'uuid'],
                'source_ids' => ['required', 'array', 'min:1', 'max:24'],
                'source_ids.*' => ['string', 'uuid'],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            /** @var array<string, list<string>> $errors */
            $errors = $e->errors();

            return ProblemDetails::response(ErrorCode::ValidationFailed, 422, 'invalid input', errors: $errors);
        }

        /** @var User $user */
        $user = $request->user();
        $all = $this->resolveAllScoped($user, array_merge([$input['target_id']], $input['source_ids']));
        if ($all === null) {
            return $this->notFound();
        }

        /** @var Ticket|null $target */
        $target = Ticket::query()->find($input['target_id']);
        $sources = Ticket::query()->findMany($input['source_ids'])->all();
        if ($target === null || count($sources) !== count($input['source_ids'])) {
            return $this->notFound();
        }

        try {
            $survivor = $this->merges->merge(array_values($sources), $target, $user);
        } catch (TransitionRefused $refused) {
            return ProblemDetails::response(ErrorCode::TransitionRejected, 422, $refused->getMessage(), extra: ['refusal_code' => $refused->refusalCode]);
        } catch (DomainException $e) {
            return ProblemDetails::response(ErrorCode::TransitionRejected, 422, $e->getMessage());
        }

        foreach ([$survivor->identifier(), ...$input['source_ids']] as $ticketId) {
            $this->projector->recomputeTicket((string) $ticketId);
        }

        return new JsonResponse(['ok' => true, 'survivor_id' => $survivor->identifier()]);
    }

    /**
     * ALL ids must survive the viewer's scope — a single miss 404s the lot.
     *
     * @param  list<string>  $ids
     * @return list<object{ticket_id: string, conversation_id: string, number: int|string, subject: string|null, ticket_created_at: string, last_activity_at: string|null, customer_id: string|null, customer_display_name: string|null, sla_state: string|null, tags: string|null}>|null
     */
    private function resolveAllScoped(User $user, array $ids): ?array
    {
        $query = TicketListItem::query()->whereIn('ticket_id', $ids);
        $this->scopes->apply($query, 'tickets', $user, ListReadModel::columns());
        /** @var list<object{ticket_id: string, conversation_id: string, number: int|string, subject: string|null, ticket_created_at: string, last_activity_at: string|null, customer_id: string|null, customer_display_name: string|null, sla_state: string|null, tags: string|null}> $rows */
        $rows = $query->get()->all();

        return count($rows) === count(array_unique($ids)) ? $rows : null;
    }

    private function notFound(): JsonResponse
    {
        Log::info('inbox merge 404-pattern served', ['metric' => 'security.cross_tenant_probe']);

        return ProblemDetails::response(
            ErrorCode::ResourceNotFound,
            404,
            'not found',
            extra: ['search_instead' => '/inbox'],
        );
    }
}
