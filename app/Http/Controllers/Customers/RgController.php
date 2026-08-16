<?php

declare(strict_types=1);

namespace App\Http\Controllers\Customers;

use App\Domain\Customers\Rg\RgFlagRepository;
use App\Domain\Customers\Rg\RgFlagService;
use App\Models\Customer;
use App\Models\User;
use App\Support\Problem\ErrorCode;
use App\Support\Problem\ProblemDetails;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * FR-14/FR-15's doors (step 11; the storage + AC-05 physics landed at step
 * 4, BEFORE any of this was routable). Reads ride the NO-CACHE repository
 * (NFR-06/AC-B4-01); the E07 history renders whole, never deleted. Set and
 * clear both demand reasons (E04/E06's drawn Reason-Required pattern —
 * also the D04–D06 class-2 fill's source, filed with both citations).
 * A-37: setting an already-set flag / clearing a clear one is the service's
 * own declared noop.
 */
final class RgController
{
    public function show(string $customerId, RgFlagRepository $rg): JsonResponse
    {
        Customer::query()->findOrFail($customerId); // C-10

        return new JsonResponse([
            'current' => $rg->current($customerId),
            'history' => $rg->history($customerId), // E07: whole, forever
        ]);
    }

    public function set(Request $request, string $customerId, RgFlagService $flags): JsonResponse
    {
        Customer::query()->findOrFail($customerId);

        /** @var array{reason: string} $input */
        $input = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000']]);

        /** @var User $actor */
        $actor = $request->user();

        try {
            $historyId = $flags->set($customerId, $input['reason'], 'staff', $actor->identifier());
        } catch (DomainException $e) {
            return ProblemDetails::response(ErrorCode::ValidationFailed, 422, $e->getMessage());
        }

        return new JsonResponse(['history_id' => $historyId], 201);
    }

    public function clear(Request $request, string $customerId, RgFlagService $flags): JsonResponse
    {
        Customer::query()->findOrFail($customerId);

        /** @var array{reason: string} $input */
        $input = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000']]);

        /** @var User $actor */
        $actor = $request->user();

        $historyId = $flags->clear($customerId, $input['reason'], $actor->identifier());

        if ($historyId === null) {
            // A-37: the service's own no-change return (nothing was set).
            $request->attributes->set('audit.noop', true);

            return new JsonResponse(['cleared' => false]);
        }

        return new JsonResponse(['cleared' => true, 'history_id' => $historyId]);
    }
}
