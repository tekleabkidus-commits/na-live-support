<?php

declare(strict_types=1);

use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\Event;

/*
|--------------------------------------------------------------------------
| P-KB-VIEWED (Doc 13-owned, permanent; countersigned 2026-09-05). The
| article door publishes FR-12's kb.article.viewed through the outbox, and
| the outbox refuses a publish outside a transaction (Doc 01 T-03). Found
| live on the HARNESS at S13 step 9; Pest's per-test transaction wrapper
| masked it since S10 — every Feature test runs INSIDE the TestCase
| transaction, so the guard could never fire under Pest. This control asserts
| the SHAPE the guard needs: the door BEGINS a transaction of its own around
| the event (a savepoint under the wrapper, a real transaction live). It ran
| RED once against the unpatched door (docs/evidence/S13-step9/
| p-kb-viewed-control-RED-01.txt) before the one-statement diff landed.
|--------------------------------------------------------------------------
*/

it('P-KB-VIEWED control: the article door begins its own transaction around kb.article.viewed', function (): void {
    [$s, $key, $token, $cookie] = widgetKbScene();
    $pub = kbArticle($s, '[FIXTURE] Telebirr deposits');
    test()->actingAs($s['admin'], 'staff')->postJson('https://localhost/admin/kb/variants/'.$pub['variant_id'].'/publish')->assertOk();
    bindTenant($s['tenant']);
    kbWidgetIndexVariant($pub['variant_id']);

    $begun = 0;
    Event::listen(TransactionBeginning::class, function () use (&$begun): void {
        $begun++;
    });
    kbWidget($key, $token, $cookie, 'GET', 'kb/articles/'.$pub['article_id'])->assertOk();

    expect($begun)->toBeGreaterThanOrEqual(1, 'the viewed event must ride a transaction the door opens (Doc 01 T-03) — on the live stack the guard answers 500 otherwise');
});
