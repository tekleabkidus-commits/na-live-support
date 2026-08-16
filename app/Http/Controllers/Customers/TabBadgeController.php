<?php

declare(strict_types=1);

namespace App\Http\Controllers\Customers;

use App\Domain\Customers\Rg\CustomerTabBadge;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The badge door for client-side thread switches (escalation #3 part 2):
 * the SAME CustomerTabBadge brain the workspace's initial render uses — one
 * derivation, two doors. Also hands back the conversation's customer id,
 * the panel's fetch anchor (the caller holds customers.view).
 */
final class TabBadgeController
{
    public function show(Request $request, CustomerTabBadge $badge): JsonResponse
    {
        /** @var array{conversation_id: string} $input */
        $input = $request->validate(['conversation_id' => ['required', 'uuid']]);

        /** @var User $viewer */
        $viewer = $request->user();

        return new JsonResponse([
            'customer' => $badge->for($input['conversation_id'], $viewer),
            'customer_id' => $badge->customerFor($input['conversation_id']),
        ]);
    }
}
