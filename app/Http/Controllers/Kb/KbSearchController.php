<?php

declare(strict_types=1);

namespace App\Http\Controllers\Kb;

use App\Domain\Kb\Search\StaffSearch;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Doc 10 FR-17 staff search (S10 step 10): a READ — the query is echoed for the empty state and stored nowhere (SEC-06). */
final class KbSearchController extends Controller
{
    public function __construct(private readonly StaffSearch $search) {}

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $q = $request->query('q');
        $filters = [];
        foreach (['language', 'visibility', 'status', 'category_id'] as $key) {
            $v = $request->query($key);
            if (is_string($v) && $v !== '') {
                $filters[$key] = $v;
            }
        }

        return new JsonResponse($this->search->search(is_string($q) ? mb_substr($q, 0, 200) : '', $user, $filters, (int) $request->query('limit', '20')));
    }
}
