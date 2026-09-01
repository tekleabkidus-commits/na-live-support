<?php

declare(strict_types=1);

namespace App\Domain\Automation\Actions;

use App\Domain\Tickets\Tags\TicketTags;
use App\Models\Ticket;
use DomainException;

/**
 * Doc 12 FR-03 — add_tags / remove_tags through P-DOORS clause 2's system
 * arms (one class, the direction configured at bind time — the door's own
 * add/remove split preserved).
 */
final class TagsAction implements AutomationAction
{
    use ArmSupport;

    public function __construct(private readonly TicketTags $tags, private readonly bool $adding) {}

    public function run(array $action, string $executionId, int $automationDepth, array $basis = []): array
    {
        $name = $this->adding ? 'add_tags' : 'remove_tags';
        $ticket = $this->ticketOf($executionId);
        /** @var list<string> $list */
        $list = array_values(array_filter(is_array($action['tags'] ?? null) ? $action['tags'] : [], 'is_string'));
        if (! $ticket instanceof Ticket) {
            return ['action' => $name, 'outcome' => 'failed', 'reason' => 'no_ticket'];
        }
        if ($list === []) {
            return ['action' => $name, 'outcome' => 'failed', 'reason' => 'no_tags'];
        }
        try {
            $this->adding
                ? $this->tags->addBySystem($ticket, $list, 'rule:'.$executionId)
                : $this->tags->removeBySystem($ticket, $list, 'rule:'.$executionId);
        } catch (DomainException $e) {
            return ['action' => $name, 'outcome' => 'failed', 'reason' => 'tags_refused', 'detail' => $e->getMessage()];
        }

        return ['action' => $name, 'outcome' => 'ok'];
    }
}
