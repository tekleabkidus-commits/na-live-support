<?php

declare(strict_types=1);

namespace App\Domain\Tickets\Creation;

/**
 * FR-01's creation-path enum, mirrored by the `tickets_source_check` CHECK.
 *
 * WHY IT IS RECORDED AT ALL (FR-13: "source recorded"). Doc 15 needs to tell an AI
 * handoff from an agent-typed ticket without inferring it, and the four paths have
 * genuinely different validation shapes — a handoff arrives with a packet, an offline
 * form arrives with form data and no live conversation partner, an API create arrives
 * with an idempotency key. Recording the source is what lets one validation path
 * serve all of them (FR-13's "same validation path") without any of them lying about
 * where it came from.
 */
enum TicketSource: string
{
    case AiHandoff = 'ai_handoff';
    case Agent = 'agent';
    case Automation = 'automation';
    case Api = 'api';
    case OfflineForm = 'offline_form';
}
