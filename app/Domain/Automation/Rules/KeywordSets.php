<?php

declare(strict_types=1);

namespace App\Domain\Automation\Rules;

use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Doc 12 FR-02 — managed, reusable keyword lists. Retire via active=false
 * (NO DELETE by grant — rules reference by id); a condition against a retired
 * set evaluates false and the rule editor surfaces the warning (the EC-08
 * pattern applied to sets).
 */
final class KeywordSets
{
    // Audited, deliberately not evented: the EVENT-FAMILY AMENDMENT lists no
    // keyword-set event (sets are rule INPUT content; automation.rule.changed
    // covers the config surface Doc 15 reads) — stated, not slipped.
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    /** @param list<string> $keywords */
    public function create(string $name, array $keywords, string $actorId): string
    {
        $this->assertKeywords($keywords);
        $id = (string) Str::uuid7();
        DB::transaction(function () use ($id, $name, $keywords, $actorId): void {
            DB::table('automation_keyword_sets')->insert([
                'id' => $id, 'tenant_id' => $this->context->tenantId(),
                'name' => $name, 'keywords' => json_encode($keywords, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ]);
            $this->announce($id, $actorId);
        });

        return $id;
    }

    /** @param array{name?: string, keywords?: list<string>, active?: bool} $changes */
    public function update(string $id, array $changes, string $actorId): void
    {
        if (isset($changes['keywords'])) {
            $this->assertKeywords($changes['keywords']);
            $changes['keywords'] = json_encode($changes['keywords'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }
        $update = array_intersect_key($changes, array_flip(['name', 'keywords', 'active']));
        if ($update === []) {
            return;
        }
        $update['updated_at'] = DB::raw('clock_timestamp()');
        DB::transaction(function () use ($id, $update, $actorId): void {
            if (DB::table('automation_keyword_sets')->where('id', $id)->update($update) === 0) {
                throw new InvalidArgumentException('unknown keyword set');
            }
            $this->announce($id, $actorId);
        });
    }

    /** @param list<string> $keywords */
    private function assertKeywords(array $keywords): void
    {
        if ($keywords === [] || array_filter($keywords, static fn (string $k): bool => trim($k) === '') !== []) {
            throw new InvalidArgumentException('a keyword set is a non-empty list of non-empty strings (FR-02)');
        }
    }

    private function announce(string $id, string $actorId): void
    {
        // SEC-03: sets may embed sensitive patterns — the audit carries the id, never the list.
        $this->audit->record('automation.keyword_set.changed', 'automation_keyword_set', $id, AuditActor::Staff, ['keyword_set_id' => $id], actorId: $actorId);
    }
}
