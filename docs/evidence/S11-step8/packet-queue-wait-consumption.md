# S11 step 8 — PACKET PROPOSAL (escalate-first; countersign BEFORE landing): `{{queue_wait}}` CONSUMPTION — Doc 05 + Doc 09 core edits

## Finding
- Doc 05 `TemplateResolver::render($botId, $language, $key, $variables)` merges `$variables + SEAM_FIXTURES`
  (`queue_wait => 'a few minutes'`) inside a `final` class; the ONLY render call in the tree today is
  `ModePolicy::redirectReply()` (`'redirect'`, no variables). The FR-28 `handoff` / `off_hours` templates
  (the `{{queue_wait}}` carriers) are seeded and operator-editable but RENDERED NOWHERE in code — Doc 09's
  `HandoffDecision` sends the plain `ai.handoff.template` lang string (no placeholder) and its docblock
  says "[D11]'s live wait estimate joins that template when Doc 11 lands". Two paths, one seam.
- Doc 11 built the provider: `Routing/Estimator/QueueWaitVariable::forConversation($conversationId, $language)`
  → the localized band phrase (`routing.estimate.band.*`), bands only (SEC-05).

## Proposed diffs (≤ 3 lines each; no assertion changes except the pins named)
**Doc 05 — `TemplateResolver`:** retire the `queue_wait` SEAM_FIXTURES entry (the seam's own comment: "when
the owning module lands, its real value replaces the fixture and this entry is removed") and, in the
docblock, name `QueueWaitVariable` as the source. `tests/Feature/Telegram/DirectSurfaceTest.php:100-107`
(the stated-seam pin) INVERTS deliberately: the rendered `{{queue_wait}}` equals the provider's phrase.
**Doc 05 — the handoff/off-hours render (when Doc 05's sending path renders them; today none does):** the
call site passes `'queue_wait' => $queueWait->forConversation($conversationId, $language)`. Since no such
call exists yet, this half is a NOTE on Doc 05's FR-28 row (the reopener already filed at 2b grows one line).
**Doc 09 — `HandoffDecision` (line ~87):**
```diff
-                $template = __('ai.handoff.template', [], $language);
+                $template = __('ai.handoff.template', ['queue_wait' => $this->queueWait->forConversation($conversation->identifier(), $language)], $language);
```
plus `lang/en|am/ai.php` `handoff.template` gaining the `:queue_wait` slot ("…will be with you shortly —
about :queue_wait.") — R-14 queue, both languages; `ChatbotTurnTest`/`HandoffDecision` pins that assert the
byte-exact template move deliberately with the interpolation named.

## Proofs riding the packet
Doc 05 `DirectSurfaceTest` (the seam pin inverted), Doc 09 `ChatbotTurnTest` (handoff copy carries the phrase,
never a number), Doc 11 `EstimatorTest` (the phrase source), and the R-14 rows for the two languages.
Closes Doc 09 FR-12's [D11] row ("the live queue-wait estimate is Doc 11 data") on landing.
