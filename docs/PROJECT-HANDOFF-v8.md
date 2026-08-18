# PROJECT-HANDOFF-v8 — Na Live Support
## Written by the retiring Doc 08 advisor at Doc 08's verified close (361550a). Supersedes v7 (88341de) as the state of record.

You are the FIFTH advisor. Your document is **Doc 09 — the AI system**. One advisor per
document; you never consult, revive, or run parallel to a prior advisor chat. Your
predecessor closed Doc 08 and retires on the commit that files this kit.

---

## §1 — STATE OF THE BUILD

**Docs 01–08 are CLOSED and OWNER-ACCEPTED.** Roughly 65% of the way to MVP.

Doc 08 closed 2026-08-14: flip commit **361550a**, close-out **7e69094** (owner-grounded,
CI green all stages), register **78 DONE / 11 PARTIAL / 2 NOT DONE**, 91/91 family-exact
against the advisor's independent enumeration, sabotage ledger 16/16 item-by-item, no
false DONE. The two NOT DONE rows are SANCTIONED CARRIES — see §4 rows 0a/0b. They are
the first thing you deal with.

**Docs 01–08 are ALL escalate-first territory now.** Any edit to a file they own follows
escalate → advisor ruling → land. Never the reverse.

The permission catalog remains **CLOSED at 113** (registries/permissions.php; verify by
executing `count(require ...)`, never by reading). Render-gates are derivations of
existing keys. Standing rules are **EIGHTEEN, unchanged** — the authoritative list is
docs/PROJECT-HANDOFF-v7.md §6, which stays in the repo; re-read it there, do not work
from memory. Governance incidents now number **FIFTEEN** (§7).

---

## §2 — SOURCES OF TRUTH (read before ruling on anything)

1. `docs/Doc09-*.md` — the law for everything Doc 09 builds. Your FIRST private act:
   read it end to end and build a **gapless id enumeration by family, kept private,
   never shown to the session**. Beware the v7-era trap that survives: documents cite
   OTHER documents' ids inline ("Doc 07 NFR-07", "Doc 02 SEC-08") — a naive regex
   over-counts. Verify family boundaries against the section text itself.
2. `docs/BUILD-STATUS.md` — the Doc 08 close section, the S08 DESIGN-DEVIATIONS table,
   incidents #14 and #15 in full, risks 0a–0j, the platform notes.
3. `docs/ANSWERS-FROM-DOC-AUTHOR.md` — Q1–Q9 (Q9: registries normative, prose
   descriptive), A-1…A-42, R-1…R-26, SCHEMA AMENDMENT 2 with additions 7/8/9 and the
   grant delta.
4. `docs/adr/` — 0003, 0010, 0011, 0013, **0014** (libphonenumber, pinned 9.0.36).
5. `docs/DESIGN-FIDELITY-MANDATE.md` (v8) + `design/frozen/` — **217 files** (v7 said
   219; 217 is the ls-verified truth, filed as honest trail; this kit carries the
   correction).
6. `prompts/SESSION-08-doc08-customers.md` and `docs/SESSION-08-APPROVED-PLAN.md` — the
   exemplars your SESSION-09 and its plan audit mirror.

---

## §3 — DECISIONS MADE THIS GENERATION (binding)

- **A-38**: erasure initiation rides `customers.delete` — initiation only; Doc 17 owns
  approval/execution; Super-Admin-only by default (R-10).
- **A-39**: unblock rides `customers.block`; distinctness = separate door + mandatory
  reason + separate audit action (Q9 shape).
- **A-40**: channel→identity-type mapping lives in the Doc 08-owned ChannelIdentityMap,
  NOT on the ChannelAdapter contract (layering; R-16 preserved). Its fail-loud test
  fires on any unmapped channel.
- **A-41**: `pgsql_platform` use in migrations is scoped to one-time, migration-local
  tenant enumeration, actor logged. **A fence, not a precedent** — do not let Doc 09
  cite it for anything broader.
- **A-42**: the unlink anchor derives from channel physics; **v1-scoped**, reopened by
  Doc 13 (webchat) and Doc 14 (SMS). A MATCH against an existing identity is evidence;
  MINTING from a chat id is inference.
- **ADR 0014**: libphonenumber pinned 9.0.36; normalize-once-at-write; a re-normalization
  that would fuse two customers refuses loudly.
- **SCHEMA AMENDMENT 2** + additions: the Doc 08 tables; the customer_notes grant delta
  (customer_id UPDATE, replaced physics = an architecture assertion that only
  CustomerMerge writes it); addition 7 (customer_block_cascade_items, marker pattern);
  addition 8 (legacy index dropped; the arbiter is the only non-PK unique index —
  ON CONFLICT law); addition 9 (customer_field_values; history in audit_log only,
  checked against the frames before ruling).

### OWNER DECISIONS (given after v7 was written — they bind the Doc 09 session)

- **AI PROVIDER: Addis AI** (platform.addisassistant.com). Ethiopian company,
  purpose-built Amharic/Afan Oromo models, OpenAI-compatible API, pay-as-you-go,
  ETB billing available. Conditions attached by the retiring advisor and accepted:
  1. The provider seam is built **provider-agnostic** anyway (the OpenAI-compatible
     shape makes switching a baseURL/config change).
  2. Doc 09's plan STARTS with a **capability check, not a build**: fixture
     conversations through Addis AI, scored on (a) structured output — strict JSON,
     the classification shape RG detection needs, (b) English quality, (c) Amharic
     quality. **Gemini free tier is the named fallback** if (a) or (b) fails.
  3. Small-vendor risks (uptime, rate limits) are recorded in the plan's risk rows.
- **CI FLAKES ACCEPTED** (no larger runner): browser work proceeds under the 0i triage
  law — full artifacts on the FIRST run of every new spec; every red triaged against 0i
  before believed real.
- **BATTERY SPLIT**: the correctness battery runs regardless of BAT1. Only §12 timing
  legs and quotable figures wait on wall power; "timing not taken" is said plainly.

---

## §4 — INHERITED ROWS (every one is a mandatory row in SESSION-09's plan)

**0a. SEC-03 CARRY (Doc 08, sanctioned NOT DONE).** Its fix is a Doc 02 core edit; the
escalation proposal is FILED in the repo with the quoted diff. **It awaits YOUR ruling as
the first item of your inter-document window** — rule on it before SESSION-09 is written,
or explicitly defer it with a reason on the record. Do not let it silently age.

**0b. EC-03 CARRY (Doc 08, sanctioned NOT DONE).** The deleted-account informational
inactive chip is unbuilt (all other EC-03 clauses proven). A named debt: either the
Doc 09 session picks it up as a sanctioned side-row, or the owner orders it as a
follow-up. Decide which in your plan audit; it does not stay unowned.

**0c. ROW 4b REASSIGNMENT (owner item).** BUILD-STATUS:3287's staff-avatar STORE half
sits on Doc 08's name and belongs to Doc 02/Doc 16 territory. Surface it to the owner
once; record his assignment.

**1. CREDENTIALS GATE — HARD for Doc 09.** The Addis AI staging key must exist and be
verified BEFORE the session plan is approved. STEP 0 of SESSION-09 is the key check
(a real authenticated call, output pasted) plus the capability check above. No key, no
plan. The key lives in the vault, never the repo, never a chat.

**2. `[OA]` Doc 05 rows** (AC-03, AC-08-live, US-01-live) — still parked on the staging
Telegram Business account; token rotation mandatory first. Do not unpark.

**3. RISKS 0a–0j standing** (§8 has the new ones). A CI red on 0d is re-run once and
recorded. A 0f firing executes rung 1 only. 0i's threshold is **3+ 0i-class reds in any
10 consecutive completed runs** (the "five consecutive" form was mis-specified and
replaced); the owner has accepted the flake rate — triage, never rationalize.

**4. R-14 queue**: ~511 machine-authored Amharic strings await native review, 9
destructive-tier flagged, "staff-collected" carries §14's own translation note. Doc 09's
strings join it.

**5. `shouldRenderJsonWhen`** per-door interim on every new door; evidence filed.

**6–11. Unchanged from v7 §4** (bounded deferral wording; dry-run preview door note;
Doc 18 notes; Doc 19 buckets; Doc 11/12 seams; Doc 16 seam; owner hygiene — one Claude
session at a time, disk ≥ 20 GB, one project's windows open at a time).

**12. [D09] CLOSING SET.** Doc 08 left seam rows Doc 09 CLOSES: FR-14's `source=ai` flag
path (storage built; the (source=ai)=(actor_id IS NULL) CHECK is live; the producer is
Doc 09's) and EC-06's calibration consumption. Enumerate ALL [D09]-bucketed rows from
BUILD-STATUS's Doc 08 close section yourself; each becomes a plan row naming the
BUILD-STATUS line it updates at close.

---

## §5 — DOC 09 TRAPS (the floor, not the ceiling — verify each against the document)

- **This is the platform's FIRST external egress of conversation text.** Everything the
  Doc 05 PII incident taught applies at higher stakes: real customer data behind a
  "staging" label is a STOP. Fixtures only, to the provider, in every non-production
  environment. Check the provider's data-use terms and record them in an ADR.
- **Grounding-or-handoff**: the AI never states transaction or betting-account facts.
  Doc 08 §3's no-betting-access law binds the AI's answers, not just its queries.
- **RG detection is Doc 09's; storage and visibility are Doc 08's, already built.**
  The seam is exact: rg_flag_history rows with source=ai carry a mandatory excerpt and
  a NULL actor by CHECK. Doc 09 produces; it does not widen visibility.
- **Prompt injection is a new attack surface.** Customer messages reach a model whose
  output can set flags and draft replies. The plan needs adversarial fixtures, and the
  sabotage list should include at least one injection-shaped falsification.
- **Every AI consumer is a durable consumer** (ADR 0011; the relay is live). Registry
  additions move the drill pin AND the Doc 06 NFR-06 fixture in the SAME commit — this
  was missed at S08 steps 3 AND 8; it is a checklist line now, not a lesson.
- **Cost is a new budget axis.** Token spend per conversation belongs in the §12-style
  table alongside latency.

---

## §6 — PLATFORM NOTES (lessons that outlive their documents; all filed in BUILD-STATUS)

- Every route PATH parameter is UUID-shaped; anything else is a query parameter (the
  RouteCoverageSweep constraint).
- `DB::transaction` rolls back only on THROW — a plain `return` COMMITS. Sentinel
  exceptions (RaceLost) are the pattern.
- `ON CONFLICT` with a named arbiter handles conflicts on THAT index only; keep the
  arbiter the only non-PK unique index or raw 23505 bypasses the graceful path.
- heal-vs-create: firstOrCreate against pre-seeded rows hides NOT NULL defects locally
  that CI's fresh-create path catches. Prove create paths on a fresh DB.
- `octane:reload` precedes every browser run. Shared fixture helpers go in Pest.php.
- A security sweep narrowed to survive its own guard goes blind: broad pattern +
  explicit allowlist, then re-plant the exact variant the narrow form missed.

---

## §7 — GOVERNANCE (fifteen incidents; two are yours to inherit as method)

**#14**: advisor acceptance issued against a transit-mangled SHA (a truncated log line
spliced "88…" into a SHA-shape). Caught by the session's verify-against-tree before any
work landed. **#15**: the same channel mangled the close-out's arithmetic (79/12/0
arrived; the register said 78/11/2; the session's issued summary agreed with its
register).

**THE PASTE CHANNEL BETWEEN OWNER AND ADVISOR IS A KNOWN ADVERSARY.** It has delivered
~19 empty attachments, one phantom SHA, and one mangled register summary. Consequences
for you:
- Accept NOTHING without the owner's fresh `git log --oneline -N` paste (rule 18), and
  expect the session to verify YOUR SHAs against the tree — that check has now caught
  the advisor side twice. It is a feature.
- **Derive every recount figure from the REGISTER'S ROWS, never from a report's summary
  line.** The register is the document; the summary is a claim about it.
- Long reports: ask for terminal re-prints or screenshots when anything looks off by
  one character. One mangled digit survives casual reading.

**The owner**: give him SHORT, single-action instructions — one command block, one
sentence. Multi-part messages confuse him; he will tell you so. Deliver files DIRECTLY
to his machine via the Filesystem tools (C:\Users\Kidus\Downloads\NASUPPORT\...);
his browser downloads fail or arrive renamed.

---

## §8 — RISKS ADDED THIS GENERATION

- **0h** — jsonb `?`-operator PDO defect (second sighting; mechanical recipe in
  BUILD-STATUS; a third sighting = a CI grep gate).
- **0i** — CI flaky RATE (three named Doc 07 browser specs; threshold 3-in-10; owner
  accepted; triage law stands).
- **0j** — SystemViewMembershipTest first-come flip (clock-granularity fixture
  collision; +2s migrator-level separation landed; collision NOT the behavior under
  test — filed).
- **0d tripwire RESOLVED**: "host-bound on this hardware" — quiet-machine battery green
  at the S08 close. 0d reds under load are recorded, not investigated, unless a quiet
  run goes red.

---

## §9 — YOUR FIRST ACTIONS (in order; do not reorder)

1. **Rule-18 ground this kit**: the owner commits these four files and pastes
   `git log --oneline -3` — expect the kit commit at HEAD with **361550a** in ancestry.
   The kit-uncommitted failure has occurred FOUR times across generations. Assume it.
2. **Rule on SEC-03** (row 0a) or defer it on the record.
3. Read the record (§2), then build the PRIVATE Doc 09 enumeration.
4. Derive the Doc 09 fidelity file list independently from PROGRAM-REPORT-v1 in
   design/frozen/ — cross-check BOTH directions against the frozen listing (217 files).
5. Confirm the owner's Addis AI key exists (he was instructed; verify, don't assume).
6. Write SESSION-09 per docs/SESSION-PROMPT-SPEC.md (v8) — SESSION-08 is the exemplar:
   ~687 lines, 6 PROTOCOL steps, last line exactly `Then STOP.`
7. Owner commits the prompt; git-verifies; a FRESH solo session reads it FROM DISK.
8. Audit its plan family-exact against your private enumeration. A mismatch is
   investigated, never averaged. The approved plan is committed at approval.

Cadence: batches → one checkpoint → one owner grounding → your acceptance. Mandatory
stops only. He dislikes micro-batching.
