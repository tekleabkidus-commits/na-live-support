<?php

declare(strict_types=1);

namespace App\Domain\Macros;

use App\Models\User;
use App\Support\Events\EventPublisher;
use Illuminate\Support\Facades\DB;

/**
 * Doc 12 FR-16 / B12-S03 (THE BINDING SEAM) — the palette CONTRACT's data:
 *
 *   - `/` search across scopes, PERSONAL FIRST (the drawn law), fuzzy
 *     (letters match anywhere in the name), one list with the kind tag;
 *   - preview = LIVE resolution against THIS ticket through the ONE resolver
 *     (per-variable ✓/missing states — the warning treatment's data);
 *   - INSERT NEVER SENDS: this reader holds no send capability of any kind —
 *     insertion hands text to the composer, and the unresolved-pill guard
 *     (MacroGuard) is what the send API refuses on;
 *   - usage counts recorded per macro (ranking here; Doc 15 reads macro.used).
 */
final class PaletteReader
{
    public function __construct(
        private readonly VariableResolver $resolver,
        private readonly EventPublisher $events,
    ) {}

    /**
     * @return list<array{id: string, name: string, kind: string, scope: string, usage_count: int, bundled: bool}>
     */
    public function search(User $agent, string $query = ''): array
    {
        /** @var list<object{id: string, name: string, kind: string, scope: string, usage_count: int|string, bundled_actions: ?string, owner_id: ?string}> $rows */
        $rows = DB::select(
            "SELECT id, name, kind, scope, usage_count, bundled_actions, owner_id FROM macros
             WHERE active AND (scope <> 'personal' OR owner_id = ?)
             ORDER BY (scope = 'personal') DESC, usage_count DESC, name",
            [$agent->identifier()],
        );
        $needle = mb_strtolower(trim($query));
        $out = [];
        foreach ($rows as $row) {
            if ($needle !== '' && ! $this->fuzzyMatches($needle, mb_strtolower($row->name))) {
                continue;
            }
            $out[] = [
                'id' => $row->id, 'name' => $row->name, 'kind' => $row->kind,
                'scope' => $row->scope, 'usage_count' => (int) $row->usage_count,
                'bundled' => is_string($row->bundled_actions),
            ];
        }

        return $out;
    }

    /**
     * The preview pane: live resolution against the ticket (B12-S03's per-variable states).
     *
     * @return array{text: string, variables: array<string, ?string>, unresolved: list<string>}
     */
    public function preview(string $macroId, string $ticketId, User $agent): array
    {
        $body = DB::table('macros')->where('id', $macroId)->value('body');
        if (! is_string($body)) {
            throw new \InvalidArgumentException('unknown macro');
        }

        return $this->resolver->resolve($body, $ticketId, $agent->identifier());
    }

    /** FR-16: an insertion records usage (ranking + Doc 15's macro.used). */
    public function recordUse(string $macroId, string $ticketId): void
    {
        $tenantId = DB::table('macros')->where('id', $macroId)->value('tenant_id');
        if (! is_string($tenantId)) {
            throw new \InvalidArgumentException('unknown macro');
        }
        DB::transaction(function () use ($macroId, $ticketId, $tenantId): void {
            DB::table('macros')->where('id', $macroId)->increment('usage_count');
            $this->events->publish('macro.used', [
                'tenant_id' => $tenantId,
                'macro_id' => $macroId, 'ticket_id' => $ticketId,
            ]);
        });
    }

    /** B12-S03: "Letters match anywhere in the name" — subsequence, not substring. */
    private function fuzzyMatches(string $needle, string $haystack): bool
    {
        $pos = 0;
        $length = mb_strlen($needle);
        for ($i = 0; $i < $length; $i++) {
            $char = mb_substr($needle, $i, 1);
            $found = mb_strpos($haystack, $char, $pos);
            if ($found === false) {
                return false;
            }
            $pos = $found + 1;
        }

        return true;
    }
}
