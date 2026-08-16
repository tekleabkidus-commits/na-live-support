<?php

declare(strict_types=1);

namespace App\Http\Controllers\Customers;

use App\Domain\Customers\Blocking\BlockCustomer;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * FR-18's doors (step 11; the guard + cascade were falsified at step 7,
 * BEFORE this was routable — sabotage #8's ordering honored). Reasons
 * mandatory BOTH directions (§8; A-39: unblock is its own permissioned act
 * riding customers.block). F04's drawn Reason-Required dialog is the
 * client; the door is the law. A-37 noops declared on both directions.
 */
final class BlockController
{
    public function block(Request $request, string $customerId, BlockCustomer $blocks): JsonResponse
    {
        Customer::query()->findOrFail($customerId); // C-10

        /** @var array{reason: string} $input */
        $input = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000']]);

        /** @var User $actor */
        $actor = $request->user();

        if ($blocks->block($customerId, $input['reason'], $actor->identifier()) === null) {
            $request->attributes->set('audit.noop', true); // A-37: already blocked

            return new JsonResponse(['blocked' => true, 'changed' => false]);
        }

        return new JsonResponse(['blocked' => true, 'changed' => true]);
    }

    public function unblock(Request $request, string $customerId, BlockCustomer $blocks): JsonResponse
    {
        Customer::query()->findOrFail($customerId);

        /** @var array{reason: string} $input */
        $input = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000']]);

        /** @var User $actor */
        $actor = $request->user();

        if ($blocks->unblock($customerId, $input['reason'], $actor->identifier()) === null) {
            $request->attributes->set('audit.noop', true); // A-37: not blocked

            return new JsonResponse(['blocked' => false, 'changed' => false]);
        }

        return new JsonResponse(['blocked' => false, 'changed' => true]);
    }
}
