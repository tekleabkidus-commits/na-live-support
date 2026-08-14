# ADR 0014 — Phone normalization via giggsey/libphonenumber-for-php

- **Status:** Accepted (owner approval at S08 plan approval, 2026-08-14; conditions
  (a)–(c) below are the approval's own text)
- **Date:** 2026-08-14 (written BEFORE the code it covers, per the R-15 precedent)
- **Spec:** Doc 08 FR-04 ("phone (E.164, normalized on save with country inference
  help)"), §14 ("E.164 stored, national format shown with country flag"), BR-04,
  B4-S08 I08 ("the country is shown, never assumed" — the Kenyan-number frame)

## Context

Doc 08 makes a phone number an IDENTITY: unique per `(tenant, type, value)` (BR-04,
DB-enforced), matched by the resolver, guarded by FR-09's duplicate-phone block. That
gives normalization a safety property the UI concerns don't have on their own: **the
unique index assumes one phone normalizes one way, forever.** Two spellings of one
number that normalize differently become two identities — precisely the silent
misattribution BR-02 exists to prevent, in a product where a wrong link misroutes
money conversations. The drawn record also requires non-Ethiopian numbers handled
correctly (I08 draws a Kenyan number with its own flag and format, "shown, never
assumed"), so an in-house ET-only normalizer is not a smaller-risk option — it is a
larger one, an invented subset of a well-known hard problem.

## Decision

Add **`giggsey/libphonenumber-for-php`** (the maintained PHP port of Google's
libphonenumber) as the single normalizer of record, wrapped in one Doc 08 class
(`App\Domain\Customers\Identity\PhoneNumber`) so no other code touches the library
directly. Parsing uses the country-inference help FR-04 names (region hint from the
input's country selector or the tenant default); storage is E.164; display derives
national format + region code from the STORED value.

**Conditions bound at approval:**

- **(a) Pinned version.** Composer requires an EXACT version (no `^`/`~` drift);
  `composer.lock` carries the pin and this ADR records it at the install commit:
  **installed version: `9.0.36` (composer.json requires exactly `9.0.36`,
  installed at the S08 step-2 commit).** A bump is a deliberate change to this
  ADR, never a side effect of `composer update`. Install-time note: `composer
  audit` flagged 6 advisories — all on the PRE-EXISTING transitive
  `league/commonmark`, none on this package; filed to the Doc 18 notes.
- **(b) Normalize once at write.** A stored `customer_identities.value` is written by
  exactly one normalization at save time and is NEVER silently re-normalized by a
  library/metadata upgrade. No code path re-parses stored values to rewrite them;
  display formatting reads the stored E.164 and never writes back.
- **(c) Upgrade policy.** If a metadata upgrade would change what an already-stored
  value normalizes to (a renumbered range, a new region rule), the stored value
  STANDS — it is history, and rewriting it would move an identity under BR-04's
  unique index without a human decision. The upgrade ships with a one-off comparison
  report (stored value vs. fresh normalization) listing divergent rows for HUMAN
  consolidation through FR-09/FR-10's audited doors — the same "humans consolidate"
  posture as the S08 legacy backfill (ANSWERS, correction B2). New saves use the new
  metadata from the moment it lands.

## Consequences

- One runtime dependency; server-side only (the widget/client never bundles it).
  The wrapper is the seam any future replacement swaps behind.
- The normalize-once rule means a number typed two ways on two days can still meet
  FR-09's duplicate block (same E.164) but a metadata change can never silently fuse
  or split identities — that is exactly the trade the approval chose: determinism of
  stored identity over freshness of formatting rules.
- Declined alternatives, for the record: an in-house ET-focused normalizer (invented
  subset; fails I08's non-ET reality; the riskier option per the approval), a JS-side
  library (the server is the normalizer of record — a client cannot be), regex
  normalization (fails everything above quietly).
