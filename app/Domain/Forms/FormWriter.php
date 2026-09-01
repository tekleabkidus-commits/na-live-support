<?php

declare(strict_types=1);

namespace App\Domain\Forms;

use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Events\EventPublisher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Doc 12 FR-20 — the form shell (name, entity bindings, active). The DRAFT is
 * the builder's working copy; the server holds only published versions (BR-06:
 * a version exists exactly when it is immutable — FormPublisher mints it).
 * entity_bindings: {department_id, priority?} — completion actions live
 * per-version in the schema (the amendment's own split).
 */
final class FormWriter
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly EventPublisher $events,
        private readonly AuditLogger $audit,
    ) {}

    /** @param array{name: string, entity_bindings?: array<string, mixed>, active?: bool} $definition */
    public function create(array $definition, string $actorId): string
    {
        if (trim($definition['name']) === '') {
            throw new InvalidArgumentException('a form needs a name');
        }
        $formId = (string) Str::uuid7();
        DB::transaction(function () use ($formId, $definition, $actorId): void {
            DB::table('forms')->insert([
                'id' => $formId, 'tenant_id' => $this->context->tenantId(),
                'name' => $definition['name'],
                'entity_bindings' => json_encode($definition['entity_bindings'] ?? [], JSON_THROW_ON_ERROR),
                'active' => $definition['active'] ?? true,
            ]);
            $this->announce($formId, null, 'created', $actorId);
        });

        return $formId;
    }

    /** @param array<string, mixed> $changes */
    public function update(string $formId, array $changes, string $actorId): void
    {
        $update = array_intersect_key($changes, array_flip(['name', 'active']));
        if (isset($changes['entity_bindings']) && is_array($changes['entity_bindings'])) {
            $update['entity_bindings'] = json_encode($changes['entity_bindings'], JSON_THROW_ON_ERROR);
        }
        if ($update === []) {
            return;
        }
        $update['updated_at'] = DB::raw('clock_timestamp()');
        DB::transaction(function () use ($formId, $update, $actorId): void {
            if (DB::table('forms')->where('id', $formId)->update($update) !== 1) {
                throw new InvalidArgumentException('unknown form');
            }
            $this->announce($formId, null, 'updated', $actorId);
        });
    }

    public function announce(string $formId, ?string $versionId, string $kind, string $actorId): void
    {
        $this->events->publish('form.changed', ['tenant_id' => $this->context->tenantId(), 'form_id' => $formId, 'version_id' => $versionId, 'kind' => $kind]);
        $this->audit->record($kind === 'published' ? 'form.published' : 'form.changed', 'form', $formId, AuditActor::Staff, ['form_id' => $formId, 'version_id' => $versionId, 'kind' => $kind], actorId: $actorId);
    }
}
