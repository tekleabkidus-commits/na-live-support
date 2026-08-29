<?php

declare(strict_types=1);

use App\Domain\Kb\Search\KbSearchDocuments;
use App\Jobs\Kb\KbSearchIndexer;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/KbSceneHelpers.php';

/**
 * Doc 10 FR-18 / US-04 / SEC-05 / BR-01 / BR-05 / C-10 / FS-02 (S10 step 11): the widget Help
 * tab's API contract on the REAL Doc 02 widget stack (widget key + visitor token + cookie).
 * PUBLIC + PUBLISHED only; `start_chat: true` on every response (the escape hatch is a render
 * FACT); search degrades honestly; feedback rides the step-10 writer with the SEC-05 endpoint
 * statuses (429 / 422). Rendering is Doc 13's ([D13]) — this is what it renders.
 */
function widgetKbScene(): array
{
    [$tenant, $widget, $key] = widgetFixture();
    activateTenant($tenant);
    $tenantId = bindTenant($tenant);
    $admin = kbActor(['kb.categories.manage', 'kb.articles.create', 'kb.articles.edit', 'kb.articles.publish', 'kb.view_internal', 'kb.feedback.view'], 'admin');
    $category = test()->actingAs($admin, 'staff')->postJson('https://localhost/admin/kb/categories', ['name' => ['en' => '[FIXTURE] Payments', 'am' => 'ክፍያዎች'], 'slug' => 'payments', 'ai_usable' => true]);
    $category->assertStatus(201);
    bindTenant($tenant);
    $s = ['tenant' => $tenant, 'tenant_id' => $tenantId, 'admin' => $admin, 'category_id' => $category->json('id')];
    $session = widgetSession($key)->assertCreated();
    $cookie = collect($session->baseResponse->headers->getCookies())->first(fn ($c) => $c->getName() === 'nlw_visitor')?->getValue();
    bindTenant($tenant);

    return [$s, $key, $session->json('token'), (string) $cookie];
}

function kbWidget(string $key, string $token, string $cookie, string $method, string $path, array $body = []): TestResponse
{
    resetWidgetClient();
    $request = test()->withHeaders(['Authorization' => 'Bearer '.$key, 'Origin' => WIDGET_ORIGIN, 'X-Visitor-Token' => $token])
        ->withUnencryptedCookie('nlw_visitor', $cookie)->withCredentials();

    return $method === 'GET' ? $request->getJson('https://localhost/api/widget/v1/'.$path) : $request->postJson('https://localhost/api/widget/v1/'.$path, $body);
}

function kbWidgetIndexVariant(string $variantId): void
{
    $row = DB::table('outbox')->where('name', 'kb.article.published')->where('payload->variant_id', $variantId)->orderByDesc('id')->first();
    DB::table('events')->insertOrIgnore(['id' => $row->id, 'tenant_id' => $row->tenant_id, 'name' => $row->name, 'schema_version' => $row->schema_version ?? 1, 'payload' => $row->payload]);
    (new KbSearchIndexer($row->id))->handle(app(KbSearchDocuments::class));
}

it('FR-18/US-04: browse → search → article (viewed event) → 👎 feedback lands — public+published only, internal/draft ABSENT everywhere (C-10 404), BR-05 fallback serves en for a missing am, start_chat on every response', function (): void {
    [$s, $key, $token, $cookie] = widgetKbScene();
    $pub = kbArticle($s, '[FIXTURE] Telebirr deposit guide');
    test()->actingAs($s['admin'], 'staff')->postJson('https://localhost/admin/kb/variants/'.$pub['variant_id'].'/publish')->assertOk();
    bindTenant($s['tenant']);
    kbWidgetIndexVariant($pub['variant_id']);
    $internal = kbArticle($s, '[FIXTURE] Internal playbook', 'internal');
    test()->actingAs($s['admin'], 'staff')->postJson('https://localhost/admin/kb/variants/'.$internal['variant_id'].'/publish')->assertOk();
    bindTenant($s['tenant']);
    kbWidgetIndexVariant($internal['variant_id']);
    $draft = kbArticle($s, '[FIXTURE] Draft only');

    // Browse: the tree lists ONLY the public published article; start_chat is a render fact.
    $tree = kbWidget($key, $token, $cookie, 'GET', 'kb/categories');
    $tree->assertOk();
    bindTenant($s['tenant']);
    $articles = collect($tree->json('categories'))->firstWhere('id', $s['category_id'])['articles'];
    expect(array_column($articles, 'article_id'))->toBe([$pub['article_id']])
        ->and($tree->json('start_chat'))->toBeTrue();

    // Search: the internal article is ABSENT with no widening parameter in existence.
    $hits = kbWidget($key, $token, $cookie, 'GET', 'kb/search?q=telebirr');
    bindTenant($s['tenant']);
    expect($hits->json('unavailable'))->toBeFalse()
        ->and(array_column($hits->json('hits'), 'article_id'))->toBe([$pub['article_id']])
        ->and($hits->json('start_chat'))->toBeTrue();

    // Article: BR-05 — an am request on an en-only article serves en (fallback on MISSING only).
    $article = kbWidget($key, $token, $cookie, 'GET', 'kb/articles/'.$pub['article_id'].'?language=am');
    $article->assertOk();
    bindTenant($s['tenant']);
    expect($article->json('language'))->toBe('en')->and($article->json('served_language_matches'))->toBeFalse()
        ->and($article->json('title'))->toBe('[FIXTURE] Telebirr deposit guide')
        ->and($article->json('version_id'))->not->toBeNull()->and($article->json('start_chat'))->toBeTrue();
    expect(DB::table('outbox')->where('name', 'kb.article.viewed')->where('payload->variant_id', $pub['variant_id'])->where('payload->surface', 'widget')->exists())->toBeTrue();

    // C-10: internal and draft are indistinguishable from unknown.
    kbWidget($key, $token, $cookie, 'GET', 'kb/articles/'.$internal['article_id'])->assertStatus(404);
    bindTenant($s['tenant']);
    kbWidget($key, $token, $cookie, 'GET', 'kb/articles/'.$draft['article_id'])->assertStatus(404);
    bindTenant($s['tenant']);

    // US-04's close: 👎 with a comment lands through the step-10 writer (dedupe arbiter and all).
    $down = kbWidget($key, $token, $cookie, 'POST', 'kb/feedback', ['variant_id' => $pub['variant_id'], 'score' => -1, 'comment' => '[FIXTURE] did not help']);
    $down->assertStatus(201);
    bindTenant($s['tenant']);
    expect($down->json('replaced'))->toBeFalse()->and($down->json('start_chat'))->toBeTrue()
        ->and(DB::table('kb_feedback')->where('variant_id', $pub['variant_id'])->count())->toBe(1);
    $again = kbWidget($key, $token, $cookie, 'POST', 'kb/feedback', ['variant_id' => $pub['variant_id'], 'score' => 1]);
    bindTenant($s['tenant']);
    expect($again->json('replaced'))->toBeTrue('EC-10: same visitor, latest wins — the endpoint rides the arbiter')
        ->and(DB::table('kb_feedback')->where('variant_id', $pub['variant_id'])->count())->toBe(1);
});

it('SEC-05 endpoint legs: the rate limit answers 429 rate_limited (problem details), an over-cap comment answers 422, an unpublished variant answers 422 — and the refusals write nothing', function (): void {
    [$s, $key, $token, $cookie] = widgetKbScene();
    $pub = kbArticle($s, '[FIXTURE] Limits');
    test()->actingAs($s['admin'], 'staff')->postJson('https://localhost/admin/kb/variants/'.$pub['variant_id'].'/publish')->assertOk();
    bindTenant($s['tenant']);
    app(App\Support\Settings\SettingsRepository::class)->set('kb.feedback.rate_per_hour', 2, tenantSurface: false);

    kbWidget($key, $token, $cookie, 'POST', 'kb/feedback', ['variant_id' => $pub['variant_id'], 'score' => 1])->assertStatus(201);
    bindTenant($s['tenant']);
    kbWidget($key, $token, $cookie, 'POST', 'kb/feedback', ['variant_id' => $pub['variant_id'], 'score' => -1])->assertStatus(201);
    bindTenant($s['tenant']);
    $limited = kbWidget($key, $token, $cookie, 'POST', 'kb/feedback', ['variant_id' => $pub['variant_id'], 'score' => 1]);
    $limited->assertStatus(429);
    bindTenant($s['tenant']);
    expect($limited->json('code'))->toBe('rate_limited');

    $cap = app(App\Domain\Kb\Feedback\VisitorLimiter::class)->commentCap();
    // A different visitor (fresh session) so the rate limiter is not what refuses here.
    $session = widgetSession($key)->assertCreated();
    $cookie2 = (string) collect($session->baseResponse->headers->getCookies())->first(fn ($c) => $c->getName() === 'nlw_visitor')?->getValue();
    bindTenant($s['tenant']);
    $long = kbWidget($key, $session->json('token'), $cookie2, 'POST', 'kb/feedback', ['variant_id' => $pub['variant_id'], 'score' => 1, 'comment' => '[FIXTURE] '.str_repeat('a', $cap + 1)]);
    $long->assertStatus(422);
    bindTenant($s['tenant']);

    $draft = kbArticle($s, '[FIXTURE] Unpublished');
    kbWidget($key, $session->json('token'), $cookie2, 'POST', 'kb/feedback', ['variant_id' => $draft['variant_id'], 'score' => 1])->assertStatus(422);
    bindTenant($s['tenant']);
    expect(DB::table('kb_feedback')->where('variant_id', $pub['variant_id'])->count())->toBe(1, 'one visitor, one row; every refusal wrote nothing')
        ->and(DB::table('kb_feedback')->where('variant_id', $draft['variant_id'])->count())->toBe(0);
});

it('FS-02: Meilisearch unreachable → unavailable:true with start_chat still true — never a 500, never an empty lie', function (): void {
    [$s, $key, $token, $cookie] = widgetKbScene();
    config(['services.meilisearch.host' => 'http://127.0.0.1:1']);
    $r = kbWidget($key, $token, $cookie, 'GET', 'kb/search?q=telebirr');
    $r->assertOk();
    expect($r->json('unavailable'))->toBeTrue()->and($r->json('hits'))->toBe([])->and($r->json('start_chat'))->toBeTrue();
});
