<?php

declare(strict_types=1);

namespace App\Http\Controllers\Customers;

use App\Domain\Customers\Profile\CustomerProfile;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * FR-21's inline-edit autosave door (profile attributes; FR-01's sticky
 * sources; EC-10's apply-detected; EC-11's LWW-with-honest-conflict). The
 * A-37 causal noop declaration lives at EXACTLY the service's own no-change
 * return — never controller-entry, never response-shape-derived.
 */
final class ProfileController
{
    public function update(Request $request, string $customerId, CustomerProfile $profile): JsonResponse
    {
        Customer::query()->findOrFail($customerId); // C-10

        /** @var array{display_name?: string, language?: string, country?: string, tags?: list<string>, expected_updated_at?: string} $input */
        $input = $request->validate([
            'display_name' => ['sometimes', 'string', 'max:200'],
            'language' => ['sometimes', 'string', 'max:12'],
            'country' => ['sometimes', 'string', 'size:2'],
            'tags' => ['sometimes', 'array', 'max:50'],
            'tags.*' => ['string', 'max:60'],
            'expected_updated_at' => ['sometimes', 'string', 'max:64'],
        ]);

        /** @var User $actor */
        $actor = $request->user();

        $result = $profile->update(
            $customerId,
            array_intersect_key($input, array_flip(['display_name', 'language', 'country', 'tags'])),
            $actor->identifier(),
            $input['expected_updated_at'] ?? null,
        );

        if ($result === null) {
            // A-37: the service's own documented no-change return — the
            // signed-skip declaration, at exactly this branch.
            $request->attributes->set('audit.noop', true);

            return new JsonResponse(['changed' => [], 'conflict' => false]);
        }

        return new JsonResponse($result);
    }

    public function applyDetected(Request $request, string $customerId, CustomerProfile $profile): JsonResponse
    {
        Customer::query()->findOrFail($customerId);

        /** @var User $actor */
        $actor = $request->user();

        $result = $profile->applyDetected($customerId, $actor->identifier());

        if ($result === null) {
            $request->attributes->set('audit.noop', true); // nothing detected, or already equal — the same A-37 shape

            return new JsonResponse(['changed' => [], 'conflict' => false]);
        }

        return new JsonResponse($result);
    }
}
