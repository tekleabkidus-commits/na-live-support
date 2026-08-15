<?php

declare(strict_types=1);

namespace App\Http\Controllers\Customers;

use App\Domain\Customers\Fields\CustomerFieldValues;
use App\Domain\Customers\Fields\FieldRetired;
use App\Models\Customer;
use App\Models\User;
use App\Support\Problem\ErrorCode;
use App\Support\Problem\ProblemDetails;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * FR-16's doors: the betting-fields card (values + the SEC-05 hint) and the
 * value writer. The card reads on customers.view; the write rides
 * customers.edit with the A-37 noop declared at the service's own branch.
 * EC-09's retired refusal answers 422 with the stable validation code.
 */
final class FieldsController
{
    public function card(Request $request, string $customerId, CustomerFieldValues $fields): JsonResponse
    {
        Customer::query()->findOrFail($customerId); // C-10

        /** @var User $viewer */
        $viewer = $request->user();

        return new JsonResponse([
            'fields' => $fields->valuesFor($customerId),
            // SEC-05: counts + scope-checked links, never names.
            'duplicate_hint' => $fields->duplicateHint($customerId, $viewer),
        ]);
    }

    public function update(Request $request, string $customerId, CustomerFieldValues $fields): JsonResponse
    {
        Customer::query()->findOrFail($customerId); // C-10

        /** @var array{values: array<string, ?string>} $input */
        $input = $request->validate([
            'values' => ['required', 'array', 'max:20'],
            'values.*' => ['nullable', 'string', 'max:200'],
        ]);

        /** @var User $actor */
        $actor = $request->user();

        try {
            $changed = $fields->set($customerId, $input['values'], $actor->identifier());
        } catch (FieldRetired $e) {
            return ProblemDetails::response(ErrorCode::ValidationFailed, 422, __('customers.fields.retired_refusal', ['field' => $e->key]));
        }

        if ($changed === null) {
            // A-37: the service's own documented no-change return.
            $request->attributes->set('audit.noop', true);

            return new JsonResponse(['changed' => []]);
        }

        return new JsonResponse(['changed' => $changed]);
    }
}
