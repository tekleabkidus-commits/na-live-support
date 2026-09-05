<?php

declare(strict_types=1);

require_once __DIR__.'/WidgetDesignerSupport.php';

/*
|--------------------------------------------------------------------------
| Doc 13 §4.4 (S13 step 11) — the Designer's PAGES: the list and the editor render
| their props for a viewer; the preview document is the panel bundle booted from an
| inlined envelope carrying the visitor's OWN strings, framed by the Backoffice page
| alone (frame-ancestors 'self'), never cached; a retired widget answers 404.
|--------------------------------------------------------------------------
*/

it('renders the list and the editor with their props; the preview document carries the envelope, the customer strings and frame-ancestors self', function (): void {
    [, $user] = designerScene();
    $id = designer($user, 'POST', '', ['name' => '[FIXTURE] Pages widget', 'allowed_origins' => ['https://help.fixture.example']])->json('id');
    designer($user, 'POST', '/'.$id.'/config/publish', ['config' => designerConfig()])->assertStatus(201);
    $viewer = ticketActor(['webchat.widgets.view'], 'Fixture Viewer Role');

    $index = test()->actingAs($viewer, 'staff')->get('https://localhost/admin/widgets');
    $index->assertOk();
    $index->assertInertia(fn ($page) => $page->component('Admin/Widgets/Index', shouldExist: false)->has('widgets', 1)->where('widgets.0.name', '[FIXTURE] Pages widget')->where('can', fn ($can) => (collect($can)->get('webchat.widgets.view') === true) && (collect($can)->get('webchat.widgets.configure') === false))->has('copy.nav_widgets'));
    bindTenant($GLOBALS['__designer_tenant']);
    $editor = test()->actingAs($viewer, 'staff')->get('https://localhost/admin/widgets/'.$id.'/edit');
    $editor->assertOk();
    $editor->assertInertia(fn ($page) => $page->component('Admin/Widgets/Editor', shouldExist: false)->where('widget.id', $id)->where('widget.live_version', 1)->has('config.appearance')->has('history', 1)->has('health.counters')->where('install.key_shown_once', false)->has('fonts', 3)->where('voice_flag', false));
    bindTenant($GLOBALS['__designer_tenant']);

    $preview = test()->actingAs($viewer, 'staff')->get('https://localhost/admin/widgets/'.$id.'/preview?theme=dark&language=am&frame=panel');
    $preview->assertOk();
    expect($preview->headers->get('Cache-Control'))->toContain('no-store')
        ->and($preview->headers->get('Content-Security-Policy'))->toContain("frame-ancestors 'self'")
        ->and($preview->headers->get('X-Frame-Options'))->toBeNull();
    $html = (string) $preview->getContent();
    expect($html)->toContain('window.__NLW_PREVIEW=')->toContain('"theme":"dark"')->toContain('"language":"am"')
        ->toContain('ገንዘቤ የት ደረሰ?') // the preview thread in the visitor's Amharic strings
        ->not->toContain('"designer"')  // the Designer branch never rides
        ->toContain('id="nlw-root"');
    bindTenant($GLOBALS['__designer_tenant']);

    designer($user, 'POST', '/'.$id.'/retire')->assertOk();
    test()->actingAs($viewer, 'staff')->get('https://localhost/admin/widgets/'.$id.'/edit')->assertStatus(404);
    bindTenant($GLOBALS['__designer_tenant']);
    test()->actingAs($viewer, 'staff')->get('https://localhost/admin/widgets/'.$id.'/preview')->assertStatus(404);
});
