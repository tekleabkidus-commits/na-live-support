# ADR 0019 — Addis AI provider record + data-use terms review (Doc 09 SEC-01)

**Status:** approved with the S09 plan (owner, 2026-08-19). SEC-01 requires provider
data-retention terms "reviewed and pinned in Doc 18's vendor register"; the register
does not exist yet — **this ADR is the interim record and says so** (the `[D18]` seam;
the vendor-register row supersedes this section when Doc 18 lands).

## The provider

**Addis AI** (platform.addisassistant.com; API `https://api.addisassistant.com/v1`),
the owner's ruled provider (PROJECT-HANDOFF-v8 §3). OpenAI-compatible; one model
served as of 2026-08-19 (`addis-አሌፍ-፩`), so fast/strong tiers are config-routing to
one model until the catalog grows. Capability record: S09 STEP 0.2 (structured output
0/23 malformed; hardened identity/language rules required in the system layer and
pinned in the golden suite; no embeddings endpoint — ADR 0018).

## Data-use terms review (fetched 2026-08-20 from addisassistant.com/terms, effective 2026-07-13)

Findings, quoted:

- **Training on content is an explicit reserved right**: the license covers use to
  "evaluate, test, research, develop, train, fine-tune, and improve models, voices,
  systems, and related products."
- **No stated retention limit**: the license lasts "for as long as necessary for
  those purposes."
- **No confidentiality on the standard service**: "No duty of confidentiality applies
  to Content merely because you submit it through the standard Services."
- **Sensitive data requires written agreement**: "Do not submit … sensitive personal
  data unless it is necessary, lawful, and you have implemented appropriate
  safeguards and obtained our written agreement where required."

## Consequences (binding on this build; the production half is the owner's)

1. **Non-production is fixtures-only BY MECHANISM** (`FixturesOnlyEgress`, S09
   step 1) — synthetic fixtures may be retained/trained on without harm.
2. **PRODUCTION EGRESS OF REAL CUSTOMER TEXT IS BLOCKED ON AN OWNER DECISION**: under
   these terms, real conversation text would be trainable, retained indefinitely, and
   non-confidential. Before any production key exists the owner must either obtain a
   written data-processing agreement with Addis AI (no-training / confidentiality /
   retention terms) or switch providers (the seam makes that a config change).
   Carried as an `[OA]` row beside the key-rotation row; Doc 18's vendor register
   inherits it with exit criteria (R-04).
3. **Send-minimization binds harder** (SEC-01): only the T-02 context layers, never
   full profiles; SEC-02's masking applies before egress; SEC-04's allowlist excludes
   RG content and staff notes from prompts entirely.
4. The **rate-card config** (T-07) records Addis AI's pay-as-you-go pricing when the
   owner supplies the current sheet; the ~270–420-token fixed preamble overhead
   observed at STEP 0 is metered as real cost, not assumed away.
