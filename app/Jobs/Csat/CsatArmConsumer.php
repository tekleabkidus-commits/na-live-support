<?php

declare(strict_types=1);

namespace App\Jobs\Csat;

use App\Domain\Csat\SurveyArmer;
use App\Jobs\OutboxConsumer;

/**
 * Doc 12 FR-24 (ADR 0011) — the durable CSAT arming consumer for BOTH
 * resolution paths (ticket.resolved, ai.resolved). Carries only the event id;
 * every fact re-read from rows; idempotent by the (tenant,
 * resolution_event_id) unique — a re-drive answers already_armed and changes
 * nothing (C-09).
 */
final class CsatArmConsumer extends OutboxConsumer
{
    public function handle(SurveyArmer $armer): void
    {
        $armer->armFromEvent($this->eventId);
    }

    protected function queueFamily(): string
    {
        return 'automation';
    }
}
