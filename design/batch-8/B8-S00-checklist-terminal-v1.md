# B8-S00 — Batch 8 checklist (terminal track) — v1

Doc 09 (AI System: Chatbot, Copilot, Supervisor) → the design contract, organized in
the 12 sections of the chat contract. Every row carries its Doc 09 reference. A row is
covered only when a delivered screen shows it in BOTH themes, at 1440 AND 375, with the
named states. Components and tokens are B1-DS v2 + addenda B5/B6/B7 verbatim; the AI
accent family (light #F1EDFE/#5B3FD6/#7C5CFC · dark #292144/#BCA9FF/#A18BFF, drawn
AI-bubble anatomy with the "✦ AI assistant" TEXT label) is the only AI visual language.

Planned files (one per section; S01 pending the owner's Step-0 answer):
S01 Copilot panel · S02 composer actions · S03 AI marking & honesty · S04 personas ·
S05 thresholds & simulation · S06 guardrails · S07 scope & kill switches · S08
provider/config status · S09 decision log & quality loop · S10 supervisor outputs +
RG review queue · S11 degraded/AI-off states · S12 cross-cutting proof sheet.

## THE FIVE HARD ABSENCES — contracted as DESIGNED ABSENCES (audited on every screen)

| # | Absence | Doc 09 ref |
|---|---|---|
| X1 | No auto-send: no control, affordance, or flow sends a Copilot artifact without the agent's composer send | BR-01, FR-14, AC-06 |
| X2 | No generated RG text: every RG response surface shows the fixed operator-approved template, never free text | BR-03, FR-13, AC-04 |
| X3 | No removable disclosure: no toggle, setting, or empty state implies the AI disclosure can be hidden — wording editable, presence not | FR-19a, BR-05 |
| X4 | No stated transaction/account facts: every frame with money content uses claim-framing ("you mentioned…") and grounded citations or hands off | FR-19b, BR-02, EC-03, AC-05 |
| X5 | No publish without fresh simulation: threshold publish controls are unreachable until the simulation for the pending values exists | FR-11, BR-07, FS-05 |

## A — Copilot panel (fills Doc 07's slot)

| # | Item | States | Doc 09 ref |
|---|---|---|---|
| A01 | Panel frame in Doc 07's context-panel slot (tab order Ticket/Customer/Copilot untouched); every section an independently-labeled region with its own skeleton | normal · per-section skeleton | FR-14, §8, §13 |
| A02 | Live thread summary, refresh debounced 10 s, refresh stamp visible | fresh · refreshing · stale | FR-14 |
| A03 | Intent / sentiment / urgency chips, AI accent + text (never color alone) | populated · absent-per-chip | FR-14, §9 |
| A04 | Suggested-reply card: streamed text with subtle caret, [Insert] [Regenerate] [Discard] | streaming · complete · discarded | FR-14, §8, §9 |
| A05 | Insert gated by ai.copilot.insert_draft; focus returns to composer after insert | permitted · no-permission (control absent) | FR-14, §13 |
| A06 | Regenerate limited ×3 with cooldown; counter visible | 1st/2nd/3rd · cooldown | §8 |
| A07 | EC-09: regenerate exhausted → card locks to low-confidence state with KB links; weekly-review telemetry note | locked | EC-09 |
| A08 | Low-confidence banner REPLACES the draft card (never an empty spinner; 10 s timeout → degraded card) | low-confidence · timeout | FR-14, §8 |
| A09 | Guardrail notice travels WITH the draft card as an amber inline strip (standing ruling — never panel-level) | present · absent | FR-14, §8 |
| A10 | Relevant KB, top 3, insert-as-link | 3 hits · none | FR-14 |
| A11 | Similar resolved tickets, top 3, scope-checked (out-of-scope rows absent, not masked) | 3 · none | FR-14, T-05 |
| A12 | Macro suggestions (Doc 12 seam, fixture-fed) | present · empty | FR-14 |
| A13 | Confidence meter: 5-segment bar + percent + text label low/medium/high | low · medium · high | §9 |
| A14 | Degraded card: "AI unavailable — macros and KB search still work" | degraded | §8, FS-01 |
| A15 | Streaming a11y: updates announced at sentence boundaries; reduced-motion renders in chunks | note on frame | §13, §9 |
| A16 | Amharic proof: long Amharic summary + chips + draft in the same layouts | am variant | §14 |

## B — Composer AI actions (Doc 07 FR-19 slot)

| # | Item | States | Doc 09 ref |
|---|---|---|---|
| B01 | Action cluster in the composer slot: Draft reply · Improve · Translate · Summarize | normal · busy · AI-off | FR-15, §8 |
| B02 | Draft reply → card into the flow of A04 (same never-auto-send anatomy) | draft | FR-15 |
| B03 | Improve: whole-text accept (v1), original recoverable before accept | preview · accepted | FR-15, §8 |
| B04 | Translate: two-line preview (original ↔ translation) BEFORE send; original stored in metadata, visible on hover post-send | preview · sent+hover | FR-15, US-04, §8 |
| B05 | Digit/currency/reference preservation: validator visual for altered digits (money-safety) | pass · reject | §14, EC-10 |
| B06 | EC-10 mixed-language input: dominant-language translation preserving embedded English terms | mixed am/en | EC-10 |
| B07 | Summarize thread → inserts as internal-note draft (note tokens, never a public reply) | note draft | FR-15 |
| B08 | 44 px touch targets at 375; keyboard operability; focus return | mobile variant | §13 |

## C — AI marking & honesty (staff thread + customer-side rendering)

| # | Item | States | Doc 09 ref |
|---|---|---|---|
| C01 | First greeting: persona name + disclosure, en + am | both languages | FR-19a, §8, AC-05 |
| C02 | AI bubble staff-side: accent + "✦ AI assistant" text label, exactly the B1-DS drawn anatomy | in-thread | FR-19c, §9 |
| C03 | Claim-framing on money content: "you mentioned a 2,000 ETB deposit" — never a stated fact; OCR fields restated as the customer's claim | trap example rendered | FR-19b, EC-03 |
| C04 | Citations render: trailing article links (Telegram) and "Read more" (web note) | cited answer | FR-10, §8 |
| C05 | No-edit rule: corrections are new AI messages; no edit affordance exists on an AI message | correction pair | FR-19d |
| C06 | Handoff message with live queue-wait estimate (Doc 11 seam, fixture value) | handoff | FR-12, §8 |
| C07 | RG override moment: calm fixed template (operator-approved text, byte-fixed), RG routing note; the flag's triggering excerpt visible in the staff view | RG turn | FR-13, X2 |
| C08 | CSAT 1-tap 1–5 + optional comment, post-resolve | prompt | FR-09, §8 |
| C09 | post_resolution reopen: continuity greeting ("About your deposit from earlier —"); ×2 then mandatory-handoff note | reopen · forced handoff | FR-09, EC-08 |
| C10 | Batch-tail: 6 rapid messages → ONE coherent turn addressing all | batch example | EC-01 |
| C11 | Typing indicator during generation (Telegram ⌨/…) | generating | §8 |
| C12 | EC-02 third-language voice note: polite capability message + unsupported_language handoff | ec-02 | EC-02 |
| C13 | EC-04 betting-advice deflection: friendly template + responsible-play KB link; ×3 → blocked_topic_persistence handoff | deflect · persist | EC-04 |

## D — Personas (Backoffice)

| # | Item | States | Doc 09 ref |
|---|---|---|---|
| D01 | Persona editor: name, avatar, greeting per language, tone sliders formal↔friendly / brief↔detailed | normal | FR-18, §8 |
| D02 | Live preview chat against the 5 canned conversations, per language, AI bubbles in accent | preview en · am | FR-18, §8 |
| D03 | Publish flow: versioned records, instant publish, rollback affordance | draft · published · rollback | FR-04 |
| D04 | Disclosure wording editable per language, presence shown as platform-fixed (lock, not toggle) | lock visible | FR-19a, X3 |
| D05 | Per-language completeness: a language missing required strings shows its gate plainly | complete · gated | §14 |

## E — Thresholds & simulation (Backoffice)

| # | Item | States | Doc 09 ref |
|---|---|---|---|
| E01 | Four sliders: auto_resolve_min · handoff_below · spam_min · rg_min | normal | FR-11 |
| E02 | Simulation panel: before/after columns, deltas, small bar viz, on yesterday's decisions ("1,240 conversations → 61% auto-resolved / 12% more handoffs") | computed · computing · no-data | FR-11, §8, §9 |
| E03 | "Conversations that flip" drill-down list, anonymized display names | open | §9 |
| E04 | Publish gated on fresh simulation for the PENDING values (X5); stale-sim state disables publish and says why | fresh · stale | BR-07, X5 |
| E05 | Per-endpoint overrides (stricter VIP bot example) | override row | FR-11, EC-07 |
| E06 | RG threshold: simulation + the precision readout (NFR-07 target stated) | rg row | T-06, NFR-07 |
| E07 | Simulation deltas readable as a text table (a11y) | table form | §13 |

## F — Guardrails (Backoffice)

| # | Item | States | Doc 09 ref |
|---|---|---|---|
| F01 | Blocked topics managed list; seeded items (odds/betting advice, legal advice, payment/reversal promises, account-balance claims) with lock icons — wording editable, not deletable | list · edit | FR-18, §8 |
| F02 | Mandatory disclosure wording block (presence fixed — X3's lock again at the editing site) | edit | FR-18, X3 |
| F03 | RG template text per language, byte-fixed usage note (X2); language-completeness gate: an endpoint cannot enable a language missing its RG template | complete · gated | FR-18, §14, X2 |
| F04 | max_ai_messages (default 8) with its consequence stated | edit | FR-12, FR-18 |
| F05 | Profanity policy · PII-redaction toggle for AI outputs | edit | FR-18, SEC-02 |
| F06 | Destructive acts (delete custom item) confirm; seeded items offer no delete | confirm | FR-18 |

## G — Scope & kill switches (Backoffice)

| # | Item | States | Doc 09 ref |
|---|---|---|---|
| G01 | Scope: AI-usable KB categories; endpoints where the chatbot is on | normal | FR-18 |
| G02 | Kill-switch grid: per surface × channel × bot/widget; oversized toggles, role="switch" semantics | all-on · mixed | FR-18, §9, §13 |
| G03 | Red-when-off with "OFF since {time} by {actor}" caption | off | §9, US-07 |
| G04 | Confirmation modal stating the blast radius ("turns off the chatbot for @BrandSupport — 312 conversations/day average") | confirm | §8, AC-08 |
| G05 | Status banner mirrored in the inbox header while any switch is off | banner | §8, AC-08 |
| G06 | High-visibility audit note (who/when) on the page | audit strip | SEC-05 |

## H — Provider / config status (its OWN screen — standing ruling Q1b; B6-S06 untouched, no cross-link)

| # | Item | States | Doc 09 ref |
|---|---|---|---|
| H01 | Provider chain per task tier (fast/strong/embeddings): ordered fallback with per-provider circuit-breaker state | healthy · tripped · all-tripped | FR-02, T-01, FS-01 |
| H02 | Model map per tier; provider switch is a config change (no deploy) — change affordance permission-noted (ai.providers.manage) | normal | FR-02 |
| H03 | Credentials as vault references only — never a key character | vault ref | SEC-01 |
| H04 | Config/prompt versions: current + history, publish + instant rollback (FS-05 note) | history · rollback | FR-04, FS-05 |
| H05 | Degraded-mode status: failover ≤10 s, all-exhausted → straight-to-human, auto-resume — the ladder as live status | degraded · recovered | NFR-04, FS-01 |
| H06 | Quota meter: per-tenant daily tokens, 80% alert mark, 5% reserve explained | under · 80% · exhausted | NFR-06, EC-12 |

## I — Decision log & quality loop (exactly TWO tabs — standing ruling Q2)

| # | Item | States | Doc 09 ref |
|---|---|---|---|
| I01 | Filterable table on FR-20's fields: surface, task, model+config_version, tokens, confidence, intent, action, outcome, latency | rows · filtered · empty | FR-20 |
| I02 | Outcome enrichment visible (reopened, CSAT, routing-agreed, Copilot edit outcomes FR-16) | enriched row | FR-20, FR-16 |
| I03 | Payload access split: ai.decisions.view sees the table; raw payloads behind the stricter platform-debug note; 30-day reduction to hashes stated | permitted · reduced | SEC-06 |
| I04 | TAB 1: top-10 unanswerable questions with the one-click "draft KB article from these conversations" action (Doc 10 seam) | tab | FR-20, SS-02 |
| I05 | TAB 2: confidence calibration curve, readable as text values too | tab | FR-20, §13 |
| I06 | NO RG queue here — it lives in J (designed placement, Q2); no count, no tab, no trace on this screen | absence | Q2 ruling |

## J — Supervisor outputs incl. the RG review queue (rulings Q2/Q3 — artefacts, NO Doc 15 report pages)

| # | Item | States | Doc 09 ref |
|---|---|---|---|
| J01 | Anomaly feed item with baseline context ("withdrawal complaints +240% since 14:00 · baseline 12/hr") | item | FR-17, US-05 |
| J02 | Live sentiment-watch item linking into the thread | item | FR-17 |
| J03 | Daily digest: feed form AND email form, localized, exactly six bullets | feed · email · am | FR-17, §14 |
| J04 | QA pre-score where a reviewer meets it — advisory framing, never a published agent score | artefact | FR-17 |
| J05 | RG precision review queue (all fires + all clears), calm slate treatment, excerpt on every fire | queue · clear flow | FR-20, FR-13, §9 |
| J06 | RG queue permission gate: for unpermitted viewers ABSENT — no count, no tab, no trace | permitted · absent | Q2 ruling, SEC-06 |

## K — Degraded / AI-off states (the labeled-degrade pattern — never silent empties)

| # | Item | States | Doc 09 ref |
|---|---|---|---|
| K01 | FS-01 ladder as experienced per surface: failover → all-exhausted → chatbot bypass (straight-to-handoff w/ template) → auto-resume | each rung | FS-01, AC-09 |
| K02 | Copilot degraded card (A14's home section cross-ref) + Supervisor tasks paused note | degraded | FS-01 |
| K03 | EC-12 quota exhausted: current turn completes from 5% reserve → straight-to-human; 80% admin alert | exhausted | EC-12, NFR-06 |
| K04 | No-KB-coverage: chatbot setup checklist pointing at Doc 10 starter templates | empty | §8 |
| K05 | FS-02 retrieval corruption: no_grounding handoff mode for policy questions, smalltalk/forms unaffected — stated | fs-02 | FS-02 |
| K06 | EC-11 provider policy-refusal: distinct provider_refusal logging note; raw refusal text NEVER customer-visible | ec-11 | EC-11 |
| K07 | Kill-switch-off experience: the header banner + what customers get meanwhile | off | AC-08, §8 |
| K08 | FS-03 transcription/OCR down: player works, transcript unavailable (Doc 04 EC-07 semantics) | fs-03 | FS-03 |

## L — Cross-cutting (the proof sheet)

| # | Item | States | Doc 09 ref |
|---|---|---|---|
| L01 | Both themes on every screen, tokens verbatim (no invented dark values) | audit row | B1-DS law |
| L02 | 1440 + 375 on every screen; 44 px targets at 375 | audit row | §13 |
| L03 | WCAG AA both themes; nothing by color alone (chips, meter, switches all carry text) | audit row | §13 |
| L04 | Real Amharic throughout, flagged for native review; LONG Amharic strings prove the layouts | audit row | §14 |
| L05 | Reduced-motion: streaming in chunks, no pulse, static urgency | audit row | §9, §13 |
| L06 | White-label neutral: no real operator brand anywhere | audit row | house law |
| L07 | The five hard absences X1–X5 re-audited against every delivered screen | closing row | X1–X5 |

Delivery gate per screen: file path handed to the owner → his browser → plain-language
feedback → version bump for exactly what he asked → APPROVED → single-file commit.
No row is ✅ without a delivered file/version; a false ✅ is the worst failure possible.
