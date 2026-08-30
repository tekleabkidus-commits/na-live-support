<?php

declare(strict_types=1);

namespace App\Jobs\Org;

use App\Domain\Org\Restructure\ChunkedMover;
use App\Domain\Org\Restructure\DeleteDepartmentWizard;
use App\Domain\Org\Restructure\DeleteQueueWizard;
use App\Domain\Org\Restructure\RestructureRefused;
use App\Models\User;
use App\Support\Tenancy\RunsWithinTenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * Doc 11 FR-18 / NFR-05 / SEC-04 — the wizard's EXECUTION as a queued job (the CustomerExportJob
 * shape): tenant-bound, the OPERATOR ADMIN re-resolved from the row at run time and again per
 * chunk inside ChunkedMover (Doc 03 SEC-06 job semantics — a demoted operator's queued restructure
 * stops under post-demotion rights). A refusal is an OUTCOME written to the progress row
 * (`stopped_*`), never a silent no-op. `automation` family.
 */
final class RestructureJob implements ShouldQueue
{
    use Queueable, RunsWithinTenant;

    public int $tries = 1;

    /** @param  array<string, mixed>  $choices */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $wizard,
        public readonly string $targetId,
        public readonly array $choices,
        public readonly string $typedConfirmation,
        public readonly string $operatorId,
    ) {
        $this->onQueue('automation');
    }

    public function handle(): void
    {
        $this->withinTenant($this->tenantId, function (): void {
            $operator = User::query()->find($this->operatorId);
            $mover = app(ChunkedMover::class);
            if (! $operator instanceof User) {
                $mover->progress($this->wizard, $this->targetId, 0, 0, 0, 'stopped_operator_gone');

                return;
            }
            try {
                match ($this->wizard) {
                    DeleteQueueWizard::WIZARD => app(DeleteQueueWizard::class)->execute($this->targetId, $this->choices, $this->typedConfirmation, $operator),
                    DeleteDepartmentWizard::WIZARD => app(DeleteDepartmentWizard::class)->execute($this->targetId, $this->choices, $this->typedConfirmation, $operator),
                    default => throw new RestructureRefused('wizard_unknown', $this->wizard),
                };
            } catch (RestructureRefused $e) {
                $mover->progress($this->wizard, $this->targetId, 0, 0, 0, 'stopped_'.$e->refusalCode);
                Log::warning('restructure stopped', ['wizard' => $this->wizard, 'target_id' => $this->targetId, 'refusal_code' => $e->refusalCode, 'metric' => 'org.restructure.stopped']);
            }
        });
    }
}
