<?php

declare(strict_types=1);

namespace App\Domain\Macros;

use App\Domain\Tickets\Priority\ChangePriority;
use App\Domain\Tickets\Tags\TicketTags;
use App\Domain\Tickets\Transitions\TicketStatus;
use App\Domain\Tickets\Transitions\TicketTransitionService;
use App\Domain\Tickets\Transitions\TransitionContext;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Rbac\CapabilityMapProvider;

/**
 * Doc 12 FR-14 / EC-09 — bundled actions applied on insert-and-send, with
 * PER-CAPABILITY HONESTY: the text inserts regardless; each bundled action
 * runs THROUGH ITS OWNING DOOR as the INSERTING AGENT and, where the agent
 * lacks the capability, is SKIPPED with an inline notice — no silent
 * privilege, no silent drop (EC-09's own words). Doc 07's composer renders
 * the outcome list.
 */
final class BundledActions
{
    public function __construct(
        private readonly CapabilityMapProvider $capabilities,
        private readonly TicketTransitionService $transitions,
        private readonly ChangePriority $priority,
        private readonly TicketTags $tags,
    ) {}

    /**
     * @param  list<array{type: string, value: mixed}>  $bundled
     * @return list<array{type: string, outcome: string, notice_key?: string}>
     */
    public function applyOnSend(Ticket $ticket, array $bundled, User $agent): array
    {
        $map = $this->capabilities->mapFor($agent);
        $out = [];
        foreach ($bundled as $action) {
            $needed = match ($action['type']) {
                'set_status' => 'tickets.status.change',
                'set_priority' => 'tickets.priority.change',
                'add_tags' => 'tickets.tags.manage',
                default => null,
            };
            if ($needed === null || ! $map->allows($needed)) {
                // EC-09: text inserted, the bundled action skipped WITH a notice.
                $out[] = ['type' => (string) $action['type'], 'outcome' => 'skipped', 'notice_key' => 'workflow.macros.bundled_skipped'];

                continue;
            }
            $value = is_string($action['value']) ? $action['value'] : '';
            try {
                match ($action['type']) {
                    'set_status' => $this->transitions->transition($ticket->fresh() ?? $ticket, TicketStatus::from($value), TransitionContext::staff($agent)),
                    'set_priority' => $this->priority->change($ticket->fresh() ?? $ticket, $value, $agent),
                    'add_tags' => $this->tags->add($ticket->fresh() ?? $ticket, array_values(array_filter((array) $action['value'], 'is_string')), $agent),
                };
                $out[] = ['type' => (string) $action['type'], 'outcome' => 'applied'];
            } catch (\Throwable $e) {
                $out[] = ['type' => (string) $action['type'], 'outcome' => 'skipped', 'notice_key' => 'workflow.macros.bundled_failed'];
            }
        }

        return $out;
    }
}
