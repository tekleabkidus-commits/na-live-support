<?php

declare(strict_types=1);

namespace App\Domain\Routing\Strategies;

use Illuminate\Support\Facades\DB;

/**
 * Doc 11 FR-03 / FR-09..FR-11 — the queue's CONFIGURED strategy behind the port: reads
 * queues.assignment_strategy and delegates. Bound as AssignmentStrategy from step 6 (replacing
 * step 5's FirstEligible, which remains the documented tiebreak floor every strategy ends on).
 */
final class ConfiguredStrategy implements AssignmentStrategy
{
    public function __construct(
        private readonly RoundRobin $roundRobin,
        private readonly LoadBased $loadBased,
        private readonly SkillBased $skillBased,
    ) {}

    public function name(): string
    {
        return 'configured';
    }

    public function order(string $queueId, array $routable, array $ticket): array
    {
        $configured = DB::table('queues')->where('id', $queueId)->value('assignment_strategy');
        $strategy = match ($configured) {
            'load_based' => $this->loadBased,
            'skill_based' => $this->skillBased,
            default => $this->roundRobin,
        };
        $out = $strategy->order($queueId, $routable, $ticket);
        $out['note'] = $strategy->name().': '.$out['note'];

        return $out;
    }
}
