# S13 step 9 — P-KB-VIEWED (Doc 10 core edit; PROPOSED 2026-09-04, NOT landed)

**The defect (found LIVE on the harness, never by Pest):** `GET /api/widget/v1/kb/articles/{id}` answers **500** for every visitor: `KbHelpController::article` calls `UsageEvents::viewed()` (the FR-12 `kb.article.viewed` event) OUTSIDE a transaction, and the outbox publisher refuses by law (Doc 01 T-03: "the outbox row must commit with the business write it describes"). Pest never saw it because every Feature test runs INSIDE a rolled-back transaction — the guard is satisfied by the harness's own transaction. On the live stack the reader is `HelpContent::article` (reads only) and the view event has no business write of its own; the publisher still needs the transactional boundary.

**Diff (one statement, Doc 10-owned file `app/Http/Controllers/Widget/KbHelpController.php`):**
```php
-        $this->usage->viewed($variantId, 'widget'); // FR-12; conversation attribution rides [D13]
+        DB::transaction(fn () => $this->usage->viewed($variantId, 'widget')); // FR-12; the outbox row needs its transaction (Doc 01 T-03) — found live at S13 step 9
```
(+ `use Illuminate\Support\Facades\DB;`). **Control at landing:** a Feature test that asserts the article door outside any test transaction — `tests/Feature/Kb/WidgetHelpLiveTest.php` using `DB::afterCommit` shape or the RefreshDatabase-less path — plus the harness `widget-help.spec.ts` E03/E04/E07 tests turning green. **Register:** Doc 10 FR-12/FR-18's widget half was PARTIAL [D13]; the browse/search/feedback legs are proven at step 9; the article leg is OWED on this packet.
