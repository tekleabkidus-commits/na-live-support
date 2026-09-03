# PROJECT-HANDOFF v12 — Na Live Support
_Written by the retiring Doc 12 advisor AT acceptance — not before. Doc 12
accepted f037bc8 (docs/S12-ACCEPTANCE.md); the R-D12-1 window discharged at
56cd93f (docs/F1-ACCEPTANCE.md). The next document is **Doc 13 — Web Chat
Widget**; you are the NINTH advisor. Everything here was true at 56cd93f.
VERIFY every load-bearing claim against the repo before ruling — the v11 kit
was written ahead of a close that had not landed and its anchors were false;
this kit is written after grounding, but the law is the same: cite the repo,
not the handoff._

## 1. What this project is
Na Live Support — multi-tenant, AI-first, mobile-first ticket support for
betting companies. Three channels: Telegram (built), website live chat
(**Doc 13 — yours**), SMS notify-only (Doc 14). Laravel/Postgres/Redis/Reverb
+ Inertia/Vue3/TS + Meilisearch. 19-document spec series; docs are law;
registries normative (Q9). The owner (Kidus) is a non-programmer PM who
verifies via pasted terminal output; one command block + one sentence per
message; ONE Claude Code session at a time.

## 2. State at handoff — verified at 56cd93f
- **Docs 01–12 CLOSED AND ACCEPTED.** Doc 12: acceptance f037bc8 (row 12
  flipped d90fd5e→605ca86); register 97 ids 88/9/0; verdict of record CI
  33641590086 on a86d022. ~88% to MVP.
- **All built surfaces at frozen fidelity.** Batch 9 frozen 67dc4e4 (Doc 10
  staff + Doc 12 Workflow, 13 screens); the combined [B8]+[B9]+[B12-batch]
  fidelity session closed bb8764f, accepted 56cd93f; registers 194 rows
  186/4/4 recounted from rows. The design/fidelity debt that accumulated
  from Doc 09 onward is GONE. `design/frozen/` = 204 entries at 67dc4e4 —
  ls-verify yourself.
- Permission catalog CLOSED at **114** (verify EXECUTED). SCHEMA AMENDMENTS
  2–6 (6 = Doc 12's sixteen tables + RIDERS 1–5). Event-family amendments 1–2
  of S12 (21 Doc 12 events). ADRs 0003–0019. Filed interpretations A-1…A-60
  (A-59 field storage; A-60 the outbound-webhook arm is Doc 12's).
- Doc 12 delivered: the rule engine under absolute guard caps, SLA clock as
  a pure property-tested library with real business-hours calendars (the
  A-53 seam made real), forms as immutable versioned contracts, custom
  fields with grant-physics immutability, macros on the one resolver,
  CSAT token-bound and never incentivized, the Telegram flow engine (closed
  Doc 10 FR-19), the outbound-webhook arm, all Workflow surfaces at fidelity.

## 3. The repo and how truth works
github.com/tekleabkidus-commits/na-live-support (private; the owner's plain-
terminal `git log` paste is the ONLY grounding channel). BUILD-STATUS.md is
the register (rows over summaries — the F1 session's summary lines miscounted
three buckets; the rows were right). ANSWERS-FROM-DOC-AUTHOR.md holds
Q1–Q9, A-1…A-60, R-1…R-26, the amendments and riders. docs/ADVISOR-RULINGS-
D12.md + S12-ACCEPTANCE.md + F1-ACCEPTANCE.md are this generation's rulings.

## 4. Inherited rows — Doc 13's opening table (verify each against BUILD-STATUS)
| Row | Item |
|---|---|
| **0a** | **PREREQUISITE GATE (verify against Doc 13's text): the widget needs a public origin.** BUILD-STATUS row 13 has said "needs support subdomain" since S0. Doc 13's live legs (real browser embedding, cookie-less media origin, CORS from a customer domain) need a staging subdomain + TLS. Put it to the owner FIRST; if absent, the plan carries an honest [OA] bucket for the live halves (the Doc 05 Business-account precedent), never mocks-called-proven. |
| 0b | Staging-key rotation before any production key (incident #16) — owner, standing. |
| 0c | Addis AI DPA-or-switch before production egress (ADR 0019) — owner, [OA]. |
| 0d | Staging Telegram Business account — Doc 05 [OA] rows stay parked. |
| 0e | **Timing legs on wall power**: S12 and F1 both closed with "timing not taken, waived/owed." The §12 dev-scale figures for Doc 12 and the F1 buckets are OWED — order them in ANY wall-power window; they are not Doc 13's build work but they do not age well. |
| 1 | **The [D13] closing set — enumerate from the register's OWN rows.** Known families (verify, don't inherit): Doc 12's [D13] rows (the widget form render per B3-S05; the widget CSAT star row per FR-25 — contracts drawn, renders owed); Doc 10 FR-18 + US-04 [D13] (the widget Help API is mounted; rendering is yours); Doc 09 T-04 [D13]; Doc 02 EC-08 PARTIAL-per-ruling (widget); Doc 08 A-42's unlink-anchor reopener (webchat identity); the F1 [B9] widget half; Doc 04's real-adapter re-proof bucket (the webchat adapter through the ChannelAdapter conformance suite with ZERO core edits — Doc 05's precedent). |
| 2 | **F1 follow-ons F1-FO-1…8** stand as named rows (retry-action door; macro language variants schema; skeleton loading phase; concurrency conflict state; the pause door; three Doc 09 items). Not yours to build unless Doc 13 touches them — but yours to CARRY as named debt with a trigger. |
| 3 | **Three Doc 12 door findings** (F1 close): unique violations leak as 500 on the calendar and field doors; show_when/visible_when normalised. Doc 12 is closed — a fix is escalate-first with countersign. Small; do not let them age. |
| 4 | Risks 0a–0m stand; 0l binds every browser fixture AND scene budget (S11/S12/F1 all re-learned it: dedicated customers per scene ticket; no census-fragile pins; the full battery drifts shared fixtures — reseed + `inbox:views-rebuild` is the sanctioned heal). |
| 5 | R-14 Amharic native review: cumulative, +~1,660 keys from F1 alone. Destructive tier flagged. Owner action. |
| 6 | Platform NNRR unchanged (per-door shouldRenderJsonWhen; partition fold-in; QuotaTest order-dependence; index DDL migrator-owned). **New**: a static `AuditLogger::recordedCount` leaks across files when Workflow tests run first — filed, suite order is green; a proper reset is a Doc 17-adjacent tidy. |
| 7 | WSL: the `/etc/wsl.conf generateResolvConf=false` permanent fix is STILL owed; Windows-gateway nameserver; MTU 1350 on TLS hangs. The Claude Desktop app respawns its WSL transport within minutes of being killed — transports are not sessions; the guard ignores them when they have no child CLI. |

## 5. Decision log highlights this generation
R-D12-1 (the [B8]/[B9] deferral by owner signature with a hard trigger —
HONORED and discharged at 56cd93f); R-D12-2 (Doc 12 design: Option A with two
binding seams — B12-S03 palette, B3-S05 pre-chat; both held); R-D12-3 (no
credential gate); R-D12-4 ([D12] closing set 12/12); R-D12-5 (plan-approval
rulings: STOP-B NEIGHBOR, A-59, A-60, P-A53/P-ESC shapes; the v1-strike
refused on repo evidence); Amendment 6 + RIDERS 1–5 all filed-before-code;
packets P-A53, #3, P-DOORS (clause 3 = the pipeline hint — the engine stays
the ONE assigner), P-CHIP, P-FIELDS, P-SEND, P-CLS (surface='chatbot'),
P-NAV, P-F1-BUBBLE/BANNER/TOPBAR — every one countersigned BEFORE landing,
zero landed-then-escalated across S12 and F1.

## 6. Standing rules — EIGHTEEN, unchanged
Authoritative list: docs/PROJECT-HANDOFF-v7.md §6 (in-repo). This generation's
demonstrations: rule 18 both ways (the v11 kit's anchors failed grounding;
the fabricated checkpoint reports failed grounding; the F1 summary lines
failed the recount); rule 11 (F1's one battery red owned and fixed at
cause); rule 15 (F1's fix-first CI chain). Timing waivers are an OWNER
directive on the record, never a session's choice — correctness batteries
run regardless of power.

## 7. Design state
`design/frozen/` = 204 at 67dc4e4 (ls-verify). Batches frozen: B1–B9, B12.
**Doc 13's surfaces ARE DESIGNED — Batch 3** (the web chat widget,
customer-facing, mobile-first): B3-S01…S0n + B3-A01's three acceptance rows
(build obligations that close as executed tests) + the B3-S06 closing audit.
Derive the file list yourself from PROGRAM-REPORT-v1 and cross-check both
directions. FULL mandate fidelity with frame-mapping rows; NO Option A. The
seam files that already bind you: B3-S03 (the star row, built by S12 as a
contract), B3-S04 (the Help-panel reader, Doc 10's tombstones render in it),
B3-S05 (pre-chat form + offline/hours — Doc 12's form definitions and
calendars feed it). VERIFY the "designed" claim anyway — the check has never
come back empty-handed in five generations.

## 8. Doc 13 traps — verify each against the document text before the prompt
- **The widget is the platform's first CUSTOMER-facing web surface**: no
  failure vocabulary anywhere (the mandate's law), R-24 fallback-language
  rendering, real Amharic at 375 first.
- **The webchat adapter is a ChannelAdapter** — the conformance suite (Doc 04
  T-09, 32 combinations) must pass on the REAL adapter with ZERO core edits;
  the webchat-in/out queue families exist since S0; the mock webchat service
  is a fixture, not the adapter.
- **Visitor tokens are Doc 02's** (FR-21/22, AC-06, the TTL'd keys Doc 18
  must spare); consume, never re-mint.
- **Identity**: A-42 reopens here — a webchat visitor's anchor derives from
  channel physics (a match is evidence; minting from a session id is
  inference); Doc 08's ChannelIdentityMap needs its webchat entry (A-40's
  fail-loud test fires today).
- **Media origin is cookie-less and separate** (Doc 04); the widget's
  uploads ride the existing scan-before-store chain.
- **CORS**: S14's lesson — the framework default once granted `api/* → *`;
  the widget's allowed origins are per-tenant registered content.
- **Reverb from a customer domain**: channel-auth with the visitor
  principal; payloads ≤32 KB; the S14 staff-channel-500-on-WidgetVisitor
  defect is the recorded shape.
- **The Help API (Doc 10 FR-18) and the CSAT endpoint (Doc 12 FR-25) are
  MOUNTED on the widget stack already** — render them, never rebuild them.
- **Offline/hours display reads Doc 12's REAL calendars** (B3-S05's "hours"
  half) — the HoursContract is live; consume it.

## 9. The advisor's duties, in order (do not reorder)
1. Kit grounding: owner commits these four files, pastes `git log --oneline
   -3` + `git merge-base --is-ancestor 56cd93f HEAD && echo ANCESTRY-OK`.
2. Rule on row 0a (the public-origin prerequisite) with the owner.
3. Read the record; build your PRIVATE Doc 13 enumeration.
4. Design verification both directions (Batch 3 files vs PROGRAM-REPORT-v1
   vs the frozen listing); put the fidelity file list to the owner.
5. Enumerate the [D13] closing set from the register's rows.
6. Write SESSION-13 per docs/SESSION-PROMPT-SPEC.md + your rulings file;
   deliver as downloads; owner commits; grounding; fresh solo terminal reads
   FROM DISK.
7. Audit the plan family-exact; batch rhythm; countersign-before-landing;
   the close gate; the recount FROM ROWS; the acceptance; then write the v13
   kit AT acceptance.
