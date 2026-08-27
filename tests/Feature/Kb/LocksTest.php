<?php

declare(strict_types=1);

use App\Domain\Kb\Editor\Locks;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/KbSceneHelpers.php';

/**
 * Doc 10 FR-05 / T-07 / FS-05 (S10 step 3): first acquire wins (SET NX EX in
 * redis-volatile), a second editor gets 409 kb_lock_held naming the holder, the
 * heartbeat refreshes only for the holder, takeover overwrites and audits BOTH
 * parties, release frees it; Redis down → {mode: optimistic}, never a 500.
 */
it('locks: first acquire wins, the second editor is refused naming the holder, heartbeat is holder-only, takeover audits BOTH parties, release frees', function (): void {
    $s = kbScene();
    $admin = $s['admin'];
    $article = $this->actingAs($admin, 'staff')->postJson('https://localhost/admin/kb/articles', ['category_id' => $s['category_id'], 'audience' => 'public', 'source_language' => 'en', 'title' => '[FIXTURE] Lock me']);
    bindTenant($s['tenant']);
    $variantId = $article->json('variant_id');
    $hanna = kbActor(['kb.articles.edit'], 'hanna');
    $dawit = kbActor(['kb.articles.edit'], 'dawit');

    $this->actingAs($hanna, 'staff')->postJson('https://localhost/admin/kb/variants/'.$variantId.'/lock')->assertOk()->assertJson(['acquired' => true, 'mode' => 'locked']);
    $refused = $this->actingAs($dawit, 'staff')->postJson('https://localhost/admin/kb/variants/'.$variantId.'/lock');
    $refused->assertStatus(409);
    expect($refused->json('code'))->toBe('kb_lock_held')->and($refused->json('holder_id'))->toBe($hanna->identifier());
    expect($this->actingAs($dawit, 'staff')->postJson('https://localhost/admin/kb/variants/'.$variantId.'/lock/heartbeat')->json('alive'))->toBeFalse();
    expect($this->actingAs($hanna, 'staff')->postJson('https://localhost/admin/kb/variants/'.$variantId.'/lock/heartbeat')->json('alive'))->toBeTrue();

    $took = $this->actingAs($dawit, 'staff')->postJson('https://localhost/admin/kb/variants/'.$variantId.'/lock/takeover');
    $took->assertOk();
    expect($took->json('previous_holder_id'))->toBe($hanna->identifier());
    bindTenant($s['tenant']);
    $rows = DB::table('audit_log')->where('action_key', 'kb.lock.taken_over')->where('entity_id', $variantId)->get(['actor_id', 'payload']);
    expect($rows)->toHaveCount(2);
    $parties = $rows->mapWithKeys(fn ($r) => [json_decode((string) $r->payload, true)['party'] => $r->actor_id])->all();
    expect($parties)->toBe(['taker' => $dawit->identifier(), 'holder' => $hanna->identifier()]);

    $this->actingAs($dawit, 'staff')->deleteJson('https://localhost/admin/kb/variants/'.$variantId.'/lock')->assertJson(['released' => true]);
    $this->actingAs($hanna, 'staff')->postJson('https://localhost/admin/kb/variants/'.$variantId.'/lock')->assertOk()->assertJson(['acquired' => true]);
});

it('FS-05: with the lock service down every lock door answers mode=optimistic (never a 500) and the editor continues on the working-copy stamp', function (): void {
    $s = kbScene();
    $admin = $s['admin'];
    $article = $this->actingAs($admin, 'staff')->postJson('https://localhost/admin/kb/articles', ['category_id' => $s['category_id'], 'audience' => 'public', 'source_language' => 'en', 'title' => '[FIXTURE] Redis down']);
    bindTenant($s['tenant']);
    $variantId = $article->json('variant_id');

    $dead = new class implements RedisFactory
    {
        public function connection($name = null): never
        {
            throw new RuntimeException('connection refused');
        }
    };
    app()->instance(Locks::class, new Locks(app(App\Support\Tenancy\TenantContext::class), $dead, app(App\Support\Settings\SettingsRepository::class), app(App\Support\Audit\AuditLogger::class)));

    $this->actingAs($admin, 'staff')->postJson('https://localhost/admin/kb/variants/'.$variantId.'/lock')->assertOk()->assertJson(['mode' => 'optimistic', 'acquired' => false]);
    $this->actingAs($admin, 'staff')->postJson('https://localhost/admin/kb/variants/'.$variantId.'/lock/heartbeat')->assertOk()->assertJson(['mode' => 'optimistic']);
    $this->actingAs($admin, 'staff')->postJson('https://localhost/admin/kb/variants/'.$variantId.'/lock/takeover')->assertOk()->assertJson(['mode' => 'optimistic']);
    $this->actingAs($admin, 'staff')->deleteJson('https://localhost/admin/kb/variants/'.$variantId.'/lock')->assertOk()->assertJson(['mode' => 'optimistic']);
    bindTenant($s['tenant']);
    $saved = $this->actingAs($admin, 'staff')->putJson('https://localhost/admin/kb/variants/'.$variantId.'/working-copy', ['content' => ['blocks' => []]]);
    $saved->assertOk();
});
