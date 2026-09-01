<?php

declare(strict_types=1);

namespace App\Domain\Automation\Actions;

use App\Domain\Conversations\Outbound\ComposeOutbound;
use App\Domain\Macros\MacroGuard;
use App\Domain\Macros\VariableResolver;
use App\Models\Conversation;
use App\Models\Ticket;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Doc 12 FR-03 / FR-15 / BR-04 / T-06 — send-macro-as-reply through Doc 04's
 * ComposeOutbound with the SYSTEM author (the messages CHECK's own vocabulary),
 * channel-capability aware by the door's nature. THE ONE RESOLVER runs first;
 * an unresolved variable FAILS THIS ACTION with `blank_variable` — nothing is
 * sent, the chain continues (the automation half of T-06's send-API law;
 * packet P-SEND lands the staff half). Bundled actions do NOT run from rules
 * (they are the INSERTING AGENT's — EC-09; a rule wanting status/priority
 * uses its own actions through their doors).
 */
final class SendMacroReplyAction implements AutomationAction
{
    use ArmSupport;

    public function __construct(
        private readonly VariableResolver $resolver,
        private readonly ComposeOutbound $compose,
    ) {}

    public function run(array $action, string $executionId, int $automationDepth, array $basis = []): array
    {
        $ticket = $this->ticketOf($executionId);
        $macroId = is_string($action['macro_id'] ?? null) ? $action['macro_id'] : '';
        if (! $ticket instanceof Ticket) {
            return ['action' => 'send_macro_reply', 'outcome' => 'failed', 'reason' => 'no_ticket'];
        }
        $body = DB::table('macros')->where('id', $macroId)->where('active', true)->value('body');
        if (! is_string($body)) {
            return ['action' => 'send_macro_reply', 'outcome' => 'failed', 'reason' => 'unknown_macro'];
        }
        $resolved = $this->resolver->resolve($body, $ticket->identifier(), null);
        if ($resolved['unresolved'] !== []) {
            // T-06/BR-04: the blank never leaves the building.
            return ['action' => 'send_macro_reply', 'outcome' => 'failed', 'reason' => MacroGuard::CODE, 'unresolved' => $resolved['unresolved']];
        }
        $conversation = Conversation::query()->find($ticket->getAttribute('conversation_id'));
        if (! $conversation instanceof Conversation) {
            return ['action' => 'send_macro_reply', 'outcome' => 'failed', 'reason' => 'no_conversation'];
        }
        try {
            MacroGuard::assertNoBlank($resolved['text']);
            $this->compose->compose($conversation, 'system', null, 'text', $resolved['text'], 'rule:'.$executionId.':'.$macroId);
        } catch (DomainException $e) {
            return ['action' => 'send_macro_reply', 'outcome' => 'failed', 'reason' => 'send_refused', 'detail' => $e->getMessage()];
        }

        return ['action' => 'send_macro_reply', 'outcome' => 'ok'];
    }
}
