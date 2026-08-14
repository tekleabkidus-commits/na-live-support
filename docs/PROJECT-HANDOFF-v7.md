# PROJECT-HANDOFF v7 — Na Live Support
**Generation: Doc 08 (Customer Profiles & Identity). Written by the retiring Doc 07 advisor at Doc 07's verified close.**
**Predecessors: v3 … v6 in docs/ (v6 filed at 8e3c0b4). This file supersedes v6 as the state of record.**

---

## 1. What this project is

"Na Live Support" — a Zendesk-class, AI-first, mobile-first, white-label ticket/support platform for betting companies, built for the owner's own operation first. Exactly three channels: **Telegram (primary, via Telegram Business connected-bot: account → bot → system), website live chat, SMS (notification-only)**. Enterprise RBAC (unlimited custom roles; User Deny > User Allow > Role), no betting-account access, no SMS/OTP identity verification, feature flags per module, full backoffice configurability, audit everywhere, Explore-style reporting.

**Stack (owner-approved, closed):** Laravel / Postgres / Redis / Reverb + Inertia / Vue 3 / TypeScript + Meilisearch. Approved additions to date: vue-virtual, pinia, @lhci/cli (Doc 07).

**The law:** a 19-document specification series (fixed 20-section template) in the repo as .md files. **The documents are law.** Registries are normative, prose descriptive (Q9). Rulings come only from the owner, recorded in docs/ANSWERS-FROM-DOC-AUTHOR.md and ADRs.

**The owner** is a non-programmer PM (Kidus). He verifies through tests, audits, git output, and copy-paste blocks. He wants **honest percentages** — over-claiming is the single worst failure. Everything travels through paste blocks between the advisor chat and a Claude Code terminal session.

## 2. State at handoff — verified, not asserted

| Doc | State | Acceptance / evidence |
|---|---|---|
| S0 + Docs 01–04 (security core, messaging engine) | CLOSED | through 2026-08-07; R-19/ADR-0010 DB-clock physics; R-19a/b grant guards |
| Doc 06 (Ticketing) | CLOSED | e49f5e2; 96 ids 84/12/0; CI 31292481214; TicketTransitionService sole status writer + gate |
| Doc 05 (Telegram channel) | CLOSED | e62f204; 101 ids 84/17/0; CI tree da335a8 run 31382257865; [OA] rows parked (see §4 row 1) |
| **Doc 07 (Unified Inbox & Agent Workspace)** | **CLOSED & ACCEPTED 2026-08-14** | **acceptance commit b90099e; close-out 429a754; CI run 31772609440 GREEN (all stages)**; 101 ids **92 DONE / 9 PARTIAL / 0 NOT DONE**; sabotage ledger 22/22 item-by-item; final battery Pest 1097/16,815, browser 156+2-solo-green, canonical 116/795, **gates 14/14**, lint 0, §12 strict PASS |

**Progress: ~60% toward MVP.** The expensive, risky half (security, messaging, ticketing, Telegram, the full agent workspace) is done. Doc 07's PARTIALs are exclusively pre-agreed buckets: §12/Doc 19 formal-scale halves (incl. the flagged **TTI 1977 ms vs 1500 ms**, binding at Doc 19 L1 ENFORCE), staging-operational halves, and slot internals owned by Docs 08/09/11/12/16.

**What Doc 07 left FOR Doc 08, by design:** the ContextPanel **Customer tab slot** (built at step 8 with seam fixtures and the `tabBadges.customer` flag) is an honest empty slot waiting for exactly this document. Doc 08 fills it — it does not rebuild the workspace around it.

## 3. The repo and how truth works

- Repo: github.com/tekleabkidus-commits/na-live-support (main). WSL2 Ubuntu at /home/kidus/projects/na-live-support; Docker Desktop stack via docker/compose.yaml (+dev overlay). `make fresh` rebuilds from zero; `make lint`, `make gates` (now **14 gates**, all blocking), canonical suites, full Pest + Playwright batteries.
- **The relay is ALIVE** (Doc 07's biggest discovery): a dedicated `relay` compose service runs `outbox:relay` continuously — before step 14 it had NEVER run as a process and every live event path was dead outside tests. `outbox:liveness-probe` (N=5s, derived from T-02/FR-11) gates CI Stage 4 before any browser test. The relay's not-ready SQLSTATE family is enumerated (42P01/57P03/3D000/08xxx, errorInfo[0]-first); the healthcheck reads procfs (the `ps` binary does not exist in the image — the fourth and final CI-red cause). Anything else crashes loudly where FS-01 sees it.
- CI: seven stages; **rule 15** — one look per checkpoint, verdicts owed forward, never poll. GitHub Actions billing is account-level budgets (a $0 Actions budget once killed everything — fixed by the owner).
- Evidence lives under docs/evidence/S07-*/ (and prior S-dirs); captures are unfiltered, verdicts only from capture summary + exit code (rule 11 — re-proven live twice in Doc 07 via pipe-masked gates).

## 4. Inherited rows — Doc 08's opening table (verify each against BUILD-STATUS, then carry or discharge)

0. **CREDENTIALS GATE (Doc 08: none hard).** Doc 08 needs no external credentials. **Doc 09 does** — the owner must obtain AI-provider API keys (staging-grade) before Doc 09's session is written; remind him at Doc 08 kickoff so it runs in parallel.
1. **[OA] Doc 05 rows (AC-03, AC-08-live, US-01-live)** remain parked on the dedicated **staging Telegram Business account**; **token rotation is MANDATORY first** (the Doc 05 PII incident). Owner-action; do not unpark without it.
2. **RISKS standing:** 0a/0d (intermittents, Docs 01–04 era), 0f (auth-limiter family; rung-1 v2 instrumentation armed on both buckets; ladder in BUILD-STATUS), **0g** (shortcuts T-palette poll under battery load — root-caused to relay-backlog scale, hardened; lettered at Doc 07 close), rbac tri-state first-sighting note.
3. **R-14 queue:** ~420 machine-authored Amharic strings await native review; **destructive-tier flags** on the typed confirm phrases — those review first.
4. **Platform item (filed, evidence accumulating):** `shouldRenderJsonWhen` latent shape — JSON callers on web-group routes get 302s on validation failure; per-door interim pattern applied on every Doc 07 door. A Doc 01 resolution is escalate-first territory; Doc 08's doors will hit it too — apply the interim, keep filing.
5. **Bounded deferral (Doc 03):** the mid-action refusal names the missing permission but **not the granter** — naming it would widen RequirePermission's 403. Filed in DESIGN-DEVIATIONS; revisit only by owner ruling.
6. **Dry-run preview door note:** the drawn bulk-bar per-item disabled-reason strip was superseded by SEC-04 (at-execution verdicts). A preview door reusing the SAME live-check path is owner-orderable later; never a second implementation.
7. **Doc 18 notes filed:** relay startup-ordering (compose depends_on/healthcheck), the C-06-was-test-only finding, the DockerHeal saga. Doc 18 discharges them.
8. **Doc 19 buckets:** all §12/NFR formal-scale halves (500-agent, match-night, 8h soak p-values, 10M-message search, L2 SLOs) + the TTI L1 flag. Doc 19 needs staging-grade hardware.
9. **Doc 11/12 seams honest and waiting:** department/queue rows render the seam posture (opaque ids, EmptyMembership fail-closed); transfer dialog + escalate-department path deferred whole; SLA/sentiment data fixture-fed until Doc 12/09 produce.
10. **Doc 16 seam:** the empty-state token surface; away-timer/notification-preferences editing land in the settings shell.
11. **Owner hygiene (recurring causes, all root-caused):** wall power for battery sequences (battery-power perf caps corrupted a Docker VHD once); disk ≥ 20 GB free (a 99%-full C: caused the entire crash week; owner freed to 70 GB); **one Claude session at a time** (solo guard has fired 6+ times); one project's windows open at a time (incident #11/#13 class).

## 5. Decision log — the operative record (full texts in docs/ANSWERS-FROM-DOC-AUTHOR.md + ADRs)

- **Questions/answers:** Q1–Q9 (Q9: registries normative). **Interpretations:** A-1…A-37. Doc 07 added: A-30 (per-scope ZSET variants, lazy), A-31 (sync scope via ticket_list_items), A-32 (mention unresolved until thread-open/dismiss), A-33 (events ordering/idempotency — relay byte-copy IS the C-09 PK physics; T-07 watermark W=10s; UUIDv7 tiebreak-only), A-34 (FR-06 audience-not-grant), A-35 (BindTenantContextToJob restore-what-you-found), A-36 (§13 "listbox" yields to the GRID pattern — activedescendant rejected with reasons), A-37 (audit-declaration verifier accepts EXPLICIT causal noop declarations — the signed-skip shape; applied at design time thereafter).
- **Rulings:** R-1…R-26. R-22 RESOLVED = the tag rename/merge surface (delivered as the class-2 fill at cc52cc1). **R-26 = views.manage**, the third and last catalog addition: **permission catalog CLOSED at 113**; generalized principle — per-user workspace-state/config writes ride views.manage; Read Only's exclusion is the mechanism; six siblings applied. NFR-03 discipline: render-gates are DERIVATIONS of existing keys, never new ones.
- **ADRs:** 0010 (DB-clock physics), 0011 (ConsumerRegistry durable dispatch — every durable consumer registers with a contract fixture), 0012 (thin TelegramBotApi), 0013 (Doc 07 stack growth). **SCHEMA AMENDMENT 1** (Doc 07): 7 tables incl. inbox_read_markers; three pinned-provenance copies on ticket_list_items; every column R-19b-analyzed.
- **RULED CORE EDITS — seven, plus one comment-only exception.** The law: **escalate → ruling → land, NEVER the reverse.** #1–#4 (prior docs), #5 A-35, **#6 the pending-reason ride (08036be) — recorded honestly as "landed-then-escalated (process breach), countersigned retroactively"**, **#7 MessageDraft cleared_at fillable — escalate-first honored** (the contrast is deliberate and on the record). Packets in docs/evidence/.
- **GOVERNANCE INCIDENTS: THIRTEEN.** Read #13 in full in BUILD-STATUS before your first acceptance: *advisor-side acceptance divergence* — the previous advisor accepted step-14/15 reports citing work that did not exist (phantom SHA 41f1c2c among them); the terminal's git-grounded REFUSAL caught it; nothing false was committed; all void acceptances retracted; **rule 18 was born from it**. Provenance of the phantom reports was never resolved (wrong-window paste per #11, or out-of-repo generation — Gemini/ChatGPT windows were open). The gate protects regardless. Also standing: #7 one-advisor-per-document (recurred twice), #10 undelivered-advisor-block, #11 wrong-project paste, #12 kit/prompt-commit misses (caught 3×).

## 6. Standing rules — EIGHTEEN (the additions bolded)

1–10: as v5/v6 (solo terminal + integrity check; plan committed at approval; unfiltered captures; capture-first diagnostics; sabotage evidence fail+restored; item-by-item ledger reconciliation, never counts; PARTIALs only in pre-agreed buckets; escalate-first on core edits; one advisor per document; advisor blocks verified DISCHARGED in the next report).
11. Verdicts only from unfiltered capture + exit code; **no pipes on a gate** (defeated twice in Doc 07 — `;` and `| tail` — both self-filed; the bare `make lint && commit` form is law).
12. The session prompt/plan/kit are committed and **owner-verified** at their moment.
13. Wall power (`BAT1: Charging`) before any battery sequence — evidence-backed, session self-gates.
14. Relay-live spec law: **tests must not share mutated rows** (async projector recomputes); seeders self-heal their scene canon each run.
15. CI: one look per checkpoint; verdicts owed forward; the fix's run becomes the verdict of record.
16. Enumerations grow only by filed amendment (filed BEFORE the run).
17. **Evidence to the advisor travels as PLAIN PASTED TEXT or terminal SCREENSHOTS.** File attachments into the advisor chat arrived EMPTY ~19 recorded times, including at the close. Have the session re-print reports in the terminal; the owner copies text into the message box. Screenshots (Win+Shift+S) are the one attachment type that works.
18. **RULE 18 — THE GROUNDING RHYTHM (non-negotiable, born of incident #13):** every checkpoint report ends with its commit SHA; the advisor accepts ONLY after the owner independently pastes `git log --oneline -N` output from the plain terminal and it matches. Progress notes don't need per-note grounding; checkpoints, countersigns, and the close do. The close-out grounds BEFORE the recount recommendation issues; the acceptance commit grounds after. **Never pre-issue an acceptance.** GitHub blocks the advisor's direct fetch — the owner's git paste is the SOLE independent channel.

## 7. Design state

- The 7-batch Claude Design program: **219 files frozen at design/frozen/** (screens/audits: highest version frozen; system: B1-DS-v2 + addenda B5/B6/B7). PROGRAM-REPORT-v1 is the map — read it in full before writing SESSION-08.
- DESIGN-FIDELITY-MANDATE (this kit) binds every built surface. Doc 07 set the working precedents for all three deviation classes — cite them (mandate §4).
- No pixel baselines exist for Batches 1/2; only B7's 44-frame manifest is wired. Doc 08's screens live mainly in the Batch-4 customer-panel files — derive your own fidelity file list from PROGRAM-REPORT-v1 (the spec's candidate lists have missed files before).
- Design-vs-document conflicts: **the document wins** (twice ruled in Doc 07 with the frozen file's own text as co-citation). Design gaps: class-2 fills from design-system components, owner-ruled. Stale frame references get STRUCK, not built.

## 8. Doc 08 traps — verify each against the document text; trust nothing here without citation

1. **Doc 07 is now a CORE document.** Its files (workspace, panel, stores, doors, middleware incl. DeclareAuditAction) are escalate-first territory for Doc 08, exactly as Docs 01–06 were for Doc 07.
2. **The Customer tab fills a slot, it doesn't own the workspace.** ContextPanel's slot + seam fixtures + `tabBadges.customer` exist since step 8; B14's law (identical controls in the 375 sheet, single shared slot fill) binds the customer panel too.
3. **Identity is hybrid and "staff-collected, never verified"** — expect the document to forbid treating support-collected data as verified identity; render provenance honestly (the subject-source pattern from B13 is the house idiom).
4. **No betting-account access** — profiles build from support interactions only. Any door that looks like it reads betting data is a stop.
5. **Every durable consumer registers** (ADR 0011) with a contract fixture; the relay is live, so consumers get REAL deliveries — C-09 idempotency is exercised by reality now.
6. **New tables/columns:** R-19b per-column analysis + ADR-0003 grant physics + a filed schema amendment for countersign; the APP_WRITABLE_COLUMNS deliberate-split comment discipline; DB-time only (C-11), pinned copies for anything ordering-relevant (A-33/R-19b).
7. **Audit-declared routes with documented noop paths** use the A-37 causal declaration at the exact branch — apply at design time (the cc52cc1 precedent).
8. **RG-flag surfaces** (responsible-gambling): expect strict rendering/visibility law — read the document's SEC rows before drawing anything.
9. **PII discipline:** the Doc 05 incident is precedent — any surface touching real customer data in a "staging" walk stops first. Customer profiles are the PII-heaviest document yet; the C-10 404-pattern and curated-payload-allowlist idioms (B12) are the floor.
10. **shouldRenderJsonWhen** bites every new door: per-door interim, file the evidence.

## 9. The owner's desk (state plainly at kickoff)

- Doc 08 needs nothing from him to start. **Start Doc 09's key acquisition now** (AI provider, staging-grade).
- Staging Telegram Business account still wanted (unlocks the Doc 05 [OA] addendum; **token rotation first**).
- His grounding job per checkpoint: paste the report AS TEXT + one `git log --oneline -N` line. That pair is the whole safety system; it caught incident #13.
- Cadence: he prefers large batches — default to the Doc 07 endgame cadence (one batch, one checkpoint, mandatory stops only), not per-unit reports.
- Machine hygiene per §4 row 11. Account switches (claude1 ↔ claude2) are an established pattern; one session at a time.

## 10. Forward plan

**Doc 08 next (this generation) → Doc 09 (needs keys) → then owner's choice among 10/11/12** (11 activates Team/Dept scopes and the routing that feeds Own-scope agents — the Unassigned×Own-scope PINNED decision is revisited there). Remaining after: 13–19 + the [OA] addendum. Rough remaining effort at Doc 07 close: ~100–155 terminal-hours.

**Your first actions are in NEXT-ADVISOR-BOOTSTRAP.md. Do them in order. The first one is verifying THIS kit's commit — by the owner's git paste, rule 18, from message one.**
