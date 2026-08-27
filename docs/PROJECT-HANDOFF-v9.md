# PROJECT-HANDOFF-v9 — Na Live Support
## Written by the retiring Doc 09 advisor at Doc 09's verified acceptance (8e82a7a). Supersedes v8 (8d758b0-era) as the state of record.

You are the SIXTH advisor. Your document is **Doc 10 — the Knowledge Base**. One
advisor per document; you never consult, revive, or run parallel to a prior advisor
chat (the single carved-out exception is §4 row 0e: the Batch 8 design track).

---

## §1 — STATE OF THE BUILD

**Docs 01–09 are CLOSED and OWNER-ACCEPTED.** Roughly 72% of the way to MVP.

Doc 09 closed 2026-08-25/26: close-out **ad363b0**, post-close fix **4ab1135**
(two deterministic Stage-4 CI reds fixed at cause + two countersigned Doc 07 defect
fixes + risk 0l), acceptance ruling **8e82a7a** filed verbatim at
`docs/S09-ACCEPTANCE.md`, CI run **32951395795 GREEN all stages** on 4ab1135 (the
verdict-bearer; earlier truncated runs superseded). Register **71 DONE / 23
PARTIAL-with-bucket / 0 NOT DONE** over 94 ids, family-exact against the advisor's
private enumeration, recounted from rows. Ledger 16/16. Seven core edits, every one
countersigned BEFORE landing. Both v8 carries (SEC-03, EC-03) discharged. No false
DONE. The close itself was recounted from the committed register after the session
terminal died — the register-first discipline held.

**Docs 01–09 are ALL escalate-first territory now.** Any edit to a file they own
follows escalate → advisor ruling → land. Never the reverse. S09's record shows the
full spectrum: seven packets proposed, countersigned with proving obligations, landed
with fail-first controls — be that.

The permission catalog remains **CLOSED at 113** (`registries/permissions.php`; verify
by executing `count(require ...)`, never by reading) — **it becomes 114 the moment §4
row 0a lands.** Standing rules are **EIGHTEEN, unchanged** (authoritative list:
`docs/PROJECT-HANDOFF-v7.md` §6, in-repo — re-read there). Governance incidents number
**SIXTEEN** (#16: the owner pasted the staging key into a session chat; ruled
stays-in-service for staging, rotation mandatory before any production key).

---

## §2 — SOURCES OF TRUTH (read before ruling on anything)

1. `docs/Doc10-Knowledge-Base.md` — the law for everything Doc 10 builds. FIRST
   private act: gapless id enumeration by family, kept private. The cross-document
   citation trap survives (Doc 10 is cited BY Doc 09 constantly — "Doc 09 T-05",
   "[D10]" — and cites back; verify family boundaries against section text).
2. `docs/BUILD-STATUS.md` — the Doc 09 close section (the register, the §12 budget
   table with the token-cost axis, DESIGN-DEVIATIONS/[B8] state), **risks 0a–0l**
   (0k: Doc 02 queued-mail CI flake candidate, 3-in-10 threshold; 0l: warm-DB browser
   fixture accumulation — CI's fresh DB is the verdict-bearer; new browser fixtures
   use the heal+reproject pattern), incidents #14–#16, the platform notes.
3. `docs/S09-ACCEPTANCE.md` + `docs/ADVISOR-RULINGS-D09.md` — this generation's
   rulings; `docs/ANSWERS-FROM-DOC-AUTHOR.md` (Q1–Q9, A-1…A-42, R-1…R-26, SCHEMA
   AMENDMENTS 2 and 3).
4. `docs/adr/` — 0003…0014 plus this generation's **0015** (pgvector; trusted=false
   correction — init-script superuser install), **0016** (thin OpenAI-compatible
   client), **0017** (Horizon `ai` family; ai-background own supervisor amendment),
   **0018** (LOCAL embeddings sidecar, bge-m3 pinned, 1024 dims — Doc 10's indexing
   consumes THIS, Addis AI serves no embeddings), **0019** (Addis AI terms — LOUD:
   train-on-content reserved, no retention limit; production egress blocked on
   DPA-or-switch, [OA]).
5. `docs/DESIGN-FIDELITY-MANDATE.md` (v9) + `design/frozen/` — **ls-verify the count
   yourself** (217 at the v8 kit; Batch 8 files land under `design/batch-8/` then
   freeze as the owner approves them — the Batch 8 design track is live and remains
   with the retiring advisor's chat, §4 row 0e).
6. `prompts/SESSION-09-doc09-ai.md` + `docs/SESSION-09-APPROVED-PLAN.md` — your
   exemplars alongside the SESSION-08 pair. SESSION-09's plan shape (pre-agreed
   buckets with unblockers, safety-tests-before-features, a closing set enumerated
   from BUILD-STATUS) is the current best form.

---

## §3 — DECISIONS MADE THIS GENERATION (binding on Doc 10)

- **Provider stack**: Addis AI generation (hardened T-02 identity/language rules
  pinned in the golden suite; single-model catalog; ~2.4 s p50), Gemini named
  fallback, **local bge-m3 sidecar for ALL embeddings** (1024 dims — `kb_chunks`
  vector width is already set to it).
- **`kb_chunks` exists and is Doc 09-owned schema** (SCHEMA AMENDMENT 3): index-time
  instruction-stripped content + provenance, `simple` tsvector config with the
  language COLUMN as filter (no Amharic regconfig exists), HNSW cosine, retirement
  flips `retrievable` — never deletes, chunk-level `ai_usable` is the **[D10]
  interim** whose reopener is YOURS (article-level flags are Doc 10's law; rule how
  they map down).
- **Fixtures-only egress guard** (step-1 S09) covers ALL provider payloads including
  embeddings input, in every non-production environment, by mechanism. Doc 10's
  indexing tests live under it.
- **Filed interpretations that touch you**: EC-06's tombstone-resolution half is
  [D10]; SS-02's one-click "draft KB article from these conversations" seam emits
  ids-only from the top-10 view and waits on your intake surface; T-08 literal
  packet embedding is a named Doc 06 schema_version-2 candidate (not yours).
- **Design Option A precedent (R-D09-2)**: an undesigned document's build runs
  backend-first while a design batch runs in parallel; functional UI from
  design-system components rides a [B-batch] bucket, never a flood of class-2
  deviations. Doc 10's surfaces are also undesigned — expect the same owner decision
  (a Batch 9) and cite the precedent.

---

## §4 — INHERITED ROWS (every one a mandatory row in SESSION-10's plan)

**0a. `ai.providers.manage` SIGN-OFF — the first item of your inter-document window.**
The owner already confirmed the addition-of-record path; what remains is his explicit
sign-off ON THE RECORD, then the registration lands as an early sanctioned side-row of
SESSION-10: catalog 113→114, the CatalogSnapshotTest pins and the provider page's
route mapping in the SAME commit. Get the sign-off before SESSION-10 is written.

**0b. ROW 4b REASSIGNMENT (owner item, inherited TWICE now).** BUILD-STATUS's
staff-avatar STORE half still sits on Doc 08's name and belongs to Doc 02/16
territory. Surface it to the owner ONCE, record his assignment, stop inheriting it.

**0c. KEY ROTATION** before any production key exists (incident #16). Standing at
every checkpoint.

**0d. ADDIS AI DPA-OR-SWITCH** before production egress of real customer text
(ADR 0019, [OA]). Not needed for Doc 10's build (fixtures-only by mechanism); needed
before launch.

**0e. BATCH 8 DESIGN TRACK + [B8] FIDELITY PASS.** By the owner's instruction the
Batch 8 design work continues with the retiring Doc 09 advisor (design approvals
only). When the owner reports Batch 8 CLOSED and files `design/batch-8/` → frozen,
the **[B8] fidelity pass over Doc 09's shipped screens is a small follow-up build
session that YOU order and audit** (the sitting advisor owns all build authority):
derive the Batch 8 file list independently, frame-mapping rows, the four [B8] register
rows re-verified at full fidelity.

**1. NO EXTERNAL CREDENTIAL GATE for Doc 10** — the KB is internal; embeddings ride
the local sidecar. STEP 0 of SESSION-10 is therefore the rulings-filed proof + the
**[D10] CLOSING SET**: enumerate ALL [D10]-bucketed rows from BUILD-STATUS's Doc 09
close section (nine-plus rows: FR-10 live articles, T-05 index maintenance on publish
events, EC-06 tombstone resolution, SS-02's loop, the top-10 draft-KB intake, chunk
`ai_usable` mapping…) — each becomes a plan row naming the BUILD-STATUS line it flips
at close. This is the largest closing set any document has inherited; it is the heart
of Doc 10's job.

**2. `[OA]` Doc 05 rows** still parked (staging Telegram Business account; token
rotation first). Do not unpark.

**3. RISKS 0a–0l standing.** 0k's threshold is 3-in-10 (Doc 02 queued-mail class —
escalate-first if it trips, it is not your file). 0l binds every browser fixture you
add: dedicated tickets, heal+reproject every seed, never a shared canon row.

**4. R-14 queue** grown by S09's machine-authored Amharic (~200 strings; destructive
tier flagged: kill-switch confirm, guardrail deletion, RG template edit, provider
switch, rollback). Doc 10's strings join it.

**5–11.** Unchanged in substance from v8 §4 rows 5–11 (per-door
`shouldRenderJsonWhen`; bounded deferral wording; Doc 18/19 buckets; Doc 11/12/16
seams; owner hygiene). Plus the small parked items: Doc 01 QuotaTest order-dependence
(Doc 01/18 window); partition-maintenance fold-in (platform window); the Doc 07
channel-health → AI-provider-screen cross-link (future scope-limited design reopen).

---

## §5 — DOC 10 TRAPS (the floor — verify each against the document's text)

- **You consume a live retrieval stack; you do not rebuild it.** HybridRetriever,
  KbIndexer's chunk contract, the sidecar, the golden suite that proves grounding —
  all Doc 09's, all escalate-first. Doc 10 owns articles, authoring, publishing,
  versioning, and the PUBLISH EVENTS whose consumers maintain the index.
- **Every index-maintenance consumer is a durable consumer** (ADR 0011): registry +
  drill pin + the Doc 06 NFR-06 fixture in the SAME commit. This was missed twice at
  S08 and never again since — keep it that way.
- **Publish/retire must round-trip the golden suite**: a retired article's chunks flip
  `retrievable`, citations to them resolve as tombstones (EC-06's [D10] half), and
  the transaction-fact traps stay caught — run the Doc 09 golden+red-team suites as a
  no-regression floor at every step-commit that touches indexing.
- **Instruction-stripping at index time is a safety mechanism** (S09 sabotage #3
  class): Doc 10's authoring path must not create a second, unstripped route into
  `kb_chunks`. One indexer door.
- **`ai_usable` mapping is a ruling, not an accident**: how article-level flags map to
  chunk rows closes the [D10] interim; attach the proving test.
- **KB content is operator content**: en+am first-class, R-24 fallback rendering, the
  language-column filter (never a stemmer swap).

---

## §6 — PLATFORM NOTES (all filed in BUILD-STATUS; the S09 additions)

Everything from v8 §6 stands, plus: a cache client's `get()` can be fail-soft while
its writes throw — read liveness-critical counters via increment-by-zero; a
refused-quota compensation can under-fill a counter (fill-to-cap on overflow); jsonb
`?`-operator PDO defect hit twice more (0h recipe; a further sighting = the CI grep
gate); browser fixtures follow 0l's heal+reproject law; `octane:reload` before every
browser run; GitHub billing before CI triage.

---

## §7 — GOVERNANCE (sixteen incidents; the channel is still the adversary)

#14 (mangled SHA), #15 (mangled register summary), #16 (key pasted into chat), plus
unnumbered channel events at S09: a garbled plan-table paste (condition: the committed
plan is the plan of record) and two self-caught commit-body mis-transcriptions
(captures over prose). Consequences: rule-18 grounding both ways; register rows over
summaries; committed files over chat pastes; screenshots when anything looks off by a
character.

---

## §8 — RISKS ADDED THIS GENERATION

- **0k** — Doc 02 queued-mail CI flake candidate ("mailable was not queued", one test
  per run, green locally); threshold 3-in-10; escalate-first to fix.
- **0l** — warm-DB browser fixture accumulation (8-red inventory pinned in
  BUILD-STATUS; CI fresh DB = verdict-bearer; heal+reproject for all new fixtures;
  `inbox:views-rebuild` restores volatile ZSETs after a battery flush).
- Standing observations: Addis AI latency tight against NFR-01 (streaming + [D19]);
  provider preamble token overhead is a metered rate-card fact; single-model catalog.

---

## §9 — YOUR FIRST ACTIONS (in order; do not reorder)

0. **The ordered routine line**: BUILD-STATUS top-table row 17 → CLOSED/ACCEPTED
   citing `docs/S09-ACCEPTANCE.md`. Have the owner commit it with the kit (one
   commit is fine).
1. **Rule-18 ground this kit**: owner commits these four files and pastes
   `git log --oneline -3` — kit commit at HEAD, **8e82a7a** in ancestry. Assume the
   kit-uncommitted failure until the paste proves otherwise.
2. **Row 0a**: obtain the owner's `ai.providers.manage` sign-off on the record.
3. **Row 0b**: surface the row-4b reassignment once; record the answer.
4. Read the record (§2); build the PRIVATE Doc 10 enumeration.
5. Fidelity: ls-verify `design/frozen/` yourself; derive Doc 10's (empty) frame list
   from the program report; put the Batch 9 decision to the owner citing R-D09-2.
6. Enumerate the [D10] closing set from BUILD-STATUS yourself (row 1).
7. Write SESSION-10 per `docs/SESSION-PROMPT-SPEC.md` (v9); SESSION-08/09 are the
   exemplars; 6 PROTOCOL steps; last line exactly `Then STOP.`
8. Owner commits it; git-verifies; a FRESH solo session reads it FROM DISK; audit its
   plan family-exact. A mismatch is investigated, never averaged.

Cadence: batches → one checkpoint → one owner grounding → your acceptance. Mandatory
stops only. He dislikes micro-batching.
