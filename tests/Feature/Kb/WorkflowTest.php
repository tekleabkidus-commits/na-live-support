<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

require_once __DIR__.'/KbSceneHelpers.php';

/**
 * Doc 10 FR-07 / FR-13 / BR-01 / BR-02 / AC-01 / AC-10 (S10 step 4): the workflow
 * matrix — Draft → submit → In review → request changes (comment) → Draft → submit →
 * approve/publish (mints the immutable version, humans live immediately, retrieval
 * pending for a public+ai_usable variant, the kb.article.published event in the same
 * transaction) → unpublish (chunks retire in-transaction) → archive / un-archive; the
 * SELF-PUBLISH collapse (edit + publish on one person) is audited as such and refused
 * for a publisher without edit; every door on its CLOSED key; illegal edges are 409
 * transition_rejected; AC-10's alt gate blocks a PUBLIC publish and LISTS the blocks.
 * Plus the sole-writer sweep: Transitions.php is the only writer of variant status.
 */
it('AC-01: the matrix — submit → request changes (comment, back to draft) → submit → approve mints v1 (published, index pending, event emitted) → unpublish retires → archive/un-archive; illegal edges are 409; keys are closed', function (): void {
    $s = kbScene();
    $a = kbArticle($s);
    $v = $a['variant_id'];
    $author = kbActor(['kb.articles.edit'], 'author');
    $reviewer = kbActor(['kb.articles.publish'], 'reviewer');
    $url = 'https://localhost/admin/kb/variants/'.$v;

    $this->actingAs($reviewer, 'staff')->postJson($url.'/submit')->assertStatus(403); // submit needs edit
    $this->actingAs($author, 'staff')->postJson($url.'/publish')->assertStatus(403); // publish needs the publish key
    $this->actingAs($author, 'staff')->postJson($url.'/submit')->assertOk()->assertJson(['status' => 'in_review']);
    $this->actingAs($author, 'staff')->postJson($url.'/submit')->assertStatus(409)->assertJson(['code' => 'transition_rejected']);

    $this->actingAs($reviewer, 'staff')->postJson($url.'/request-changes', ['comment' => 'Name the settlement bank.', 'block_anchor' => 'b1'])->assertOk()->assertJson(['status' => 'draft']);
    $thread = $this->actingAs($author, 'staff')->getJson($url.'/comments');
    expect($thread->json('open'))->toBe(1)->and($thread->json('comments.0.block_anchor'))->toBe('b1');
    $this->actingAs($author, 'staff')->postJson('https://localhost/admin/kb/comments/'.$thread->json('comments.0.id').'/resolve')->assertOk();
    $this->actingAs($author, 'staff')->postJson($url.'/submit')->assertOk();

    $published = $this->actingAs($reviewer, 'staff')->postJson($url.'/publish', ['note' => 'approved']);
    $published->assertOk();
    expect($published->json('version'))->toBe(1)->and($published->json('self_published'))->toBeFalse()->and($published->json('index'))->toBe('pending');
    bindTenant($s['tenant']);
    $row = DB::table('kb_article_variants')->where('id', $v)->first();
    expect($row->status)->toBe('published')->and($row->current_version_id)->toBe($published->json('version_id'))->and($row->translation_state)->toBe('current')->and($row->published_at)->not->toBeNull();
    expect(DB::table('outbox')->where('name', 'kb.article.published')->exists())->toBeTrue('the publish event rides the outbox in the same transaction')
        ->and(DB::table('audit_log')->where('action_key', 'kb.variant.published')->where('entity_id', $v)->exists())->toBeTrue();

    $this->actingAs($reviewer, 'staff')->postJson($url.'/unpublish')->assertOk()->assertJson(['status' => 'draft']);
    $this->actingAs($reviewer, 'staff')->postJson($url.'/archive')->assertOk()->assertJson(['status' => 'archived']);
    $this->actingAs($author, 'staff')->postJson($url.'/submit')->assertStatus(409);
    $this->actingAs($reviewer, 'staff')->postJson($url.'/unarchive')->assertOk()->assertJson(['status' => 'draft']);
});

it('FR-07 self-publish collapse: edit+publish on one person publishes straight from Draft and is audited kb.variant.self_published; a publisher WITHOUT edit is refused from Draft', function (): void {
    $s = kbScene();
    $a = kbArticle($s);
    $url = 'https://localhost/admin/kb/variants/'.$a['variant_id'];
    $publisherOnly = kbActor(['kb.articles.publish'], 'publisher');
    $refused = $this->actingAs($publisherOnly, 'staff')->postJson($url.'/publish');
    $refused->assertStatus(409);
    expect($refused->json('detail'))->toContain('edit + publish');

    $self = $this->actingAs($s['admin'], 'staff')->postJson($url.'/publish');
    $self->assertOk();
    expect($self->json('self_published'))->toBeTrue();
    bindTenant($s['tenant']);
    expect(DB::table('audit_log')->where('action_key', 'kb.variant.self_published')->where('entity_id', $a['variant_id'])->exists())->toBeTrue()
        ->and(DB::table('audit_log')->where('action_key', 'kb.variant.published')->where('entity_id', $a['variant_id'])->exists())->toBeFalse();
});

it('AC-10: with kb.require_alt on, a PUBLIC publish with an image lacking alt text is refused and LISTS the blocks; an internal article is not gated; alt supplied → publishes', function (): void {
    $s = kbScene();
    $a = kbArticle($s);
    $url = 'https://localhost/admin/kb/variants/'.$a['variant_id'];
    $this->actingAs($s['admin'], 'staff')->putJson($url.'/working-copy', ['content' => ['blocks' => [['id' => 'img1', 'type' => 'image', 'storage_key' => 't/x/1.png', 'alt' => ''], ['id' => 'img2', 'type' => 'image', 'storage_key' => 't/x/2.png', 'alt' => 'Deposit screen'], ['id' => 'img3', 'type' => 'image', 'storage_key' => 't/x/3.png']]]])->assertOk();
    $blocked = $this->actingAs($s['admin'], 'staff')->postJson($url.'/publish');
    $blocked->assertStatus(409);
    expect($blocked->json('missing_alt'))->toBe(['img1', 'img3']);

    $this->actingAs($s['admin'], 'staff')->putJson($url.'/working-copy', ['content' => ['blocks' => [['id' => 'img1', 'type' => 'image', 'storage_key' => 't/x/1.png', 'alt' => 'Telebirr menu']]]])->assertOk();
    $this->actingAs($s['admin'], 'staff')->postJson($url.'/publish')->assertOk();

    bindTenant($s['tenant']);
    $internal = kbArticle($s, '[FIXTURE] Internal playbook', 'internal');
    $this->actingAs($s['admin'], 'staff')->putJson('https://localhost/admin/kb/variants/'.$internal['variant_id'].'/working-copy', ['content' => ['blocks' => [['id' => 'i1', 'type' => 'image', 'storage_key' => 't/x/9.png']]]])->assertOk();
    $this->actingAs($s['admin'], 'staff')->postJson('https://localhost/admin/kb/variants/'.$internal['variant_id'].'/publish')->assertOk();
});

it('sole-writer sweep (the Doc 06 status-writer class): Transitions.php is the ONLY file under app/ that writes kb_article_variants.status', function (): void {
    $writers = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app'), FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $source = (string) php_strip_whitespace($file->getPathname());
        $rel = str_replace(base_path().'/', '', $file->getPathname());
        $touchesVariants = str_contains($source, 'kb_article_variants');
        // Raw SQL naming the column in the SET clause (a `status = 'published'` predicate in the
        // WHERE clause is a read, not a write — S10 step 8's translation-state consumer filters on it),
        // or a builder ->update([...]) array carrying 'status' => on a file that touches the variants
        // table (an event payload's 'status' key is not a write).
        $writesStatus = preg_match('/UPDATE\s+kb_article_variants\s+SET\s+(?:(?!\bWHERE\b)[^;])*\bstatus\s*=/i', $source) === 1
            || ($touchesVariants && preg_match('/->update\(\s*\[[^\]]*[\'"]status[\'"]\s*=>/s', $source) === 1);
        if ($writesStatus) {
            $writers[] = $rel;
        }
    }
    expect($writers)->toBe(['app/Domain/Kb/Workflow/Transitions.php'], 'a second status writer: '.implode(', ', $writers));
});
