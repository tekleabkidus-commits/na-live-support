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

## P-WIDGET-KEY — PROPOSED at S13 step 10 (schema growth; NOT landed; interim shipped)

**The conflict:** B3-S08 J06 draws the Install tab with the COMPLETE snippet (`…/w/wk_9f21.js`) at any time. Doc 02's issuer stores a widget key as `prefix + sha256(secret)` only (`tenant_keys`), and the shim is addressed by the FULL key (`X-Tenant-Key` = the plaintext; `POST session` presents the same key — Doc 02 FR-21). So after creation nothing on the platform can render the snippet again. Doc 02 FR-21 makes the key a PUBLIC identifier bound to the origin allowlist (it sits in every host page's HTML) — the "shown once" rule is the API-key pattern applied to a public handle.

**Interim shipped at step 10 (honest, drawn-vs-built filed):** the complete snippet rides the CREATE response only (`install.snippet`); `GET /admin/widgets/{id}` answers `install.snippet: null, install.key_shown_once: true` with the CSP block and origins intact. The Designer UI (step 11) says so inline. Deviation class 1/3 (document conflict with a technical mechanism), row D13-D06.

**The packet (one expand migration, countersign BEFORE migration per R-D13-7 — the column holds a public identifier, not a secret; stated so the advisor rules on the class):** `ALTER TABLE widgets ADD COLUMN key_public text` (nullable; app INSERT/UPDATE grant on that column only), written by `WidgetDesigner::create` with the plaintext the issuer returns, backfilled for the harness widget by its seeder; `InstallSnippet::forWidget` renders from it; `GET show` returns the full snippet. Control: `WidgetDesignerTest` asserts the show-door snippet equals the create-door snippet; `SecretRotationTest`'s no-plaintext-in-audit pin stays (a widget key is not a secret; the identity secret is). Nothing in Doc 02's key resolution changes.

## P-CAL-WEEKDAY — ORDERED by the Batch C opening ruling (2026-09-05); escalate-first, NOT landed; the diff is `docs/evidence/S13-step11/p-cal-weekday.diff`

**The mechanism (the filed Doc 12 finding's disposition, not a new finding):** `HoursContract::windowFor` projects TODAY's open/close and the coming week's `days` from the DB clock (`clock_timestamp()`, C-11 — there is NO seam to freeze: the class reads the database, not an injected clock). `CalendarTest` "P-A53 REAL source" paints Mon–Fri 08–17 with a recurring Friday holiday (2026-09-11) and asserts `windowFor(null)` as `08:00–17:00` — so BOTH halves of that one assertion are date-sensitive: weekends answer `00:00–24:00`, and the week that holds 2026-09-11 drops day 5 from `days`. Every FIXED-DATE assertion in the test (`isOpen`, `nextOpen`, the holiday skip) is already clock-independent.

**The diff (Doc 12-owned `tests/Feature/Hours/CalendarTest.php`; +25 / −3; no `app/` file touched):** the `windowFor` open/close assertion is REMOVED from "P-A53 REAL source" (explicitly flagged in a comment naming the mechanism) — every other line of that test byte-untouched; a NEW test "P-CAL-WEEKDAY" paints a seven-day 08–17 calendar with no holiday and asserts `windowFor` = `08:00 / 17:00 / [1..7]` — weekday-independent BY CONSTRUCTION (whatever day the DB clock lands on is painted the same), and records the projection day it ran on so a red names its day.

**Proof, half taken:** `docs/evidence/S13-step11/p-cal-weekday-proof-saturday-01.txt` — run under the REAL Saturday clock (`Sat Sep 5 07:30:32 UTC 2026`), `Tests: 7 passed (32 assertions)` (the unpatched file answers 1 failed on the same clock — the checkpoint battery and CI 33947830795). **The Tuesday half is OWED and said plainly:** the DB clock cannot be frozen from a test (a Postgres session has no fake `clock_timestamp()`), so the Tuesday proof is the same file green on a weekday run — the next weekday CI stage or a weekday local run — recorded when it happens. The seven-day construction is the independence argument; the Tuesday run is its second witness, not its condition. Landing order: countersign → the diff lands in its own commit → Doc 12's suite green unmodified elsewhere → BEFORE the Batch C checkpoint push.
