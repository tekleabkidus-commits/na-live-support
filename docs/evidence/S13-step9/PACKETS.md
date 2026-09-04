# S13 step 9 — P-KB-VIEWED (Doc 10 core edit; PROPOSED 2026-09-04, NOT landed)

**The defect (found LIVE on the harness, never by Pest):** `GET /api/widget/v1/kb/articles/{id}` answers **500** for every visitor: `KbHelpController::article` calls `UsageEvents::viewed()` (the FR-12 `kb.article.viewed` event) OUTSIDE a transaction, and the outbox publisher refuses by law (Doc 01 T-03: "the outbox row must commit with the business write it describes"). Pest never saw it because every Feature test runs INSIDE a rolled-back transaction — the guard is satisfied by the harness's own transaction. On the live stack the reader is `HelpContent::article` (reads only) and the view event has no business write of its own; the publisher still needs the transactional boundary.

**Diff (one statement, Doc 10-owned file `app/Http/Controllers/Widget/KbHelpController.php`):**
```php
-        $this->usage->viewed($variantId, 'widget'); // FR-12; conversation attribution rides [D13]
+        DB::transaction(fn () => $this->usage->viewed($variantId, 'widget')); // FR-12; the outbox row needs its transaction (Doc 01 T-03) — found live at S13 step 9
```
(+ `use Illuminate\Support\Facades\DB;`). **Control at landing:** a Feature test that asserts the article door outside any test transaction — `tests/Feature/Kb/WidgetHelpLiveTest.php` using `DB::afterCommit` shape or the RefreshDatabase-less path — plus the harness `widget-help.spec.ts` E03/E04/E07 tests turning green. **Register:** Doc 10 FR-12/FR-18's widget half was PARTIAL [D13]; the browse/search/feedback legs are proven at step 9; the article leg is OWED on this packet.

## P-KB-VIEWED — the fail-first control, RUN RED at the Batch B checkpoint (2026-09-05)

**Why a Pest control needs a different shape:** every Feature test runs inside the `TestCase` transaction, so the outbox guard (`EventPublisher::publish`, `DB::transactionLevel() === 0` → `LogicException`) can never fire under Pest — that is exactly why the live 500 escaped it. The control therefore asserts the SHAPE the guard needs: the article door must BEGIN a transaction of its own around the viewed event (a savepoint under the wrapper, a real transaction on the live stack). Laravel fires `TransactionBeginning` on both paths.

**The control (`tests/Feature/Kb/WidgetHelpLiveTest.php` — text only until the countersign; it was written, run once against the UNPATCHED door, and removed):**
```php
it('P-KB-VIEWED control: the article door begins its own transaction around kb.article.viewed', function (): void {
    [$s, $key, $token, $cookie] = widgetKbScene();
    $pub = kbArticle($s, '[FIXTURE] Telebirr deposits');
    test()->actingAs($s['admin'], 'staff')->postJson('https://localhost/admin/kb/variants/'.$pub['variant_id'].'/publish')->assertOk();
    bindTenant($s['tenant']);
    kbWidgetIndexVariant($pub['variant_id']);

    $begun = 0;
    Event::listen(TransactionBeginning::class, function () use (&$begun): void { $begun++; });
    kbWidget($key, $token, $cookie, 'GET', 'kb/articles/'.$pub['article_id'])->assertOk();

    expect($begun)->toBeGreaterThanOrEqual(1, 'the viewed event must ride a transaction the door opens (Doc 01 T-03) — on the live stack the guard answers 500 otherwise');
});
```
(runs alongside `WidgetHelpContractTest.php`, whose `widgetKbScene`/`kbWidget`/`kbWidgetIndexVariant` helpers it reuses.)

**RED first (`p-kb-viewed-control-RED-01.txt`):** `Tests: 1 failed, 3 passed (61 assertions)` — the control red (`$begun` = 0: the door opens no transaction), the three contract tests green (the same door answers 200 under the wrapper — the wrapper's transaction is what hides the defect).

**Second control, live:** `widget-help.spec.ts` E03/E04/E07 on the harness (both projects) — RED today (`step9a` and this checkpoint's browser battery), expected GREEN after the one-statement diff.

**Landing order once countersigned:** the diff → the control file committed → `WidgetHelpContractTest` + the control green → E03/E04/E07 green on the harness → the Doc 10 FR-12/FR-18 widget-half register row moves from PARTIAL [D13] to DONE.

## MediaStatePush — the advisor's acceptance (2026-09-05)

`Doc13/MediaStatePush`'s two consumer declarations on Doc 04's `media.stored` / `media.infected` are accepted by the advisor as registry entries under A-45 (no Doc 04 file changed).
