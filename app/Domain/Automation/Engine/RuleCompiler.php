<?php

declare(strict_types=1);

namespace App\Domain\Automation\Engine;

use App\Support\Cache\TenantCache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Doc 12 T-02 / FS-03 / NFR-01 — active rules per trigger, compiled to a cached
 * decision structure; invalidated on activation (RuleWriter calls invalidate()).
 *
 * FS-03: a corrupt cache entry falls back to INTERPRETIVE evaluation (the same
 * query, uncached — slower, correct) with a loud log; recompile heals on the
 * next pass. The cache is never the source of truth (C-07): the query IS the
 * compiler; the cache only remembers its answer.
 */
final class RuleCompiler
{
    private const TTL_SECONDS = 3600; // C-07 mandatory TTL; activation invalidates sooner

    public function __construct(private readonly TenantCache $cache) {}

    /**
     * @return list<array{id: string, revision_id: string, position: int, cooldown_seconds: int, conditions: array<string, mixed>, actions: list<array<string, mixed>>}>
     */
    public function activeRulesFor(string $trigger): array
    {
        $suffix = 'automation:compiled:'.$trigger;
        try {
            /** @var mixed $cached */
            $cached = $this->cache->remember($suffix, self::TTL_SECONDS, 'automation.rule.changed(activated)', fn (): array => $this->compile($trigger));
            if (is_array($cached) && $this->wellFormed($cached)) {
                /** @var list<array{id: string, revision_id: string, position: int, cooldown_seconds: int, conditions: array<string, mixed>, actions: list<array<string, mixed>>}> $cached */
                return $cached;
            }
            Log::error('automation rule cache corrupt — interpretive fallback (FS-03)', ['trigger' => $trigger]);
            $this->cache->forget($suffix);
        } catch (\Throwable $e) {
            Log::error('automation rule cache unavailable — interpretive fallback (FS-03)', ['trigger' => $trigger, 'error' => $e->getMessage()]);
        }

        return $this->compile($trigger);
    }

    public function invalidate(string $trigger): void
    {
        $this->cache->forget('automation:compiled:'.$trigger);
    }

    /**
     * @return list<array{id: string, revision_id: string, position: int, cooldown_seconds: int, conditions: array<string, mixed>, actions: list<array<string, mixed>>}>
     */
    private function compile(string $trigger): array
    {
        /** @var list<object{id: string, active_revision_id: ?string, position: int, cooldown_seconds: int, definition: ?string}> $rows */
        $rows = DB::select(
            'SELECT r.id, r.active_revision_id, r.position, r.cooldown_seconds, v.definition
             FROM automation_rules r
             LEFT JOIN automation_rule_revisions v ON v.id = r.active_revision_id
             WHERE r.trigger = ? AND r.active
             ORDER BY r.position, r.id',
            [$trigger],
        );
        $out = [];
        foreach ($rows as $row) {
            if ($row->active_revision_id === null || $row->definition === null) {
                continue; // active without a revision cannot evaluate — the writer prevents it
            }
            /** @var array{conditions?: array<string, mixed>, actions?: list<array<string, mixed>>} $definition */
            $definition = json_decode($row->definition, true, 32, JSON_THROW_ON_ERROR);
            $out[] = [
                'id' => $row->id,
                'revision_id' => $row->active_revision_id,
                'position' => $row->position,
                'cooldown_seconds' => $row->cooldown_seconds,
                'conditions' => $definition['conditions'] ?? ['all' => []],
                'actions' => $definition['actions'] ?? [],
            ];
        }

        return $out;
    }

    /** @param array<int|string, mixed> $compiled */
    private function wellFormed(array $compiled): bool
    {
        foreach ($compiled as $entry) {
            if (! is_array($entry) || ! is_string($entry['id'] ?? null) || ! is_array($entry['actions'] ?? null)) {
                return false;
            }
        }

        return array_is_list($compiled);
    }
}
