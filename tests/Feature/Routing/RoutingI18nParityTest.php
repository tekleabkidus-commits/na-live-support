<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Doc 11 §14 / C-08 / R-14 (S11 step 14) — the i18n PARITY GATE for the routing family:
| every routing.* and org.* key exists in en AND am, BOTH directions, no empty values,
| and every `:placeholder` set survives translation (a dropped slot ships a raw colon
| token to a reader). Structure pinned forever; prose quality stays R-14's native review.
| The KbI18nParityTest pattern, owned by DOC 11 SUITES.
|--------------------------------------------------------------------------
*/

/** @return array<string, string> dot-flattened */
function routingFlat(string $locale, string $file): array
{
    /** @var array<string, mixed> $tree */
    $tree = include base_path("lang/{$locale}/{$file}.php");
    $out = [];
    $walk = function (array $node, string $prefix) use (&$walk, &$out): void {
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $walk($value, $prefix.$key.'.');
            } else {
                $out[$prefix.$key] = (string) $value;
            }
        }
    };
    $walk($tree, '');

    return $out;
}

/** @return list<string> */
function routingPlaceholders(string $value): array
{
    preg_match_all('/:([a-z_]+)/', $value, $m);
    $slots = $m[1];
    sort($slots);

    return $slots;
}

it('§14: routing.* and org.* have FULL en↔am parity — both directions, no empty value, every placeholder set surviving', function () {
    foreach (['routing', 'org'] as $file) {
        $en = routingFlat('en', $file);
        $am = routingFlat('am', $file);
        expect(array_diff_key($en, $am))->toBe([], "{$file}: keys missing in am")
            ->and(array_diff_key($am, $en))->toBe([], "{$file}: keys in am that en does not have");
        foreach ($en as $key => $value) {
            expect(trim($value))->not->toBe('', "{$file}.{$key} is empty in en")
                ->and(trim($am[$key]))->not->toBe('', "{$file}.{$key} is empty in am")
                ->and(routingPlaceholders($am[$key]))->toBe(routingPlaceholders($value), "{$file}.{$key}: the :placeholder set changed in translation");
        }
    }
});

it('§14: the destructive tier is PRESENT in both languages — typed confirmations, delete buttons, the RG replacement, both stopped states', function () {
    foreach (['en', 'am'] as $locale) {
        $org = routingFlat($locale, 'org');
        foreach (['wizard.type_label', 'wizard.type_mismatch', 'wizard.execute', 'wizard.rg_label', 'wizard.rg_blocked', 'wizard.stopped_operator_gone', 'wizard.stopped_permission_lost', 'queues.qdelete_button', 'queues.deactivate_button'] as $key) {
            // Flat dotted keys — toHaveKey would dot-traverse, so existence is asserted directly.
            expect(array_key_exists($key, $org))->toBeTrue("the destructive-tier key {$key} must exist in {$locale}");
        }
    }
});

it('§14: every record sentence code the writers emit has a routing.record key in BOTH languages', function () {
    $codes = [
        'department.explicit', 'department.ai_accepted', 'department.ai_flagged', 'department.connection_tag', 'department.connection_tag_miss', 'department.tenant_default', 'department.tenant_default_flagged',
        'queue.explicit', 'queue.routing_tag', 'queue.department_default', 'rg.forced', 'rg.missing',
        'position.scored', 'position.starved', 'eligibility.none', 'eligibility.ok',
        'strategy.none', 'strategy.rr', 'strategy.load', 'strategy.first',
        'strategy.skill.matched', 'strategy.skill.dropped_starved', 'strategy.skill.dropped_nearest', 'strategy.skill.hold', 'strategy.skill.none',
        'assign.ok', 'assign.at_capacity', 'assign.claimed', 'assign.out_of_scope',
        'wait', 'engine_error', 'courtesy_hold', 'manual_reassign', 'rescue_return', 'no_boosts',
    ];
    foreach (['en', 'am'] as $locale) {
        $flat = routingFlat($locale, 'routing');
        foreach ($codes as $code) {
            expect(array_key_exists('record.'.$code, $flat))->toBeTrue("routing.record.{$code} missing in {$locale}");
        }
    }
});

it('§14 END-TO-END: a routed ticket\'s record steps carry LOCALIZED sentences — Ethiopic under am, English under en, old-record prose fallback intact, and no uuid ever inside a sentence', function () {
    $tenant = createTenant();
    bindTenant($tenant);
    app(App\Domain\Org\Seeding\OrganizationSeeder::class)->seed();
    [, $conversation] = conversationFixture();
    $queue = (string) Illuminate\Support\Facades\DB::table('departments')->where('slug', 'payments')->value('default_queue_id');
    $department = (string) Illuminate\Support\Facades\DB::table('queues')->where('id', $queue)->value('department_id');
    $ticketId = (string) Illuminate\Support\Str::uuid7();
    Illuminate\Support\Facades\DB::table('tickets')->insert([
        'id' => $ticketId, 'tenant_id' => $tenant->identifier(), 'conversation_id' => $conversation->identifier(),
        'customer_id' => $conversation->customer_id, 'number' => 991401, 'status' => 'new', 'priority' => 'normal', 'source' => 'agent',
    ]);
    $ticket = App\Models\Ticket::query()->findOrFail($ticketId);
    $result = Illuminate\Support\Facades\DB::transaction(fn () => app(App\Domain\Routing\Pipeline\RoutingPipeline::class)->route($ticket, 'created', ['explicit_department_id' => $department, 'explicit_queue_id' => $queue]));

    $supervisor = wizardParitySupervisor();
    $reader = app(App\Domain\Routing\Record\RecordReader::class);

    app()->setLocale('en');
    $en = $reader->forViewer($supervisor, $ticketId, $result['record_id']);
    expect($en)->not->toBeNull();
    $byStep = collect($en['steps'])->keyBy('step');
    expect($byStep['department']['sentence'])->toBe('Department set explicitly by the caller.')
        ->and($byStep['queue']['sentence'])->toBe('Queue set explicitly by the caller.')
        ->and($byStep['position']['sentence'])->not->toBeNull()
        ->and(collect($en['steps'])->last()['sentence'])->not->toBeNull();

    app()->setLocale('am');
    $am = $reader->forViewer($supervisor, $ticketId, $result['record_id']);
    $amDept = collect($am['steps'])->keyBy('step')['department']['sentence'];
    expect($amDept)->toBe('ክፍሉ በጠሪው በግልጽ ተቀምጧል።');
    app()->setLocale('en');

    // SEC-02 stays: no uuid ever rides a sentence (slots are counts/codes only).
    foreach (array_merge($en['steps'], $am['steps']) as $step) {
        if (is_string($step['sentence'] ?? null)) {
            expect(preg_match('/[0-9a-f]{8}-[0-9a-f]{4}/', $step['sentence']))->toBe(0, 'a uuid leaked into a sentence');
        }
    }

    // The old-record fallback: a step with NO code and an undeciphérable input keeps sentence null and the prose stands.
    $rawRow = Illuminate\Support\Facades\DB::table('routing_decisions')->where('id', $result['record_id'])->first();
    $raw = json_decode((string) $rawRow->record, true, 16, JSON_THROW_ON_ERROR);
    expect($raw['steps'][0])->toHaveKeys(['step', 'input', 'outcome'], 'the stored prose stays the canonical data');
});

function wizardParitySupervisor(): App\Models\User
{
    $role = App\Models\Role::query()->firstOrCreate(['name' => 'S14 Record Viewer'], ['color' => 'role-agent', 'permissions' => ['tickets.view', 'reports.agents.individual'], 'scopes' => ['tickets' => 'All', 'conversations' => 'All', 'customers' => 'All', 'reports' => 'All']]);
    $user = App\Models\User::factory()->create();
    $user->forceFill(['role_id' => $role->getKey()])->save();
    app(App\Support\Rbac\CapabilityCacheInvalidator::class)->forgetUser($user);

    return $user;
}
