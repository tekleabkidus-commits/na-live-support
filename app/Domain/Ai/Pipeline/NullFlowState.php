<?php

declare(strict_types=1);

namespace App\Domain\Ai\Pipeline;

/** No flows yet (Doc 05 menus / Doc 12 forms arrive on their documents). */
final class NullFlowState implements FlowStateProvider
{
    public function activeState(string $conversationId): ?array
    {
        return null;
    }

    public function availableForms(?string $endpointRef = null): array
    {
        return [];
    }
}
