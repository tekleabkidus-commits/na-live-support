Table of Contents

# Document 09 — AI System: Chatbot, Copilot, Supervisor

**Product:** AI-First Support Platform for Betting Companies **Document series:** 09 of 19 · Standalone enterprise specification **Audience:** AI engineers, backend engineers, frontend engineers, QA, product, compliance **Depends on:** Doc 01 (events, settings, quotas), Doc 03 (permissions ai.*), Doc 04 (message pipeline, transcript/OCR hooks, batch-tail hint), Doc 05 (flows, menus, business-mode fallbacks), Doc 06 (handoff packet contract, subject generation), Doc 08 (customer context, RG flag storage). Consumed by: Doc 07 (Copilot slot, AI actions), Doc 10 (KB retrieval), Doc 12 (automation AI actions), Doc 15 (AI reports). **Stack context:** Provider-agnostic orchestrator over HTTP LLM APIs (model-per-task), pgvector (retrieval), Redis (semaphores), Horizon ai queue, Reverb streaming

## 1. Executive Summary

This document specifies the platform’s AI layer as three surfaces sharing one orchestrator: the **Chatbot** (customer-facing on Telegram and web — greets, detects language and intent, runs forms, answers from the knowledge base, resolves or hands off), the **Copilot** (agent-facing — summaries, drafted replies, translation, similar tickets, macro suggestions; never auto-sends), and the **Supervisor** (management-facing — anomaly alerts, sentiment monitoring, QA pre-scoring, daily digests). It defines the per-message inference pipeline (normalize → classify → decide → act), retrieval-augmented answering over Doc 10’s published articles, the Responsible Gaming classifier with its priority override, the complete Backoffice configuration surface (personas, thresholds with historical simulation, guardrails, kill switches — all no-code), the AI decision log that makes every action reportable and tunable, the quality loop, honesty rules (disclosure, no invented transaction facts, visible AI marking), provider abstraction with model-per-task routing, prompt/config versioning, cost metering against Doc 01 quotas, and degradation behavior when providers fail. The platform’s stance: AI resolves the routine majority, and every AI action is visible, attributable, reversible in effect, and measurable.

## 2. Purpose

- Specify AI behavior as product contracts (inputs, outputs, thresholds, fallbacks) rather than model magic, so QA can test it and operators can configure it.

- Bound the Chatbot’s authority precisely — what it may answer, what it must never claim, when it must hand off — for a money-adjacent domain.

- Make AI performance a first-class dataset (decision log) powering Doc 15 reports and continuous tuning.

## 3. Scope

**In scope:** orchestrator architecture & provider abstraction; task catalog (classify, chatbot turn, draft, summarize, translate, transcribe, OCR, subject, QA pre-score, anomaly digest); the inference pipeline and its attachment points in Doc 04/05; RAG over KB; RG classifier & override; handoff logic and the Doc 06 packet production; Copilot features & UI contract for Doc 07’s slot; Supervisor surface outputs; Backoffice config (personas, thresholds, guardrails, scopes, kill switches); decision logging; quality loop; honesty rules; cost metering; degradation; events. **Out of scope:** KB authoring (Doc 10); automation rule conditions that consume AI outputs (Doc 12); AI report rendering (Doc 15); the raw media hooks’ storage (Doc 04); model training/fine-tuning (not in v1 — prompt+retrieval only).

## 4. Functional Requirements

### 4.1 Orchestrator & task catalog

FR-01 AiOrchestrator exposes typed tasks; each task declares: input schema, output schema, default model tier (fast = cheap classification-grade, strong = drafting-grade), max tokens, timeout, retry policy, and whether output is customer-visible (stricter guardrail set). v1 tasks: classify_message, chatbot_turn, draft_reply, improve_text, summarize_thread, translate, transcribe_voice, ocr_receipt, generate_subject (Doc 06 FR-03), qa_prescore, daily_digest, anomaly_scan. FR-02 Provider abstraction: named provider configs (endpoint, auth ref via vault, model map per tier) with an ordered fallback chain per task tier; switching providers or models is a Backoffice change (ai.thresholds.edit-adjacent key ai.providers.manage added to the Doc 03 catalog change log), zero deploy. FR-03 Every task call runs as a queued job on the ai queue with a per-tenant concurrency semaphore (Redis) and per-tenant daily token quota (Doc 01 NFR-06); customer-blocking paths (chatbot turn) get priority lanes over background paths (digest, QA pre-score). FR-04 Prompt/config versioning: prompts, persona settings, thresholds, and guardrail sets are versioned records; every decision logs the exact config_version; publishing a new version is instant with rollback (Doc 05 menu-publish pattern).

### 4.2 Inference pipeline (per inbound customer message — attaches at Doc 04 FR-10 step 6)

FR-05 **Normalize:** voice → transcribe_voice (writes transcript_ref per Doc 04 T-05); receipt-like images → ocr_receipt extracting {amount, currency, reference, timestamp, method?} with per-field confidence; language detection on text/transcript (persisted per Doc 04 FR-06). FR-06 **Classify** (classify_message, fast tier, batched on Doc 04 EC-01’s batch-tail hint): outputs intent (operator-configurable taxonomy seeded: deposits, withdrawals, bonuses, verification, technical, betting_rules, complaint, rg_risk, spam, other), sentiment (−2..+2), urgency (0..3), spam probability, **rg_risk score** — the RG classifier is a dedicated prompt tuned for high precision; any rg_risk ≥ threshold **overrides everything** (FR-13). FR-07 **Decide:** if the conversation has an open assigned ticket → no chatbot turn; deliver classification to Copilot context and stop. Else if chatbot enabled for this endpoint (Doc 05/13 flags + kill switches) → chatbot_turn. Else → handoff path directly. FR-08 **Chatbot turn** (chatbot_turn, strong tier, streaming): context = persona + guardrails + conversation window (last N messages, config) + customer context (language, prior-ticket topical summary — no RG flag content ever enters prompts for non-RG flows, SEC-04) + retrieval results (FR-10) + active flow state (Doc 05 menu flows / Doc 12 forms). Output is a structured action: answer{text, citations[]} | run_form{form_id} | continue_flow{...} | handoff{reason} | resolve{closing_text}; the adapter layer renders per channel capability (buttons vs numbered text, Doc 05 FR-27). FR-09 **Resolution ****&**** CSAT:** resolve marks the conversation AI-resolved (no ticket) and triggers the 1-tap CSAT prompt (Doc 12 survey); a customer reply after AI-resolve within 24 h reopens the AI turn with prior context flagged post_resolution.

### 4.3 Retrieval (RAG)

FR-10 Retrieval scope: **published** KB articles only (Doc 10 contract), restricted to categories the operator marked AI-usable, in the customer’s language with the Doc 01 fallback chain; internal articles are retrievable only for Copilot tasks, never chatbot. Embeddings maintained per Doc 10’s publish events (pgvector); retrieval returns top-k chunks with article ids → answers must carry citations (article ids) or the answer path is refused in favor of handoff (no_grounding reason) — the chatbot never answers policy/money questions from model memory. FR-11 Confidence & thresholds (all Backoffice sliders with **historical simulation**: “with these values, yesterday’s 1,240 conversations would have been 61% auto-resolved / 12% more handoffs” computed from the decision log): auto_resolve_min, handoff_below, spam_min, rg_min; per-endpoint overrides (a stricter bot for VIP account).

### 4.4 Handoff & RG

FR-12 Handoff triggers (any): confidence < handoff_below; customer requests a human (intent or Doc 05 /human); blocked-intent list (guardrails); form completion routed to human by design; no_grounding; message limit without resolution (guardrail max_ai_messages, default 8); post-resolution reopen ×2. Handoff produces the **Doc 06 FR-11 packet** exactly (summary, intent+confidence, language, sentiment, urgency, suggested routing + reasons, form_data, kb_shown, transcript anchor) and calls createFromHandoff; the customer receives the handoff template (Doc 05 FR-28) with live queue-wait estimate (Doc 11 data). FR-13 RG override: rg_risk ≥ rg_min → immediate handoff to the RG queue (Doc 11) regardless of all other state (even mid-form), the RG customer template (calm, resource-including, operator-approved fixed text — not generated), rg_flag set on the customer (source=ai, excerpt ref — Doc 08 FR-14), promotional suppression contracts engage (Doc 08 FR-15). Generated text is **never** used for RG responses; only the approved template. False-positive clearing feeds calibration (Doc 08 EC-06).

### 4.5 Copilot (agent surface — fills Doc 07’s slot)

FR-14 Panel contents (contract): live thread summary (refreshes on new messages, debounced 10 s); intent/sentiment/urgency chips; **suggested reply** (streamed, editable, insert-to-composer — never auto-send; insertion gated by ai.copilot.insert_draft); relevant KB (top 3 with insert-as-link); similar resolved tickets (retrieval over resolved-ticket summaries, top 3, scope-checked); macro suggestions (Doc 12); guardrail notices (e.g., “low confidence — verify the reference manually”); low-confidence banner instead of a bad draft. FR-15 Composer AI actions (Doc 07 FR-19 slot): Draft reply, Improve (tone/grammar keeping meaning), Translate (agent writes in their language → send in customer language, with the original always stored in message metadata and visible on hover — honesty), Summarize thread (inserts as internal note draft). FR-16 Every Copilot artifact the agent uses is logged with outcome (inserted_unedited | inserted_edited{diff_ref} | discarded) — the edit-mining input for the quality loop (FR-20).

### 4.6 Supervisor surface

FR-17 Outputs (consumed by Doc 15 dashboards + notifications): anomaly_scan (hourly: volume/intent/sentiment deltas vs baseline → alerts like “withdrawal complaints +240% since 14:00”), live sentiment watch (conversations crossing very-negative threshold → supervisor feed item), qa_prescore (scores sampled resolved tickets against the operator’s rubric (Doc 12/16 content) to prioritize human QA review — pre-score is advisory, never a published agent score by itself), daily_digest (yesterday in 6 bullets, per manager scope, localized).

### 4.7 Configuration, kill switches, honesty

FR-18 Backoffice (permissions per Doc 03): **Persona** (name, avatar, greeting, tone sliders formal↔friendly / brief↔detailed, per language) — persona changes preview against 5 canned conversations before publish; **Thresholds** (FR-11 sliders + simulation); **Guardrails**: blocked topics (seeded: odds/betting advice, legal advice, promises of payment or reversal, account-balance claims), mandatory disclosure wording, RG template text, max_ai_messages, profanity policy, PII redaction toggle for AI outputs; **Scope**: AI-usable KB categories, endpoints where chatbot is on; **Kill switches** (ai.killswitch): per surface × per channel × per bot/widget — instant, no deploy, big red UI with confirmation, evented. FR-19 Honesty rules (product-level, non-configurable): (a) the assistant discloses it is an AI in its first greeting and whenever asked (wording configurable, presence not); (b) the chatbot never states transaction/account facts as facts — templates force claim-framing (“you mentioned a 2,000 ETB deposit”) and money questions without grounding hand off; (c) all AI-authored messages are marked author_type=ai (Doc 04) and rendered with the AI accent everywhere staff-facing (Doc 07 §9); (d) AI messages are never edited post-send — corrections are new messages (Doc 04 FR-23).

### 4.8 Decision log & quality loop

FR-20 Every task invocation writes ai_decisions (Doc 01 schema): surface, task, model+config_version, tokens, confidence, intent, action, outcome, latency. Outcome enrichment arrives from downstream events (resolved-then-reopened, CSAT score, handoff-then-agent-agreed-routing y/n, Copilot edit outcomes FR-16). Weekly automated outputs: top-10 unanswerable questions → one-click “draft KB article from these conversations” (Doc 10 integration); confidence calibration curve; RG precision review queue (all fires + all clears). FR-21 Events: ai.decision.logged (high-volume, sampled to bus), ai.handoff.created, ai.resolved, ai.rg.flagged, ai.killswitch.changed, ai.config.published — audit + reports.

## 5. Non-functional Requirements

NFR-01 Chatbot first token ≤ 2.5 s p90, full turn ≤ 8 s p95 (streamed to the customer as it generates where the channel allows; Telegram sends complete messages — internal streaming still bounds total). NFR-02 Classification ≤ 1.2 s p95 (fast tier) and never blocks message persistence (Doc 04 ordering: persist first, AI after). NFR-03 Copilot summary/draft first token ≤ 2 s p90 in-panel (streamed). NFR-04 Provider failover engages ≤ 10 s after error-rate trip; total AI unavailability degrades to straight-to-human within one message (FR/FS contracts) — customer wait never exceeds the handoff template time. NFR-05 Decision-log write adds ≤ 3 ms to task completion (async insert, batched). NFR-06 Cost ceilings: per-tenant daily token quota enforced pre-call with 80% alert (Doc 01 NFR-06); per-conversation turn cap (max_ai_messages) bounds worst-case spend. NFR-07 RG classifier: precision target ≥ 0.9 at launch threshold (validated on a labeled fixture set, Doc 19); recall is secondary by design (missed cases fall to human handling; false accusations are the costlier error — documented rationale).

## 6. User Stories

US-01 As a player at 2 AM, I ask in Amharic where my deposit is; the assistant reads my receipt screenshot, tells me the typical timing with a citation, and checks back in 30 minutes — no human needed. US-02 As a player in distress, the moment I say I can’t stop, marketing goes silent and a calm, pre-approved message with help resources arrives, and a specialist team gets my case. US-03 As an agent, every handed-off ticket opens with a summary so I never scroll 40 messages to understand it. US-04 As an agent, I type my reply in English, hit Translate, and the customer receives Amharic — with the original preserved so my team can audit what I actually said. US-05 As a supervisor, I get pinged that withdrawal complaints tripled in an hour — before the queue melts, not after. US-06 As an operator admin, I drag a confidence slider and see exactly how yesterday would have played out before I commit. US-07 As an operator admin, one red button turns the chatbot off on one bot during an incident, instantly, and the audit shows who pressed it.

## 7. Business Rules

BR-01 AI never auto-sends on the Copilot surface — the agent’s send is the only send (FR-14); no roadmap item may change this without a series-wide review. BR-02 Grounding or handoff (FR-10): policy and money answers require citations; “the model probably knows” is never sufficient in this domain. BR-03 RG responses are template-only (FR-13): generation risk is unacceptable where the fixed, operator-approved wording is the safe artifact. BR-04 High precision over recall for RG (NFR-07) — flagging a healthy customer as at-risk is the costlier error; the human pipeline catches what AI misses. BR-05 The disclosure requirement (FR-19a) is not operator-removable — white-label extends to the assistant’s name, not to hiding its nature. BR-06 AI reads customer context to help, never to profile: prompts include topical history summaries, never RG flag contents outside RG flows, never staff notes (notes are staff-private, Doc 04 BR-01 extended to prompts). BR-07 Every threshold change is simulated before publish (FR-11) — configuration by evidence, not vibes.

## 8. UX Requirements

- **Chatbot (customer):** greeting includes persona name + disclosure; typing indicator during generation (Doc 05 FR-19 / Doc 13); citations render as “Read more” article links (web) / trailing links (Telegram); handoff message sets expectations with live wait estimate; CSAT is a 1-tap 1–5 prompt with optional comment; post_resolution reopens greet with continuity (“About your deposit from earlier —”).

- **Copilot panel (Doc 07 slot):** sections per FR-14 with independent skeletons; suggested reply streams into a card with [Insert] [Regenerate] [Discard]; regenerate limited ×3 per message with cooldown; low-confidence state replaces the draft card with the banner + KB links (never an empty spinner forever — 10 s timeout to the degraded card); guardrail notices as amber inline strips.

- **Composer actions:** buttons cluster per Doc 07 FR-19; Translate shows a two-line preview (original ↔ translation) before send; Improve shows a diff view (accept/revert per change v2 — v1 whole-text accept).

- **Backoffice AI screens:** persona editor with live preview chat (canned conversations); threshold sliders with the simulation panel (numbers + a small bar delta viz); guardrails as managed lists with seeded-item lock icons (platform-seeded items editable in wording, not deletable); kill-switch page with per-scope toggle grid, confirmation modal stating blast radius (“turns off the chatbot for @BrandSupport — 312 conversations/day average”), and a status banner mirrored in the inbox header when any switch is off.

- **Empty/error states:** no KB coverage yet → chatbot setup checklist points to Doc 10 starter templates; provider outage → Copilot degraded card (“AI unavailable — macros and KB search still work”), chatbot auto-bypass note in health.

- **Loading:** all AI surfaces stream or skeleton; nothing blocks the composer or the thread.

## 9. UI Requirements

- AI accent (platform token) on: chatbot bubbles (staff view), Copilot cards, AI-suggested values in the handoff packet, AI-set fields — one consistent visual language (Doc 07 §9 rule).

- Confidence meter: 5-segment bar + percent tooltip; never color-only (labels low/medium/high).

- Simulation panel: before/after columns with deltas, and a “conversations that flip” drill-down list (samples, anonymized display names).

- Kill-switch toggles: oversized, red-when-off with an “OFF since {time} by {actor}” caption.

- RG-related admin UI uses the calm slate treatment (Doc 08 §9 consistency).

- Streaming text uses a subtle caret, no typewriter sound, reduced-motion renders in chunks.

## 10. Technical Requirements

T-01 Orchestrator: task classes with typed IO (per FR-01), provider driver interface (complete/stream), fallback chain executor with circuit breakers per provider (error-rate trip → next provider; all-tripped → degraded mode events). T-02 Prompt assembly: layered — system (platform honesty rules, immutable) + tenant persona + guardrails + task template + context; layers logged by version hash; the immutable layer is code-shipped (not operator-editable) and its hash asserted in decisions. T-03 Prompt-injection posture: customer content is delimited and role-separated; retrieved chunks carry provenance and are instruction-stripped (sanitizer removes imperative-to-assistant patterns from KB text at index time — Doc 10 hook); output actions are schema-validated (an “action” the model invents outside the enum is discarded → handoff invalid_action); tool surface is closed (the chatbot has no tools beyond the declared action enum in v1). T-04 Streaming: provider stream → Reverb relay to the Copilot card / widget; Telegram receives the completed message (with an optional early “…” typing action per Doc 05 FR-19). T-05 Retrieval: pgvector HNSW over Doc 10 chunk embeddings; hybrid score (vector + keyword) with language filter; index maintenance on Doc 10 publish events; similar-tickets retrieval over nightly-embedded resolved-ticket summaries with Doc 03 scope filtering at query time. T-06 RG classifier: separate prompt+config from general classification; evaluation harness with the labeled fixture set (Doc 19 owns the corpus process); threshold changes require the simulation view like all others plus a precision readout. T-07 Metering: token counts from provider responses accumulate to Redis per-tenant counters reconciled daily to DB (Doc 01 FS-05 pattern); cost = tokens × provider rate card (config). T-08 Decision log: async batched inserts (NFR-05); outcome enrichment via event consumers joining on decision ids carried in downstream events (handoff packet embeds decision_id; CSAT joins via ticket). T-09 Contract tests: golden-conversation suite (fixture dialogs → expected action types at pinned config versions); guardrail red-team fixtures (blocked topics, injection attempts, transaction-fact traps) run in CI against a recorded/replay provider mock + nightly against the live provider in staging.

**Turn flow (text diagram):**

inbound (Doc 04 fanout) → classify(fast) ──rg≥min──► RG override: template + RG handoff + flag
        │
        ├─ open ticket? → context to Copilot, stop
        └─ chatbot_turn(strong): persona+guardrails+window+retrieval
              → action: answer(cited) | run_form | continue_flow | resolve(+CSAT) | handoff
              → adapter renders per channel · decision logged (config_version, confidence)

## 11. Security Requirements

SEC-01 Provider credentials in vault; prompts/outputs logged without secrets; provider data-retention terms reviewed and pinned in Doc 18’s vendor register (send-minimization: only the context layers listed in T-02, never full customer profiles). SEC-02 PII minimization in prompts: phone/email/id numbers masked by the redaction layer unless the task needs them (OCR reference numbers are needed; masking map logged). SEC-03 Injection defenses per T-03 with CI red-team suite (T-09); a successful injection class found later is a Sev-2 with a runbook (kill switch → patch sanitizer → replay fixtures). SEC-04 RG flag contents and staff notes never enter prompts (BR-06) — enforced by the context-builder’s allowlist, tested. SEC-05 Kill switches and config publishes are high-visibility audit events (Doc 03 high-risk class). SEC-06 Decision-log access is ai.decisions.view; raw prompt/output payload access is a stricter platform-debug permission (payloads stored 30 days then reduced to hashes + metrics — privacy by schedule).

## 12. Performance Requirements

- Latency budgets NFR-01–03 under L2 with 20 concurrent chatbot turns/tenant (semaphore default; tunable).

- Retrieval ≤ 120 ms p95 at 50k chunks; hybrid rerank included.

- Simulation over one day’s decisions (10k) ≤ 5 s.

- Anomaly scan over hourly aggregates ≤ 30 s per tenant.

- Degraded-mode switchover (provider chain exhausted → straight-to-human) ≤ 1 message turn (no customer ever waits on a dead provider past its timeout).

## 13. Accessibility Requirements

Copilot cards are labeled regions with streaming updates announced politely at sentence boundaries (not token-by-token); confidence conveyed as text; insert/regenerate/discard fully keyboard-operable with focus returning to the composer after insert; kill-switch grid navigable with clear on/off state semantics (role="switch"); simulation deltas readable as text tables; widget chatbot (Doc 13) inherits WCAG AA including reduced-motion streaming.

## 14. Localization Requirements

Persona, greetings, disclosure wording, handoff/RG templates per language with completeness gates (an endpoint can’t enable a language missing its RG template — hard rule); classification/generation quality validated per launch language with the golden suite in en + am; translation tasks preserve numbers/currency/reference formats verbatim (validator rejects altered digits — money-safety rule); Copilot chips and banners under ai.* i18n; digests localized per recipient.

## 15. Edge Cases

EC-01 Customer sends 6 rapid messages → batch-tail classification (Doc 04 EC-01) yields one coherent turn addressing all; fixture-pinned. EC-02 Voice note in a third language (neither en nor am) → transcript + detected language; if no KB coverage in that language → polite capability message (localized template in detected language where supported, else English) + handoff unsupported_language. EC-03 OCR reads a doctored receipt confidently → the honesty rule saves us: AI only restates the claim and checks timing; any credit decision is human (BR framing in FR-19b); the OCR fields land in the packet for the agent’s own verification. EC-04 Customer asks the chatbot for betting advice (“what should I bet on?”) → blocked topic → friendly deflection template + optional KB link to responsible-play content; repeated ×3 → handoff with blocked_topic_persistence. EC-05 The model outputs an action outside the enum or malformed JSON → schema validation discards → automatic single retry with a repair instruction → still bad → handoff invalid_action; both logged (this is the FR-08 contract’s safety net). EC-06 KB article unpublished mid-conversation after being cited → citation links resolve to the Doc 10 tombstone (“this article was updated”) — acceptable; retrieval cache invalidation (Doc 10) prevents *new* citations. EC-07 Two endpoints, different thresholds, same customer (hybrid Doc 05 EC-04) → per-endpoint config applies per conversation; the packet notes the endpoint for agent context. EC-08 post_resolution loop (customer keeps replying after resolve) → ×2 reopen then mandatory handoff (FR-12) — no infinite AI loops. EC-09 Copilot regenerate ×3 exhausted → card locks to the low-confidence state with KB links; telemetry flags the conversation for the weekly unanswerable review. EC-10 Translate task on mixed-language input (Amharic + English in one message) → dominant-language detection, translation preserves embedded English terms; validator’s digit rule applies. EC-11 Provider returns policy-refusal on benign content (over-refusal) → mapped to no_grounding-style handoff, logged distinctly (provider_refusal) for the vendor review; never surfaces raw provider refusal text to customers. EC-12 Quota exhausted mid-conversation → chatbot completes the current turn from reserve headroom (5% soft buffer), then straight-to-human with the standard handoff template; admins alerted at 80% long before.

## 16. Failure Scenarios

FS-01 Primary provider outage → circuit breaker → fallback chain; if all exhausted: chatbot bypass (inbound goes straight to handoff with template), Copilot degraded card, Supervisor tasks pause; recovery auto-resumes; the whole sequence is evented and visible in health. FS-02 Retrieval index corruption → chatbot enters no_grounding handoff mode for policy questions (safe), smalltalk/forms unaffected; rebuild from Doc 10 sources; alert. FS-03 Transcription/OCR provider down → Doc 04 EC-07 semantics (player works, transcript unavailable); classification proceeds on caption/absence; retry queue drains on recovery. FS-04 Decision-log backlog → task completion unaffected (async); reports lag with standard staleness pattern; reconciliation on drain. FS-05 A bad config publish (e.g., threshold typo auto-resolving everything) → instant rollback (FR-04) + the simulation requirement (BR-07) makes this unlikely + the daily digest surfaces anomalous resolution-rate jumps within 24 h at worst; runbook: rollback → replay affected conversations’ outcomes for the report correction note.

## 17. Success Scenarios

SS-01 Sixty days post-launch: AI resolution ≥ 40% with CSAT(AI) within 0.3 of CSAT(human), reopen-after-AI ≤ 8% — read directly off Doc 15’s AI report, sourced from the decision log. SS-02 The weekly unanswerable-questions review turns the top gap into a KB article in one click; the following week’s handoffs for that intent drop measurably (the loop demonstrably closes). SS-03 An RG fixture audit: 50 seeded distress dialogs → template-only responses, correct routing, flags with excerpts, zero generated RG text (AC-04). SS-04 A provider incident at peak: failover within 10 s, then full outage → zero customers stuck (all handed off with wait estimates), Copilot degraded gracefully, post-incident report generated from events alone.

## 18. Acceptance Criteria

AC-01 Golden-conversation suite (T-09) green at pinned configs for en + am across all action types, including batch-tail (EC-01) and post-resolution (EC-08) flows. AC-02 Grounding rule: policy/money fixtures without retrieval hits always hand off (no_grounding); every answer action carries ≥1 valid citation (schema-enforced, tested). AC-03 Threshold simulation: for a seeded decision-log day, slider changes produce exactly the recomputed outcomes the engine would produce (simulation-vs-replay parity test). AC-04 RG: labeled fixture set meets NFR-07 precision at the shipped threshold; override preempts mid-form; template-only output byte-verified; flag + excerpt + suppression contracts fire (Doc 08/11/12/14 integration fixtures). AC-05 Honesty: disclosure present in first greeting across personas/languages; transaction-fact traps (fixtures asserting the bot won’t confirm “so my deposit WAS received, right?”) pass; AI marking present on every surface (DOM assertions). AC-06 Copilot: never-auto-send proven (no code path from suggestion to adapter without the composer send action — static + runtime test); edit-outcome logging (FR-16) captures all three outcomes. AC-07 Injection red-team suite: all shipped fixtures neutralized (blocked topics hold, no system-prompt leakage, no out-of-enum actions executed); suite required in CI. AC-08 Kill switches: each scope combination silences exactly its blast radius ≤ 10 s, evented, bannered. AC-09 Degradation ladder (FS-01): scripted provider-failure drill produces the specified customer experience with zero unanswered customers. AC-10 Metering: token counts reconcile to provider invoices within 2% on the monthly check; quota enforcement (EC-12) proven.

## 19. Future Expansion

- Tool-use for the chatbot (closed enum → declared tools like “check queue wait” already implicit; future: operator-approved read-only lookups) — the action-schema architecture is the extension point.

- Fine-tuned or distilled classification models for cost (task abstraction makes swap invisible).

- Ghost-text inline Copilot (Doc 07 §19) under the same never-auto-send rule.

- Voice replies (TTS) on channels that support it.

- Cross-conversation customer-level summaries (“relationship memory”) with explicit provenance — gated on a privacy review.

- Agent-quality coaching packs generated from QA pre-scores + edit mining (aggregated, opt-in per Doc 16 policy).

## 20. Risks

R-01 Hallucinated money facts (Critical impact) → grounding-or-handoff (BR-02), claim-framing templates, transaction-fact trap tests (AC-05), no-tools v1; residual risk accepted and monitored via reopen-after-AI + CSAT. R-02 RG false positives alienating healthy customers (High) → precision-first threshold (NFR-07), calm template, human clearing with calibration loop; false *negatives* fall to normal human handling by design. R-03 Prompt injection via KB or customer content (High/Medium) → T-03 layered defenses + CI red-team + Sev-2 runbook; closed action enum bounds blast radius. R-04 Provider dependency (High likelihood of *some* incident) → fallback chains, degradation ladder, vendor register with exit criteria; the platform is fully operable AI-off (the ultimate fallback is the product’s own human workflow). R-05 Cost runaway on viral days (Medium/Medium) → quotas, turn caps, fast-tier classification, EC-12 soft-landing; cost dashboards in Doc 15. R-06 Operator over-trust (treating AI resolution rate as the only KPI) (Medium/Medium) → Doc 15 pairs resolution rate with CSAT(AI) and reopen-after-AI by default — the honest triad ships together.

## 21. Recommendations

- Build the orchestrator + decision log + golden-conversation harness before any surface — the harness is how every subsequent prompt change stays safe.

- Launch the chatbot conservative (high handoff threshold, small KB scope) and widen via the simulation tool weekly; earned autonomy beats day-one ambition in a money domain.

- Treat the RG fixture corpus as a governed asset (labeled, versioned, reviewed with the operator’s compliance contact) — it is the conscience of this module.

- Keep the immutable honesty layer (T-02) in code with its hash in every decision — when an auditor or regulator asks “could the operator disable the disclosure?”, the answer must be provably no.

- Schedule the monthly invoice-reconciliation (AC-10) and the quarterly vendor review from day one; AI cost and dependency drift silently otherwise.

*End of Document 09. Next in series: Document 10 — Knowledge Base.*