<?php

declare(strict_types=1);

use App\Domain\Kb\Help\HelpContent;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/KbSceneHelpers.php';

/**
 * Doc 10 FR-05 / SEC-02 / EC-05 / AC-10 (S10 step 11): article images ride Doc 04's pipeline —
 * REAL clamd scan before storage (an EICAR upload is refused and NOTHING lands on disk), the
 * staff door answers a signed editing URL, the customer path is the widget media door (public +
 * published enforced; signing rotates behind the stable path). EC-05: a retention-deleted file
 * blocks publish LISTING the blocks, and an already-published render degrades to
 * `placeholder: true` — never a broken image.
 */
const KB_PNG_BYTES = "\x89PNG\r\n\x1a\n\x00\x00\x00\x0dIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89\x00\x00\x00\x0aIDATx\x9cc\x00\x01\x00\x00\x05\x00\x01\x0d\x0a\x2d\xb4\x00\x00\x00\x00IEND\xaeB`\x82";
const KB_EICAR = 'X5O!P%@AP[4\\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';

it('SEC-02/FR-05: a clean upload scans then stores (signed editing URL back); EICAR is refused with NOTHING stored; EC-05: a deleted file blocks publish listing the block and an existing render degrades to placeholder', function (): void {
    $s = kbScene();
    $a = kbArticle($s, '[FIXTURE] Illustrated guide');
    $disk = app(Illuminate\Contracts\Filesystem\Factory::class)->disk((string) config('media.disk'));

    $clean = $this->actingAs($s['admin'], 'staff')->post('https://localhost/admin/kb/variants/'.$a['variant_id'].'/media', [
        'image' => UploadedFile::fake()->createWithContent('screenshot.png', KB_PNG_BYTES),
    ]);
    $clean->assertStatus(201);
    bindTenant($s['tenant']);
    $mediaKey = $clean->json('media_key');
    expect($mediaKey)->toStartWith('t/')->toContain('/kb/'.$a['article_id'].'/')
        ->and($clean->json('url'))->toBeString()->and($clean->json('alt_required'))->toBeTrue()
        ->and($disk->exists($mediaKey))->toBeTrue()
        ->and(DB::table('audit_log')->where('action_key', 'kb.article.media_uploaded')->exists())->toBeTrue();

    // SEC-02's order is physics: the infected file is refused AND the article's media dir gained nothing.
    $before = count($disk->files(dirname($mediaKey)));
    $infected = $this->actingAs($s['admin'], 'staff')->post('https://localhost/admin/kb/variants/'.$a['variant_id'].'/media', [
        'image' => UploadedFile::fake()->createWithContent('totally-a-screenshot.png', KB_EICAR),
    ]);
    $infected->assertStatus(422);
    bindTenant($s['tenant']);
    expect(count($disk->files(dirname($mediaKey))))->toBe($before, 'the refusal stored nothing');

    // The block references the file (with alt — AC-10 is not what this leg tests); publish serves it.
    $this->actingAs($s['admin'], 'staff')->putJson('https://localhost/admin/kb/variants/'.$a['variant_id'].'/working-copy', ['content' => ['blocks' => [
        ['id' => 'b1', 'type' => 'paragraph', 'text' => '[FIXTURE] See the screenshot.'],
        ['id' => 'img1', 'type' => 'image', 'media_key' => $mediaKey, 'alt' => '[FIXTURE] deposit screen'],
    ]]])->assertOk();
    bindTenant($s['tenant']);
    $this->actingAs($s['admin'], 'staff')->postJson('https://localhost/admin/kb/variants/'.$a['variant_id'].'/publish')->assertOk();
    bindTenant($s['tenant']);
    $served = app(HelpContent::class)->article($a['article_id'], ['en']);
    $img = collect($served['blocks'])->firstWhere('id', 'img1');
    expect($img['url'])->toBe('/api/widget/v1/kb/media/'.$a['article_id'].'/'.basename($mediaKey))
        ->and($img)->not->toHaveKey('media_key')->and($img)->not->toHaveKey('placeholder');

    // EC-05: retention deletes the file → the LIVE render degrades to placeholder (after the purge
    // a publish would do), and the NEXT publish is BLOCKED listing the block id.
    $disk->delete($mediaKey);
    app(App\Domain\Kb\Cache\KbCaches::class)->purgeArticle($a['article_id']);
    $degraded = app(HelpContent::class)->article($a['article_id'], ['en']);
    $img = collect($degraded['blocks'])->firstWhere('id', 'img1');
    expect($img['placeholder'])->toBeTrue()->and($img)->not->toHaveKey('url');
    $blocked = $this->actingAs($s['admin'], 'staff')->postJson('https://localhost/admin/kb/variants/'.$a['variant_id'].'/publish');
    $blocked->assertStatus(409); // the house shape for a refused transition (the alt gate's status)
    bindTenant($s['tenant']);
    expect($blocked->json('missing_media'))->toBe(['img1']);
});

it('FR-05: the widget media door serves ONLY public+published article files (302 to a signed URL); draft, internal and deleted-file are all 404 (C-10)', function (): void {
    [$tenant, $widget, $key] = widgetFixture();
    activateTenant($tenant);
    $tenantId = bindTenant($tenant);
    $admin = kbActor(['kb.categories.manage', 'kb.articles.create', 'kb.articles.edit', 'kb.articles.publish'], 'admin');
    $category = test()->actingAs($admin, 'staff')->postJson('https://localhost/admin/kb/categories', ['name' => ['en' => '[FIXTURE] Media'], 'slug' => 'media', 'ai_usable' => true]);
    bindTenant($tenant);
    $s = ['tenant' => $tenant, 'tenant_id' => $tenantId, 'admin' => $admin, 'category_id' => $category->json('id')];
    $a = kbArticle($s, '[FIXTURE] Media article');
    $up = test()->actingAs($admin, 'staff')->post('https://localhost/admin/kb/variants/'.$a['variant_id'].'/media', ['image' => UploadedFile::fake()->createWithContent('pic.png', KB_PNG_BYTES)]);
    $up->assertStatus(201);
    bindTenant($tenant);
    $file = $up->json('file');

    $session = widgetSession($key)->assertCreated();
    $cookie = (string) collect($session->baseResponse->headers->getCookies())->first(fn ($c) => $c->getName() === 'nlw_visitor')?->getValue();
    bindTenant($tenant);
    $call = function () use ($key, $session, $cookie, $a, $file) {
        resetWidgetClient();

        return test()->withHeaders(['Authorization' => 'Bearer '.$key, 'Origin' => WIDGET_ORIGIN, 'X-Visitor-Token' => $session->json('token')])
            ->withUnencryptedCookie('nlw_visitor', $cookie)->withCredentials()
            ->get('https://localhost/api/widget/v1/kb/media/'.$a['article_id'].'/'.$file);
    };

    $call()->assertStatus(404); // draft article: absent (C-10)
    bindTenant($tenant);
    test()->actingAs($admin, 'staff')->postJson('https://localhost/admin/kb/variants/'.$a['variant_id'].'/publish')->assertOk();
    bindTenant($tenant);
    $served = $call();
    $served->assertStatus(302);
    bindTenant($tenant);
    expect($served->headers->get('Location'))->toContain(basename($file, '.png'));

    test()->actingAs($admin, 'staff')->postJson('https://localhost/admin/kb/variants/'.$a['variant_id'].'/unpublish')->assertOk();
    bindTenant($tenant);
    $call()->assertStatus(404); // unpublished again: the long-lived path went dark with it
    bindTenant($tenant);
});
