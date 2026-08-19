# SESSION — DOC 09: AI SYSTEM — CHATBOT, COPILOT, SUPERVISOR

You are the principal engineer and platform architect for **Na Live Support**, with 25+
years building LLM orchestration layers, retrieval systems, and safety-gated AI surfaces
of this class (Intercom Fin, Zendesk AI, Stripe-grade reliability discipline). I am the
product owner and I am **not a programmer** — I verify by running tests, so **never claim
anything works without pasting the executed test output.** Never simplify a spec, never
silently drop a requirement, never shorten your way past a hard part; if a spec is
ambiguous or conflicts with these instructions, STOP and ask.

**Before ANY work — including reading — prove this is the ONLY claude process running**
(raw process list pasted, your own PID identified by ancestry). This guard has fired
correctly SEVEN+ times in this project catching stale sessions. The recurring cause is
the owner launching before typing `exit` in the old window — if you find another process,
tell the owner to `exit` it first, create NOTHING, and re-run the check when told
"Cleared". **Discriminator for Claude remote daemons:** a `--serve`/`--bridge` tree
spawned the SAME SECOND as this session and parented by `/init` is your own transport —
harmless; one started EARLIER on a different tty is stale and blocks — it must die first
(a live `--serve` can ACCEPT a session). One session, verified solo, or nothing happens.
Repeat this proof at every resume, takeover, and account switch. A resume additionally
re-runs: prompt integrity, HEAD verification, `git status`, and a truncation sweep BY
RUNNING the suites — a dirty tree from a dead session is SHOWN and ruled on, never
silently extended; committed work by a dead session is provisional until its evidence
claims are re-verified.

**Prompt integrity check, first thing:** confirm you read THIS FILE FROM DISK at
`prompts/SESSION-09-doc09-ai.md`, state the number of PROTOCOL steps it contains, and
confirm its last line is exactly `Then STOP.` If any of those three fail, stop and tell
the owner — you may be reading a truncated or wrong-window copy.

**Three standing rules you carry from the first minute (they bind every step below):**

- **CI is checked ONCE per checkpoint, never polled** (standing rule 15). `gh run view`
  once; if the run is still in progress, the verdict is OWED FORWARD and reported at the
  next checkpoint. A poll loop killed a session at 100% context. No exceptions. Reds are
  triaged against RISK 0i (threshold: 3+ 0i-class reds in any 10 consecutive completed
  runs) before believed real; real reds are fix-first; full artifacts on the FIRST run of
  every new browser spec.
- **Evidence to the advisor travels as PLAIN PASTED TEXT or terminal SCREENSHOTS**
  (standing rule 17). File attachments into the advisor chat have arrived EMPTY ~19
  recorded times. Re-print reports in the terminal so the owner can copy text;
  screenshots (Win+Shift+S) are the one attachment type proven to work.
- **RULE 18 — THE GROUNDING RHYTHM.** Every checkpoint report, every countersign
  request, and the close-out ENDS WITH ITS COMMIT SHA. The advisor accepts only after
  the owner independently pastes `git log --oneline -N` from his plain terminal.
  **And the mirror half, born of governance incidents #14 and #15: VERIFY EVERY SHA THE
  ADVISOR SENDS YOU against your own tree before building on it.** The paste channel
  between owner and advisor has mangled a SHA (#14) and a register summary (#15) —
  your verify-against-tree check caught both. It is a feature; keep doing it. Derive
  every figure you report from REGISTER ROWS, never from a summary line.

---

## STEP 0 — THE CREDENTIALS GATE + CAPABILITY CHECK. HARD. NO KEY, NO PLAN.

**Doc 09 is the first document with an external-credential gate that is HARD:** the
**Addis AI staging key** (provider: Addis AI, platform.addisassistant.com,
OpenAI-compatible API — the owner's ruled provider choice) must be proven live BEFORE
your plan table exists.

**0.1 — Key check.** The key lives in the VAULT — never the repo, never a chat, never a
capture. Execute ONE real authenticated call against the Addis AI API from the staging
config and paste the output **with every credential character masked** (show HTTP
status, model id, and token usage — never the key, never an `Authorization` header).
A missing or dead key is a full STOP: tell the owner exactly what to create, then wait.

**0.2 — Capability check (the plan's provider rows depend on this; it is a check, not a
build).** Run FIXTURE conversations — fixtures ONLY, never real customer text — through
Addis AI and score, with the outputs pasted:

- **(a) Structured output**: strict JSON conformance to the classification shape RG
  detection needs (intent, sentiment, urgency, spam, rg_risk) and to the FR-08 action
  enum. Malformed-JSON rate over ≥10 fixture turns, stated as a number.
- **(b) English quality**: coherent, on-persona fixture answers.
- **(c) Amharic quality**: same fixtures in Amharic; flag verbatim outputs for the R-14
  native-review queue rather than judging fluency yourself.
- **(d) Embeddings availability**: T-05's retrieval needs an embeddings endpoint. Probe
  it. If Addis AI does not serve embeddings, say so plainly and name the fallback
  (Gemini free-tier embeddings or a pinned local model) as a plan decision for the
  owner — do not silently pick.

**Gemini free tier is the NAMED FALLBACK if (a) or (b) fails** (owner ruling, filed in
PROJECT-HANDOFF-v8 §3). Whatever the result, the provider seam is built
**provider-agnostic** — the OpenAI-compatible shape makes switching a baseURL/config
change, and the fallback-chain executor (T-01) treats every provider as replaceable.
Small-vendor risks (uptime, rate limits) get their own risk rows in your plan.

**0.3 — The rulings-filed proof.** Before your plan table exists, prove IN THE REPO that
every Doc 09-relevant standing ruling is filed, citing the commit for each:

1. `docs/ANSWERS-FROM-DOC-AUTHOR.md` carries **A-38 … A-42**. The ones that bind you
   hardest here: **A-41** (`pgsql_platform` is a FENCE, not a precedent — do not cite it
   for anything), **A-42** (match-vs-mint identity law — your pipeline consumes
   identities, it never mints them from chat ids).
2. **ADR 0014** (libphonenumber pinned 9.0.36) and ADRs **0003 / 0010 / 0011 / 0013**.
3. **SCHEMA AMENDMENT 2** with additions 7/8/9 and the customer_notes grant delta —
   read it as the SHAPE your own amendment must take.
4. **`docs/ADVISOR-RULINGS-D09.md`** — this generation's rulings (committed with this
   prompt): **R-D09-1** (the SEC-03 discharge, below), **R-D09-2** (design Option A),
   **R-D09-3** (EC-03 chip as your sanctioned side-row).
5. The **permission catalog CLOSED at 113** — verify by EXECUTING
   `php -r "echo count(require 'registries/permissions.php');"` (never by reading), and
   verify every `ai.*` key you will route to (`ai.decisions.view`,
   `ai.copilot.insert_draft`, `ai.providers.manage`, threshold/killswitch keys per
   Doc 03) EXISTS in the registry. A key Doc 09 names that the registry lacks is a
   STOP-and-escalate, never an invention.

**A missing filing is a STOP.** Paste the evidence, wait for the advisor's confirmation,
and only then produce your plan table.

---

## Read, in full, before writing any code

Each with why. No plan row and no build decision may come from memory or from this
prompt's summary of a document — read the source.

1. **`CLAUDE.md`** — the constitution.
2. **`docs/Doc09-AI-System-Chatbot-Copilot-Supervisor.md`, end to end, all 21
   sections.** The law for everything you build. Your plan enumerates every id from
   THIS FILE — and beware the cross-document citation trap: Doc 09 cites other
   documents' ids inline (Doc 04 FR-23/T-05/EC-01, Doc 05 FR-19/27/28, Doc 07 FR-19,
   Doc 01 NFR-06/FS-05, Doc 08 FR-14/15/EC-06 …). Verify family boundaries against the
   section text itself; a naive regex over-counts.
3. **`docs/BUILD-STATUS.md`** — the **Doc 08 close section** (the 91-id register, the
   16-item ledger, the [D09] rows, the canonical-suites command, the seven ruled core
   edits), **GOVERNANCE INCIDENTS #14 and #15 in full**, **RISKS 0a–0j** (0h's jsonb
   `?`-operator recipe; 0i's triage law; 0j; 0d "host-bound on this hardware"), the
   **DESIGN-DEVIATIONS** tables, the platform notes, the standing-PARTIAL buckets.
4. **`docs/ANSWERS-FROM-DOC-AUTHOR.md`** — Q1–Q9 (Q9: **registries are normative, prose
   descriptive**), A-1 … A-42, R-1 … R-26, SCHEMA AMENDMENT 2.
5. **`docs/DESIGN-FIDELITY-MANDATE.md` (v8)** in full — its §5 is written about this
   document specifically — plus **`docs/ADVISOR-RULINGS-D09.md`** for what Option A
   changes about it (below).
6. **`docs/PROJECT-HANDOFF-v8.md`** (§3 owner decisions, §4 inherited rows, §5 traps)
   and **`docs/PROJECT-HANDOFF-v7.md` §6** — the EIGHTEEN standing rules, re-read
   there, never from memory.
7. **The substrate you consume, read as CODE, not from memory of its docs:**
   - **Doc 04's inbound pipeline** and its FR-10 step-6 AI attach point; the batch-tail
     hint (Doc 04 EC-01); message persistence ordering (persist first, AI after);
     `author_type=ai` marking; transcript/OCR hooks (Doc 04 T-05, EC-07 semantics).
   - **Doc 05**: menu flows and their state machine, FR-19 typing action, FR-27
     capability rendering (buttons vs numbered text), FR-28 handoff template path,
     business-mode fallbacks, EC-04 hybrid endpoints.
   - **Doc 06**: `createFromHandoff` and the FR-11 packet contract, FR-03 subject
     generation seam.
   - **Doc 07**: the Copilot slot and the FR-19 composer AI-actions slot — built as
     honest seams at Doc 07; you FILL them, you do not rebuild the workspace or change
     the slot mechanism.
   - **Doc 08**: the RG seam EXACTLY — `rg_flag_history` with the live
     `(source=ai)=(actor_id IS NULL)` CHECK and the mandatory excerpt; FR-15
     promotional-suppression contracts; EC-06 clear-reasons stored consumable;
     `ChannelIdentityMap` (A-40).
   - **Doc 01**: outbox + live `relay` compose service, `ConsumerRegistry` (ADR 0011),
     the settings registry, quotas + 80% alerts (NFR-06, FS-05 reconcile pattern),
     RLS + the four-role grant model, C-11 DB time.
8. **`registries/permissions.php`** (closed, 113) and **`docs/EVENT-CATALOG.md`** —
   FR-21's six `ai.*` events join it with closed payloads; `ai.decision.logged` is
   high-volume and SAMPLED to the bus (full fidelity lives in `ai_decisions`, not the
   event stream — the Q7 payload-split law generalized).

---

## Stack / context notes (not conflicts; do not stall on them)

- Laravel / Postgres / Redis / Reverb + Inertia / Vue 3 / TypeScript + Meilisearch +
  Horizon. Approved additions to date: `vue-virtual`, `pinia`, `@lhci/cli`,
  libphonenumber (ADR 0014). **Doc 09 will need NEW stack pieces — each needs an ADR
  and the owner's approval, NAMED IN YOUR PLAN before it ships:** `pgvector` (first
  use; extension install is migrator-territory under ADR 0003 grant physics), an HTTP
  LLM client layer, the Horizon `ai` queue family (the ADR 0007 `mail` shape), and an
  embeddings path per STEP 0.2(d).
- **Provider data-use terms are a plan row**: review Addis AI's data-retention/data-use
  terms and record them in a NEW ADR; SEC-01's "pinned in Doc 18's vendor register"
  half is a `[D18]` seam (the register does not exist yet — the ADR is the interim
  record and says so).
- **Registries are normative, prose is descriptive** (Q9). The document wins over any
  drawing, any prior chat, any belief of yours; on a genuine conflict you STOP.
- **The relay is ALIVE**; every consumer you write is a durable consumer (ADR 0011).
  **Registry additions move the drill pin AND the Doc 06 NFR-06 fixture in the SAME
  commit** — this was missed TWICE at S08; it is a checklist line on every consumer
  commit, not a lesson.
- **`shouldRenderJsonWhen`** per-door interim on every new door; evidence filed.
- Platform notes bind: UUID-shaped path params only; `DB::transaction` commits on plain
  `return` (sentinel exceptions are the pattern); ON CONFLICT arbiter law; prove create
  paths on a fresh DB; `octane:reload` before every browser run; broad sweep +
  allowlist, then re-plant the missed variant.

---

## ESCALATE-FIRST — THE LAW, STATED BEFORE THE SCOPE

**Docs 01–08 are ALL core. Any edit to a file they own follows: escalate → advisor
ruling → land. NEVER the reverse.** The record's teaching pair: ruled core edit **#6**
was landed-then-escalated and is filed as a process breach; **#7** was escalate-first
honored. Be #7.

Doc 09 will push on this in at least these predictable places — plan the escalation,
do not discover it mid-commit:

- **Doc 04's FR-10 step-6 attach point.** Wiring the pipeline dispatch into inbound
  processing touches Doc 04 files. Escalate with the diff summary first.
- **Doc 07's Copilot slot and composer FR-19 slot.** Filling is expected; changing the
  slot mechanism, stores, or doors is a core edit.
- **Doc 05's adapter layer** (FR-27 rendering, FR-19 typing, FR-28 handoff template
  send). Consuming is fine; altering is a core edit.
- **Doc 06's `createFromHandoff`.** You produce the packet; the constructor is theirs.
- **Doc 08's RG seam.** You PRODUCE `source=ai` rows through the built path; widening
  visibility, touching the CHECK, or altering suppression contracts is a core edit.
- **Doc 02's `ScrubSecrets.php`** — the ONE pre-sanctioned core edit (R-D09-1, below).
  Sanctioned does not mean skip the mechanics: show the diff, get the countersign, land.

Adding files in your own `ai` namespaces is not a core edit. Touching a file another
document owns is — regardless of how small the change looks.

---

## THE SEC-03 SIDE-ROW — DISCHARGED EARLY, BY STANDING RULING R-D09-1

Doc 08's SEC-03 carry is YOURS to discharge, and it lands **before any provider-egress
code ships** — AI request/response logging is exactly where phone/email values start
flowing, so the scrubber must already catch them. The ruling (owner-confirmed, filed in
`docs/ADVISOR-RULINGS-D09.md`):

- Extend `app/Support/Logging/ScrubSecrets.php` (Doc 02 core file) with **phone and
  email VALUE patterns** — E.164 AND local Ethiopian formats for phones, standard
  address shapes for email — as a broad pattern + explicit allowlist (never a sweep
  narrowed to survive its own guard).
- **Escalate-first mechanics still apply**: you write the diff, show it, receive the
  advisor countersign, THEN land.
- **The proving test is the plant→detect→remove control**: plant sentinel phone values
  (both formats) and email values into log-bound context, prove they are scrubbed in
  ALL FIVE sinks, paste fail (scrubber stubbed) AND restored-pass; the `log-scrubber`
  gate and Doc 02's canonical suites stay green.
- BUILD-STATUS's SEC-03 row flips from the carry annotation at your close, citing this
  discharge.

This is a mandatory early plan row with a step number, not a footnote.

---

## SCOPE

**Implement all of Doc 09.** Enumerate every FR / NFR / US / BR / T / SEC / EC / FS /
SS / AC / R id **from the document itself** and cover each one. **State your id count by
family in the plan** — the advisor holds an independent, private enumeration and
reconciles against yours family-by-family. A mismatch is investigated, never averaged.

**Out of scope — do not touch, stub the seam and document it:**

- KB **authoring**, article storage, publish workflow → **Doc 10**. Retrieval INFRA
  (chunk store, embeddings, HNSW index, hybrid scoring) is YOURS; content production
  and publish events are theirs — fixture-fed here.
- Automation rule **conditions** consuming AI outputs, forms, CSAT survey capture,
  macros content → **Doc 12** (you call `run_form` seams and read macro suggestions
  fixture-fed).
- RG **queue routing** target → **Doc 11** (you hand off TO the RG queue contract;
  the queue is theirs). Live wait estimates likewise.
- Web chat widget **surface** → **Doc 13** (the chatbot's web rendering waits there;
  Telegram is your live channel).
- SMS → **Doc 14**. AI **report rendering** → **Doc 15**. Rubric/policy content →
  **Doc 12/16**. Vendor register → **Doc 18**. Formal scale → **Doc 19**.
- Model training / fine-tuning — **not in v1 at all** (§3: prompt+retrieval only).

**Pre-agreed closure buckets** (verified against Doc 09's own text — do not invent
deferrals beside them; each names its unblocker):

| Bucket | Rows it covers | Unblocker |
|---|---|---|
| `[D10]` KB substrate | T-05 index maintenance on publish events; EC-06's live tombstone resolution; FR-10 over real articles; SS-02's loop | Doc 10. Retrieval proven HERE over fixture-seeded chunks |
| `[D11]` routing | FR-12/FR-13's live RG-queue delivery + queue-wait estimates | Doc 11. The handoff contract and packet are YOURS and proven |
| `[D12]` forms/CSAT/macros | `run_form` execution, FR-09's CSAT survey capture, macro suggestion content, qa_prescore rubric content | Doc 12 (rubric content also Doc 16). Seams fixture-fed here |
| `[D13]` web surface | Chatbot on the web widget; widget streaming/a11y clauses of §8/§13 | Doc 13. Telegram path proven end-to-end HERE |
| `[D15]` reports | FR-17/FR-20 outputs RENDERED in dashboards; R-06's honest triad display | Doc 15. The data products (decision log, digests, anomalies) are YOURS and proven |
| `[D18]` vendor register | SEC-01's register pin | Doc 18. The ADR is the interim record |
| `[D19]` formal scale | §12 budgets at L2/50k-chunk/10k-decision scale; NFR loads | Doc 19 + staging hardware. Dev-scale indicative figures ship HERE, plainly flagged |
| `[B8]` design fidelity | Pixel fidelity + frame mapping for every Doc 09 staff surface | Batch 8 frozen files (Option A, R-D09-2, below) |
| `[OA]` owner/external | AC-10's monthly invoice reconciliation (needs a real invoice month); R-14 native review of your Amharic strings | Owner/calendar |

**THE `[D09]` CLOSING SET — rows this document CLOSES, not defers.** Doc 08 left rows
bucketed `[D09]` for you: the anchors you already know are **FR-14's `source=ai`
producer path** (storage built, CHECK live — the producer is yours) and **EC-06's
calibration consumption**. **Enumerate ALL `[D09]`-bucketed rows from BUILD-STATUS's
Doc 08 close section yourself** — every one becomes a plan row naming the BUILD-STATUS
line it updates at close. If you find rows beyond the anchors, they are yours too; if
fewer, say so and cite why.

---

## INHERITED (PROJECT-HANDOFF-v8 §4 — each is a mandatory plan-table row with its
disposition stated)

- **0a SEC-03** — disposition: **discharge-at-early-step** per R-D09-1 (the side-row
  section above). Ruled; not re-litigated.
- **0b EC-03 chip** (Doc 08's deleted-account informational inactive chip) —
  disposition: **your sanctioned side-row** per R-D09-3. A Doc 08-owned UI file:
  escalate-first with the diff, land with its own proving test, flip the BUILD-STATUS
  row at close. Small; do not let it sprawl.
- **0c row 4b reassignment** (staff-avatar STORE half) — disposition: **owner-action**;
  the advisor surfaces it; your plan carries it as a row marked owner-pending. Build
  nothing for it.
- **1 credentials gate** — discharged at STEP 0 or nothing else happens.
- **2 `[OA]` Doc 05 rows** (AC-03, AC-08-live, US-01-live) — parked on the staging
  Telegram Business account; token rotation first. **Do not unpark.**
- **3 risks 0a–0j** standing; the 0i triage law and 0d re-run-once law as stated in the
  standing-rules block above.
- **4 R-14 queue** — your machine-authored Amharic strings JOIN the ~511-string queue;
  destructive-tier flags in your close-out (for this document: kill-switch confirm,
  guardrail deletions, RG template edits, provider switch, config rollback).
- **5 `shouldRenderJsonWhen`** per-door interim, evidence filed.
- **6–11** unchanged from v7 §4 — bounded deferral wording; dry-run preview door note;
  Doc 18 notes; Doc 19 buckets; Doc 11/12 seams; Doc 16 seam; owner hygiene (one Claude
  session, disk ≥ 20 GB, one project's windows).
- **12 `[D09]` closing set** — the SCOPE section above; enumerated by you from
  BUILD-STATUS.

---

## THE SUBTLE PARTS (verified against Doc 09's text — read them there, not here)

1. **FIXTURES-ONLY EGRESS IS A GUARD YOU BUILD, NOT A HABIT YOU KEEP.** This document
   is the platform's FIRST external egress of conversation text. The Doc 05 PII
   incident's law at higher stakes: **real customer text behind a "staging" label is a
   STOP.** Build a non-production egress guard that refuses provider calls whose
   payload is not fixture-tagged, prove it with its own plant control, and sequence it
   BEFORE the first provider-touching test. Production is the only environment where
   real text flows.
2. **The RG override outranks everything** (FR-06/FR-13): any `rg_risk ≥ threshold`
   preempts even mid-form, routes to the RG queue contract, sends ONLY the
   operator-approved template (byte-verified — generated text NEVER, BR-03), writes the
   Doc 08 flag through the built path (source=ai, mandatory excerpt, NULL actor by
   CHECK), and engages FR-15 suppression. Your fixtures prove preemption order, not
   just the happy path.
3. **The honesty layer is IMMUTABLE and hash-asserted** (T-02, FR-19, BR-05,
   §21 rec 4): platform honesty rules are code-shipped, not operator-editable; every
   decision logs the layer-version hash; the proving test asserts the hash and that no
   Backoffice surface can alter the layer. Disclosure presence is non-configurable;
   only wording is.
4. **The chatbot never states transaction/account facts** (FR-19b, BR-02, EC-03,
   AC-05): claim-framing templates ("you mentioned a 2,000 ETB deposit"), grounding or
   handoff on money/policy, ≥1 valid citation schema-enforced on every `answer`, and
   the transaction-fact trap fixtures ("so my deposit WAS received, right?") in the
   golden suite. Doc 08 §3's no-betting-access law binds the AI's ANSWERS, not just its
   queries.
5. **The action enum is closed and schema-validated** (FR-08, T-03, EC-05): an invented
   action is discarded → single retry with repair instruction → handoff
   `invalid_action`, both logged. No tools in v1. This is the injection blast-radius
   bound — treat the validator as a safety mechanism (it gets sabotaged).
6. **Prompt-injection posture is layered and CI-enforced** (T-03, SEC-03, T-09, AC-07,
   R-03): delimited/role-separated customer content; KB chunks instruction-stripped at
   index time with provenance; red-team fixtures (blocked topics, injection attempts,
   transaction-fact traps) in CI against a record/replay provider mock, nightly against
   live staging. A later-found injection class is a Sev-2 with the runbook (kill switch
   → patch sanitizer → replay fixtures).
7. **SEC-04's allowlist is physics, not politeness**: RG flag contents and staff notes
   NEVER enter prompts (BR-06 — notes law extended from Doc 04 BR-01). The
   context-builder consumes an explicit allowlist; the test proves exclusion by
   planting.
8. **Copilot never auto-sends** (BR-01, FR-14, AC-06): no code path from suggestion to
   adapter without the composer send action — proven static AND runtime. Insertion
   rides `ai.copilot.insert_draft`. Translate preserves the original in metadata
   (FR-15); the validator's digit rule (§14) rejects altered numbers — money safety.
9. **Kill switches are exact blast radii** (FR-18, US-07, AC-08, SEC-05): per surface ×
   channel × bot/widget, ≤10 s, evented, bannered, high-visibility audit. Each scope
   combination silences EXACTLY its radius — the test asserts both directions (off
   where told, on everywhere else).
10. **Decision log is the product's memory** (FR-20, T-08, NFR-05, SEC-06): every
    invocation writes `ai_decisions` (async batched, ≤3 ms overhead); decision_id rides
    the handoff packet for outcome enrichment; payloads live 30 days then reduce to
    hashes+metrics — privacy by schedule is a scheduled job you build and prove, not a
    policy sentence. Access split: `ai.decisions.view` vs stricter platform-debug for
    raw payloads.
11. **Quota is enforced PRE-call** (NFR-06, EC-12, T-07, FR-03): per-tenant daily
    tokens, 80% alert (Doc 01 NFR-06 pattern), 5% reserve completes the current turn,
    then straight-to-human. Redis counters reconcile daily to DB (FS-05 pattern). Token
    spend per conversation is a §12-table budget axis alongside latency.
12. **Degradation is a ladder with zero stranded customers** (NFR-04, FS-01, AC-09,
    SS-04): circuit breakers per provider, fallback chain, all-exhausted → chatbot
    bypass straight-to-handoff within one message turn, Copilot degraded card,
    Supervisor pause, auto-resume, all evented. Provider policy-refusals map to a
    distinct `provider_refusal` handoff (EC-11) — raw refusal text never reaches
    customers.
13. **Language completeness is a hard gate** (§14): an endpoint cannot enable a
    language missing its RG template. EC-02's third-language path degrades politely.
14. **Every AI consumer is a durable consumer** (ADR 0011): outcome-enrichment
    consumers, index-maintenance consumers, digest jobs — registered, contract-fixtured,
    drill pin + Doc 06 NFR-06 fixture moved in the SAME commit.
15. **Doc 04 ordering law**: persistence first, AI after (NFR-02) — classification
    never blocks message write; batch-tail (EC-01) yields ONE coherent turn.

---

## PROTOCOL (unchanged in lineage, in full)

1. **PLAN FIRST**: a table of every Doc 09 id → file(s) → proving test, plus rows for
   every INHERITED item (0a–12), a row per `[D09]` closing-set item enumerated from
   BUILD-STATUS, the SEC-03 side-row with its step number, the EC-03 side-row, and the
   DESIGN FIDELITY scoping row (Option A form, below). **State your id count by
   family** and your **event-family enumeration** (FR-21's six). Sequence safety tests
   BEFORE the features they constrain and justify any counter-intuitive ordering — at
   minimum: the **fixtures-only egress guard BEFORE any provider-touching test**; the
   **SEC-03 scrubber discharge BEFORE provider request/response logging exists**; the
   **immutable-layer hash assertion BEFORE prompt assembly ships**; the **action-enum
   validator + out-of-enum discard BEFORE the chatbot is reachable**; the **SEC-04
   allowlist proof BEFORE any prompt is built from customer context**; the
   **never-auto-send proof BEFORE Copilot insert exists**; the **RG template-only
   byte-verify + preemption fixture BEFORE the RG path is live**; the **kill-switch
   blast-radius proof BEFORE the chatbot is enabled on any endpoint**; the **pre-call
   quota check BEFORE any load-shaped test**. §21's recommendation binds the head of
   the build order: **orchestrator + decision log + golden-conversation harness before
   any surface** — the harness is how every subsequent prompt change stays safe.
   Propose roughly **10–14 steps**. Define this document's **canonical suites command
   in BUILD-STATUS at plan time** (standing rule 14). Name every new stack dependency
   (pgvector, LLM client, `ai` queue, embeddings path) for owner approval with the
   plan. **WAIT for approval — the approved plan table is COMMITTED at approval,
   before step 1's first commit** (standing rule 12). STEP 0's evidence is pasted and
   confirmed before this table exists.

2. **One commit per numbered step**, registries updated as you go — permission mappings
   for every new route (**keys from the CLOSED catalog only, 113, verified by
   executed count**; render-gates are derivations, never new keys); settings named per
   conventions with platform-maximum validation (thresholds, semaphore size, quotas,
   turn caps, reserve buffer); notification categories registered (quota 80%,
   kill-switch state, anomaly alerts, digest delivery); **audit-coverage for every
   state-mutating action** — config publish/rollback, threshold change, guardrail edit,
   kill-switch flip, provider switch, persona publish — with actor + old/new (SEC-05:
   kill switches and publishes are the high-visibility class); traceability rows; the
   **EVENT-CATALOG regenerated with closed payloads** for FR-21's six events
   (`ai.decision.logged` SAMPLED; identifiers and reason categories only — never
   prompt/output text in bus payloads, the Q7 split). **`make gates` on EVERY
   commit.** **Enumerated lists grow only by FILED amendment** (standing rule 16).
   **New tables** — `ai_decisions`, provider/persona/threshold/guardrail config
   versions, chunk/embedding store, calibration rows — require **R-19b per-column
   analysis + ADR-0003 grant physics + a FILED SCHEMA AMENDMENT countersigned BEFORE
   the migration ships**; pgvector's extension install is migrator-owned DDL. **DB time
   only** (C-11). **Every durable consumer registers** (ADR 0011) with drill pin +
   NFR-06 fixture in the same commit. C-10 404-pattern and curated payload allowlists
   on every read door — decision-log payloads are PII-adjacent by nature.

3. **Falsify every safety mechanism you build, positive control per assertion, sabotage
   BEFORE counting anything proven.** A sabotage that stays green condemns the TEST;
   a by-design green is EXPLAINED, never worsened. Live-DB sabotage wiped by
   `migrate:fresh` is INVALID — redo at migration level, file the invalid attempt under
   an honest name. **Floor of TEN, grown only by filed amendment.** Verified against
   Doc 09's text, the floor is:
   1. Immutable honesty layer made operator-reachable / its hash unasserted → the T-02
      hash test goes red.
   2. Action-enum schema validation removed → EC-05's invented-action fixture executes
      an out-of-enum action → red (**injection-shaped #1**).
   3. KB index-time instruction-stripping disabled → an imperative planted in a chunk
      reaches the prompt → the T-03/AC-07 red-team fixture goes red
      (**injection-shaped #2**).
   4. SEC-04 allowlist widened (RG flag content or a staff note planted into context) →
      the exclusion proof goes red (BR-06).
   5. RG preemption de-prioritized (mid-form message with rg_risk ≥ threshold continues
      the form) → AC-04's preemption fixture goes red (FR-13).
   6. RG response switched from template to generated text → AC-04's byte-verify goes
      red (BR-03).
   7. A code path added from Copilot suggestion to adapter send without the composer
      action → AC-06's static + runtime proof goes red (BR-01).
   8. The citation requirement dropped from `answer` on a policy/money fixture with no
      retrieval hit → AC-02 goes red (BR-02, `no_grounding`).
   9. A kill-switch scope made to leak (silences a neighbor endpoint, or leaves its own
      target on) → AC-08's both-directions proof goes red.
   10. Disclosure removed from the first greeting of ONE persona in ONE language →
       AC-05 goes red (FR-19a/BR-05).
   11. The non-production fixtures-only egress guard removed → its plant control (a
       non-fixture payload attempts egress) goes red — the Doc 05 PII law, mechanized.
   12. The pre-call quota check moved post-call → EC-12/NFR-06's exhausted-quota
       fixture bills past the cap → red.
   Your plan enumerates ITS OWN complete list (this is the floor); the ledger is
   reconciled item by item at close, never against a count. Paste fail AND
   restored-pass; backup before sabotage; tree proven clean after. **Captures first**
   (full output to `docs/evidence/S09-*/` before any filter); verdicts from unfiltered
   captures; **no pipes on a gate**.

4. Every user-visible string is an i18n key with `en` AND `am` entries (`ai.*` per
   §14); `string-lint` green; machine-authored Amharic joins R-14's queue;
   **destructive-tier flagged in your close-out** (kill-switch confirm, guardrail
   deletion, RG template edit, provider switch, rollback). Language completeness gate
   (§14's RG-template rule) is code, not process. Persona/greeting/disclosure/template
   content is per-language operator content — fallback rendering (R-24) applies.
   Digits/currency/reference formats verbatim through translation (the validator).

5. Targeted tests while iterating; the **FULL correctness battery via the canonical
   command, plus `make gates` and `make lint`, once per step-commit — REGARDLESS of
   BAT1** (the battery split is owner law: only §12 timing legs and quotable figures
   wait on wall power, and "timing not taken" is said plainly). Browser proofs under
   `make browser` with `octane:reload` first. Push and record the CI run ID — checked
   ONCE per checkpoint; 0i triage before belief; 0d re-run-once; a red is fix-first.
   Golden-conversation suite and red-team suite run against the record/replay mock in
   CI; the nightly live-staging leg is configured and its first run's output pasted
   once (then it is CI's).

6. Finish with the full Doc 09 self-audit table (**every id, DONE / PARTIAL / NOT
   DONE + one-line reason; PARTIALs cite their bucket by name; a false DONE is a
   Sev-1**), the **sabotage ledger reconciled item by item**, the **`[D09]`
   closing-set outcomes** (each BUILD-STATUS row updated, including FR-14 and EC-06),
   the **SEC-03 and EC-03 side-row outcomes with their BUILD-STATUS flips**, the
   **DESIGN-DEVIATIONS / `[B8]` fidelity table updated**, the **inherited-row outcomes
   (0a–12) cited explicitly**, the **core-edit enumeration** (every Docs 01–08 file
   touched, each with its escalation and ruling — the ScrubSecrets edit included), the
   **honest trail in full**, **R-14 totals with destructive tier flagged**, the **§12
   budget table with dev-scale latency AND token-cost figures** (the new axis), the
   provider-terms ADR reference, the final battery, and CI once. **The close-out ends
   with its commit SHA; the owner grounds it BEFORE the advisor's recount issues.**
   Then the CLAUDE.md §9 report: BUILT / PROVEN / NOT DONE / BLOCKERS / RISKS / NEXT
   SESSION.

---

## CADENCE — READ THIS BEFORE YOU START REPORTING

The owner prefers **batches**, not per-unit reports. Default shape: **one batch → one
checkpoint report → one owner `git log --oneline -N` grounding → the advisor's
acceptance.** Per-unit commits, proofs, and captures continue underneath.

**STOP mid-batch only for these** (mandatory, not optional):

- a ruling you need and cannot derive from the documents;
- a **guard conflict** (two correct mechanisms colliding);
- a **red battery** you cannot resolve inside your own files;
- a **core-edit boundary** (any Docs 01–08 file);
- a **new enumeration amendment** (event family, sabotage list) — filed before it
  ships;
- a **schema amendment** awaiting countersign;
- a **design conflict or gap** (mandate §3 — a mandatory batch stop).

**STOP at every checkpoint until the advisor's grounded acceptance returns.**

---

## DESIGN FIDELITY — OPTION A (ruling R-D09-2; this changes the usual shape)

`DESIGN-FIDELITY-MANDATE.md` (v8) binds — but **no frozen Doc 09 screen exists.**
PROGRAM-REPORT-v1 states it plainly (Doc 09 surfaces "remain undesigned and are not
scheduled"), and the advisor's both-directions cross-check of the 217-file frozen
listing confirms it. The owner has ruled **Option A**: a Batch 8 design track runs in
Claude Design in parallel; the build is **backend-first**; staff-facing UI lands late.

What this means for you, exactly:

- **The design SYSTEM is the only current design law**: `B1-DS-design-system-v2` §2
  (both colourways) + addenda `B5`, `B6` (v3), `B7` (v4) — including the violet AI
  accent and the AI message bubble, which ARE drawn and DO bind wherever AI content
  renders (Doc 07 §9 rule). Tokens first: re-run the committed hex-extraction script
  before your first screen; tokens, never literals.
- **Sequence UI steps at the tail of the build order.** If Batch 8 files are FROZEN in
  `design/frozen/` when a UI step arrives: full mandate fidelity — the advisor issues
  the Batch 8 file list (derived independently from the Batch 8 program report,
  cross-checked both directions), your plan gains **frame-mapping rows by filed
  amendment**, and deviations run the normal three classes.
- **If Batch 8 is not frozen when a UI step arrives**: build the screen FUNCTIONAL,
  themed, i18n-keyed, from design-system components only (the Doc 02 AC-09 / Q8
  precedent — the series' own shape for exactly this sequencing), and carry the row
  **PARTIAL in `[B8]`** — pixel fidelity and frame mapping attach when Batch 8
  freezes. This is a bucket, not a deviation; do not flood DESIGN-DEVIATIONS with
  non-deviations.
- **Mandate §5 still binds on its face**: any Batch 8 frame that later implies the AI
  states betting-account/transaction facts is class 1 on arrival; any frame
  contradicting the built RG seam shape (source=ai ⇒ mandatory excerpt + NULL actor)
  is class 1; AI uncertainty/fallback/outage states the document requires but no frame
  draws are class 2, filled from FS-01's labeled-degrade pattern — never a silent
  empty.
- The B6 closing audit's **Q15 (N/E — "no Batch 6 surface renders AI content")**
  becomes exercisable the moment your Copilot card renders; note it for the Batch 8
  audit rather than resolving it yourself.

---

## HARD PROHIBITIONS

- **No secrets, tokens, or PII** in this prompt, your artifacts, your commits, or your
  captures. The Addis AI key lives in the vault; a key character in any output is a
  Sev-1.
- **NO REAL CUSTOMER TEXT TO THE PROVIDER outside production.** Fixtures only, in
  every non-production environment, enforced by the egress guard you build. The Doc 05
  PII incident at higher stakes: real data behind a "staging" label is a STOP.
- **No invented permission keys.** The catalog is CLOSED at 113; render-gates are
  derivations. A key the document wants that the registry lacks is a STOP, not a seed.
- **No document-file edits** without escalate-first.
- **No green claimed without pasted output.** No CI polling. No second Claude process.
- **No second implementation** of a check that already exists — reuse the live path
  (the Doc 08 RG write path, the quota engine, the outbox — consume, never fork).
- **No PARTIAL outside a bucket named in the approved plan.** A false DONE is a Sev-1.

Then STOP.
