<?php

declare(strict_types=1);

namespace App\Domain\Macros;

use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Events\EventPublisher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Doc 12 FR-14 — the ONE door for macros & saved replies (one storage, `kind`
 * — B12-S03's one-list-with-type-tag). Scopes: personal (owner), team, tenant;
 * the controller maps macros.personal.manage / macros.shared.manage. Bundled
 * actions are a CLOSED chip vocabulary (set_status/set_priority/add_tags —
 * FR-14's own list) rendered as chips so agents know a macro does more than
 * text; their per-capability honesty at insert is BundledActions' (EC-09).
 */
final class MacroWriter
{
    private const BUNDLED_TYPES = ['set_status', 'set_priority', 'add_tags'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly EventPublisher $events,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{name: string, scope: string, kind?: string, body: string, owner_id?: ?string, team_id?: ?string, bundled_actions?: list<array{type: string, value: mixed}>}  $definition
     */
    public function create(array $definition, string $actorId): string
    {
        foreach ($definition['bundled_actions'] ?? [] as $bundled) {
            if (! in_array($bundled['type'], self::BUNDLED_TYPES, true)) {
                throw new InvalidArgumentException('bundled actions are set_status/set_priority/add_tags only (FR-14)');
            }
        }
        // The declared variable set derives from the body — one truth.
        preg_match_all('/\{\{\s*([a-z_.]+[a-z0-9_.]*)\s*\}\}/i', $definition['body'], $matches);
        $macroId = (string) Str::uuid7();
        DB::transaction(function () use ($macroId, $definition, $matches, $actorId): void {
            DB::table('macros')->insert([
                'id' => $macroId, 'tenant_id' => $this->context->tenantId(),
                'scope' => $definition['scope'], 'kind' => $definition['kind'] ?? 'macro',
                'owner_id' => $definition['owner_id'] ?? null, 'team_id' => $definition['team_id'] ?? null,
                'name' => $definition['name'], 'body' => $definition['body'],
                'variables' => json_encode(array_values(array_unique($matches[1])), JSON_THROW_ON_ERROR),
                'bundled_actions' => isset($definition['bundled_actions']) ? json_encode($definition['bundled_actions'], JSON_THROW_ON_ERROR) : null,
            ]);
            $this->announce($macroId, 'created', $actorId);
        });

        return $macroId;
    }

    /** @param array<string, mixed> $changes */
    public function update(string $macroId, array $changes, string $actorId): void
    {
        $update = array_intersect_key($changes, array_flip(['name', 'scope', 'kind', 'owner_id', 'team_id', 'active']));
        if (isset($changes['body']) && is_string($changes['body'])) {
            preg_match_all('/\{\{\s*([a-z_.]+[a-z0-9_.]*)\s*\}\}/i', $changes['body'], $matches);
            $update['body'] = $changes['body'];
            $update['variables'] = json_encode(array_values(array_unique($matches[1])), JSON_THROW_ON_ERROR);
        }
        if (array_key_exists('bundled_actions', $changes)) {
            $update['bundled_actions'] = $changes['bundled_actions'] === null ? null : json_encode($changes['bundled_actions'], JSON_THROW_ON_ERROR);
        }
        if ($update === []) {
            return;
        }
        $update['updated_at'] = DB::raw('clock_timestamp()');
        DB::transaction(function () use ($macroId, $update, $actorId): void {
            if (DB::table('macros')->where('id', $macroId)->update($update) === 0) {
                throw new InvalidArgumentException('unknown macro');
            }
            $this->announce($macroId, 'updated', $actorId);
        });
    }

    private function announce(string $macroId, string $kind, string $actorId): void
    {
        $this->events->publish('macro.changed', ['tenant_id' => $this->context->tenantId(), 'macro_id' => $macroId, 'kind' => $kind]);
        $this->audit->record('macro.changed', 'macro', $macroId, AuditActor::Staff, ['macro_id' => $macroId, 'kind' => $kind], actorId: $actorId);
    }
}
