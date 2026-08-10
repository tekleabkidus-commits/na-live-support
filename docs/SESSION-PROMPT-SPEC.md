# SESSION-PROMPT-SPEC.md — how the advisor writes SESSION-07 (and every later session prompt)
_This file instructs the NEXT advisor chat. The deliverable it governs is
`SESSION-07-doc07-inbox.md` (match the repo's actual Doc 07 filename): the complete
prompt the owner commits to `prompts/` and a fresh Claude Code session reads from disk to
build Doc 07. The bar is `prompts/SESSION-05-doc05-telegram.md` in the repo — read it
first as the exemplar (SESSION-06 is the prior one; both are committed). Nothing below is
optional._

## A. Before writing a single line

0. **THE OWNER-RULINGS GATE shapes the prompt's opening** (Doc 07 has no credential
   gate): before any plan is approved, R-22-RESOLVED / R-24 / R-25 (owner design rulings
   of 2026-08-10 — the tag rename/merge class-2 fill; untranslated-content
   fallback-language rendering; ink-ramp outage bars) must be verified FILED in
   docs/ANSWERS-FROM-DOC-AUTHOR.md, and the prompt's inherited row 0 orders the session
   to cite the filing commit. The R-22 fill is IN SCOPE for Doc 07 as a frame-mapped
   class-2 row using design-system components (settings-row + confirmation patterns).
1. Read **Doc 07 (Unified Inbox: Agent Workspace) end to end** — every section: the
   four-region layout and its breakpoints (FR-01…04), the views system and T-03's
   counting contract, the list's live-behavior rules (FR-11's batched 5 s resort and
   rug-pull affordance), the thread composition of Doc 04/05/06 contracts, composer and
   send flows, selection/bulk (server-side selection tokens capped by Doc 06 limits),
   presence/collision (FR-16), search (BR-03's invisible scoping), notifications/sounds
   and the FR-04 multi-tab leader election, agent status and its routing contract,
   offline/reconnect, the complete shortcut map, the read-model + realtime architecture,
   and the full EC/FS/SS/AC lists. No prompt content may come from memory, summary, or
   the handoff alone.
2. Read PROJECT-HANDOFF-v6.md §4 (inherited rows 0–12), §5–§7 (rulings, standing rules,
   governance), §9 (Doc 07 traps — the floor, not the ceiling) — these become prompt
   content, not background — and DESIGN-FIDELITY-MANDATE.md in full, because §B.8 is the
   heaviest fidelity block of the series.
3. Enumerate Doc 07's id families yourself (FR/NFR/US/BR/T/SEC/EC/FS/SS/AC/R) and count
   them. You will NOT print your count in the prompt — the protocol makes the session
   state ITS count first, and you audit the plan against yours. Keep yours for that
   audit. (Calibration only, after your own count: the outgoing advisor did NOT
   enumerate Doc 07 line-by-line; an automated pull of the HIGHEST id per family from
   the text found FR-31, NFR-07, US-07, BR-06, T-08, SEC-06, EC-12, FS-05, SS-04,
   AC-10, R-05 — gaplessness UNVERIFIED. Your fresh count against the text governs;
   investigate any mismatch, never average.)
4. Cross-check what already exists so the prompt names real substrate, not guesses (via
   a read-only terminal request if needed): the shared Doc 05 components (WindowChip,
   ConnectionStatePill, HealthRollupPill — CONSUMED, never re-built); Doc 04's §8/§9
   thread contracts, presence (FR-19), drafts last-writer-wins + other-device toast
   (T-07/EC-06), delivery glyphs, tombstones, revision markers, the media lightbox and
   edit-marker drawn sources (B12-S01-v3, B12-S02-v2); Doc 06's property contract,
   transition service, bulk machinery and limits, handoff-packet card data (FR-11/AC-08
   merge chip); Doc 05's composer-state contract JSON, rescue data, window events, and
   `channel.health.changed` v2 (FIRST consumer here — contract fixture demanded);
   Doc 03's ScopeSets, RequirePermission, the T-04 page-capability manifest, Reverb
   channel-auth with Doc 03 scope, `views.share` in the closed catalog; the
   ConsumerRegistry (every read-model projector is a durable consumer — C-09 + catch-up
   drills); Reverb payload limits (≤32 KB, schema-versioned); **Meilisearch — running
   since S0, never yet indexed into; Doc 07's search is its first consumer, and tenancy
   scoping of the index is CODE the session proves, not RLS**; the settings and
   notifications registries (sounds/desktop notifications need registered categories);
   the events.created_at deferral (row 7); ADRs 0003/0007/0010/0011.

## B. Mandatory anatomy of SESSION-07 (same blocks as SESSION-05, in this order)

1. **Role block** — principal engineer, 25+ years, systems of this class; owner is a
   non-programmer; never claim anything works without pasted executed output; never
   simplify/drop/shorten; STOP and ask on ambiguity or conflict. Session hygiene: solo
   proof before ANY work (the guard's five firings stated); **CI checked once, never
   polled (standing rule 15, with the S23 context-death stated)**; evidence to the
   advisor as files (rule 17). Then **STEP 0 = the rulings-filed proof** (row 0), cited,
   advisor confirmation awaited, before the plan.
2. **Reading list** — CLAUDE.md; Doc 07 md end to end; BUILD-STATUS (the S23 close,
   RISK 0a/0d/0f with 0f's disposition ladder, DESIGN-DEVIATIONS incl. the four S23
   rows, standing-PARTIAL buckets, the canonical-suites precedent);
   docs/ANSWERS-FROM-DOC-AUTHOR.md (Q1–Q9, A-1…A-29, R-1…R-25 — A-22's v2 event and the
   three 2026-08-10 rulings matter most); DESIGN-FIDELITY-MANDATE.md; the substrate
   files from §A.4; ADRs 0003/0007/0010/0011. Each with WHY.
3. **Stack/context notes** — Laravel 13 + Inertia/Vue 3/TS + Reverb/Echo + Redis read
   models + Meilisearch (all running substrate); registry-normative (Q9);
   interpretation-record precedence (document wins, STOP); **panels are SLOTS** (Doc 08
   Customer, Doc 09 Copilot — named slots with fixture-proven seams, zero panel logic);
   ticket/message/window semantics stay Docs 04/05/06's — Doc 07 RENDERS and reads,
   it re-implements nothing; consumers not yet built are seams with fixtures (Doc 11
   queue views, Doc 12 SLA chips/macros — fixture-shaped, their definitions theirs).
4. **SCOPE** — implement all of Doc 07, enumerate every id from the document itself;
   explicit OUT-of-scope list (ticket semantics → Doc 06; message semantics → Doc 04;
   Customer panel contents → Doc 08; Copilot contents → Doc 09; queue configuration →
   Doc 11; macro/SLA definitions → Doc 12; supervisor dashboard → Doc 15); and
   **pre-agreed closure buckets** verified against Doc 07's own text — expect at
   minimum: Doc 08/09 panel contents (slots + fixtures here); Doc 11 queue-view
   generation (the view TYPE renders here against fixtures); Doc 12 SLA/macro
   definitions (chips/UX here, rules theirs); Doc 15 supervisor surfaces; §12/NFR
   p-values → Doc 19 (500-agent load is L2; dev-indicative structure here). Each bucket
   named with its unblocker. **And the closing set: the prompt lists the Doc 05 [CO]
   halves and Doc 06 Doc 07-bucketed PARTIALs (handoff §4 rows 1–2) as rows that CLOSE
   standing PARTIALs — each names the BUILD-STATUS row it updates at close.**
5. **INHERITED items, each as a mandatory plan-table row** (PROJECT-HANDOFF-v6 §4,
   rows 0–12) — the rulings gate; the two closing sets; B12-A01's nine dispositions;
   shared-components-consumed; the health-v2 consumer contract; projectors as registry
   consumers; the events.created_at check; R-19b; route→permission from the closed
   catalog; standing PARTIALs; the intermittents; design-evidence honesty.
6. **THE SUBTLE PARTS** — spelled out from Doc 07's own text after you read it. At
   minimum expect (verify each): the T-03 counting contract proven against event replay;
   FR-11's 5 s batched resort + pointer-inside rug-pull affordance + slide/fade motion
   specs; FR-12 cursor pagination with scroll restoration (B2-S01-v3 is the drawn
   source); FR-13 selection surviving live re-sorts, server-side tokens capped by
   Doc 06; FR-14's thread composition — ticket-boundary markers, pinned collapsible
   handoff-packet card, bulk-collapsible system events; FR-15 deep links + first-unread
   anchors + reply-to context fetch (Doc 04 EC-08); FR-16 collision strip + send-race
   confirm; FR-04 multi-tab convergence + leader-elected sounds; agent status and its
   routing contract seam (Doc 11 fixture); offline/reconnect truthfulness (queued sends,
   state on reconnect); the complete shortcut map as an enumerable spec; search scoping
   invisible (BR-03) with Meilisearch index tenancy proven by test; notification
   categories registered; empty/loading/error states for ALL regions (drawn frames);
   the R-22 fill; R-24 fallback-language rendering wherever operator content renders;
   R-25 ink-ramp outage bars on the FR-03 health surfaces.
7. **PROTOCOL — the six steps VERBATIM from SESSION-05** (which carries the lineage plus
   ALL standing amendments; add rules 15–17 explicitly: CI checked once; enumerations
   grow only by filed amendment; evidence as files). Sabotage minimums to name after
   verifying against the text: scope filter dropped from a read model → a cross-tenant/
   cross-scope list row appears → red; the search index fed without the tenancy scope →
   scoped-search test red; selection token cap removed → Doc 06 bulk-limit test red;
   leader election disabled → duplicate-notification test red; the resort batcher
   bypassed (instant rug-pull) → FR-11 affordance test red; a delivered glyph rendered
   for Telegram → AC-07's DOM assertion red (this closes the Doc 05 half); the
   collision warning suppressed → FR-16 red; a projector made non-idempotent → C-09
   replay red. The plan enumerates ITS OWN complete list (these are the floor); the
   ledger reconciles against that enumeration at close.
8. **DESIGN FIDELITY block — the heaviest of the series.** Derive the exact file list
   from PROGRAM-REPORT-v1's inventory for Doc 07's surfaces (candidates to verify,
   never assume: the B1 stack's inbox screens — B1-S01 and the views-sheet file among
   them — plus B2-S01-mobile-interactions-v3, B2-S02-mobile-search-status-v3,
   B2-S03-ticket-properties-v2, B2-S04-state-affordances-v2, B2-S05-states-v2,
   B12-S01-media-lightbox-v3, B12-S02-edit-marker-v2; audits: B12-S00-combined-
   checklist-audit-v3, B2-S06-closing-audit-v2 — **the seven Batch 2 owner rulings live
   in B2-S06 and bind these surfaces**; acceptance: B12-A01-behaviour-acceptance-v1,
   all nine rows dispositioned; always the design system: B1-DS v2 §2 both colourways +
   B5 + v3 + v4 addenda). Access wrapper verbatim (named files only; highest version
   frozen). The scoping verification is a plan row; frame-mapping rows for every built
   surface; the mandate's fidelity sentence VERBATIM in SCOPE; tokens-first with the
   committed hex-extraction script RE-RUN; the shared Doc 05 components consumed;
   deviations only in the three classes, every one filed; no pixel baselines exist for
   Batches 1/2 — traceability rows say so.

## C. Quality bar and prohibitions

- The prompt is **complete and self-contained** — the terminal session must need
  nothing beyond it, the repo, and the attached docs. Never "see previous discussion."
- Nothing skipped, shortened, summarized, or re-litigated; every ruling in force that
  touches Doc 07 appears in the prompt itself; every inherited item is a row; the
  protocol is never abbreviated; the rulings gate is stated in full.
- No invented requirements: every SCOPE and SUBTLE-PARTS line traces to Doc 07's text, a
  filed ruling, or the handoff. If you cannot cite it, stop and check.
- Owner-facing delivery: the prompt as a downloadable .md; committed to `prompts/` with
  the commit VERIFIED; fresh terminal session, integrity check, solo proof, STEP 0, plan
  to the advisor BEFORE approval, plan committed at approval.

## D. After SESSION-07 is delivered

The same loop as Docs 02–05: audit the plan against your own count; APPROVED-with-
rulings + plan committed; rule and file; audit checkpoints FROM YOUR CHAT ONLY, verifying
each order's DISCHARGE in the next report; audit the close-out with the mechanical
recount on uploaded files; then write the v7 four-file kit for your successor, owner-
verified committed and generation-current. Notes for the v7 spec: after Doc 07 the owner
chooses the next document — **Doc 09 (AI) needs a PREREQUISITE gate like Doc 05's: real
AI-provider API keys (staging first) confirmed by the owner before launch, plus the
grounding-or-handoff non-negotiables restated**; Doc 08 (identity/Customer panel) slots
into Doc 07's seam and closes the hybrid-identity bucket; whichever runs first inherits
the other as seams. The [OA] backlog (AC-03/AC-08 live halves) closes in a short
owner-assisted addendum session whenever the staging Business account lands — token
rotation FIRST per the recorded boundary; never fold it into another document's session.
