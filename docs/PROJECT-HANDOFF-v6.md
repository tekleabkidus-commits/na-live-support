# PROJECT-HANDOFF-v6.md — Na Live Support, advisor handoff after Doc 05
_File 1 of the four-file handoff package (with NEXT-ADVISOR-BOOTSTRAP.md,
SESSION-PROMPT-SPEC.md, DESIGN-FIDELITY-MANDATE.md). Written by the Doc 05 advisor at
SESSION-23 close, 2026-08-10. The reader is the NEXT advisor chat, whose first document is
**Doc 07 — Unified Inbox: Agent Workspace** (repo file:
`docs/Doc07-Unified-Inbox-Agent-Workspace.md`)._

## 1. What this project is

A multi-tenant, AI-first customer-support platform for betting companies in Ethiopia
(Amharic + English, Telegram-dominant), built to the Zendesk/Intercom class by one
non-programmer owner driving Claude Code terminal sessions, one specification document per
session, with an advisor chat (you) writing the session prompts, auditing plans and
close-outs, and ruling where documents are silent. The law: the 19-document series +
CLAUDE.md (Q1: the series permanently supersedes the old master document). Registries are
normative over prose counts (Q9). The interpretation record
`docs/ANSWERS-FROM-DOC-AUTHOR.md` is canonical — a ruling that lives only in a chat is not
filed.

## 2. Build state — six documents complete, CI-proven

| Doc | State | Session | Closed at |
|---|---|---|---|
| 01 Foundation/schema | CLOSED | S…–S14 era | (see BUILD-STATUS) |
| 02 Auth/webhooks | CLOSED | S15–S17 | " |
| 03 RBAC | CLOSED | S18–S19 | " |
| 04 Conversations/messaging | CLOSED | S20 | R-18 soak later resolved (S22 step 0) |
| 06 Ticketing | CLOSED | S22 | e49f5e2 |
| **05 Telegram channel** | **CLOSED** | **S23** | **acceptance SHA e62f204; closure record 72ea20c; CI-green tree da335a8 (run 31382257865)** |

**Doc 05 closed at 84 DONE / 17 PARTIAL / 0 NOT DONE (101 ids), advisor-recounted
mechanically.** All 17 PARTIALs are sanctioned: pre-agreed buckets ([PB]), design-scoping
contract-only rows Doc 07 renders ([CO]), or the owner-assisted blocker ([OA] — the
dedicated staging Business account). The sabotage ledger closed 20/20 item-by-item; the
event enumeration closed at its amended 10 new + 1 v2 bump (catalog 83). **~50% toward
MVP** (MVP = Docs 01–07 + conservative Doc 09, Telegram-only).

What Doc 05 left in the repo that Doc 07 consumes directly: the real Telegram channel
end-to-end (webhook → pipelines → tickets → outbound), the reply-window state on
conversations (`reply_window_expires_at` + state, events `telegram.window.closing|
expired|reopened`), `channel_unreachable` rescue data, owner-phone attribution
(`author_type=agent`, agent type `owner_phone`), the **shared UI components built at
design fidelity in S23 steps 10–11: WindowChip (the countdown chip — Doc 07 CONSUMES it,
never re-implements), ConnectionStatePill, HealthRollupPill, StepChecklist**, and
`channel.health.changed` schema_version 2 (healthy|degraded|down, transition-only, A-22)
— **which has no consumer contract yet; Doc 07's header indicator is its first consumer
and the S22 contract machinery will demand the fixture.**

## 3. How the tracks run (unchanged mechanics)

One fresh terminal session per document; the session reads its prompt from
`prompts/` (integrity check: last line exactly "Then STOP." + the PROTOCOL step count);
it proves it is the ONLY claude process before any work (the guard has fired FIVE+ times;
the owner launches before typing `exit` — remind them); plan table → advisor audit →
owner pastes APPROVED-with-rulings → **plan committed at approval** → build with
checkpoints audited FROM YOUR CHAT ONLY → close-out → advisor mechanical recount →
acceptance. Mid-document session replacement (context exhaustion, crash) follows the §5
resume procedure below. The owner runs everything by copy-paste: give exact paste-ready
blocks and say which window they go to; answer status questions in plain language with
honest percentages.

**§5 resume procedure** (used successfully at the S23 step-10/11 boundary): fresh session
→ prompt integrity check → solo proof → HEAD + clean-tree verification against the last
known commit → truncation sweep only if a crash interrupted writes → verify-by-running
(canonical suites + gates) before trusting the tree → reload state (BUILD-STATUS session
section, committed plan, rulings) → resume at the exact boundary, reporting owed CI
verdicts once.

## 4. INHERITED ITEMS for SESSION-07 — each becomes a mandatory plan-table row

0. **Owner-rulings gate (BEFORE the plan is approved):** three owner rulings of
   2026-08-10 must be verified FILED in docs/ANSWERS-FROM-DOC-AUTHOR.md — **R-22
   RESOLVED** (tag rename/merge surface = implementation-side class-2 fill from existing
   design-system components — settings-row + confirmation patterns; no design reopen;
   Doc 07 builds it), **R-24** (untranslated menu/button content renders
   fallback-language text per the Doc 01 fallback chain, never blank or raw key, with the
   completeness indicator), **R-25** (health outage bars use the neutral ink ramp; status
   hues stay reserved per design-system law R-07/R-08). The S23 terminal was ordered to
   file them in a final docs commit — verify the commit exists; if it does not, the
   filing is YOUR first paste-ready block for the owner.
1. **Doc 05's [CO] halves come DUE and close their PARTIALs:** §8 composer-state
   rendering (normal/closing/expired/paused/revoked/blocked — closes halves of FR-23,
   AC-04, US-02), the rescue view (US-04), the FS-01 outage inbox banner, EC-04's
   dual-channel badge + grouping + older-activity warning (also R-04's grouping half),
   US-03's clearly-marked phone-reply render, and **AC-07's DOM half — the executed
   assertion that no delivered state ever renders for Telegram**. Each closing row
   updates the Doc 05 BUILD-STATUS row it closes.
2. **Doc 06's Doc 07-bucketed PARTIALs come due:** T-08 read models, SEC-05's
   property-export surface, EC-08's prompt, EC-11's affordances (the subject-fallback
   snippet Doc 07 FR-09 cites), AC-08's merge-chip rendering, and the merge
   banners/dialogs/preview. Same closing discipline.
3. **B12-A01's nine behaviour-acceptance rows:** every row naming a surface built in
   Doc 07 binds as an executed test cited by row id; rows already claimed by Doc 04/05
   builds keep their status; the plan enumerates ALL nine with a disposition each
   (executed-here / already-executed-at / deferred-with-bucket).
4. **Shared components are CONSUMED, never re-built:** WindowChip,
   ConnectionStatePill, HealthRollupPill, the Doc 04 delivery glyphs/tombstone/edit-marker
   patterns. A second implementation of any of these is a fidelity violation and an
   architecture escalation.
5. **channel.health.changed v2 first consumer:** the FR-03 header indicator subscribes →
   a ConsumerRegistry entry (ADR 0011) with the C-09 idempotency proof, an FS-02-style
   catch-up drill, AND the consumer contract fixture the S22 machinery demands (its
   first for this event).
6. **Read-model projectors are durable consumers:** every projector feeding the
   lists/counts is a ConsumerRegistry entry with idempotency + catch-up drills; the T-03
   counting contract's correctness is proven against event replay, not assumed.
7. **The events.created_at deferral check (standing):** if ANY Doc 07 read model orders
   a read over the events table, the deferral FIRES and the R-19 treatment lands first.
   State the check's outcome in the plan even if the answer is "no ordered read."
8. **R-19b on every new table/column** (read-model tables included): per-column analysis
   in the plan; clock_timestamp default + both-revoked grant + schema-derived guard from
   the first migration for every ordering/metric-bearing timestamp; ZSET/score physics
   per R-23 (integer, no fractional ties).
9. **Route→permission mapping same-commit** from the CLOSED catalog (111 + `views.share`
   + `ai.providers.manage`). `views.share` exists for FR-06's shared views — verify
   inline, never invent (NFR-03).
10. **Standing PARTIALs untouched** except the rows this document's §4.1/§4.2 closures
    legitimately close: the [OA] rows (AC-03, AC-08-live, US-01-live) stay blocked on the
    staging account; Doc 19/Doc 08/Doc 12/Doc 14/Doc 15 buckets stay; Doc 03 SS-01/SS-02
    stay.
11. **Open intermittents acknowledged:** RISK 0a (soak — isolated CI job, stays), RISK 0d
    (RbacBudgets p99 host-noise — a CI red is re-run once and recorded, never loosened),
    **RISK 0f (OPEN — the 0b reopen: PasswordReset throttle recurrences post-fix; two
    sightings with captures; ratified disposition ladder: failure-time limiter-key
    snapshot FIRST, the harness-isolation extension only if bleed is confirmed, a Doc 02
    session if it proves a product write — the ladder is filed, not yet applied)**. Two
    single-sighting timing notes (EnumerationTest; TicketsBudgets linearity) become
    lettered risks on recurrence.
12. **Design-evidence honesty:** no pixel baselines are exported for Batches 1/2/6
    (Batch 7's 44-frame manifest is the only wired set); traceability rows claim
    functional-state + a11y coverage only, per the Doc 03/Doc 05 precedent, until the
    owner fires an export.

## 5. Decision log in force (read the record itself; this is the index)

- **Q1–Q9** (series supersedes master; registry-normative Q9; the Doc 02 auth rulings).
- **A-1…A-18** — Doc 02/04/06 era (A-1 replay fails closed; A-4 channel.health.changed;
  A-5 messaging.rate.*; A-6 tickets.reply dead-letter mapping; …).
- **A-19…A-29 — the Doc 05 set:** A-19 token storage per the hmac_secret precedent
  (encrypted cast, $hidden, serialize pin, scrubber, C-13 vault handoff for Doc 18);
  A-20 DeliveryFailureReason + connection_paused/credentials_unavailable
  (document-required; conformance table updated same-commit); A-21 + ADR 0012 thin
  in-house TelegramBotApi (Nutgram declined); A-22 channel.health.changed v2
  healthy|degraded|down TRANSITION-ONLY (this filing is the canonical home of
  transition-only); A-23 mode = per-bot entity configuration wearing the settings-row
  contract; A-24 dev inbound strategy (capture-replay; Telegram REFUSES unresolvable
  setWebhook URLs, so real registration+delivery verification is a Doc 18 handoff row);
  A-25 webhook quota edge fails open on metering outage (S15 capacity posture);
  A-26 the just-in-time SourceUrlResolver seam (scheme-keyed; tokened URLs never persist;
  per-attempt re-resolution); A-27 the inbound author-override resolver seam (owner-phone
  = agent; server-side id comparison is the only authority; spoof control permanent);
  A-28 write test-tools on telegram.bots.manage; A-29 FR-26 acquisitions adapter-side
  (write-once by grant, deterministic join).
- **R-1…R-25**: all prior rulings stand; R-18 CLOSED; **R-22 RESOLVED, R-24, R-25 — the
  2026-08-10 owner design rulings (inherited row 0)**.
- **ADRs 0003, 0004, 0007, 0008, 0010, 0011, 0012** bind as before. Four ruled core
  edits exist from S23 (A-20, A-22, A-26, A-27), each enumerated in the committed
  zero-core-edits diff assertions — they are precedent for HOW a core edit happens
  (escalate → rule → enumerate → sabotage), never precedent that core edits are casual.

## 6. Standing rules (the fourteen, plus S23's additions)

The fourteen v5 rules stand unchanged (documents are law; fixes inside the main concept;
positive control per assertion; sabotage before counting; a green sabotage condemns the
test — and a by-design green is explained, never worsened; ledger reconciles against the
enumerated list, never a count; capture-first with verdicts only from unfiltered
summaries + exit codes; plan committed at approval; canonical suites command defined at
plan time and used for every figure; full battery on EVERY commit including hygiene
commits; R-19b for every new table; honest PARTIALs never re-claimed; interpretation
record canonical; one advisor chat per document with audits from that chat only). S23
adds three:

15. **Never poll CI in a loop.** CI verdicts are checked ONCE per checkpoint
    (`gh run view`), owed forward if still running. A poll loop killed the S23 step-10
    session at 100% context.
16. **Enumerated lists grow only by filed amendment.** The sabotage list went 18→19→20
    by filed amendments; the event family went 8→10 the same way after the advisor
    caught silent growth. Any planned enumeration (sabotages, events, whatever the plan
    fixes) is amended in the committed record BEFORE the addition ships, cited to its
    document requirement.
17. **Terminal→advisor evidence travels as UPLOADED FILES or plain pasted text only.**
    Inline attachments have arrived empty NINE recorded times. Close-out recount
    material is files, full stop.

## 7. Governance record — twelve incidents; do not repeat them

1–9 as recorded in v5 (advisor high-risk miscount; missing-kit commits ×2; concurrent
sessions; ledger-vs-count; filtered-capture losses; the #7 foreign-instruction pattern
and its recurrence; superseded-spec upload; the uncommitted plan). S23 adds:

10. **A delivered-looking advisor block never reached the terminal:** the S23 close-out
    arrived requesting a countersign the advisor had already issued — the owner's paste
    of the step-11 confirmation was never delivered. Caught because the advisor treats
    any report referencing an un-received instruction as a mix-up to flag (the #7
    discipline pointing the other way). Verify order-discharge in every next report; if
    orders are undischarged, ask "did my block reach the terminal?" before re-ruling.
11. **A wrong-project paste landed in the advisor chat** (an unrelated Laravel app's
    session output). Flagged, not audited. The owner runs multiple Claude projects —
    check that a report matches THIS project's record before ruling on it.
12. **The prompt-file-never-committed failure recurred** at SESSION-05 launch (caught by
    the session's own integrity check — the third occurrence project-wide). The
    verify-the-commit-landed step is not optional, for kits AND prompts.

## 8. Risks and open facts (carry these verbatim where marked)

- **Token exposure (verbatim, owner-ruled):** "staging bot token was exposed in an owner
  chat and remains unrotated by owner decision; the live-account connection that made it
  critical has been severed; rotation is MANDATORY before any Business account is
  reconnected to this bot and before any production traffic — whichever comes first."
- **PII incident (verbatim):** "the staging bot was temporarily connected to the live
  production Business account; real customer PII entered and was purged from uncommitted
  evidence; the dedicated staging account requirement is mandatory before any future
  live walk."
- **The [OA] backlog:** AC-03's live lifecycle walk, AC-08's live render half, US-01's
  live walk — unblocker: the dedicated staging Business account (Premium, spare device,
  Doc 05 §21). If the owner lands it mid-Doc 07, a short owner-assisted S23-addendum
  session can close them — rotation FIRST per the boundary above; do not fold that work
  into the Doc 07 session.
- **No public ingress in dev** (A-24): real Telegram→endpoint webhook delivery has never
  been exercised; Doc 18 owns the staging-ingress verification row.
- **Perf posture:** two Doc 01/03 strict budgets MARGINAL on dev hardware (recorded at
  their rows); Doc 19's L1 profile is the honest closure; Doc 07's own budgets (≤1.5 s
  list latency at 500 agents, T-03 counting) will be dev-indicative + Doc 19-bucketed
  the same way.
- **~180 machine-authored Amharic strings** sit in the R-14 native-review queue with
  destructive-tier strings flagged; Doc 07 will add substantially more.

## 9. Known Doc 07 traps (the floor, not the ceiling — verify each against the text)

- **It is the design track's heaviest document.** The frozen inventory spans the B1
  stack (inbox screens), B2-S01…S06 (mobile interactions, search/status, ticket
  properties, state affordances, states, closing audit), B12-S01 media-lightbox-v3,
  B12-S02 edit-marker-v2, B12-A01's nine rows, and the combined B12-S00 checklist —
  **and the seven Batch 2 owner rulings live in B2-S06-closing-audit-v2** (search scope
  invisible per BR-03; views sheet; assignee inline refusal; tags WITHOUT rename/merge —
  now R-22's fill; KB hover preview; bulk bar; scroll restoration). §B.8 of the spec
  derives the exact list; expect the largest frame-map yet.
- **Read models without stampede:** T-03's counting contract, cursor pagination,
  batched 5 s re-sorts with the "list updated" affordance (FR-11's rug-pull rule),
  scroll restoration (FR-12, drawn in B2-S01-v3).
- **Multi-tab leader election** (FR-04, BroadcastChannel) for sounds/notifications.
- **Server-side selection tokens** for >page bulk selections, capped by Doc 06's bulk
  limits (FR-13) — selection survives live re-sorts.
- **Presence/collision** (FR-16): typing warning strip, send-race non-blocking confirm.
- **Search is ONE search, scope enforced invisibly** (BR-03 + the B2-S02 ruling — no
  scope toggle exists; do not let one appear).
- **Panels are SLOTS:** Customer (Doc 08) and Copilot (Doc 09) plug into named slots —
  fixture-proven seams, zero panel logic here.
- **Accessibility as executed tests:** §13 live regions, bulk progress at 25%
  increments (also a B12-A01 row and Doc 06 §13), the complete shortcut map, offline/
  reconnect states — drawn states are enumerable like ids.
- **Meilisearch is RUNNING substrate** (the S0 stack) but nothing indexes into it yet —
  Doc 07's search is its first real consumer; expect index-definition + tenancy-scoping
  questions early; RLS does not protect a search index — scoping is code you prove.
- **The R-22 fill is in scope** (inherited row 0): tag rename/merge UI from
  design-system components, frame-mapped as a filed class-2 fill.

## 10. Design-track state

Frozen program: 165+ files across seven batches (PROGRAM-REPORT-v1 is the map; access
wrapper always: named files only, highest version frozen, design system = v2 §2 both
colourways + B5 + v3 + v4 addenda). S23 added four filed deviations (the AA-contrast
class-1 fix, no-pixel-baselines, the owner-approved Pending pill, the token eye-mix
correction + `--ds-danger-surface-border`) — DESIGN-DEVIATIONS in BUILD-STATUS is
current. The hex-extraction script is committed and re-runnable; run it again at Doc 07's
tokens-first step. Batch 7's AC-09 manifest (44 auth frames) remains the only wired
pixel set.
