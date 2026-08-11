<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inbox;

use App\Domain\Inbox\Search\SearchProxy;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * FR-23's search door — SEC-02's ONLY path. The request contract is the
 * QUERY STRING alone: no filter, scope, index or tenant parameter exists,
 * so there is nothing scope-shaped for a client to send (BR-03; the proxy
 * derives everything server-side).
 */
final class SearchController extends Controller
{
    public function __invoke(Request $request, SearchProxy $proxy): JsonResponse
    {
        /** @var array{q: string} $input */
        $input = $request->validate([
            'q' => ['required', 'string', 'min:1', 'max:200'],
        ]);

        /** @var User $user */
        $user = $request->user();

        return new JsonResponse(['v' => 1] + $proxy->search($input['q'], $user));
    }
}
