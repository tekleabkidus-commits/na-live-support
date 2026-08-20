<?php

declare(strict_types=1);

namespace App\Domain\Ai\Pipeline;

/**
 * FR-08's "active flow state" seam — Doc 05 menu flows and Doc 12 forms
 * ([D12]) provide it; the chatbot READS the state and the available forms
 * and records run_form / continue_flow actions against it. The container
 * default is NullFlowState (no flow, no forms); tests bind fixture states.
 */
interface FlowStateProvider
{
    /** @return array{form_id?: string, menu?: string, step?: int}|null */
    public function activeState(string $conversationId): ?array;

    /** @return list<string> form ids the chatbot may start */
    public function availableForms(?string $endpointRef = null): array;
}
