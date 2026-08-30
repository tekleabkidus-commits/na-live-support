# PROJECT-HANDOFF-v10 — Na Live Support
## Written by the retiring Doc 10 advisor at Doc 10's verified acceptance (052e3e4). Supersedes v9 (5df56d1) as the state of record.

You are the SEVENTH advisor. Your document is **Doc 11 — Departments, Teams, Queues &
Routing**. One advisor per document; you never consult, revive, or run parallel to a
prior advisor chat.

---

## §1 — STATE OF THE BUILD

**Docs 01–10 are CLOSED and OWNER-ACCEPTED.** Roughly 78% of the way to MVP.

Doc 10 closed 2026-08-30: close-out **03cdfea**, acceptance ruling **052e3e4** filed
verbatim at `docs/S10-ACCEPTANCE.md`. Register **78 DONE / 8 PARTIAL-with-bucket /
0 NOT DONE** over 89 ids, family-exact against the advisor's private enumeration,
recounted from rows. Ledger 13/13. [D10] closing set 7/7 — the largest any document
inherited, fully discharged. Eight core-edit packets (P1–P8) plus amendments A-43…A-48,
every one countersigned BEFORE landing. No false DONE.

**Docs 01–10 are ALL escalate-first territory now.** Any edit to a file they own
follows escalate → advisor ruling → land. Never the reverse.

The permission catalog is **CLOSED at 114** (`registries/permissions.php`; verify by
executing `count(require ...)`, never by reading) — `ai.providers.manage` landed as the
fourth addition of record at S10 step 0.4, HIGH set 39. Standing rules are **EIGHTEEN,
unchanged** (authoritative list: `docs/PROJECT-HANDOFF-v7.md` §6, in-repo — re-read
there). Governance incidents number **SIXTEEN**; S10 added no numbered incident but
three named session-discipline findings (§7).

---

## §2 — SOURCES OF TRUTH (read before ruling on anything)

1. `docs/Doc11-Departments-Teams-Queues-Routing.md` — the law. FIRST private act:
   gapless id enumeration by family, kept private, never shown to the session. The
   cross-document citation trap survives: Doc 11 is cited by Docs 03/06/07/09/10 and
   cites back. Verify family boundaries against section text, never a regex.
2. `docs/BUILD-STATUS.md` — the Doc 10 close section + `docs/S10-ACCEPTANCE.md`,
   risks **0a–0l**, incidents #14–#16, the platform notes.
3. `docs/ANSWERS-FROM-DOC-AUTHOR.md` — Q1–Q9, A-1…A-48, R-1…R-26, SCHEMA AMENDMENTS
   2, 3 and **4** (the Doc 10 tables + the kb_chunks partition split).
4. `docs/adr/` — 0003…0019 (0015 pgvector, 0016 LLM client, 0017 ai queue, 0018 local
   embeddings sidecar — now BUILT and running under a compose profile, 0019 Addis AI
   terms, still [OA]).
5. `docs/DESIGN-FIDELITY-MANDATE.md` (v10) + `design/frozen/` — **ls-verify the count
   yourself**. Batch 8 is frozen (Doc 09 screens); Batch 9 (Doc 10 screens) was never
   run — Doc 10's staff surfaces shipped functional-first under [B9].
6. `prompts/SESSION-10-doc10-kb.md` + `docs/SESSION-10-APPROVED-PLAN.md` — your
   exemplars alongside the SESSION-08/09 pairs. S10's plan shape (plan-time STOPS
   raised before step 1, safety tests before the features they constrain, a closing
   set enumerated from BUILD-STATUS) is the current best form.

---

## §3 — DECISIONS MADE THIS GENERATION (binding on Doc 11)

- **SCHEMA AMENDMENT 4**: seven Doc 10 tables + `kb_chunks` LIST-partitioned by
  audience (public/internal, own indexes each), generation-based atomic swap, the app
  role holds no DELETE anywhere in the KB — a platform-role prune is the only deleter.
- **The one-indexer-door law**: exactly one writer of `kb_chunks` (KbIndexer), proven
  by a static sweep with a positive control. Any new path into the retrieval store is
  a core edit.
- **Structural index separation**: the chatbot's retriever is bound to the PUBLIC
  partition BY TABLE NAME with no widening parameter on that path (P1). A
  query-filter bug cannot leak internal content.
- **A-43** article delete = soft delete; once-cited URLs never 404.
- **A-44 / A-48.1** the kb.* event family grew to ten by filed amendment; **A-48.2
  REFUSED** — deflection attribution is Doc 15's metric, Doc 10 supplies inputs only.
- **A-45** the event catalog is normative: a DECLARED consumer gets implemented, not
  quietly replaced by a hook. **A-46** the TaskCatalog grew 12→15 by filed amendment.
  **A-47** `ref_languages` is the active-language set until Doc 16 rules otherwise.
- **N-20 clarified**: the invariant bans BRANCHING on channel identity in domain code;
  a validation list of document-defined categories with uniform behavior is data.
- **Design Option A stands** (R-D09-2/R-D10-3): an undesigned document builds
  backend-first; functional UI from design-system components rides a [B-batch] bucket
  until its design freezes. Doc 11's surfaces are undesigned — expect the same call.

---

## §4 — INHERITED ROWS (every one a mandatory row in SESSION-11's plan)

**0a. THE 0i BROWSER PAIR — DOC 07's TO FIX, HANDED OVER.** `inbox-ticket-panel.spec.ts:333`
(four consecutive sightings) and `inbox-mobile-interactions.spec.ts:233` fail on first
attempt and pass on retry, failing the CI job by policy. Diagnosed at S10 as timing
waits in Doc 07 files; Doc 10 filed and did not touch them. They mask Stage 4 for every
push. **Rule on them early**: either order a small wait-hardening packet (escalate-first,
Doc 07 files) or accept the red posture explicitly on the record. Do not let a fourth
generation inherit an undiagnosed-looking red.

**0b. KEY ROTATION** before any production key exists (incident #16). Standing.

**0c. ADDIS AI DPA-OR-SWITCH** before production egress of real customer text
(ADR 0019, [OA]). Needed before launch, not before Doc 11's build.

**0d. THE [B8] AND [B9] FIDELITY PASSES.** Batch 8 (Doc 09 screens) is FROZEN and its
fidelity pass was never ordered. Batch 9 (Doc 10 screens) was never designed; Doc 10's
four staff surfaces shipped functional-first under [B9]. Both passes are small
follow-up build sessions the SITTING advisor orders and audits. Decide early whether
to run them, fold them into a later document's window, or file them as a named debt.

**1. NO EXTERNAL CREDENTIAL GATE for Doc 11** — routing is internal. STEP 0 is the
rulings-filed proof + the **[D11] CLOSING SET**: enumerate ALL [D11]-bucketed rows
from BUILD-STATUS yourself (Doc 07's Unassigned×Own-scope pin, Doc 08's team/dept
membership seam, Doc 09's handoff wait-estimate seam, Doc 06's queue rows — verify
each against the register's own rows, never a summary line). Each becomes a plan row
naming the BUILD-STATUS line it flips at close.

**2. `[OA]` Doc 05 rows** still parked (staging Telegram Business account; token
rotation first). Do not unpark.

**3. RISKS 0a–0l standing.** 0d is host-load-bound and PROVEN so (the S10 wall-power
run: p99 0.408 vs a 1.5 ms budget). 0k (Doc 02 queued-mail 429) fired twice more at
S10 — escalate-first, it is not your file. 0l binds every browser fixture you add:
dedicated fixed-id rows, heal-by-reprojection, never a shared canon row.

**4. R-14 queue** grown by S10's ~86 Amharic keys, destructive tier flagged. Doc 11's
strings join it.

**5–11.** Unchanged in substance from v9 §4 (per-door `shouldRenderJsonWhen`; bounded
deferral wording; Doc 18/19 buckets; Doc 12/16 seams; owner hygiene). Plus the parked
items: Doc 01 QuotaTest order-dependence; partition-maintenance fold-in; the Doc 07
channel-health ↔ AI-provider-screen cross-link.

---

## §5 — DOC 11 TRAPS (the floor — verify each against the document's text)

- **Team and Dept scopes resolve to EMPTY sets today.** Doc 03's T-03 seam is
  fail-closed and has been waiting since S19. Doc 11 supplies the membership source —
  the moment it does, every Team/Dept-scoped permission in the platform starts
  resolving to real rows. Prove the transition deliberately; a scope that silently
  widens is a security event.
- **The routing decision record is per-ticket and mandatory** — every ticket carries
  why it went where. That is an audit surface, not a log line.
- **The starvation cap is absolute** (30 min). Property-test it; a cap that can be
  configured to null is not a cap.
- **RG routing is FINAL** — an RG-flagged conversation's routing cannot be overridden
  downstream. Doc 08's RG law and Doc 09's RG detection both feed this.
- **Doc 07's Unassigned×Own-scope pin is Doc 11's to revisit** (the S07 owner
  override left open by name).
- **Every routing consumer is a durable consumer** (ADR 0011): registry + drill pin +
  the Doc 06 NFR-06 fixture in the SAME commit.
- **Ship round-robin and load-based first** (the document's own recommendation);
  skill-based routing is a later expansion, not v1 scope creep.

---

## §6 — PLATFORM NOTES (all filed in BUILD-STATUS; the S10 additions)

Everything from v9 §6 stands, plus: a channel conditional in domain code fails the
N-20 gate — push the enumeration to DB physics (a CHECK constraint plus a
column-scoped grant refuses garbage without a branch); `+` array-union silently keeps
the LEFT operand's keys and has bitten twice — use explicit merges; a masked 500 can
hide a wrong column name until you call the service directly, so keep one direct-call
leg in the suite; index DDL is migrator-owned (neither app nor platform_admin owns the
indexes) — a REINDEX command runs on the migrator connection; Docker Desktop's
WSL-integration toggle can silently flip OFF and take the whole stack with it.

---

## §7 — GOVERNANCE (sixteen incidents; three named findings from S10)

No new numbered incident this generation. Three findings, all self-caught and filed:

- **The concurrency class fired three times** (Pest run beside `make gates`; a live
  probe beside a chain; foreground tests under a running chain). Every occurrence was
  self-detected, its captures filed under INVALID-RED/INVALID-RUN names, and the work
  re-run serially. The rule is in the session's project memory: **never run Pest and
  gates concurrently, sweep /proc before relaunching.**
- **The rival-session saga**: a detached bg-pty-host session with no window, a
  `claude daemon run` process that respawned a killed session within a second, and the
  Windows Claude Desktop WSL bridge respawning `--serve`/`--bridge` transports. The
  guard caught all of it and STOPPED four times rather than proceed. Killing the
  respawner needs `taskkill.exe /IM "Claude.exe" /F` on the Windows side; the daemon
  itself must be killed or it resurrects what you kill.
- **KEEP-AND-VERIFY was applied twice** (the 2c9139e precedent's third and fourth
  applications) on dead sessions' uncommitted trees — accounted line-by-line, owned,
  re-proven under the living session's own runs, provenance stated in the commit.

**THE PASTE CHANNEL IS STILL THE ADVERSARY.** It truncated a countersign mid-word at
S10 (the session correctly refused to guess the tail) and delivered ~20 empty
attachments to the advisor. Consequences: rule-18 grounding both ways; register rows
over summary lines; captures over commit prose; committed files over chat pastes.

**The owner**: SHORT, single-action instructions — one command block, one sentence.
Deliver files as chat downloads with a rename-safe copy command (the Filesystem tools
have been dead since S09). He often works from a laptop on battery: timing legs wait
for wall power and "timing not taken" is said plainly.

---

## §8 — RISKS ADDED THIS GENERATION

- No new lettered risk. **0d RESOLVED as host-load-bound with evidence** (wall-power
  p99 0.408 vs 1.5 ms) — battery-run sightings are recorded, not investigated.
- Standing observation: the FrankenPHP worker-init failure in the nightly workflow
  (second sighting) is a platform-window item, not any document's.

---

## §9 — YOUR FIRST ACTIONS (in order; do not reorder)

1. **Rule-18 ground this kit**: owner commits these four files and pastes
   `git log --oneline -3` — kit commit at HEAD, **052e3e4** in ancestry. The
   kit-uncommitted failure has occurred five times across generations. Assume it.
2. **Rule on row 0a** (the 0i browser pair) — early, on the record.
3. **Rule on row 0d** (the [B8]/[B9] fidelity passes) — order, fold, or file as debt.
4. Read the record (§2); build the PRIVATE Doc 11 enumeration.
5. Fidelity: ls-verify `design/frozen/`; derive Doc 11's frame list from the program
   report; put the design decision to the owner citing R-D09-2/R-D10-3.
6. Enumerate the [D11] closing set from BUILD-STATUS's own rows.
7. Write SESSION-11 per `docs/SESSION-PROMPT-SPEC.md` (v10); SESSION-09/10 are the
   exemplars; 6 PROTOCOL steps; last line exactly `Then STOP.`
8. Owner commits it; git-verifies; a FRESH solo session reads it FROM DISK; audit its
   plan family-exact. A mismatch is investigated, never averaged.

Cadence: batches → one checkpoint → one owner grounding → your acceptance. Mandatory
stops only. He dislikes micro-batching.
