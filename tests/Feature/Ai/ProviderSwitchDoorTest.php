<?php

declare(strict_types=1);

use App\Domain\Ai\Orchestrator\AiOrchestrator;
use App\Domain\Rbac\RoleSeeder;
use App\Domain\Rbac\RoleTemplates;
use App\Models\Role;
use App\Support\Rbac\PermissionCatalog;
use Illuminate\Support\Facades\DB;

/**
 * S10 step 0.4 — `ai.providers.manage`, the FOURTH addition of record (Doc 09
 * FR-02's change-log key; R-D10-1 owner-signed, amended at the S10 plan-approval
 * rulings: the switch DOOR binds here, the B8-S08 screen is the [B8] pass's).
 * Proves: the executed catalog count is 114 and the key is HIGH (39); SA + Admin
 * hold it by their computed rules and no other template does; the door refuses
 * without the key (403), answers the model map, switches a tier through
 * ConfigVersions (zero deploy — the orchestrator's chain changes at once), audits
 * ai.providers.switched with the OLD and NEW chains + actor (SEC-05), and refuses
 * an unknown provider with a Problem (422).
 */
function providerActor(array $keys): App\Models\User
{
    return grantPermissions(App\Models\User::factory()->create(), array_merge(['tickets.view'], $keys), 'S10 providers '.implode(',', $keys));
}

it('R-D10-1: the catalog is 114 by EXECUTED count, ai.providers.manage is HIGH, and only Super Admin + Admin seed it', function (): void {
    $catalog = app(PermissionCatalog::class);
    expect(count(require base_path('registries/permissions.php')))->toBe(114)
        ->and($catalog->get('ai.providers.manage')->high)->toBeTrue();

    $tenant = createTenant();
    asTenant($tenant, function (): void {
        app(RoleSeeder::class)->seed();
        $roles = Role::query()->get()->keyBy('name');
        foreach (RoleTemplates::NAMES as $name) {
            $holds = in_array('ai.providers.manage', $roles[$name]->permissions, true);
            expect($holds)->toBe(in_array($name, ['Super Admin', 'Admin'], true), "template {$name}");
        }
    });
});

it('the switch door: 403 without the key; GET answers the model map; POST switches a tier through ConfigVersions (chain changes instantly) and audits ai.providers.switched old/new; an unknown provider is a 422 Problem', function (): void {
    [$tenant, $tenantId, $conversation, $ticket, $actor] = copilotScene();

    $other = providerActor(['ai.chatbot.configure', 'ai.thresholds.edit']);
    $admin = providerActor(['ai.providers.manage']);
    $this->actingAs($other, 'staff')->getJson('https://localhost/admin/ai/providers')->assertStatus(403);
    $this->actingAs($other, 'staff')->postJson('https://localhost/admin/ai/providers/routing', ['tiers' => []])->assertStatus(403);

    bindTenant($tenant);
    $map = $this->actingAs($admin, 'staff')->getJson('https://localhost/admin/ai/providers');
    $map->assertOk();
    expect($map->json('tiers.strong.0.provider'))->toBe('addis')
        ->and($map->json('providers'))->toContain('fake')
        ->and($map->json('published'))->toBeNull();

    $switched = $this->actingAs($admin, 'staff')->postJson('https://localhost/admin/ai/providers/routing', ['tiers' => [
        'fast' => [['provider' => 'addis', 'model' => 'addis-አሌፍ-፩']],
        'strong' => [['provider' => 'fake', 'model' => 'fixture-strong'], ['provider' => 'addis', 'model' => 'addis-አሌፍ-፩']],
        'embeddings' => [['provider' => 'local-embeddings', 'model' => 'bge-m3']],
    ]]);
    $switched->assertOk();
    $versionId = $switched->json('version_id');

    asTenant($tenant, function () use ($versionId): void {
        expect(DB::table('ai_config_versions')->where('id', $versionId)->value('status'))->toBe('published')
            ->and(app(AiOrchestrator::class)->chainFor('strong'))->toBe([
                ['provider' => 'fake', 'model' => 'fixture-strong'],
                ['provider' => 'addis', 'model' => 'addis-አሌፍ-፩'],
            ]);
        $audit = DB::table('audit_log')->where('action_key', 'ai.providers.switched')->orderByDesc('occurred_at')->first();
        expect($audit)->not->toBeNull();
        $payload = json_decode((string) $audit->payload, true);
        expect($payload['old']['strong'][0]['provider'])->toBe('addis')
            ->and($payload['new']['strong'][0]['provider'])->toBe('fake')
            ->and($audit->actor_id)->not->toBeNull();
        expect(DB::table('audit_log')->where('action_key', 'ai.config.published')->where('entity_id', $versionId)->exists())->toBeTrue();
    });

    $bad = $this->actingAs($admin, 'staff')->postJson('https://localhost/admin/ai/providers/routing', ['tiers' => [
        'fast' => [['provider' => 'openai', 'model' => 'gpt']], 'strong' => [['provider' => 'addis', 'model' => 'x']], 'embeddings' => [['provider' => 'addis', 'model' => 'x']],
    ]]);
    $bad->assertStatus(422);
    expect($bad->json('code'))->toBe('validation_failed');
});
