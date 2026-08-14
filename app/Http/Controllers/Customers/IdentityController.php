<?php

declare(strict_types=1);

namespace App\Http\Controllers\Customers;

use App\Domain\Customers\Identity\DuplicateIdentity;
use App\Domain\Customers\Identity\LinkIdentity;
use App\Domain\Customers\Identity\UnlinkIdentity;
use App\Domain\Customers\Identity\UnlinkMoveSet;
use App\Models\Customer;
use App\Models\User;
use App\Support\Problem\ErrorCode;
use App\Support\Problem\ProblemDetails;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * FR-09/FR-10's doors (T-02 names the link route). C-10 floor: a missing or
 * cross-tenant customer/identity answers 404 (RLS makes the cross-tenant row
 * a missing row; no tenant-existence probing). Payloads are CURATED — ids
 * and counts, never row dumps (the PII floor).
 *
 * FR-09's block answers 409 with the OTHER customer's id only — the door
 * hands the client the merge-instead OFFER; whether that link may be
 * FOLLOWED is the C02-v2 scope boundary, resolved where the profile is
 * fetched (its own door, its own scope check) — a count-and-id here leaks no
 * name (SEC-05's discipline).
 *
 * AC-04 BY CONSTRUCTION: unlinkPreview and unlink both read UnlinkMoveSet —
 * the single source — so the confirmation the client renders and the move
 * that executes cannot diverge (sabotage #7's subject).
 */
final class IdentityController
{
    public function link(Request $request, string $customerId, LinkIdentity $linker): JsonResponse
    {
        Customer::query()->findOrFail($customerId);   // C-10: 404, RLS-shaped

        /** @var array{type: string, value: string, region?: string} $input */
        $input = $request->validate([
            'type' => ['required', 'in:phone,email'],
            'value' => ['required', 'string', 'max:320'],
            'region' => ['sometimes', 'string', 'size:2'],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        $actorId = $actor->identifier();

        try {
            $identityId = $linker->link($customerId, $input['type'], $input['value'], $actorId, $input['region'] ?? 'ET');
        } catch (DuplicateIdentity $e) {
            // FR-09: blocked, with the link to that customer and the
            // merge-instead offer. 409 — a stable C-04 code, not a validation 422.
            return ProblemDetails::response(
                ErrorCode::IdentityAlreadyLinked,
                409,
                __('customers.identity.duplicate', ['type' => $e->type]),
                extra: ['existing_customer_id' => $e->existingCustomerId, 'merge_offer' => true],
            );
        }

        if ($identityId === null) {
            // Already on THIS customer — the service's documented noop (A-37).
            $request->attributes->set('audit.noop', true);

            return new JsonResponse(['ok' => true, 'linked' => false]);
        }

        return new JsonResponse(['ok' => true, 'linked' => true, 'identity_id' => $identityId], 201);
    }

    public function unlinkPreview(string $customerId, string $identityId, UnlinkMoveSet $moveSet): JsonResponse
    {
        $set = $this->moveSetFor($customerId, $identityId, $moveSet);

        // The confirmation content, from the single source: counts + ids.
        return new JsonResponse([
            'identity_id' => $set['identity']->id,
            'type' => $set['identity']->type,
            'value' => $set['identity']->value,
            'conversations' => count($set['conversation_ids']),
            'tickets' => count($set['ticket_ids']),
            'open_ticket_ids' => $set['open_ticket_ids'],
        ]);
    }

    public function unlink(Request $request, string $customerId, string $identityId, UnlinkMoveSet $moveSet, UnlinkIdentity $unlinker): JsonResponse
    {
        $this->moveSetFor($customerId, $identityId, $moveSet);   // C-10 shape first

        /** @var array{reason: string} $input */
        $input = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);

        /** @var User $actor */
        $actor = $request->user();

        $moved = $unlinker->unlink($identityId, $input['reason'], $actor->identifier());

        return new JsonResponse(['ok' => true] + $moved);
    }

    /** @return array{identity: object{id: string, customer_id: string, type: string, value: string, channel: string}, conversation_ids: list<string>, ticket_ids: list<string>, open_ticket_ids: list<string>} */
    private function moveSetFor(string $customerId, string $identityId, UnlinkMoveSet $moveSet): array
    {
        $set = $moveSet->for($identityId);

        if ($set === null || $set['identity']->customer_id !== $customerId) {
            abort(404);   // C-10: wrong customer pairing is indistinguishable from missing
        }

        return $set;
    }
}
