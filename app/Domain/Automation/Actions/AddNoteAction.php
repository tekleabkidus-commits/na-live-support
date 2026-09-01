<?php

declare(strict_types=1);

namespace App\Domain\Automation\Actions;

use App\Domain\Conversations\Notes\NotesWriter;
use App\Models\Conversation;
use DomainException;

/**
 * Doc 12 FR-03 — add_internal_note through Doc 04's NotesWriter SYSTEM voice
 * (the merge-origin note's own writer). N-04 holds by construction: a note is
 * a different type with no adapter path — nothing here could reach a customer.
 */
final class AddNoteAction implements AutomationAction
{
    use ArmSupport;

    public function __construct(private readonly NotesWriter $notes) {}

    public function run(array $action, string $executionId, int $automationDepth, array $basis = []): array
    {
        $ticket = $this->ticketOf($executionId);
        $body = is_string($action['body'] ?? null) ? $action['body'] : '';
        if ($ticket === null) {
            return ['action' => 'add_note', 'outcome' => 'failed', 'reason' => 'no_ticket'];
        }
        $conversation = Conversation::query()->find($ticket->getAttribute('conversation_id'));
        if (! $conversation instanceof Conversation) {
            return ['action' => 'add_note', 'outcome' => 'failed', 'reason' => 'no_conversation'];
        }
        try {
            $this->notes->addSystemNote($conversation, $body);
        } catch (DomainException $e) {
            return ['action' => 'add_note', 'outcome' => 'failed', 'reason' => 'empty_note', 'detail' => $e->getMessage()];
        }

        return ['action' => 'add_note', 'outcome' => 'ok'];
    }
}
