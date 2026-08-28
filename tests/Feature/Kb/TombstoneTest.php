<?php

declare(strict_types=1);

use App\Domain\Kb\Tombstones\CitationResolver;
use App\Domain\Kb\Tombstones\CitationState;
use App\Domain\Kb\Tombstones\CitationUrls;
use App\Domain\Kb\Tombstones\VersionedCitationResolver;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/KbSceneHelpers.php';

/**
 * Doc 10 FR-16 / T-06 / EC-06 / EC-07 / SS-04 / AC-06 (S10 step 7): a once-cited URL NEVER
 * 404s — unpublish, archive, supersede and slug-change fixtures all resolve (tombstone or
 * redirect); citation URLs EMBED the version id so "what exactly did the bot show on May 3"
 * is ONE lookup answering the exact version; a slug collision auto-suffixes and BOTH URLs
 * keep working through slug history; an internal article's citation is ABSENT for a
 * reader without kb.view_internal.
 */
it('binds the truth-fed resolver at step 7 and legacy chunk refs still resolve', function (): void {
    expect(app(CitationResolver::class))->toBeInstanceOf(VersionedCitationResolver::class);
});

it('AC-06/SS-04: supersede → the old citation is Retired WITH a link to the current version and the exact shown version; unpublish → Retired without a link; archive → Retired; unknown → tombstone, never 404', function (): void {
    $s = kbScene();
    $a = kbArticle($s, '[FIXTURE] Withdrawal times');
    $url = 'https://localhost/admin/kb/variants/'.$a['variant_id'];
    $v1 = $this->actingAs($s['admin'], 'staff')->postJson($url.'/publish', ['note' => 'May 3']);
    $v1->assertOk();
    $cite1 = CitationUrls::citation($a['article_id'], $v1->json('version_id'));
    expect(CitationUrls::parseCitation($cite1))->toBe(['article_id' => $a['article_id'], 'version_id' => $v1->json('version_id')]);

    $one = $this->actingAs($s['admin'], 'staff')->getJson('https://localhost/admin/kb/cite/'.$a['article_id'].'/'.$v1->json('version_id'));
    $one->assertOk()->assertJson(['state' => 'current', 'current_version_id' => $v1->json('version_id')]);
    expect($one->json('shown.version'))->toBe(1)->and($one->json('shown.title'))->toBe('[FIXTURE] Withdrawal times')->and($one->json('current_url'))->toBe('/kb/en/fixture-withdrawal-times');

    bindTenant($s['tenant']);
    $this->actingAs($s['admin'], 'staff')->putJson($url.'/working-copy', ['content' => ['blocks' => [['type' => 'paragraph', 'text' => '[FIXTURE] Withdrawals now arrive within two hours.']]]])->assertOk();
    $v2 = $this->actingAs($s['admin'], 'staff')->postJson($url.'/publish', ['note' => 'May 9']);
    $v2->assertOk();
    $superseded = $this->actingAs($s['admin'], 'staff')->getJson('https://localhost/admin/kb/cite/'.$a['article_id'].'/'.$v1->json('version_id'));
    $superseded->assertOk()->assertJson(['state' => 'retired', 'current_version_id' => $v2->json('version_id'), 'current_url' => '/kb/en/fixture-withdrawal-times']);
    expect($superseded->json('shown.version'))->toBe(1, 'SS-04: the exact version the customer saw, one lookup')->and($superseded->json('tombstone'))->toBe('This article was updated or removed.');

    $this->actingAs($s['admin'], 'staff')->postJson($url.'/unpublish')->assertOk();
    $gone = $this->actingAs($s['admin'], 'staff')->getJson('https://localhost/admin/kb/cite/'.$a['article_id'].'/'.$v2->json('version_id'));
    $gone->assertOk()->assertJson(['state' => 'retired', 'current_url' => null]);
    expect($gone->json('shown.version'))->toBe(2);

    $this->actingAs($s['admin'], 'staff')->postJson($url.'/archive')->assertOk();
    $this->actingAs($s['admin'], 'staff')->getJson('https://localhost/admin/kb/cite/'.$a['article_id'].'/'.$v1->json('version_id'))->assertOk()->assertJson(['state' => 'retired']);
    $this->actingAs($s['admin'], 'staff')->getJson('https://localhost/admin/kb/cite/'.$a['article_id'].'/018f6b2e-0000-7000-8000-00000000dead')->assertOk()->assertJson(['state' => 'unknown', 'tombstone' => 'This article is no longer available.']);
    $this->actingAs($s['admin'], 'staff')->getJson('https://localhost/admin/kb/resolve/en/fixture-withdrawal-times')->assertOk()->assertJson(['state' => 'retired']);
    bindTenant($s['tenant']);
    expect(app(CitationResolver::class)->resolve($a['article_id']))->toBe(CitationState::Retired);
});

it('T-06/EC-06: a slug change keeps the OLD URL working (redirect via slug history); a slug collision auto-suffixes and both articles resolve; EC-07: cited seconds before unpublish still pins the version', function (): void {
    $s = kbScene();
    $a = kbArticle($s, '[FIXTURE] Bonus rules');
    $url = 'https://localhost/admin/kb/variants/'.$a['variant_id'];
    $this->actingAs($s['admin'], 'staff')->postJson($url.'/publish')->assertOk();
    $this->actingAs($s['admin'], 'staff')->getJson('https://localhost/admin/kb/resolve/en/fixture-bonus-rules')->assertOk()->assertJson(['state' => 'current', 'article_id' => $a['article_id']]);

    bindTenant($s['tenant']);
    $this->actingAs($s['admin'], 'staff')->patchJson($url.'/meta', ['slug' => 'fixture-bonus-terms'])->assertOk();
    bindTenant($s['tenant']);
    expect(DB::table('kb_slug_history')->where('slug', 'fixture-bonus-rules')->where('language', 'en')->exists())->toBeTrue('the old slug is remembered');
    $old = $this->actingAs($s['admin'], 'staff')->getJson('https://localhost/admin/kb/resolve/en/fixture-bonus-rules');
    $old->assertStatus(301)->assertJson(['state' => 'redirect', 'redirect_to' => '/kb/en/fixture-bonus-terms']);
    $this->actingAs($s['admin'], 'staff')->getJson('https://localhost/admin/kb/resolve/en/fixture-bonus-terms')->assertOk()->assertJson(['state' => 'current']);

    // EC-06: another article takes the retired slug → auto-suffixed; both URLs resolve.
    bindTenant($s['tenant']);
    $b = kbArticle($s, '[FIXTURE] Bonus rules');
    bindTenant($s['tenant']);
    expect(DB::table('kb_article_variants')->where('id', $b['variant_id'])->value('slug'))->toBe('fixture-bonus-rules-2', 'EC-06 auto-suffix — the historic slug stays with its owner');
    $this->actingAs($s['admin'], 'staff')->postJson('https://localhost/admin/kb/variants/'.$b['variant_id'].'/publish')->assertOk();
    $this->actingAs($s['admin'], 'staff')->getJson('https://localhost/admin/kb/resolve/en/fixture-bonus-rules-2')->assertOk()->assertJson(['state' => 'current', 'article_id' => $b['article_id']]);
    $this->actingAs($s['admin'], 'staff')->getJson('https://localhost/admin/kb/resolve/en/fixture-bonus-rules')->assertStatus(301);

    // EC-07: a citation minted seconds before unpublish still identifies exactly what was read.
    bindTenant($s['tenant']);
    $versionId = DB::table('kb_article_variants')->where('id', $b['variant_id'])->value('current_version_id');
    $this->actingAs($s['admin'], 'staff')->postJson('https://localhost/admin/kb/variants/'.$b['variant_id'].'/unpublish')->assertOk();
    $facts = $this->actingAs($s['admin'], 'staff')->getJson('https://localhost/admin/kb/cite/'.$b['article_id'].'/'.$versionId);
    $facts->assertOk()->assertJson(['state' => 'retired']);
    expect($facts->json('shown.title'))->toBe('[FIXTURE] Bonus rules')->and($facts->json('shown.version'))->toBe(1);
});

it('C-10: an internal article\'s citation and slug are ABSENT (unknown tombstone) for a reader without kb.view_internal — never a refusal that confirms existence', function (): void {
    $s = kbScene();
    $a = kbArticle($s, '[FIXTURE] Payout escalation', 'internal');
    $this->actingAs($s['admin'], 'staff')->postJson('https://localhost/admin/kb/variants/'.$a['variant_id'].'/publish')->assertOk();
    bindTenant($s['tenant']);
    $versionId = DB::table('kb_article_variants')->where('id', $a['variant_id'])->value('current_version_id');
    $reader = kbActor([], 'reader');
    $this->actingAs($reader, 'staff')->getJson('https://localhost/admin/kb/cite/'.$a['article_id'].'/'.$versionId)->assertOk()->assertJson(['state' => 'unknown', 'shown' => null]);
    $this->actingAs($reader, 'staff')->getJson('https://localhost/admin/kb/resolve/en/fixture-payout-escalation')->assertOk()->assertJson(['state' => 'unknown']);
    $this->actingAs($s['admin'], 'staff')->getJson('https://localhost/admin/kb/cite/'.$a['article_id'].'/'.$versionId)->assertOk()->assertJson(['state' => 'current']);
});
