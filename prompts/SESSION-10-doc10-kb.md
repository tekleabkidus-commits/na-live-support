# SESSION 10 — DOC 10: KNOWLEDGE BASE
### A fresh, solo Claude Code session reads this file FROM DISK at `prompts/SESSION-10-doc10-kb.md` and follows it completely.

---

## 1 — ROLE AND THE VERIFICATION CONTRACT

You are the principal engineer and platform architect for **Na Live Support**. The
owner is the product owner and he is **not a programmer** — he verifies by running
commands and reading pasted output, never by reading code. Therefore:

- **Never claim anything works without pasting the executed output that proves it.**
  A claim without its paste does not exist. A false DONE is a Sev-1.
- Where this prompt, the document, the repo, or a filed ruling is ambiguous, **STOP
  and ask the owner** (who relays to the advisor). Ambiguity is a stop, not a guess.
- Evidence lives in files under `docs/evidence/S10-*/` and in pasted terminal text.
  Captures over prose: when your commit message and your capture disagree, the
  capture is the record and the message gets corrected.

---

## 2 — SOLO-PROCESS GUARD (before ANY work, including reading)

Before reading a single file, prove you are the only Claude session:

1. `ps -ef | grep -i claude | grep -v grep` — the RAW list, pasted.
2. Identify your own PID by ancestry (`pstree -ps $$`), pasted.
3. Apply the discriminator: a `--serve`/`--bridge` tree spawned the SAME SECOND as
   this session and parented with it is your own remote transport — harmless. One
   started EARLIER or on a different tty is STALE and blocks: kill it (a live
   `--serve` can ACCEPT a session). The recurring respawner is the Windows Claude
   Desktop app's WSL bridge; killing it needs `taskkill.exe /IM "Claude.exe" /F`
   on the Windows side.
4. This guard REPEATS at every resume, before anything else.

If more than one true session exists, STOP and tell the owner to `exit` the other.

---

## 3 — PROMPT INTEGRITY CHECK

Immediately after the solo proof:

1. Read THIS file from disk at `prompts/SESSION-10-doc10-kb.md` — never from a
   paste.
2. State its PROTOCOL step count. It is **6**. If you count anything else, the file
   is damaged: STOP.
3. Confirm the last line of the file is exactly `Then STOP.` If it is not, STOP.

All three, or no work happens.

---

## 4 — STANDING RULES FROM MINUTE ONE

Eighteen standing rules bind (authoritative list: `docs/PROJECT-HANDOFF-v7.md` §6 —
re-read it there; do not work from memory). Three are restated here because their
violations have caused real incidents:

- **Rule 15 — CI once, never polled.** One look per checkpoint; verdicts owed
  forward are taken at the next checkpoint. Before triaging ANY red, check the
  run's billing annotation ("recent account payments have failed or your spending
  limit needs to be increased" = GitHub Actions billing, not code — it cost this
  project four dead runs at the S09 close). Then triage against the 0i and 0k
  thresholds (3+ same-class reds in any 10 consecutive completed runs) before
  believing a red is real. 0k (Doc 02 queued-mail class) is escalate-first — it is
  not your file.
- **Rule 17 — evidence as pasted text or screenshots.** Inline attachments to the
  advisor have arrived empty ~19 recorded times. Reports are printed as plain text
  in the terminal for the owner to paste onward.
- **Rule 18 — grounding both ways.** Every checkpoint, countersign, and close ends
  with its commit SHA; nothing is accepted until the owner pastes
  `git log --oneline -N` from his plain terminal. And YOU verify every SHA the
  advisor cites against the tree before building on it — that check has caught the
  advisor side twice (incidents #14, #15; the S09 plan-table paste also arrived
  garbled — the committed plan is the plan of record). Registers over summaries,
  captures over prose, always.

---

## 5 — STEP 0: RULINGS-FILED PROOF + THE [D10] CLOSING SET

There is **no external credential gate** for Doc 10: the KB is internal and all
embeddings ride the ADR 0018 LOCAL sidecar. Run the sidecar's liveness probe ONCE
as step 0's health check and paste the output (the model is pinned bge-m3-class,
1024 dims — `kb_chunks.embedding` is already `vector(1024)`).

**0.1 — Rulings-filed proof.** Prove each of these exists IN THE REPO, citing the
commit that filed it (`git log --oneline -- <path>` per file, pasted):

- `docs/ADVISOR-RULINGS-D10.md` (R-D10-1…4) and this prompt — the same commit.
- `docs/S09-ACCEPTANCE.md` (8e82a7a) and `docs/ADVISOR-RULINGS-D09.md`.
- SCHEMA AMENDMENTS 2 and 3 in `docs/ANSWERS-FROM-DOC-AUTHOR.md`.
- ADRs 0015–0019 in `docs/adr/`.
- The v9 kit (handoff/bootstrap/spec/mandate) — commit 5df56d1 expected in the log.

A missing filing is a STOP.

**0.2 — The catalog count, EXECUTED.** Run
`php -r "echo count(require 'registries/permissions.php');"` and paste it. Expect
**113** (114 only after your own R-D10-1 side-row lands — state which and why).
Then prove every `kb.*` key Doc 10 routes to EXISTS in the catalog. The six that
exist: `kb.view_internal`, `kb.articles.create`, `kb.articles.edit`,
`kb.articles.publish`, `kb.categories.manage`, `kb.feedback.view` (Doc 03
Appendix B). If any Doc 10 operation seems to need a SEVENTH key: STOP — render-
gates are derivations of existing keys and the catalog grows only by owner-signed
addition of record.

**0.3 — The first routine line.** Flip BUILD-STATUS top-table **row 17** (Doc 09)
to CLOSED/ACCEPTED citing `docs/S09-ACCEPTANCE.md` — ordered by that file itself.
One annotation edit, committed with your step-0 filings.

**0.4 — The R-D10-1 early sanctioned side-row.** With the owner's sign-off cited
(ADVISOR-RULINGS-D10.md R-D10-1): register `ai.providers.manage`, move the
CatalogSnapshotTest pins, and bind the S09 provider page's route mapping — ALL IN
ONE COMMIT. Executed count flips 113→114 at this commit. Paste the count before
and after.

**0.5 — The second routine line (R-D10-2).** Update the BUILD-STATUS row-4b
annotation: staff-avatar STORE half reassigned to Doc 16 by owner ruling.
Annotation only; no build work.

**0.6 — THE [D10] CLOSING SET.** Enumerate ALL [D10]-bucketed rows from
BUILD-STATUS's Doc 09 close section YOURSELF, from the rows (the advisor holds an
independent enumeration; R-D10-4 records that the register shows five tagged rows
plus two filed reopeners — if your enumeration disagrees with the rows you read,
the rows win and the discrepancy is stated). Expected shape:

| # | Row | Where it lives | What closes it |
|---|-----|----------------|----------------|
| 1 | Doc 09 FR-10 | BS:57 | live Doc 10 articles feeding retrieval + publish-event index maintenance |
| 2 | Doc 09 FR-20 ([D10] half) | BS:67 | the one-click draft-KB intake consuming the top-10 ids-only seam |
| 3 | Doc 09 T-05 | BS:88 | publish-event index maintenance live |
| 4 | Doc 09 EC-06 ([D10] half) | BS:104 | tombstone citation resolution + cache invalidation |
| 5 | Doc 09 SS-02 | BS:117 | the loop closed end-to-end |
| 6 | kb_chunks `ai_usable` interim | SCHEMA AMENDMENT 3 FLAG 3 | article/category-level flags re-derive the chunk flag (a RULING with its proving test, not an accident) |
| 7 | pgvector rebuild note | BS:112 (FS-02 row) | rebuild-from-rows machinery = Doc 10 FS-04 |

Each becomes a plan row naming the exact BUILD-STATUS line it flips at close.
This is the largest closing set any document has inherited; it is the heart of
Doc 10's job.

---

## 6 — READING LIST (each file, and WHY)

No plan row may come from memory or from this prompt's summaries. Read, in full:

1. `CLAUDE.md` — the constitution.
2. `docs/Doc10-Knowledge-Base.md` — the law, all sections. You will enumerate its
   id families yourself (§8 below).
3. `docs/BUILD-STATUS.md` — the Doc 09 close section (register, §12 table with the
   token-cost axis, DESIGN-DEVIATIONS/[B8] state), risks 0a–0l, incidents #14–#16,
   the platform notes, and the canonical DOC 09 SUITES command (you will need its
   exact suite names for the no-regression floor).
4. `docs/ANSWERS-FROM-DOC-AUTHOR.md` — Q1–Q9 (Q9: registries normative, prose
   descriptive), A-1…A-42, R-1…R-26, SCHEMA AMENDMENTS 2 and 3 (amendment 3's
   kb_chunks section is the substrate you write into — read it as law).
5. `docs/ADVISOR-RULINGS-D10.md` + `docs/ADVISOR-RULINGS-D09.md` +
   `docs/S09-ACCEPTANCE.md` — this window's rulings and the prior close.
6. `docs/adr/0011` (durable consumers), `0015` (pgvector), `0016` (LLM client),
   `0017` (ai queue family), `0018` (local embeddings sidecar — YOUR indexing
   consumes this), `0019` (Addis AI terms — why fixtures-only egress is law).
7. **The substrate, read as CODE** (all Doc 09-owned, all escalate-first):
   - `KbIndexer` and everything that writes `kb_chunks` — the ONE indexer door.
   - `HybridRetriever` — the query side you must not rebuild.
   - The embeddings sidecar client (ADR 0018) and its probe.
   - The golden + red-team suites (exact names from the DOC 09 SUITES command) —
     your no-regression floor.
   - The fixtures-only egress guard (S09 step 1) — your indexing tests live under
     it: ALL provider payloads including embeddings input, in every non-production
     environment, by mechanism.
   - `ConsumerRegistry` + the outbox relay (ADR 0011) — publish events get durable
     consumers.
   - The FR-20 top-10 seam (ids-only) and its decision-log surface.
   - Doc 09's EC-06 tombstone half (the `retrievable` flip + fixture-fed render).
   - The settings and quota engines (Doc 01) — `kb.require_alt` and any Doc 10
     settings go through the registry.
   - Doc 04's media pipeline (article images ride it — FR-05/SEC-02).
   - Doc 01's provisioning job — its "empty KB with starter templates (hook)" seam
     is where FR-04's seeding lands.
8. `prompts/SESSION-09-doc09-ai.md` + `docs/SESSION-09-APPROVED-PLAN.md` — the
   exemplars for plan shape (pre-agreed buckets with unblockers, safety-tests-
   before-features, the closing set as plan rows).
9. `docs/DESIGN-FIDELITY-MANDATE.md` (v9) — §5 speaks to Doc 10 directly.

---

## 7 — ESCALATE-FIRST (stated before the scope, because it binds the scope)

**Docs 01–09 are ALL core.** Any edit to a file they own follows
escalate → advisor ruling → land. Never the reverse. The S09 record is the
exemplar: seven packets proposed, countersigned with proving obligations, landed
with fail-first controls — be that. Pressure points the advisor found while
reading Doc 10, named so you meet them deliberately:

- **`kb_chunks` and `KbIndexer` are Doc 09's** (SCHEMA AMENDMENT 3). Your publish
  pipeline WRITES THROUGH the existing indexer door; changing the door is a core
  edit. Doc 10 T-01's own index-swap design (a `retrieval_generation` column) is a
  schema change to a Doc 09 table → SCHEMA AMENDMENT 4 territory + countersign
  BEFORE any migration.
- **T-01's "delete old async" vs the built REVOKE DELETE on kb_chunks** (amendment
  3: retirement flips `retrievable`, never deletes; app DELETE revoked). The
  document's words and the built physics genuinely differ on generation cleanup.
  This is a MANDATORY STOP at plan time: propose the reconciliation (e.g.
  generation swap via flag flips with platform-role cleanup, or a countersigned
  grant change) and WAIT for the ruling. Do not quietly pick one.
- **FR-15's "index-level separation, not query filters — structural"** vs the
  built single-table `audience` column ("the retriever enforces"). The built shape
  was an interim that predates your document. Whether structural separation means
  a second table/index or a proven structural property of the current shape is a
  RULING, not your call: mandatory stop, proposal + proving test attached.
- **The golden + red-team suites** are your no-regression floor — every
  step-commit that touches indexing runs them; a red there is fix-first, and any
  fix touching a Doc 09 file is escalate-first.
- **Doc 09's Backoffice shell**: if KB admin navigation lands beside the AI
  screens, the shell files are Doc 09's/Doc 07's — slot-fill packets, countersign.
- **Doc 01's provisioning job** (FR-04 seeding rides its existing hook), **Doc
  04's media pipeline**, **Doc 06's NFR-06 consumer fixture**, **Doc 07's Copilot
  KB card** (FR-17's blended top-3 feeds it): all core; packets, not edits.
- The **events catalog** (105 events, generated from `EventCatalog.php`) grows
  only by filed amendment; every new consumer is a DURABLE consumer — registry +
  drill pin + the Doc 06 NFR-06 fixture in the SAME commit (missed twice at S08;
  never since).

---

## 8 — SCOPE

**Implement all of Doc 10.** Before planning, enumerate every id family from the
document ITSELF and STATE YOUR COUNT BY FAMILY in the plan (FR / NFR / US / BR /
T / SEC / EC / FS / SS / AC / R). The advisor reconciles against an independent
private enumeration; a mismatch is investigated, never averaged. Beware the
cross-document citation trap: Doc 10 cites Doc 09's ids constantly ("Doc 09
FR-10", "Doc 09 T-05", "Doc 09 EC-06", "Doc 09 AC-07"), plus Doc 01, Doc 05,
Doc 07 — verify family boundaries against the section text, never a regex.

**Out-of-scope handoffs, named per document** (build the contract, stub or feed
the consumer):
- Doc 09 owns retrieval QUERY mechanics at answer time (Doc 10 §3 says so
  itself) — you produce; you do not rebuild the retriever.
- Doc 13 renders the widget Help tab (FR-18 is a CONTRACT here) → [D13].
- Doc 05/12 own the bot menu flow builder (FR-19 is a rendering contract) →
  [D05]/[D12].
- Doc 15 owns the deflection FORMULA and report rendering (FR-12 defines the
  events only) → [D15].
- Doc 16 owns the per-tenant review-checklist CONTENT (§8) and UI-string
  translation tooling (§3) → [D16].
- The public help-center website is §19 future — build nothing for it.
- The [B8] fidelity pass over Doc 09's screens is a SEPARATE session — not yours.

**Pre-agreed closure buckets** (the only lawful PARTIAL destinations — a PARTIAL
outside a named bucket is a plan violation):

| Bucket | Holds | Unblocker |
|--------|-------|-----------|
| [B9] | pixel fidelity of Doc 10 staff surfaces built functional-first | Batch 9 freeze (R-D10-3) |
| [D13] | widget Help tab rendering halves | Doc 13's session |
| [D05]/[D12] | Telegram KB-flow rendering halves | those sessions |
| [D15] | deflection formula + report surfaces | Doc 15's session |
| [D16] | review-checklist config content; UI-string tooling | Doc 16's session |
| [D18]/[D19] | CDN/edge cache staging-operational halves; §12 scale legs (50k chunks, L2) | staging/perf windows |
| [OA] | native Amharic review of authored strings (R-14) | owner action |

**The [D10] closing set from step 0.6 is restated as plan rows** — each naming the
BUILD-STATUS line it flips at close.

---

## 9 — INHERITED ROWS (handoff v9 §4 — every one a plan row with a disposition)

| Row | Disposition |
|-----|-------------|
| 0a `ai.providers.manage` | DISCHARGE at step 0.4 (R-D10-1, owner-signed) |
| 0b row-4b reassignment | DISCHARGE at step 0.5 (R-D10-2, annotation only) |
| 0c key rotation before production | OWNER-ACTION, standing at every checkpoint |
| 0d Addis AI DPA-or-switch | OWNER-ACTION [OA], pre-launch; not this build (fixtures-only by mechanism) |
| 0e Batch 8 track + [B8] pass | OUT OF SCOPE — separate session the advisor orders |
| 1 no external credential gate | STEP 0 shape as written above |
| 2 [OA] Doc 05 rows | CARRY, parked; do not unpark |
| 3 risks 0a–0l | CARRY; 0k escalate-first; 0l binds every browser fixture you add (dedicated tickets, heal+reproject, never a shared canon row) |
| 4 R-14 queue | CARRY + GROW: Doc 10 strings join; destructive-tier flags on category-delete-with-reassign, archive confirm, sanitizer override, visibility flip |
| 5 `shouldRenderJsonWhen` | per-door interim on every new door; evidence filed |
| 6–11 | unchanged (bounded deferral wording; Doc 18/19 buckets; Doc 11/12/16 seams; owner hygiene: one Claude session, `exit` before relaunch, disk ≥ 20 GB, one project's windows) |
| + parked | Doc 01 QuotaTest order-dependence (Doc 01/18 window); partition-maintenance fold-in (platform window); Doc 07 channel-health ↔ AI-screen cross-link (future design reopen) |

---

## 10 — THE SUBTLE PARTS (verified against Doc 10's text; cite the id when you build it)

1. **Visibility is ARTICLE-level; status is VARIANT-level** (FR-02, BR-03). One
   audience decision per article — mixed public/internal variants are prohibited
   (split into two articles). But workflow state is per variant: Published-in-am /
   Draft-in-en is normal. Model both axes or every later requirement bends.
2. **Translation states are event-driven, never scanned** (FR-03, NFR-05): source
   publish flips siblings to `outdated` ≤5s via the publish event, computed
   automatically, surfaced everywhere. `outdated` still SERVES (BR-05 —
   stale-but-published beats silent language fallback); the fallback chain applies
   to `missing` only.
3. **Humans-live-immediately, retrieval-on-swap** (FR-13, NFR-01, §8 error
   states): the publish pipeline's split is the honest state machine — a stage
   failure leaves the article published for humans and "index pending" for
   retrieval, with retry. Never a mixed retrieval generation (NFR-06, AC-03
   stage-kill chaos proof).
4. **One indexer door** (FR-13, T-03, S09 sabotage-#3 class): instruction-
   stripping at index time is a SAFETY MECHANISM. Your authoring path must not
   create a second, unstripped route into `kb_chunks`. The one-door proof and the
   no-second-route sweep are written BEFORE the authoring path exists.
5. **The sanitizer is ONE shared package with Doc 09** (T-03, R-02): same rules,
   same fixture suite (Doc 09 AC-07's), run at publish, flags surfaced to the
   publisher pre-publish, overrides recorded ON THE VERSION (BR-06, SEC-04
   immutable). EC-09: user-directed imperatives ("click Deposit") are NOT
   assistant-directed patterns — the guide-style fixture set asserts near-zero
   false positives.
6. **AI assists, humans publish** (BR-02, FR-06, AC-09): no AI path sets
   status=Published — static assertion + runtime assertion, both. All five FR-06
   tools produce AI-accented DRAFTS. Draft-from-conversations consumes the Doc 09
   top-10 seam ids-only, end-to-end against a cluster fixture.
7. **Versions are immutable at the DB level** (FR-08, AC-07, SEC-04): publish
   snapshots full content; restore creates a NEW DRAFT from an old version, never
   rewrites history. Grant physics (R-19b) make immutability physics, not policy.
8. **Tombstones: never a 404 for a once-cited URL** (FR-16, T-06, EC-06, EC-07):
   unpublish/archive/supersede/slug-change all resolve; citation URLs embed the
   version id so Doc 09's forensics ("what exactly did the bot show on May 3?" —
   SS-04) answer in one lookup. Slug-history redirects keep old URLs working
   (EC-06 collision → auto-suffix + warning).
9. **Deterministic chunking** (T-02, §12): same content → same chunks; minor edits
   re-embed ONLY changed chunks (cost fixture proves it). Heading-path prefixes;
   the FR-02 summary line is chunk 0.
10. **`ai_usable` closes by RULING** (amendment 3 FLAG 3): the category-level flag
    (FR-01) re-derives the chunk-level interim. EC-02 (move to non-ai_usable
    category = the same atomic removal path as unpublish) and EC-03 (category
    delete blocked until reassigned, dialog naming retrieval impact) are its
    proving edges. Attach the proving test to the ruling.
11. **EC-11: internal→public is a publish, not a toggle**: visibility change is
    high-visibility audited (SEC-04) and takes the FULL pipeline including review.
    No silent flips.
12. **Locks fail soft** (T-07, FS-05): Redis per-variant, 90s heartbeat, takeover
    audits BOTH parties; Redis down → optimistic concurrency with merge-conflict
    UI, noisier but safe. EC-12: working_copy jsonb is the crash-recovery point.
13. **Seeded starter articles are content, not config** (FR-04, BR-07): editable,
    DELETABLE — the deliberate contrast with Doc 03's seeded roles. They ride
    Doc 01's existing provisioning hook, tenant default language, with one-click
    AI-translate-all producing DRAFTS (§14, BR-02 intact).
14. **Feedback endpoints are hostile-input surfaces** (SEC-05, EC-10): rate-limited
    per visitor token, length-capped, inert-rendered; dedupe = one score per
    visitor per article, latest wins; anomalies flagged to Doc 15. SEC-06: the
    create-article-from-query shortcut never stores customer queries beyond the
    session unless the draft is created.
15. **Amharic authoring is first-class** (§14, mandate v9 §5): per-language slugs
    with transliteration helper, Amharic-first source articles are normal, real
    Amharic text flagged for R-14, layouts proven against LONG strings; the
    tsvector config stays `simple` with the language COLUMN as filter — never a
    stemmer swap.
16. **AC-10: the alt-text gate** (`kb.require_alt`, default on, settings registry)
    BLOCKS public publish with missing alts and LISTS them.

---

## 11 — PROTOCOL (exactly 6 steps)

**STEP 1 — PLAN FIRST, then WAIT.** Produce the full plan: every Doc 10 id → the
file(s) that satisfy it → the proving test, the family counts stated, the
inherited rows with dispositions, the closing-set rows, the buckets table, the
sabotage list, the batch structure, and the canonical **DOC 10 SUITES command**
defined NOW (it includes the Doc 09 golden + red-team suites as the no-regression
floor from step one). Safety tests are sequenced BEFORE the features they
constrain — at minimum: the one-indexer-door proof and the instruction-strip
no-second-route sweep BEFORE any authoring path exists; the publish/retire
round-trip (chunks flip `retrievable`, tombstones resolve, golden suite green)
proven BEFORE live index maintenance wires in. Raise the two named plan-time
stops (§7: generation-cleanup vs REVOKE DELETE; FR-15 structural separation) as
part of plan review. Then WAIT for approval. The approved plan is committed at
approval as `docs/SESSION-10-APPROVED-PLAN.md` — the committed file is the plan
of record, not any chat paste.

**STEP 2 — BUILD, one commit per step-unit.** Registries move as you go (every
permission mapping, setting, audit action, event, gate, traceability row — same
commit as the code it covers). Gates run on EVERY commit (`make gates`, fail-fast
`&&` chains, no pipes). Enumerated lists grow only by filed amendment. Schema
changes: R-19b grant physics + a filed amendment (SCHEMA AMENDMENT 4) countersigned
by the advisor BEFORE the migration ships — the B1 precedent. Doc 10's tables
(kb_categories, kb_articles, kb_article_variants, kb_article_versions,
kb_review_comments, kb_feedback) and ANY change to kb_chunks all ride it.

**STEP 3 — SABOTAGE, floor of TEN** (grown only by filed amendment). Enumerate the
list in the plan, verified against Doc 10's text. It MUST include at least:
(a) an **unstripped-authoring-route falsification** — plant a second path into
kb_chunks bypassing the sanitizer; the sweep/one-door proof goes red; remove;
restored green; and (b) a **retire-that-deletes falsification** — make an archive
path DELETE chunks instead of flipping `retrievable`; the proof goes red at the
predicted line. Strong candidates from the document's own text: an AI path
setting status=Published (AC-09, static+runtime), an internal chunk reachable
from the chatbot index (T-08/SEC-01), an in-place version rewrite (AC-07), a
mixed retrieval generation surviving a stage-kill (AC-03), a stubbed sanitizer
(AC-05), a 404 on a once-cited URL (AC-06), a feedback-dedupe bypass (EC-10), a
missing-vs-outdated fallback inversion (BR-05). Every assertion carries a
positive control; captures FIRST, fail AND restored-pass both kept; an
invalid-green sabotage is filed under its honest name and re-executed hardened
(the S09 #16 precedent).

**STEP 4 — i18n.** Every user-visible string an i18n key with `en` AND `am`; real
Amharic, machine-authored strings queued for R-14 with the destructive tier
flagged (§9 row 4); `kb.*` string namespace; long-string layout proofs (§14).

**STEP 5 — PROVE.** The full correctness battery runs at EVERY step-commit
regardless of BAT1 (the split is owner law); only §12 timing legs and quotable
figures wait on wall power — "timing not taken" is said plainly. CI is looked at
ONCE per checkpoint (billing annotation first, then 0i/0k triage). Browser
fixtures obey 0l: dedicated tickets, heal+reproject every seed, never a shared
canon row; `octane:reload` precedes every browser run. Every indexing-touching
step-commit re-runs the Doc 09 golden + red-team suites — a red is fix-first.

**STEP 6 — CLOSE OUT.** The full self-audit table with EVERY id itemized (the
grouped-row form of S09 is tolerated, not preferred — itemize); the sabotage
ledger reconciled item-by-item against the plan's enumerated list; every
closing-set row flipped with its BUILD-STATUS line cited; DESIGN-DEVIATIONS and
[B9] state; inherited-row outcomes; the core-edit enumeration (every ruled edit
with its countersign); the honest trail (every self-caught error, every invalid
green, every deviation, named); R-14 totals with the destructive tier; the final
battery + CI once. The close-out ends with its SHA and the owner GROUNDS IT
(`git log --oneline -N`) BEFORE the advisor's recount issues. No CLOSED is
spoken by you.

---

## 12 — CADENCE

Batches of steps → ONE checkpoint report per batch → one owner grounding → the
advisor's acceptance → next batch. The owner dislikes micro-batching; run full
batches. The SEVEN mandatory mid-batch stops, and nothing else interrupts:
(1) a ruling is needed; (2) a guard conflict; (3) a red battery; (4) a core-edit
boundary (Docs 01–09 file); (5) an enumeration amendment; (6) a schema
countersign; (7) a design conflict or gap. Context exhaustion: bank state to
project memory, resume from summary (proven safe at S09), and the resume block
re-runs the solo guard, prompt integrity, HEAD verification, `git status`, and a
truncation sweep BY RUNNING the suites. A dead session's committed work is
provisional until the living session re-verifies its evidence.

---

## 13 — DESIGN FIDELITY

Mandate v9 binds. Doc 10's surfaces are UNDESIGNED; the owner ruled **Option A**
(R-D10-3): backend-first build, Batch 9 design track in parallel. UI steps
reached before the Batch 9 freeze ship functional/themed/i18n-keyed from
design-system components ONLY (B1-DS-v2 + addenda; both colourways; 1440 + 375)
and ride PARTIAL in the **[B9]** bucket — a bucket, not a deviation class.
DESIGN-DEVIATIONS records only real deviations in the three classes, all filed;
a design conflict or gap is a mandatory stop; frame-mapping rows attach when
frozen frames exist. Mandate §5's Doc 10 lines bind now: an authoring path that
skips the indexer door is class-1 on its face; a retire affordance that deletes
is class-1 against the built seam; tombstone rendering for retired citations is
a real state — labeled-degrade pattern, never a silent empty.

---

## 14 — HARD PROHIBITIONS

- No secrets or PII in prompts, commits, or captures — ever.
- **NO REAL CUSTOMER TEXT TO ANY PROVIDER outside production.** The fixtures-only
  egress guard is law and covers embeddings input. KB fixture content is
  AUTHORED, never harvested from real conversations outside the ids-only top-10
  seam.
- No invented permission keys (six kb.* keys exist; catalog closed at 113→114 by
  R-D10-1 only).
- No document edits without escalate-first. No second indexer door. No second
  implementation of an existing check.
- No green without pasted output. No CI polling. No second Claude process.
- No PARTIAL outside a named bucket. A false DONE is a Sev-1.

Then STOP.
