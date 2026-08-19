# SESSION-09 APPROVED PLAN — Doc 09: AI System (Chatbot, Copilot, Supervisor)

**Status: APPROVED by the owner on the advisor's recount (family-exact 94/94, events 6/6,
[D09] 2/2), 2026-08-19. Committed at approval per standing rule 12. THIS FILE is the plan
of record — the chat transmission of the plan table arrived garbled (duplicated rows,
table broken midway; the known paste-channel adversary, incidents #14/#15's channel), so
any restatement elsewhere yields to this file.**

Advisor rulings folded before approval (all owner-confirmed):

1. **Embeddings = pinned LOCAL model (bge-m3 or equivalent)**, ADR required, seam stays
   provider-agnostic — no Gemini embeddings, no second egress vendor. (Addis AI serves
   no `/v1/embeddings` — proven HTTP 404 at STEP 0.2d.)
2. **Capability verdict (b) accepted** — Addis AI stays for generation with the hardened
   identity/language rules as T-02 build requirements, pinned in the golden suite.
   Provider observations become the plan's risk rows (§9 below).
3. **`ai.providers.manage` registers via addition-of-record at Doc 09 landing** with an
   owner sign-off row — confirmed; never seeded early.
4. **The exposed staging key stays in service** (owner ruling, test account, risk
   accepted on the record — governance incident #16); **rotate before any production key
   exists** is a standing plan row (§9 row 5).

---

## §1 — Id count by family (enumerated from Doc 09's own sections; cross-document
citations excluded — the citation trap checked against section text)

| FR | NFR | US | BR | T | SEC | EC | FS | SS | AC | R | Total |
|---|---|---|---|---|---|---|---|---|---|---|---|
| 21 | 7 | 7 | 7 | 9 | 6 | 12 | 5 | 4 | 10 | 6 | **94** |

**Event family (FR-21, closed at six):** `ai.decision.logged` (high-volume, SAMPLED to
the bus — full fidelity lives in `ai_decisions`, the Q7 payload-split law generalized),
`ai.handoff.created`, `ai.resolved`, `ai.rg.flagged`, `ai.killswitch.changed`,
`ai.config.published`. Payloads: identifiers and reason categories only — never
prompt/output text on the bus.

**Foreseen filed-interpretation (the A-4/A-15 mechanism; filed BEFORE shipping, never
silent):** T-01/FS-01 require "degraded mode events" that no enumeration names —
candidate `ai.provider.health_changed` (the A-22 tri-state, transition-only shape).
Flagged at plan time; enters only by filed amendment when its step arrives.

**`[D09]` closing set (enumerated from BUILD-STATUS's Doc 08 close section; a full-file
sweep found exactly these two):**

| Row | BUILD-STATUS line | What closes it |
|---|---|---|
| Doc 08 FR-14 | BUILD-STATUS:442 | The `source=ai` PRODUCER: RG override writes through `RgFlagService::set(source: 'ai')` — mandatory excerpt ref, NULL actor by CHECK (step 7) |
| Doc 08 EC-06 | BUILD-STATUS:490 | Calibration CONSUMES the stored clear-reasons: the FR-20 RG precision review queue / calibration feed reads `rg_flag_history` cleared rows (step 7) |

Other Doc 09 seam obligations discovered in the record (consumption rows inside steps,
NOT Doc 08 register rows): Doc 05 A-29's AI-first-turn acquisition context
(`DeepLinkResolver::forConversation()`), Doc 05's `telegram.handoff_requested` trigger,
Doc 06 FR-03's subject seam (subject omitted at creation for Doc 09 to generate, marked
`subject_source='ai'`), Doc 07's Copilot tab slot + composer AI-actions slot, Doc 04's
`BatchTailHint::tailFor()` and the media transcript/OCR events.

---

## §2 — New stack (each approved with this plan; ADR written BEFORE the code it covers)

| ADR | Dependency | Shape |
|---|---|---|
| 0015 | pgvector (first use) | T-05 HNSW retrieval; extension install is migrator-owned DDL (ADR 0003 grant physics) |
| 0016 | In-house thin OpenAI-compatible LLM client | ~300 auditable lines, the A-21/ADR-0012 precedent; egress-proxy pinned; provider driver seam (complete/stream) |
| 0017 | Horizon `ai` queue family | The ADR 0007 `mail` shape; FR-03 priority lanes (chatbot) over background (digest, QA pre-score) |
| 0018 | Local embeddings sidecar, pinned model (bge-m3 or equivalent) | The `/sidecars` precedent: dedicated container behind private HTTP, driver-seamed like any provider; per the owner's embeddings ruling |
| 0019 | Addis AI provider record + data-use/retention terms | SEC-01's vendor-register pin is a `[D18]` seam — this ADR is the interim record and says so; terms fetched and quoted |

**Registries impact:** routes map onto the 7 existing `ai.*` keys (`ai.copilot.use`,
`ai.copilot.insert_draft`, `ai.chatbot.configure`, `ai.guardrails.edit`,
`ai.thresholds.edit`, `ai.decisions.view`, `ai.killswitch`); **`ai.providers.manage`**
enters as the addition-of-record (catalog 113→114; CatalogSnapshotTest/RoleSeederTest
pins move in the same commit; owner sign-off row per the registry's own filed note and
Q9/R-12). Render-gates are derivations, never new keys. New settings named per
conventions with platform-maximum validation at their steps (semaphore size,
conversation-window N, quota reserve fraction, simulation sample caps). Four
notification categories: quota-80%, kill-switch state, anomaly alerts, digest delivery.
Audit-coverage rows for every state mutation: config publish/rollback, threshold change,
guardrail edit, kill-switch flip, provider switch, persona publish (SEC-05
high-visibility class, actor + old/new).

**SCHEMA AMENDMENT 3** (filed and countersigned BEFORE any migration ships — the B1
precedent): `ai_decisions` (30-day payload schedule designed in), provider/persona/
threshold/guardrail/prompt config-version tables, `ai_kill_switches`, `kb_chunks` +
embedding store (pgvector), resolved-ticket-summary embeddings, calibration read-model.
Every column R-19b-analyzed; ADR-0003 grant physics; FORCED RLS; DB time only (C-11);
pinned-provenance copies for anything ordering-relevant.

---

## §3 — Steps (14; one commit each; safety mechanisms sequenced BEFORE the features
they constrain; §21's recommendation binds the head: orchestrator + decision log +
golden harness before any surface)

| # | Step | Safety-ordering justification |
|---|---|---|
| 1 | SEC-03 scrubber discharge (R-D09-1: diff → advisor countersign → land; phone E.164 + local-Ethiopian and email VALUE patterns, broad pattern + explicit allowlist; plant→detect→remove across all five sinks; `log-scrubber` gate + Doc 02 canonical suites green) + the fixtures-only NON-PRODUCTION EGRESS GUARD (refuses provider calls whose payload is not fixture-tagged; own plant control) | Both land BEFORE any provider request/response logging or provider-touching test exists (the Doc 05 PII law, mechanized) |
| 2 | SCHEMA AMENDMENT 3 filed → countersign (MANDATORY STOP) → migrations + grant-physics tests; ADRs 0015–0019 committed | Tables before writers; pgvector DDL migrator-owned |
| 3 | Orchestrator + prompt assembly + validators (FR-01/02/04, T-01/T-02/T-03): immutable-layer HASH ASSERTION before assembly ships; ACTION-ENUM validator + EC-05 discard→retry→handoff before any chatbot path exists; SEC-04 ALLOWLIST proof before any prompt is built from customer context; SEC-02 redaction layer; hardened identity/language rules as T-02 layers (STEP 0.2 ruling) | The prompt's four head constraints, ordered inside the step: test first, wiring after |
| 4 | Decision log (FR-20/T-08/NFR-05/SEC-06): async batched writer; outcome-enrichment durable consumers (ConsumerRegistry additions move the drill pin AND the Doc 06 NFR-06 fixture SAME COMMIT); 30-day payload-reduction scheduled job; access split (raw payloads = platform actor; no invented key) | §21: decision log before any surface |
| 5 | Golden-conversation harness + red-team suite (T-09/AC-01/AC-07): record/replay provider mock; en+am dialogs; transaction-fact traps; injection fixtures; CI wiring; nightly live-staging leg configured, first run pasted once | §21: harness before any surface |
| 6 | Classification pipeline (FR-05/06/07, NFR-02, EC-01, T-06): `message.received` durable consumer + `BatchTailHint::tailFor()`; transcribe/OCR consumers of `media.needs_transcript`/`media.needs_ocr`; RG classifier separate prompt+config; NFR-07 eval harness on the labeled fixture set | Persist-first ordering (Doc 04 NFR-02) proven; classification never blocks the write |
| 7 | RG override (FR-13/BR-03/BR-04/AC-04/SS-03): template-only BYTE-VERIFY + preemption fixture BEFORE the path goes live; `RgFlagService` `source=ai` producer (**[D09] FR-14 close**); calibration consumption (**[D09] EC-06 close**); FR-15 suppression engagement via the built event path; 50-dialog SS-03 audit | The Doc 08 seam consumed EXACTLY; visibility never widened |
| 8 | Kill switches + quota + degradation (FR-18 switches, NFR-04/NFR-06, EC-11/EC-12, FS-01–FS-04, AC-08/AC-09, SS-04, T-07): blast-radius BOTH-DIRECTIONS proof BEFORE the chatbot is enabled on any endpoint; PRE-CALL quota (consuming `Quota::AiTokensPerDay` — never forked) BEFORE any load-shaped test; 5% reserve completes the current turn; circuit breakers + fallback chain + zero-stranded ladder; metering + FS-05 reconcile + 80% alert | Both mandated orderings honored structurally: switches and quota land before step 9 makes the chatbot reachable |
| 9 | Chatbot turn + retrieval + handoff (FR-08/09/10/11/12, T-04/T-05, BR-02/BR-07, EC-02/04/07/08, AC-02/AC-03): pgvector hybrid retrieval over fixture-seeded chunks; citations schema-enforced; grounding-or-handoff; `createFromHandoff` + FR-11 packet + decision_id; FR-03 subject task (any tickets-write path need is ESCALATED first); FR-28 template send + `[D11]` wait-estimate seam; FR-27 rendering + FR-19 typing consumed; A-29 first-turn context; threshold simulation ENGINE + AC-03 parity | The chatbot becomes reachable only here — every constraint from steps 1–8 already live |
| 10 | Copilot backend (FR-14/15/16, BR-01, EC-09/10, AC-06): NEVER-AUTO-SEND static + runtime proof BEFORE insert exists; insertion gated `ai.copilot.insert_draft`; translate preserves the original in message metadata; §14 digit validator; edit-outcome logging (all three outcomes) | The prompt's Copilot ordering constraint, in-step: proof precedes the insert path |
| 11 | Supervisor surface (FR-17): anomaly scan (hourly), sentiment watch, qa_prescore (rubric fixture-fed, `[D12]`/`[D16]`), localized digests; FR-20 weekly outputs (top-10 unanswerable → `[D10]` seam; calibration curve; RG precision review queue). PLUS the EC-03 chip side-row (R-D09-3: Doc 08-owned UI file, escalate-first diff → countersign → land, own proving test — small, does not sprawl) | Background surfaces; independent of the customer path |
| 12 | Backoffice + staff UI tail (FR-18 remainder; §8/§9/§13 staff clauses; AC-05 DOM marking; Copilot/composer slot fills): Option A (R-D09-2) — functional, themed, i18n-keyed, design-system components only (AI accent `--ds-ai-bg/fg`); rows ride **PARTIAL [B8]**; hex-extraction script re-run before the first screen; browser proofs under `make browser` with `octane:reload` first | R-D09-2: UI at the tail; [B8] is a bucket, not a deviation |
| 13 | §14 language gates + i18n sweep: RG-template completeness gate as CODE (an endpoint cannot enable a language missing its RG template); en+am for every `ai.*` string; string-lint; R-14 queue join with destructive tier flagged | The hard gate is code, not process |
| 14 | Close-out: 94-id self-audit (DONE/PARTIAL-with-bucket/NOT DONE); sabotage ledger item-by-item; `[D09]`/SEC-03/EC-03 BUILD-STATUS flips; core-edit enumeration; §12 budget table with the TOKEN-COST axis; final battery + CI once; §9 report; SHA grounding BEFORE the recount issues | Protocol step 6 |

**Checkpoint batches:** A = steps 1–2 · B = 3–5 · C = 6–9 · D = 10–14. STOP at every
checkpoint until the advisor's grounded acceptance returns. Mandatory mid-batch stops:
SCHEMA AMENDMENT 3 countersign; SEC-03 diff countersign; EC-03 diff countersign; any
core-edit boundary; any design conflict/gap (mandate §3); any new enumeration amendment;
any guard conflict; any red battery not resolvable in my own files.

---

## §4 — Inherited rows (PROJECT-HANDOFF-v8 §4), dispositions

| Row | Disposition |
|---|---|
| 0a SEC-03 | Discharge at step 1 per R-D09-1 (ruled; not re-litigated) |
| 0b EC-03 chip | Sanctioned side-row at step 11 per R-D09-3; escalate-first; own proving test; BUILD-STATUS flip at close |
| 0c row 4b (staff-avatar STORE half) | OWNER-PENDING; build nothing; carried |
| 1 credentials gate | DISCHARGED at STEP 0 (key live: HTTP 200, model `addis-አሌፍ-፩`, usage 271p+4c; capability check pasted; incident #16 on the record). Standing row: ROTATE the exposed key before any production key exists |
| 2 [OA] Doc 05 rows | Parked on the staging Telegram Business account; token rotation first; NOT unparked |
| 3 Risks 0a–0j | Standing. 0i triage law on every red (threshold 3-in-10); 0d re-run-once, "host-bound on this hardware" recorded; 0h mechanical recipe on any jsonb `?` symptom |
| 4 R-14 queue | Doc 09's machine-authored Amharic joins the ~511-string queue; destructive tier flagged at close: kill-switch confirm, guardrail deletion, RG template edit, provider switch, config rollback |
| 5 shouldRenderJsonWhen | Per-door interim on every new door; evidence filed |
| 6–11 | Unchanged from v7 §4; observed (bounded deferral wording; dry-run note; Doc 18 notes; Doc 19 buckets; Doc 11/12 seams; Doc 16 seam; owner hygiene) |
| 12 [D09] closing set | §1's two rows, each naming its BUILD-STATUS line, flipped at close |

---

## §5 — Sabotage enumeration (FIFTEEN; the ledger reconciles against THIS list, item by
item, never a count; grown only by filed amendment; captures first; backup before
sabotage; tree proven clean after; no pipes on a gate)

1. Immutable honesty layer made operator-reachable / its hash unasserted → the T-02 hash test goes red.
2. Action-enum schema validation removed → EC-05's invented-action fixture executes an out-of-enum action → red (injection-shaped #1).
3. KB index-time instruction-stripping disabled → a planted imperative in a chunk reaches the prompt → the T-03/AC-07 red-team fixture goes red (injection-shaped #2).
4. SEC-04 allowlist widened (RG flag content or a staff note planted into context) → the exclusion proof goes red (BR-06).
5. RG preemption de-prioritized (mid-form message with rg_risk ≥ threshold continues the form) → AC-04's preemption fixture goes red (FR-13).
6. RG response switched from template to generated text → AC-04's byte-verify goes red (BR-03).
7. A code path added from Copilot suggestion to adapter send without the composer action → AC-06's static + runtime proof goes red (BR-01).
8. The citation requirement dropped from `answer` on a policy/money fixture with no retrieval hit → AC-02 goes red (BR-02, `no_grounding`).
9. A kill-switch scope made to leak (silences a neighbor endpoint, or leaves its own target on) → AC-08's both-directions proof goes red.
10. Disclosure removed from the first greeting of ONE persona in ONE language → AC-05 goes red (FR-19a/BR-05).
11. The non-production fixtures-only egress guard removed → its plant control (a non-fixture payload attempts egress) goes red.
12. The pre-call quota check moved post-call → EC-12/NFR-06's exhausted-quota fixture bills past the cap → red.
13. SEC-02 redaction layer bypassed → the masking-map plant test goes red.
14. §14's digit validator disabled → the altered-digit translate fixture goes red (money safety).
15. The SEC-03 scrubber stubbed → the plant→detect→remove control goes red across the five sinks (R-D09-1's own proving terms; fail + restored-pass pasted).

---

## §6 — Canonical suites command ("DOC 09 SUITES", standing rule 14 — defined at plan
time; mirrored in BUILD-STATUS)

```
./vendor/bin/pest tests/Feature/Ai \
                  tests/Golden \
                  tests/Contract/AiEventConsumerContractsTest.php \
                  tests/Isolation/AiGrantPhysicsTest.php \
                  tests/Security/AiEgressGuardTest.php \
                  tests/Security/AiContextAllowlistTest.php \
                  tests/Security/AiInjectionRedTeamTest.php \
                  tests/Security/CopilotNeverAutoSendTest.php
```

The command names files later steps create (the S23/S07/S08 record-note precedent):
figures cited before a named file exists come from the extant paths, stated as such.
Full correctness battery + `make gates` + `make lint` once per step-commit REGARDLESS
of BAT1; only §12 timing legs and quotable figures wait on wall power ("timing not
taken" said plainly). Browser proofs under `make browser`, `octane:reload` first. CI
checked ONCE per checkpoint; 0i triage before belief.

---

## §7 — Pre-agreed closure buckets (the ONLY sanctioned PARTIAL homes)

| Bucket | Rows it covers | Unblocker |
|---|---|---|
| [D10] | T-05 index maintenance on publish events; EC-06's live tombstone resolution; FR-10 over real articles; SS-02's loop | Doc 10 (retrieval proven HERE over fixture-seeded chunks) |
| [D11] | FR-12/FR-13's live RG-queue delivery + queue-wait estimates | Doc 11 (handoff contract + packet proven HERE) |
| [D12] | run_form execution; FR-09 CSAT capture; macro content; qa_prescore rubric content | Doc 12 (rubric content also Doc 16); seams fixture-fed here |
| [D13] | Web-widget chatbot surface; §8/§13 widget streaming/a11y clauses | Doc 13 (Telegram proven end-to-end HERE) |
| [D15] | FR-17/FR-20 outputs RENDERED in dashboards; R-06's honest triad display | Doc 15 (data products proven HERE) |
| [D18] | SEC-01's vendor-register pin | Doc 18 (ADR 0019 is the interim record) |
| [D19] | §12 budgets at L2/50k-chunk/10k-decision scale; NFR loads; RG corpus process | Doc 19 + staging hardware (dev-scale figures ship HERE, flagged) |
| [B8] | Pixel fidelity + frame mapping for every Doc 09 staff surface | Batch 8 freeze (Option A, R-D09-2) |
| [OA] | AC-10's monthly invoice reconciliation; R-14 native review | Owner/calendar |

---

## §8 — The plan table of record (ONE clean row per id: id → file(s) → proving test)

All paths under `app/Domain/Ai/` unless noted; tests under `tests/` with the tier in
the path. Bucket names in brackets mark the sanctioned PARTIAL half where one exists.

| Id | File(s) | Proving test |
|---|---|---|
| FR-01 | Orchestrator/AiOrchestrator.php, Orchestrator/TaskDefinition.php, Tasks/* (12 v1 tasks, typed IO, tiers, timeouts, customer-visible flag) | Feature/Ai/OrchestratorTest.php |
| FR-02 | Providers/ProviderRegistry.php, Providers/Drivers/{AddisAiDriver,ReplayDriver,LocalEmbeddingsDriver}.php, ai.providers.manage door | Feature/Ai/ProviderConfigTest.php (zero-deploy switch; vault auth ref; fallback order) |
| FR-03 | app/Jobs/Ai/*, Horizon `ai` family config, Redis per-tenant semaphore | Feature/Ai/AiQueueTest.php (semaphore cap, priority lanes, pre-call quota) |
| FR-04 | Config/ConfigVersions.php, Config/PublishConfig.php | Feature/Ai/ConfigVersioningTest.php (instant publish, rollback, config_version in decisions) |
| FR-05 | Pipeline/Normalize.php + consumers of media.needs_transcript / media.needs_ocr | Feature/Ai/NormalizeTest.php (transcript_ref per Doc 04 T-05; OCR fields + per-field confidence; language persist per Doc 04 FR-06) |
| FR-06 | Pipeline/ClassifyConsumer.php (message.received durable consumer; BatchTailHint::tailFor()) + Tasks/ClassifyMessage.php | Feature/Ai/ClassifyPipelineTest.php (taxonomy, ranges, batch-tail EC-01, fast tier) |
| FR-07 | Pipeline/TurnDecision.php | Feature/Ai/TurnDecisionTest.php (open-ticket → Copilot context + stop; enabled → turn; else handoff) |
| FR-08 | Chatbot/ChatbotTurn.php, Chatbot/ActionSchema.php, Chatbot/ContextBuilder.php | Feature/Ai/ChatbotTurnTest.php + ActionSchemaTest.php (closed enum; context layers; FR-27 render consumption) |
| FR-09 | Chatbot/Resolve.php ([D12] CSAT seam fixture-fed) | Feature/Ai/ResolveTest.php (AI-resolved, no ticket; 24 h post_resolution reopen flag) |
| FR-10 | Retrieval/ChunkStore.php, Retrieval/HybridRetriever.php, Retrieval/Citations.php | Feature/Ai/RetrievalTest.php + AC-02 (published-only, AI-usable categories, language filter + fallback; internal articles Copilot-only) [D10 for live articles] |
| FR-11 | Config/Thresholds.php, Simulation/SimulationEngine.php | Feature/Ai/SimulationParityTest.php (AC-03; per-endpoint overrides EC-07) |
| FR-12 | Chatbot/HandoffDecision.php, Chatbot/PacketBuilder.php → CreateFromHandoff | Feature/Ai/HandoffTest.php (all seven triggers; packet vs fixtures/tickets/handoff/doc09-packet.json; FR-28 template; decision_id embedded) [D11 wait estimate] |
| FR-13 | Rg/RgOverride.php → RgFlagService(source: 'ai') | Feature/Ai/RgOverrideTest.php (AC-04 preemption; template-only; flag + excerpt + suppression; [D09] FR-14 flip) [D11 live queue] |
| FR-14 | Copilot/Panel/*.php + ContextPanel Copilot-slot fill (resources/js) | Feature/Ai/CopilotPanelTest.php + Browser/ai-copilot.spec.ts (debounced summary, chips, streamed reply, KB top-3, similar tickets scope-checked, guardrail notices, low-confidence banner) [B8] |
| FR-15 | Copilot/ComposerActions.php + ComposerPane AI-actions slot fill | Feature/Ai/ComposerActionsTest.php (draft / improve / translate-with-original-in-metadata / summarize-as-note-draft) |
| FR-16 | Copilot/ArtifactLog.php | Feature/Ai/ArtifactOutcomeTest.php (inserted_unedited / inserted_edited{diff_ref} / discarded) |
| FR-17 | Supervisor/{AnomalyScan,SentimentWatch,QaPrescore,DailyDigest}.php | Feature/Ai/SupervisorTest.php (hourly deltas; very-negative watch; advisory pre-score; 6-bullet localized digest) [D15 render; D12/D16 rubric] |
| FR-18 | Http/Controllers/Ai/*, resources/js/Pages/Ai/* | Feature/Ai/BackofficeConfigTest.php + Browser/ai-backoffice.spec.ts (persona 5-conversation preview; seeded-item lock; scopes; kill-switch grid) [B8] |
| FR-19 | Prompt/ImmutableLayer.php + author_type=ai marking + AI accent | Feature/Ai/HonestyTest.php (AC-05: disclosure, claim-framing, marking, never-edited) |
| FR-20 | DecisionLog/{DecisionWriter,OutcomeEnrichment,WeeklyOutputs}.php | Feature/Ai/DecisionLogTest.php (all fields; enrichment joins; calibration curve; RG precision review queue — [D09] EC-06 flip; top-10 unanswerable [D10] seam) |
| FR-21 | app/Support/Events/EventCatalog.php (+6, closed payloads, decision.logged sampled) | Contract/AiEventConsumerContractsTest.php + event-catalog gate |
| NFR-01 | streaming turn path | Performance/AiBudgetsTest.php (dev-indicative first-token/full-turn) [D19] |
| NFR-02 | ClassifyConsumer ordering | ClassifyPipelineTest.php persist-first proof + perf leg [D19] |
| NFR-03 | Copilot streaming path | Performance/AiBudgetsTest.php in-panel leg [D19] |
| NFR-04 | Providers/CircuitBreaker.php | Feature/Ai/FailoverDrillTest.php (≤10 s trip; one-message-turn degrade) |
| NFR-05 | DecisionLog async batching | Performance/AiBudgetsTest.php decision-write leg (≤3 ms) [D19] |
| NFR-06 | Quota wiring on Quota::AiTokensPerDay (consumed, never forked) | Feature/Ai/QuotaEnforcementTest.php (pre-call; 80% alert; turn cap) |
| NFR-07 | Rg/EvalHarness.php + labeled fixture set | Feature/Ai/RgEvalHarnessTest.php (precision ≥0.9 at shipped threshold, printed) [D19 corpus process] |
| US-01 | golden dialog (am receipt journey) | Golden suite fixture (OCR + citation + check-back) |
| US-02 | RG override path | RgOverrideTest.php distress fixture (suppression + specialist routing contract) |
| US-03 | handoff packet summary | HandoffTest.php (2–4-sentence summary lands on the ticket) |
| US-04 | translate with original preserved | ComposerActionsTest.php (metadata original, hover visibility) |
| US-05 | anomaly alert | SupervisorTest.php (+240% withdrawal-complaints scenario) |
| US-06 | threshold simulation | SimulationParityTest.php (yesterday recomputed before commit) |
| US-07 | kill switch | Feature/Ai/KillSwitchTest.php (one red button, instant, audited actor) |
| BR-01 | (structural absence of a send path) | Security/CopilotNeverAutoSendTest.php (static + runtime) |
| BR-02 | Citations enforcement in ActionSchema | AC-02 test (answer requires ≥1 valid citation; else no_grounding) |
| BR-03 | RG template path (generation unreachable) | RgOverrideTest.php byte-verify |
| BR-04 | precision-first threshold + rationale | RgEvalHarnessTest.php + documented rationale in config |
| BR-05 | no disclosure-disable key exists | HonestyTest.php absence assertion (the N-08 pattern) |
| BR-06 | ContextBuilder allowlist | Security/AiContextAllowlistTest.php (RG content + staff notes planted → excluded) |
| BR-07 | publish door requires simulation | Feature/Ai/ThresholdPublishTest.php |
| T-01 | Orchestrator + driver interface + fallback executor + breakers | OrchestratorTest.php + FailoverDrillTest.php |
| T-02 | Prompt/LayeredAssembly.php + ImmutableLayer hash | Feature/Ai/PromptAssemblyTest.php (layer version hashes logged; immutable layer code-shipped, hash asserted) |
| T-03 | delimiting/role separation; index-time instruction-strip; schema validation; closed tools | Security/AiInjectionRedTeamTest.php |
| T-04 | Streaming/ReverbRelay.php; Telegram complete-message + FR-19 typing | Feature/Ai/StreamingTest.php [D13 widget half] |
| T-05 | pgvector HNSW + hybrid score + language filter + nightly similar-tickets embeds + Doc 03 scope filter at query | RetrievalTest.php + SimilarTicketsTest.php [D10 publish-event maintenance] |
| T-06 | RG classifier separate prompt+config; eval harness; simulation + precision readout | RgEvalHarnessTest.php + ThresholdPublishTest.php |
| T-07 | Metering/TokenMeter.php (Redis counters → daily DB reconcile; rate card incl. preamble overhead) | Feature/Ai/MeteringTest.php (FS-05 pattern) |
| T-08 | DecisionLog async batched inserts + enrichment via event consumers (decision_id in packet) | DecisionLogTest.php [D12 CSAT join] |
| T-09 | golden + red-team suites vs record/replay mock; nightly live-staging leg | the suites themselves + CI workflow (first nightly output pasted once) |
| SEC-01 | vault auth ref (interim per A-19 precedent); ADR 0019 terms record | ProviderConfigTest.php + scrubber sweep [D18 register pin] |
| SEC-02 | Prompt/PiiRedaction.php (masking map logged; OCR reference exception) | Feature/Ai/PiiRedactionTest.php |
| SEC-03 | T-03 defenses + CI red-team + Sev-2 runbook (kill switch → patch sanitizer → replay fixtures) | AiInjectionRedTeamTest.php in CI + docs/runbooks/ai-injection-sev2.md |
| SEC-04 | ContextBuilder explicit allowlist | Security/AiContextAllowlistTest.php (proof by planting) |
| SEC-05 | audit-coverage rows for switches + publishes (high-visibility) | audit gate + BackofficeConfigTest.php |
| SEC-06 | ai.decisions.view door; raw payloads platform-actor-only; 30-day reduction job | Feature/Ai/DecisionAccessTest.php + PayloadReductionJobTest.php |
| EC-01 | batch-tail single coherent turn | golden fixture, pinned |
| EC-02 | third-language voice → polite capability message + handoff unsupported_language | Feature/Ai/UnsupportedLanguageTest.php |
| EC-03 | doctored-receipt honesty (claim restated; OCR fields in packet; credit decision human) | transaction-fact/OCR trap fixture in golden suite |
| EC-04 | blocked topic deflection ×3 → blocked_topic_persistence | Feature/Ai/BlockedTopicTest.php |
| EC-05 | out-of-enum/malformed → discard → single repair retry → handoff invalid_action, both logged | ActionSchemaTest.php |
| EC-06 | tombstone citation resolution + new-citation cache invalidation | [D10] — seam stated; fixture-fed tombstone render here |
| EC-07 | per-endpoint config per conversation; packet notes endpoint | TurnDecisionTest.php + SimulationParityTest.php |
| EC-08 | post_resolution ×2 then mandatory handoff | ResolveTest.php (no infinite AI loops) |
| EC-09 | regenerate ×3 → locked low-confidence card + weekly-review telemetry | CopilotPanelTest.php |
| EC-10 | mixed-language translate: dominant detect; embedded terms preserved; digit rule | Feature/Ai/TranslateTest.php |
| EC-11 | provider policy-refusal → provider_refusal handoff; raw text never surfaces | Feature/Ai/ProviderRefusalTest.php |
| EC-12 | quota exhaustion: reserve completes turn → straight-to-human; 80% alert long before | QuotaEnforcementTest.php |
| FS-01 | outage → breaker → chain → bypass-to-handoff; Copilot degraded card; Supervisor pause; auto-resume; evented | FailoverDrillTest.php (AC-09) |
| FS-02 | index corruption → no_grounding mode (smalltalk/forms unaffected); rebuild; alert | Feature/Ai/IndexCorruptionTest.php |
| FS-03 | transcription/OCR down → Doc 04 EC-07 semantics; classify on caption/absence; retry drains | Feature/Ai/MediaOutageTest.php |
| FS-04 | decision-log backlog → completion unaffected; staleness pattern; reconcile on drain | Feature/Ai/DecisionBacklogTest.php |
| FS-05 | bad config publish → instant rollback; simulation prevention; digest surfacing; runbook | Feature/Ai/ConfigRollbackTest.php |
| SS-01 | decision-log data products | PARTIAL [D15]/[OA] — 60-day KPI needs live time; products proven here |
| SS-02 | weekly unanswerable → KB article loop | PARTIAL [D10] — one-click draft seam fixture-fed |
| SS-03 | 50 seeded distress dialogs → template-only, correct routing, flags with excerpts, zero generated RG text | RG golden audit, executed HERE |
| SS-04 | provider incident at peak → failover, zero stranded, events-only post-incident report | FailoverDrillTest.php |
| AC-01 | golden suite en+am, all action types, batch-tail + post-resolution | Golden suite at pinned configs |
| AC-02 | grounding rule (no-hit → no_grounding; answer carries ≥1 citation, schema-enforced) | AC-02 test |
| AC-03 | simulation-vs-replay parity on a seeded decision-log day | SimulationParityTest.php |
| AC-04 | RG: NFR-07 precision; mid-form preemption; byte-verified template; flag + excerpt + suppression fixtures | RgOverrideTest.php + RgEvalHarnessTest.php |
| AC-05 | disclosure across personas/languages; transaction-fact traps; AI marking DOM assertions | HonestyTest.php + Browser/ai-honesty.spec.ts |
| AC-06 | never-auto-send (static + runtime); FR-16 three outcomes captured | CopilotNeverAutoSendTest.php + ArtifactOutcomeTest.php |
| AC-07 | injection red-team suite neutralized, required in CI | AiInjectionRedTeamTest.php + gates entry |
| AC-08 | kill-switch blast radius exact, both directions, ≤10 s, evented, bannered | KillSwitchTest.php |
| AC-09 | scripted provider-failure drill, zero unanswered customers | FailoverDrillTest.php |
| AC-10 | token counts reconcile ≤2% + quota enforcement | MeteringTest.php + QuotaEnforcementTest.php; invoice half [OA] |
| R-01 | hallucinated money facts → BR-02 + claim-framing + traps + no-tools | trap fixtures; residual monitored via [D15] triad |
| R-02 | RG false positives → precision-first + calm template + clearing/calibration loop | RgEvalHarnessTest.php + EC-06 consumption |
| R-03 | prompt injection → T-03 layers + CI red-team + Sev-2 runbook | AiInjectionRedTeamTest.php + runbook |
| R-04 | provider dependency → fallback chains + ladder + AI-off operability (platform fully operable AI-off) | FailoverDrillTest.php + [D18] register |
| R-05 | cost runaway → quotas + turn caps + fast-tier classification + EC-12 soft landing | QuotaEnforcementTest.php; dashboards [D15] |
| R-06 | operator over-trust → honest triad ships together | [D15] — data products proven here |

---

## §9 — Risk rows (provider observations from STEP 0.2, now standing)

1. **Addis AI latency**: p50 ≈2.4 s, max 5.2 s on tiny outputs vs NFR-01's 2.5 s p90
   first-token — tight; streaming mandatory; dev-scale-indicative figures, formal [D19].
2. **Hidden provider preamble**: identity leak ("Addis Assistant from Addis AI")
   controlled by hardened T-02 identity/language rules, PINNED in the golden suite;
   ~270–420 fixed prompt-token overhead is a T-07 rate-card fact, metered not assumed.
3. **Single-model catalog** (`addis-አሌፍ-፩` only): fast/strong tiers are config-routing
   to one model until the provider grows; the provider-agnostic seam makes any change a
   config edit.
4. **Small-vendor uptime/rate limits**: circuit breakers + the FS-01 ladder; vendor
   register [D18] via ADR 0019.
5. **Key exposure (incident #16)**: the exposed staging key stays in service by owner
   ruling (test account, risk accepted); **ROTATE before any production key exists** —
   owner-action row, carried to every checkpoint until done.

---

## §10 — STEP 0 record (evidence at docs/evidence/S09-step0-capability/, credential-clean)

- **0.1 key check PASS**: one authenticated call — HTTP 200, model `addis-አሌፍ-፩`,
  usage 271 prompt + 4 completion tokens; every credential character masked; key filed
  at `~/.na-vault/addis-ai.env` (700/600), never in the repo.
- **0.2 capability**: (a) structured output PASS — malformed-JSON 0/23 (17 classification
  turns EN+AM, 6 FR-08 action turns; RG fixtures 0.95 both languages; the
  transaction-fact trap handed off `no_grounding`); (b) English PASS WITH CONDITIONS —
  hardened identity/language rules required and proven controlling (ruled accepted);
  (c) Amharic outputs captured VERBATIM for the R-14 native-review queue, not judged;
  (d) embeddings NOT SERVED (HTTP 404) → the owner's pinned-local-model ruling.
- **0.3 rulings-filed proof**: A-38…A-42 present; ADRs 0003/0010/0011/0013/0014 present;
  SCHEMA AMENDMENT 2 + additions 7/8/9 + customer_notes grant delta present;
  ADVISOR-RULINGS-D09 R-D09-1/2/3 at `ce8a4f9`; permission count EXECUTED = 113; the
  seven `ai.*` keys verified in the registry; `ai.providers.manage` = pre-declared
  addition-of-record (registry header note + Q9/R-12), owner-confirmed.

*End of the approved plan. The first build commit is step 1; nothing precedes it but
this commit and the owner's grounding.*
