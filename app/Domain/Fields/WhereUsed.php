<?php

declare(strict_types=1);

namespace App\Domain\Fields;

use Illuminate\Support\Facades\DB;

/**
 * Doc 12 EC-14 / §8 — the where-used panel's COMPLETE answer: forms whose
 * versioned schemas reference the field key, rules whose condition trees read
 * its fact, and saved views filtering on it (Doc 07's filter vocabulary knows
 * no custom keys today — the views slice answers from the filters' own text so
 * it lights up the day they do; honesty by construction, not a stub).
 */
final class WhereUsed
{
    /**
     * @return array{forms: list<array{id: string, name: string}>, rules: list<array{id: string, name: string}>, views: list<array{id: string, name: ?string}>}
     */
    public function forField(string $entity, string $key): array
    {
        $fact = sprintf('"%s.custom.%s"', $entity, $key);

        /** @var list<object{id: string, name: string}> $forms */
        $forms = DB::select(
            'SELECT DISTINCT f.id, f.name FROM forms f
             JOIN form_versions v ON v.form_id = f.id
             WHERE v.schema::text LIKE ? ORDER BY f.name',
            ['%"'.$key.'"%'],
        );
        /** @var list<object{id: string, name: string}> $rules */
        $rules = DB::select(
            'SELECT DISTINCT r.id, r.name FROM automation_rules r
             JOIN automation_rule_revisions v ON v.id = r.active_revision_id
             WHERE r.active AND v.definition::text LIKE ? ORDER BY r.name',
            ['%'.$fact.'%'],
        );
        /** @var list<object{id: string, name: ?string}> $views */
        $views = DB::select(
            'SELECT id, name FROM inbox_saved_views WHERE filters::text LIKE ? ORDER BY id',
            ['%"'.$key.'"%'],
        );

        return [
            'forms' => array_map(static fn (object $r): array => ['id' => $r->id, 'name' => $r->name], $forms),
            'rules' => array_map(static fn (object $r): array => ['id' => $r->id, 'name' => $r->name], $rules),
            'views' => array_map(static fn (object $r): array => ['id' => $r->id, 'name' => $r->name], $views),
        ];
    }
}
