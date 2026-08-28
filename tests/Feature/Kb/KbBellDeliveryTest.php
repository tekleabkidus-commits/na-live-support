<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

require_once __DIR__.'/KbSceneHelpers.php';

/**
 * S10 step 7b packet P4 (the A-44 amendment + the Doc 07 arms, the S09 packet-2 shape):
 * per arm — EXACTLY the ruled recipients, never the actor, never an unpermitted holder,
 * identifiers only, redelivery mints nothing twice (C-09).
 */
function kbBellRows(string $category): array
{
    return DB::table('inbox_notifications')->where('category', $category)->orderBy('user_id')->pluck('user_id')->all();
}

it('kb.variant.submitted → every kb.articles.publish holder EXCEPT the actor; an editor without the key gets nothing; redelivery mints nothing twice', function (): void {
    $s = kbScene();
    $a = kbArticle($s, '[FIXTURE] Review me');
    $author = kbActor(['kb.articles.edit'], 'author');
    $reviewer = kbActor(['kb.articles.publish'], 'reviewer');
    $editorOnly = kbActor(['kb.articles.edit'], 'editor-only');
    $this->actingAs($author, 'staff')->postJson('https://localhost/admin/kb/variants/'.$a['variant_id'].'/submit')->assertOk();
    bindTenant($s['tenant']);
    $eventId = relayOutboxRow('kb.variant.submitted');
    runListProjector($eventId);
    $expected = [$reviewer->identifier(), $s['admin']->identifier()];
    sort($expected);
    expect(kbBellRows('notify.kb.review_requested'))->toBe($expected, 'the publish holders minus the actor — the editor-only user gets nothing');
    $payload = json_decode((string) DB::table('inbox_notifications')->where('category', 'notify.kb.review_requested')->value('payload'), true);
    expect($payload)->toHaveKeys(['article_id', 'variant_id'])->and(json_encode($payload))->not->toContain('Review me');
    runListProjector($eventId);
    expect(kbBellRows('notify.kb.review_requested'))->toHaveCount(2);
});

it('kb.variant.changes_requested → the SUBMITTER only (resolved from the audit trail, never the reviewer); kb.lock.taken_over → the displaced holder only', function (): void {
    $s = kbScene();
    $a = kbArticle($s, '[FIXTURE] Send it back');
    $author = kbActor(['kb.articles.edit'], 'author');
    $this->actingAs($author, 'staff')->postJson('https://localhost/admin/kb/variants/'.$a['variant_id'].'/submit')->assertOk();
    $this->actingAs($s['admin'], 'staff')->postJson('https://localhost/admin/kb/variants/'.$a['variant_id'].'/request-changes', ['comment' => '[FIXTURE] Name the bank.'])->assertOk();
    bindTenant($s['tenant']);
    $eventId = relayOutboxRow('kb.variant.changes_requested');
    runListProjector($eventId);
    expect(kbBellRows('notify.kb.changes_requested'))->toBe([$author->identifier()], 'the submitter, never the reviewer');
    $payload = json_decode((string) DB::table('inbox_notifications')->where('category', 'notify.kb.changes_requested')->value('payload'), true);
    expect($payload)->toHaveKeys(['article_id', 'variant_id', 'comment_id'])->and(json_encode($payload))->not->toContain('Name the bank');

    $hanna = kbActor(['kb.articles.edit'], 'hanna');
    $dawit = kbActor(['kb.articles.edit'], 'dawit');
    $this->actingAs($hanna, 'staff')->postJson('https://localhost/admin/kb/variants/'.$a['variant_id'].'/lock')->assertOk();
    $this->actingAs($dawit, 'staff')->postJson('https://localhost/admin/kb/variants/'.$a['variant_id'].'/lock/takeover')->assertOk();
    bindTenant($s['tenant']);
    $lockEvent = relayOutboxRow('kb.lock.taken_over');
    runListProjector($lockEvent);
    expect(kbBellRows('notify.kb.lock_taken_over'))->toBe([$hanna->identifier()], 'the displaced holder only — never the taker');
});
