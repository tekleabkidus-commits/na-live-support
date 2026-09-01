<?php

declare(strict_types=1);

namespace App\Domain\Forms\Runtime;

use App\Domain\Ai\Pipeline\FlowStateProvider;
use Illuminate\Support\Facades\DB;

/**
 * Doc 09 FR-08's flow-state seam, filled by Doc 12 (the AppServiceProvider
 * bind comment's "Doc 12 forms bind theirs" — bound in
 * AutomationServiceProvider, later in the register order, so the Null default
 * is overridden without touching the Doc 09 file). The chatbot reads the LIVE
 * checkpoint (never Redis — the row is the truth FS-04 trusts) and may start
 * only published, active forms (closing-set row 5's run_form half).
 */
final class ActiveFlowProvider implements FlowStateProvider
{
    public function activeState(string $conversationId): ?array
    {
        /** @var object{form_id: string, step_index: int}|null $row */
        $row = DB::table('form_flow_checkpoints')->where('conversation_id', $conversationId)->whereNull('outcome')
            ->first(['form_id', 'step_index']);

        return $row === null ? null : ['form_id' => $row->form_id, 'step' => (int) $row->step_index];
    }

    public function availableForms(?string $endpointRef = null): array
    {
        /** @var list<string> */
        return DB::table('forms')->where('active', true)->whereNotNull('current_version_id')
            ->orderBy('name')->pluck('id')->all();
    }
}
