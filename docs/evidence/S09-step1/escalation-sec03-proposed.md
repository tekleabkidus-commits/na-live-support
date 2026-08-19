# ESCALATION — SEC-03 discharge: the ScrubSecrets core edit (S09 step 1, R-D09-1)

**Status: PROPOSED — awaiting advisor countersign. Nothing lands before it returns.**
Target: `app/Support/Logging/ScrubSecrets.php` (Doc 02 core file). This packet carries
the exact diff and the plant→detect→remove test plan together, per the ruling's
escalate-first mechanics and the owner's step-1 instruction.

## 1. The proposed diff (verbatim)

```diff
--- a/app/Support/Logging/ScrubSecrets.php
+++ b/app/Support/Logging/ScrubSecrets.php
@@ (the VALUE_PATTERNS docblock + const, currently lines 78–87)
     /**
      * NFR-06 value-shape patterns (Doc 05): the bot-token format BotFather
      * issues, and the file-download URL that embeds it (T-07). File-URL first:
      * scrubbing the whole URL beats leaving `api.telegram.org/file/[scrubbed]`
      * fragments behind.
+     *
+     * SEC-03 DISCHARGE (Doc 08 carry; ruling R-D09-1, S09 step 1): phone and
+     * email VALUE patterns join the roster BEFORE the platform's first
+     * external egress of conversation text (Doc 09) starts logging provider
+     * request/response context — exactly where phone/email values begin to
+     * flow. Key-shape scrubbing cannot catch a phone under `detail` or an
+     * email inside the message string; these patterns must. Order matters:
+     * email before phones, so an address whose local part is phone-shaped is
+     * scrubbed WHOLE, never left as `[scrubbed]@domain`.
+     *
+     * BROAD PATTERN + EXPLICIT ALLOWLIST (the platform-notes law — never a
+     * sweep narrowed to survive its own guard). Over-scrubbing is the safe
+     * direction (this file's own header): the phone patterns tolerate
+     * space/dash separators and will occasionally eat a phone-shaped digit
+     * run that was not a phone; that trade is deliberate and documented.
      */
     private const VALUE_PATTERNS = [
         '~https?://api\.telegram\.org/file/\S+~i',
         '/\b\d{6,12}:[A-Za-z0-9_-]{30,}(?![A-Za-z0-9_-])/',
+        // Email addresses — the standard shape, every depth + the message.
+        '/[A-Za-z0-9._%+-]+@[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?\.[A-Za-z]{2,}/u',
+        // E.164 phones, separator-tolerant: "+251912345678", "+251-900001234",
+        // "+251 91 234 5678" — 7..15 total digits per the ITU bound.
+        '/\+\d{1,3}(?:[\s-]?\d){6,12}(?!\d)/',
+        // Local Ethiopian format: 09/07 + 8 digits, separator-tolerant
+        // (R-D09-1 names both formats; 07 covers the 7-series mobiles).
+        '/(?<!\d)0[79](?:[\s-]?\d){8}(?!\d)/',
     ];

+    /**
+     * Explicit allowlist consulted BEFORE value-pattern redaction: a match
+     * survives only if one of these patterns accepts the WHOLE matched
+     * string. Ships EMPTY — nothing benign is known to wear the shapes
+     * above in a log record. Every future entry carries a citation to the
+     * ruling or evidence that admitted it; narrowing a VALUE_PATTERN to
+     * dodge a false positive is FORBIDDEN (the sweep-narrowed-to-survive-
+     * its-own-guard law) — this allowlist is the only sanctioned relief,
+     * and every addition re-plants the exact variant it exempts.
+     */
+    private const VALUE_ALLOWLIST = [];
+
@@ (scrubValue, currently lines 131–135)
-    /** NFR-06: redact Telegram token/file-URL shapes wherever they appear. */
+    /** NFR-06 + SEC-03: redact secret/PII value shapes wherever they appear. */
     private function scrubValue(string $value): string
     {
-        return (string) preg_replace(self::VALUE_PATTERNS, self::REDACTED, $value);
+        foreach (self::VALUE_PATTERNS as $pattern) {
+            $value = (string) preg_replace_callback(
+                $pattern,
+                static function (array $match): string {
+                    foreach (self::VALUE_ALLOWLIST as $allowed) {
+                        if (preg_match($allowed, $match[0]) === 1) {
+                            return $match[0];
+                        }
+                    }
+
+                    return self::REDACTED;
+                },
+                $value,
+            );
+        }
+
+        return $value;
     }
```

Nothing else in the file changes: NEEDLES, EXACT, EXEMPT_SUFFIXES, `__invoke`,
`scrub()`, `isSecretShaped()` are untouched. With VALUE_ALLOWLIST empty the callback
is behaviorally identical to the current `preg_replace` for the two existing patterns
— the Telegram suites prove that (they run unchanged).

## 2. Design decisions, stated for the countersign

- **Both ruled phone formats, as three honest patterns**: full E.164 (any country
  code, ITU 7–15 digit bound — the ruling says "E.164", not "Ethiopian E.164") and
  the local Ethiopian 09/07 + 8-digit form; both separator-tolerant (the fixture
  format `+251-900001234` is covered).
- **Digit-boundary lookarounds** `(?<!\d)`/`(?!\d)` keep the local pattern from
  matching inside longer digit runs, but a phone-shaped run inside a hex/UUID string
  WILL be scrubbed — over-scrubbing is the safe direction and is documented in the
  diff rather than narrowed away.
- **Email before phones** in pattern order, so a phone-shaped email local part
  scrubs as one unit.
- **The allowlist ships EMPTY as a mechanism** — broad pattern + explicit allowlist
  per the platform-notes law; entries enter only by citation, each with a re-plant.
- **The gate script (`ci/log-scrubber.sh`) is NOT edited** — the new proving suite
  joins the DOC 09 SUITES canonical command instead (the TelegramScrubberPatternsTest
  precedent exactly: Doc 05's value-pattern suite lives in Doc 05's canonical set,
  not in the Doc 02 gate).

## 3. The proving test plan — `tests/Security/PhoneEmailScrubberPatternsTest.php` (new file, Doc 09's)

Mirrors the TelegramScrubberPatternsTest idiom (S19 #7 counting bar: every absence
assertion first proves its scanner on a planted positive control) plus the
SecretsNeverSerializeTest per-sink plant→detect→rollback idiom for the four non-log
sinks. All values synthetic: `+251-90000xxxx` fixture phones, `@fixture…`/
`fixture-*@example.et` emails (the CLAUDE.md fixture law — a leak is
self-identifying).

**Sink 1 — the rendered log (the real `single` channel, path repointed):**

1. **Near-miss positive controls survive** (proving scanner + exact pattern boundary,
   one property off each): `+2519 12` (too few digits for E.164), `0812345678`
   (08 prefix — outside 09/07), `091234567` (9 digits), `fixture@localhost`
   (no TLD dot). Each must REACH the sink — write path + scanner proven non-vacuous.
2. **Planted sentinels are absent, `[scrubbed]` present**, under INNOCENT keys
   (`detail`, `note`, `url`) and inside the message string itself:
   - E.164: `+251900001234`, `+251-900001234`, `+251 90 000 12 34`;
   - local: `0900001234`, `0700001234`, `09 00 00 12 34`;
   - email: `fixture-customer@example.et`, `fixture.a+tag@ex-ample.co.uk`;
   - the composed shape a provider log line would carry: a fixture chat excerpt
     containing phone AND email in one string.
3. **Benign context survives** (the over-scrub bound stays useful): trace ids,
   UUIDs, `tenant_id` values, a timestamp `2026-08-19T21:30:00+03:00` (the `+03:00`
   offset must NOT be eaten — asserted explicitly), and an `_id`-suffixed key.

**Sinks 2–5 — jobs / outbox / audit_log / relayed events (the SecretsNeverSerialize
idiom, phone/email sentinels):**

4. **Per-sink positive control**: plant `FAKECRED-PHONE-<sink>` (`+251-90000…`) and
   `FAKECRED-EMAIL-<sink>` directly into each sink's storage inside a
   savepoint-rollback (jobs insert; outbox insert; audit insert; relayed-events
   row), prove the scanner DETECTS each, roll back, prove gone — the scanner is
   never blind.
5. **The sweep**: drive a log-bound flow carrying the sentinel phone (both formats)
   and email through real log context, then sweep ALL FIVE sinks for the sentinel
   VALUES — absent everywhere (the rendered sink because the scrubber ate them; the
   serialization sinks because no flow may carry log-bound context into them).

**The fail (scrubber stubbed) AND restored-pass — sabotage #15 of the approved
enumeration, executed at this step per R-D09-1's own terms:**

6. Backup `ScrubSecrets.php`; stub = remove exactly the three new patterns; run the
   new suite → RED on exactly the phone/email absence assertions (near-miss controls
   still green — the boundary proof is independent); capture
   `docs/evidence/S09-step1/sabotage-15-RED-01.txt`. Restore byte-identical
   (diff vs backup = ∅); suite green; capture `-RESTORED-01.txt`; tree proven clean.

**Regression floor in the same commit:** `bash ci/log-scrubber.sh` green
(LogScrubberTest + SecretsNeverSerializeTest — Doc 02's suites unchanged and
passing over the edited scrubber); TelegramScrubberPatternsTest green (the existing
value patterns' behavior pinned); the full correctness battery + `make gates` +
`make lint` per the step-commit law. BUILD-STATUS SEC-03 row flips only at the S09
close, citing the discharge commit (R-D09-1 term 4).

## 4. Step-1 shape note (context, not part of this escalation)

The step's other half — the fixtures-only NON-PRODUCTION EGRESS GUARD — is Doc 09's
own namespace (`app/Domain/Ai/Guards/`), needs no escalation, and lands in the same
step-1 commit: enforcement at the thin LLM client (the single egress choke point),
refusing any non-production provider call whose customer-content segments lack the
fixture tag, with its own plant control (sabotage #11). Sequenced BEFORE any
provider-touching test per the approved plan.
