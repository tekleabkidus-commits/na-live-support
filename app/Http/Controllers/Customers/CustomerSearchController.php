<?php

declare(strict_types=1);

namespace App\Http\Controllers\Customers;

use App\Domain\Customers\Search\CustomerSearch;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * FR-20's search door (T-04). SEC-02's one-path law: the request carries a
 * QUERY STRING and nothing else — scope resolves server-side from the viewer
 * (CustomerScope), the tenant selects the physical index, and any client-sent
 * filter/scope/index parameter has NO code path (proven by the smuggle test).
 * Hits are the curated allowlist; `degraded: true` is FS-01's honest label.
 */
final class CustomerSearchController
{
    public function search(Request $request, CustomerSearch $search): JsonResponse
    {
        /** @var array{q: string} $input */
        $input = $request->validate([
            'q' => ['required', 'string', 'min:1', 'max:200'],
        ]);

        /** @var User $viewer */
        $viewer = $request->user();

        return new JsonResponse($search->search($input['q'], $viewer));
    }
}
