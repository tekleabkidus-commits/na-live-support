<?php

declare(strict_types=1);

namespace App\Domain\Forms;

use Illuminate\Support\Facades\DB;

/**
 * Doc 12 FR-22 — "usage banner shows bindings before publish": everything that
 * already points at this form. Bot menus (Doc 05 menu versions whose active
 * definition targets the form), automation rules (active revisions referencing
 * the form id — form.submitted conditions or open-form actions), and widget
 * shortcuts (Doc 13's surface — the key exists so the contract is stable; it
 * fills when [D13] lands its shortcut storage).
 */
final class UsageBanner
{
    /** @return array{bot_menus: list<array{id: string, name: string}>, rules: list<array{id: string, name: string}>, widget_shortcuts: list<array{id: string, name: string}>} */
    public function forForm(string $formId): array
    {
        /** @var list<object{id: string, name: string}> $menus */
        $menus = DB::select(
            'SELECT DISTINCT m.id, (b.username || \' (\' || m.language || \')\') AS name
             FROM telegram_menus m
             JOIN telegram_bots b ON b.id = m.bot_id
             JOIN telegram_menu_versions v ON v.id = m.active_version_id
             WHERE v.definition::text LIKE ? ORDER BY name',
            ['%"'.$formId.'"%'],
        );
        /** @var list<object{id: string, name: string}> $rules */
        $rules = DB::select(
            'SELECT DISTINCT r.id, r.name FROM automation_rules r
             JOIN automation_rule_revisions v ON v.id = r.active_revision_id
             WHERE r.active AND v.definition::text LIKE ? ORDER BY r.name',
            ['%"'.$formId.'"%'],
        );

        return [
            'bot_menus' => array_map(static fn (object $r): array => ['id' => $r->id, 'name' => $r->name], $menus),
            'rules' => array_map(static fn (object $r): array => ['id' => $r->id, 'name' => $r->name], $rules),
            'widget_shortcuts' => [],
        ];
    }
}
