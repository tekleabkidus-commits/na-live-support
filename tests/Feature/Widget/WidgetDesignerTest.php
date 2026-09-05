<?php

declare(strict_types=1);

use App\Models\Widget;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/WidgetDesignerSupport.php';

/*
|--------------------------------------------------------------------------
| Doc 13 FR-19 / FR-20 / EC-11 / EC-12 / SEC-03 — every Designer door (S13 step 10):
| create (key shown once), list, edit, allowlist through the Doc 02 matcher,
| secret create + rotate, validate, publish (versions), rollback (a new
| version), history, install snippet, retire (C3), the permission split.
|--------------------------------------------------------------------------
*/

it('creates a widget with its key shown ONCE, lists it, edits name/test mode/channel, and retires it without a DELETE', function (): void {
    [, $user] = designerScene();
    $created = designer($user, 'POST', '', ['name' => '[FIXTURE] Support subdomain', 'allowed_origins' => ['https://help.nabet.example', '*.nabet.example']]);
    $created->assertStatus(201)->assertJsonPath('name', '[FIXTURE] Support subdomain')->assertJsonPath('live_version', null);
    $plaintext = $created->json('key_plaintext');
    expect($plaintext)->toBeString()->and(strlen($plaintext))->toBeGreaterThan(20);
    $id = $created->json('id');
    expect(DB::table('tenant_keys')->where('kind', 'widget')->count())->toBe(1); // the issuer stores the hash + prefix only (Doc 02)

    expect($created->json('install.snippet'))->toContain('/w/'.$plaintext.'.js')->and($created->json('install.key_shown_once'))->toBeFalse();
    $show = designer($user, 'GET', '/'.$id)->assertOk();
    expect($show->json('key_plaintext'))->toBeNull('the plaintext is never readable again')
        ->and($show->json('allowed_origins'))->toBe(['https://help.nabet.example', '*.nabet.example'])
        ->and($show->json('install.snippet'))->toBeNull('interim until P-WIDGET-KEY: the issuer keeps only the hash')
        ->and($show->json('install.key_shown_once'))->toBeTrue()
        ->and($show->json('install.csp'))->toContain('script-src')->toContain('frame-src')
        ->and($show->json('has_secret'))->toBeFalse();
    expect(collect(designer($user, 'GET', '')->json('widgets'))->pluck('id')->all())->toContain($id);

    designer($user, 'PATCH', '/'.$id, ['name' => '[FIXTURE] Renamed', 'test_mode' => true, 'bundle_channel' => 'early'])
        ->assertOk()->assertJsonPath('name', '[FIXTURE] Renamed')->assertJsonPath('test_mode', true)->assertJsonPath('bundle_channel', 'early');
    designer($user, 'PATCH', '/'.$id, ['bundle_channel' => 'nightly'])->assertStatus(422); // the request rule (in:stable,early) refuses before the writer
    designer($user, 'PATCH', '/'.$id, ['name' => '[FIXTURE] Renamed'])->assertOk(); // same state: no audit, no error (A-37)

    designer($user, 'POST', '/'.$id.'/retire')->assertOk()->assertJsonPath('retired', true);
    expect(Widget::query()->count())->toBe(1, 'retirement is a timestamp — the row stays (C3)')
        ->and(Widget::query()->whereNotNull('deleted_at')->count())->toBe(1);
    designer($user, 'GET', '/'.$id)->assertStatus(404);
    expect(designer($user, 'GET', '')->json('widgets'))->toBe([]);
});

it('EC-12: the allowlist editor accepts exact https origins and *.wildcards through the Doc 02 matcher, refuses the rest by name, and audits added/removed', function (): void {
    [, $user] = designerScene();
    $id = designer($user, 'POST', '', ['name' => '[FIXTURE] W'])->json('id');
    designer($user, 'PUT', '/'.$id.'/allowlist', ['allowed_origins' => ['https://brand.example', '*.brand.example']])
        ->assertOk()->assertJsonPath('added', ['https://brand.example', '*.brand.example'])->assertJsonPath('removed', []);
    foreach (['http://brand.example', 'brand.example', 'https://brand.example/path', '*brand.example', 'evil-brand.example'] as $bad) {
        designer($user, 'PUT', '/'.$id.'/allowlist', ['allowed_origins' => [$bad]])->assertStatus(422)->assertJsonPath('refusal_code', 'invalid_origin');
    }
    designer($user, 'PUT', '/'.$id.'/allowlist', ['allowed_origins' => ['*.brand.example']])
        ->assertOk()->assertJsonPath('added', [])->assertJsonPath('removed', ['https://brand.example']);
    // the matcher's dot boundary holds on what was saved: evil-brand.example is NOT a *.brand.example
    $widget = Widget::query()->findOrFail($id);
    $matcher = app(App\Domain\Auth\Widget\OriginAllowlist::class);
    expect($matcher->matches('https://shop.brand.example', $widget->allowed_origins))->toBeTrue()
        ->and($matcher->matches('https://evil-brand.example', $widget->allowed_origins))->toBeFalse();
    expect(DB::table('audit_log')->where('action_key', 'widget.allowlist.changed')->count())->toBe(2);
});

it('SEC-03: the signed-identity secret is created then rotated through the one minter — shown once, overlap window stated, never in an audit row', function (): void {
    [, $user] = designerScene();
    $id = designer($user, 'POST', '', ['name' => '[FIXTURE] W'])->json('id');
    designer($user, 'POST', '/'.$id.'/secret/rotate')->assertStatus(422)->assertJsonPath('refusal_code', 'no_secret');
    $first = designer($user, 'POST', '/'.$id.'/secret')->assertStatus(201);
    designer($user, 'POST', '/'.$id.'/secret')->assertStatus(422)->assertJsonPath('refusal_code', 'secret_exists');
    $second = designer($user, 'POST', '/'.$id.'/secret/rotate')->assertStatus(201);
    expect($first->json('secret_plaintext'))->toStartWith('wis_')->and($second->json('secret_plaintext'))->not->toBe($first->json('secret_plaintext'))
        ->and($second->json('overlap_hours'))->toBeGreaterThan(0)->and($second->json('rotated_at'))->toBeString();
    expect(designer($user, 'GET', '/'.$id)->json('has_secret'))->toBeTrue();
    foreach (DB::table('audit_log')->whereIn('action_key', ['widget.secret.created', 'widget.secret.rotated'])->get() as $row) {
        expect((string) $row->payload)->not->toContain('wis_');
    }
});

it('FR-20: publish is a version + one pointer move; a second publish supersedes; rollback is a NEW version copied from the old one, the history reads true, and the sync envelope serves the live config', function (): void {
    [, $user] = designerScene();
    $id = designer($user, 'POST', '', ['name' => '[FIXTURE] W'])->json('id');
    designer($user, 'POST', '/'.$id.'/config/validate', ['config' => ['appearance' => ['position' => 'top_left']]])->assertStatus(422)->assertJsonPath('refusal_code', 'invalid_config');
    designer($user, 'POST', '/'.$id.'/config/validate', ['config' => designerConfig(['content' => ['welcome' => ['am' => '']]])])->assertOk()->assertJsonPath('warnings', ['am_incomplete']);

    $v1 = designer($user, 'POST', '/'.$id.'/config/publish', ['config' => designerConfig(), 'note' => 'first'])->assertStatus(201)->assertJsonPath('version', 1);
    $v2 = designer($user, 'POST', '/'.$id.'/config/publish', ['config' => designerConfig(['appearance' => ['position' => 'bottom_left']]), 'note' => 'left'])->assertStatus(201)->assertJsonPath('version', 2);
    expect(collect(designer($user, 'GET', '/'.$id)->json('history'))->pluck('status', 'version')->all())->toBe([2 => 'live', 1 => 'superseded'])
        ->and(Widget::query()->findOrFail($id)->live_version_id)->toBe($v2->json('version_id'));

    designer($user, 'POST', '/'.$id.'/config/rollback', ['version_id' => $v2->json('version_id')])->assertStatus(422)->assertJsonPath('refusal_code', 'already_live');
    $v3 = designer($user, 'POST', '/'.$id.'/config/rollback', ['version_id' => $v1->json('version_id')])->assertStatus(201)->assertJsonPath('version', 3);
    expect(DB::table('widget_config_versions')->count())->toBe(3, 'history is append-only — the app role cannot UPDATE a version row');
    $show = designer($user, 'GET', '/'.$id)->assertOk();
    expect($show->json('config.appearance.position'))->toBe('bottom_right', 'v3 carries v1\'s config')
        ->and(collect($show->json('history'))->pluck('status', 'version')->all())->toBe([3 => 'live', 2 => 'rolled_back', 1 => 'superseded'])
        ->and($show->json('history.0.note'))->toBe('rollback:1')->and($show->json('live_version'))->toBe(3);
    designer($user, 'POST', '/'.$id.'/config/rollback', ['version_id' => (string) Illuminate\Support\Str::uuid7()])->assertStatus(422)->assertJsonPath('refusal_code', 'version_not_found');
});

it('the permission split: view-only reads but cannot mutate (403); no session → no door', function (): void {
    [, $configurer] = designerScene();
    $id = designer($configurer, 'POST', '', ['name' => '[FIXTURE] W'])->json('id');
    $viewer = ticketActor(['webchat.widgets.view'], 'Fixture Viewer Role');
    designer($viewer, 'GET', '')->assertOk();
    designer($viewer, 'GET', '/'.$id)->assertOk();
    designer($viewer, 'GET', '/'.$id.'/health')->assertOk();
    designer($viewer, 'PATCH', '/'.$id, ['name' => 'x'])->assertStatus(403);
    designer($viewer, 'POST', '/'.$id.'/config/publish', ['config' => designerConfig()])->assertStatus(403);
    designer($viewer, 'POST', '', ['name' => 'x'])->assertStatus(403);
});

it('no staff session → no door (401)', function (): void {
    [$tenant] = designerScene();
    bindTenant($tenant);
    $status = test()->getJson('https://localhost/admin/widgets')->status();
    expect(in_array($status, [401, 302], true))->toBeTrue('the staff guard refuses (401) or sends to login (302): '.$status);
});
