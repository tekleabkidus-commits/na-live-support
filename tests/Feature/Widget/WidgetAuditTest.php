<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

require_once __DIR__.'/WidgetDesignerSupport.php';

/*
|--------------------------------------------------------------------------
| Doc 13 audit per mutation (registries/audit-coverage.php's widget.* rows):
| every Designer door writes its registered key with the registered required
| fields, and no payload carries a key or secret plaintext.
|--------------------------------------------------------------------------
*/

it('every Designer mutation audits under its registered key with the registered fields; plaintexts never appear', function (): void {
    [, $user] = designerScene();
    $created = designer($user, 'POST', '', ['name' => '[FIXTURE] W', 'allowed_origins' => ['https://a.example']])->assertStatus(201);
    $id = $created->json('id');
    designer($user, 'PATCH', '/'.$id, ['name' => '[FIXTURE] W2', 'test_mode' => true, 'bundle_channel' => 'early'])->assertOk();
    designer($user, 'PUT', '/'.$id.'/allowlist', ['allowed_origins' => ['https://b.example']])->assertOk();
    $secret = designer($user, 'POST', '/'.$id.'/secret')->assertStatus(201)->json('secret_plaintext');
    designer($user, 'POST', '/'.$id.'/secret/rotate')->assertStatus(201);
    $v1 = designer($user, 'POST', '/'.$id.'/config/publish', ['config' => designerConfig()])->assertStatus(201);
    designer($user, 'POST', '/'.$id.'/config/publish', ['config' => designerConfig(['appearance' => ['theme' => 'light']])])->assertStatus(201);
    designer($user, 'POST', '/'.$id.'/config/rollback', ['version_id' => $v1->json('version_id')])->assertStatus(201);
    designer($user, 'POST', '/'.$id.'/retire')->assertOk();

    $registry = collect(require base_path('registries/audit-coverage.php'))->keyBy('action_key');
    $expected = ['widget.created', 'widget.updated', 'widget.test_mode.changed', 'widget.bundle_channel.changed', 'widget.allowlist.changed', 'widget.secret.created', 'widget.secret.rotated', 'widget.config.published', 'widget.config.rolled_back'];
    foreach ($expected as $key) {
        $rows = DB::table('audit_log')->where('action_key', $key)->get();
        expect($rows->count())->toBeGreaterThan(0, $key.' was audited');
        foreach ($rows as $row) {
            $payload = json_decode((string) $row->payload, true);
            foreach ($registry[$key]['required_fields'] as $field) {
                expect(array_key_exists($field, $payload))->toBeTrue($key.' carries '.$field);
            }
            expect((string) $row->payload)->not->toContain($created->json('key_plaintext'))->not->toContain($secret);
        }
    }
    expect(DB::table('audit_log')->where('action_key', 'widget.updated')->count())->toBe(2, 'the field edit and the retirement');
});
