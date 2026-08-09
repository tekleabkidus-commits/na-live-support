<?php

declare(strict_types=1);

namespace App\Http\Controllers\Telegram;

use App\Domain\Telegram\Connections\ConnectionLifecycle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * FR-07 step 3 (SEC-04) / EC-01: the admin confirms a connection is really
 * the operator's account, or rejects a wrong one. Permission
 * telegram.connections.manage; B6-S02's server side (the checklist UI lands
 * at S23 step 10). The confirm step is the security control — an unconfirmed
 * connection never routes until this action runs.
 */
final class ConnectionAdminController
{
    public function __construct(private readonly ConnectionLifecycle $lifecycle) {}

    public function confirm(Request $request, string $connectionId): JsonResponse
    {
        $actorId = $request->user()?->getAuthIdentifier();
        $this->lifecycle->confirm($connectionId, is_string($actorId) ? $actorId : '');

        return new JsonResponse(['confirmed' => true]);
    }

    public function reject(string $connectionId): JsonResponse
    {
        $this->lifecycle->reject($connectionId);

        return new JsonResponse(['rejected' => true]);
    }
}
