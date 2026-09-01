<?php

declare(strict_types=1);

namespace App\Domain\Forms;

use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Events\EventPublisher;
use App\Support\Tenancy\TenantContext;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Doc 12 FR-22 / BR-06 / SS-03 — submissions snapshot {version_id + answers}
 * into Doc 06's `tickets.form_data` (A-59: the ticket IS the submission
 * record; no rival table). Schema-drift-proof: the snapshot names its exact
 * version forever. Server is truth for conditional logic (T-07): a COMPLETE
 * submission must carry every required-and-visible answer; complete=false is
 * SS-03's mid-form handoff — partial data lands flagged incomplete, and the
 * form.submitted event says so (Doc 09 FR-12's packet reads it).
 * The `custom` key (ValueWriter's territory) is preserved, never clobbered.
 */
final class SubmissionWriter
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly VersionReader $versions,
        private readonly EventPublisher $events,
        private readonly AuditLogger $audit,
    ) {}

    /** @param array<string, mixed> $answers */
    public function record(string $ticketId, string $versionId, array $answers, string $channel, bool $complete = true): void
    {
        $contract = $this->versions->widgetContract($versionId);
        if ($complete) {
            $missing = $this->missingRequired($contract['schema'], $answers);
            if ($missing !== []) {
                throw new DomainException('missing_required: '.implode(',', $missing));
            }
        }
        $snapshot = json_encode(['version_id' => $versionId, 'answers' => $answers, 'complete' => $complete], JSON_THROW_ON_ERROR);
        DB::transaction(function () use ($ticketId, $versionId, $snapshot, $contract, $channel, $complete): void {
            $updated = DB::update(
                "UPDATE tickets SET form_data = COALESCE(form_data, '{}'::jsonb) || ?::jsonb, updated_at = clock_timestamp() WHERE id = ?",
                [$snapshot, $ticketId],
            );
            if ($updated !== 1) {
                throw new DomainException('unknown ticket');
            }
            $this->events->publish('form.submitted', [
                'tenant_id' => $this->context->tenantId(), 'form_id' => $contract['form_id'],
                'version_id' => $versionId, 'ticket_id' => $ticketId,
                'channel' => $channel, 'complete' => $complete,
            ]);
            $this->audit->record('form.submitted', 'ticket', $ticketId, AuditActor::Customer, ['version_id' => $versionId, 'channel' => $channel, 'complete' => $complete]);
        });
    }

    /**
     * The server-truth visibility walk (T-07): required-and-VISIBLE fields
     * only — EC-07 guarantees a hidden field is never a silent requirement.
     *
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $answers
     * @return list<string>
     */
    public function missingRequired(array $schema, array $answers): array
    {
        $missing = [];
        foreach (is_array($schema['steps'] ?? null) ? $schema['steps'] : [] as $step) {
            if (! is_array($step) || ! is_array($step['fields'] ?? null)) {
                continue;
            }
            if (is_array($step['visible_when'] ?? null) && ! ConditionEval::holds($step['visible_when'], $answers)) {
                continue;
            }
            foreach ($step['fields'] as $field) {
                if (! is_array($field) || ($field['required'] ?? false) !== true) {
                    continue;
                }
                if (! ConditionEval::visible($field, $answers)) {
                    continue;
                }
                $key = ConditionEval::keyOf($field);
                $value = $key !== null ? ($answers[$key] ?? null) : null;
                if ($key !== null && ($value === null || $value === '' || $value === [])) {
                    $missing[] = $key;
                }
            }
        }

        return $missing;
    }
}
