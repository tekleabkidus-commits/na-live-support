# SESSION — DOC 06: TICKETING SYSTEM

You are the principal engineer and platform architect for **Na Live Support**, with 25+ years
building ticketing and workflow engines for systems of this class (Zendesk, Intercom,
Stripe-grade). I am the product owner and I am **not a programmer** — I verify by running
tests, so **never claim anything works without pasting the executed test output.** Never
simplify a spec, never silently drop a requirement, never shorten your way past a hard part;
if a spec is ambiguous or conflicts with these instructions, STOP and ask.

**Before ANY work — including reading — prove this is the ONLY claude process running**
(`ps`/process list pasted). This guard has failed both ways in this project: at SESSION-03 it
did not exist and concurrent sessions overwrote each other's edits and collided their test
suites, costing half a day; at SESSION-04 it fired correctly and caught a stale morning
session before it did damage. One session, verified solo, or nothing happens.

---

## STEP 0 — OWNER RULING R-18's GATE. THIS COMES BEFORE YOUR PLAN.

This is not a checklist line and it is not summarizable. R-18 is filed in
`docs/ANSWERS-FROM-DOC-AUTHOR.md` and recorded in `docs/BUILD-STATUS.md`, and it reads:

> Doc 04 accepted with AC-03 PARTIAL. Root-causing the AC-03 soak (deterministic result +
> `make test`/CI reconciled, most likely a dedicated CI job) and the RISK 0b capture
> protocol are STEP 0 of the next build session; no Doc 06 plan may be approved before the
> soak is deterministic. A flaky test is not a proof, and an ordering guarantee that is
> only probably true is not a guarantee.

That next build session is this one. The work is Doc 04 code; it gates Doc 06 anyway.
**Do all three parts, paste the evidence, and WAIT for my advisor's written confirmation
before you produce the plan table. Not in parallel with the plan. Before it.**

**0a — Root-cause the AC-03 soak to a deterministic result.**
The test is `LaneOrderingSoakTest` — **verify its exact path in the repo before citing it**;
do not trust this name if the file says otherwise. Its captured evidence from S20 lives at
`docs/evidence/S20-soak-isolation/`; read it before you form a hypothesis. The known history:
the 10k ordering soak ran green twice (209 assertions, zero violations) and red once with 50
violations alone on a clean database, was never root-caused, and was excluded from
`make test`. CI ran it green once — which does not overturn a local red.

*Deterministic* means: a **named root cause**, plus repeated runs producing the same outcome
with the evidence captured. One green run is not determinism. Two green runs are not
determinism. If the soak proves deterministically RED and you then fix the underlying defect
with a positive control, that satisfies this gate — a real defect found is a better outcome
than a test quietly turning green. What does not satisfy it: re-running until it passes,
loosening the assertion, raising a timeout, reducing the message count, or reclassifying the
violations as acceptable. If you conclude the test itself is wrong rather than the code, say
so explicitly with evidence and STOP for a ruling — do not silently replace it.

**0b — Reconcile `make test` with CI.**
The soak currently sits in a gap: excluded from `make test`, run by CI. That gap is how a
flaky proof survived a close-out. Close it. A dedicated CI job is the most likely shape (the
owner has been told so), but you own the design — state what you chose, why, and what a
developer running `make test` locally now does and does not exercise. Whatever you choose,
the answer to "is AC-03's ordering guarantee proven?" must be answerable from one place.

**0c — Implement RISK 0b's capture-first protocol (governance incident #6).**
Twice, an intermittent failure's only log was piped through `grep` / `| tail -4` and the
detail was destroyed. RISK 0b is `PasswordResetFlowTest` ("keeps the token alive…"), which
has recurred twice, is undiagnosed, predates the soak, and is NOT soak-attributable.
Standing order, now made real in this repo: **any diagnostic run is captured FIRST** — the
full run redirected to a file and committed under `docs/evidence/` — and only then filtered
or rerun. Build the mechanism (a make target, a wrapper script, whatever fits this repo),
prove it, and apply it to every diagnostic run you make for the rest of this session.

**Then paste:** the root cause, the reconciliation decision, the captured evidence paths, the
repeated-run outputs, and the CI run ID. **Then stop and wait for confirmation.** No plan
table until you have it. No Doc 06 code before the plan is approved.

---

Doc 06 is the workflow spine. Five documents consume its semantics as a frozen contract
(07 inbox rendering, 09 AI handoff, 11 routing, 12 SLA/automation, 14 SMS, 15 metrics), and
four of them do not exist yet. Treat the transition service, the state machine as data, and
the event family as the deliverable; treat the not-yet-built consumers as SEAMS proven
against fixtures.

## Read, in full, before writing any code

1. `CLAUDE.md` — the constitution; §2's seven registries (ADR 0005), §7's build order
   (04 → 06 → 05), §9's report shape.
2. `docs/Doc06-Ticketing-System.md` — this session's law, every section end to end,
   including §4.2's normative state diagram, §4.5's operation semantics, §4.6/FR-24's
   timestamp definitions, and the complete EC-01…12 / FS-01…05 lists. No id may be covered
   from a summary, from this prompt, or from memory of a similar product.
3. `docs/BUILD-STATUS.md` — the Doc 04 §S20 close-out (what the messaging engine actually
   shipped), RISK 0a and RISK 0b, the DESIGN-DEVIATIONS section, and every standing-PARTIAL
   bucket (you may neither re-claim nor drop any of them), plus the SESSION-03
   concurrent-session incident note that the solo-process proof above exists for.
4. `docs/ANSWERS-FROM-DOC-AUTHOR.md` — the interpretation record, and it is **canonical**:
   Q1–Q9 (Q9's registry-normative rule binds you directly), the Doc 02 rulings R-1…R-8,
   the Doc 03 rulings R-9…R-14, A-1…A-6, and R-18. **Where this record and a numbered
   document requirement disagree, the document wins and you STOP.** A ruling that lives only
   in a chat or a code comment is not filed and does not bind.
5. `docs/DESIGN-FIDELITY-MANDATE.md` — read in full; it binds any user-visible surface you
   build. See the DESIGN FIDELITY block at the end for this session's verified scoping.
6. The substrate you consume. Read the code and its docblocks before you touch anything —
   **verify each of these against the repo; where this prompt and the repo disagree, the
   repo is the fact and you STOP and tell me:**
   - **Doc 04's shipped one-open-ticket enforcement.** Doc 04 built and proved
     `open_ticket_id` plus the `open_ticket_exists` refusal at BOTH the application and
     database layers. Find where that enforcement lives before you write a line of T-02.
     **It is already built. You reconcile with it. Rebuilding it is a violation.**
   - **The `message.received` outbox event.** Doc 04 emits it; your FR-08 reopen consumer is
     its first real subscriber. Read the payload contract in `docs/EVENT-CATALOG.md` and the
     Doc 01 outbox/EventPublisher conventions your own events will use.
   - **Doc 04 FR-03's conversation-block seam.** Your FR-09 spam dialog CALLS it. It never
     implements its own blocking mechanism.
   - **The transcript exporter** (Doc 04 FR-24, `TranscriptExporter`) — inherited row 1
     extends it to a ticket's message range on the same gate, watermark, and audit path.
   - **`tenant_sequences`** (Doc 01 T-02) — the numbering substrate for FR-02.
   - **`registries/permissions.php`** — the seeded `tickets.*` keys. The catalog is CLOSED at
     111 keys seeded verbatim (R-12). **Verify every key Doc 06 names inline actually
     exists**: `tickets.view`, `tickets.create`, `tickets.edit`, `tickets.assign`,
     `tickets.assign_self`, `tickets.priority.change`, `tickets.department.change`,
     `tickets.escalate`, `tickets.merge`, `tickets.split`, `tickets.follow`,
     `tickets.spam.mark`, `tickets.reopen`, `tickets.export`, and `tickets.delete`
     (BR-01: exists in the catalog, **disabled at platform level**). Note that Doc 06's text
     does NOT name `tickets.reply` inline, but the key exists and is already load-bearing
     (Doc 04 FR-13 gates manual retry with it; A-6 maps dead-letter re-drive to it) and your
     real reply route arrives this session. **If any key Doc 06 names is absent from the
     registry, STOP and ask — never invent a key** (NFR-03).
   - **The Doc 04 dead-letter / re-drive machinery** and A-6's `tickets.reply` mapping — your
     reply route is the first real consumer of that key's intended meaning.
   - **Reverb `user.{id}`** — T-06's bulk progress channel; the emit-time tenant fence and
     scope-on-subscribe are already built and already tested.
   - **The node-kill replay drill** (Doc 04 SS-04) — inherited row 6 extends it with the
     tickets count.
   - **The settings registry conventions** — for the four `tickets.*` settings below.
7. `docs/EVENT-CATALOG.md` — the closed-payload rule (a missing OR extra key is rejected)
   applies to every event FR-26 adds. Doc 04 landed 20 catalog events; yours are additive.
8. ADRs: **0003** (database role separation — your new tenant tables live under its grant
   model; BR-01's "tickets are never deleted" becomes REVOKE DELETE grant physics, exactly as
   Doc 04's conversations did — not a policy, not a flag alone), **0004** (traceability gate
   per-document flip — Doc 06's ACs become blocking the moment its BUILD-STATUS row leaves
   NOT STARTED), **0007** (queue-family/supervisor precedent — your bulk jobs ride the
   automation queue), **0008/0009** (the Doc 04 stack growth already approved; you should not
   need to grow it further — if you think you do, that is an ADR and my approval first).

## Stack / context notes (not conflicts; do not stall on them)

- The repo is **Laravel 13** by owner ruling; spec stack contexts read as minimums.
- Prose counts anywhere are descriptive; **code registries are normative** (Q9, reaffirmed by
  R-12). This applies to your event count, your permission keys, and your settings.
- **The ticket-consumes-conversation boundary, stated up front.** Messages belong to
  conversations (Doc 04 has primacy). Tickets **reference ranges** over them (BR-02).
  Merge and split **move links, never message rows** — if you find yourself writing an
  UPDATE that changes a message's conversation, stop; that is the boundary breaking.
- **FR-01 names "Doc 01 Phase-22 schema".** Phase 22 lived in the 34-page master design
  document, which ruling **Q1 permanently superseded** — it does not exist and never will
  (`docs/CONFLICTS.md` records the closure). So FR-01's base-field reference resolves to
  nothing. The fields FR-01 itself enumerates as "additionally normative here" ARE binding
  and you build them exactly. For the base ticket row, derive from Doc 06's own text plus
  Doc 01/Doc 04 conventions (UUIDv7, timestamptz, tenant column, RLS, expand-contract), and
  **file the resulting field list as an interpretation** in the record — do not invent a
  field Doc 06 does not require and do not go looking for the missing document.
- **Consumers that do not exist are SEAMS proven against FIXTURES, never against their
  logic**: Doc 09 (the handoff packet's caller), Doc 11 (routing/re-route), Doc 12
  (SLA policy, automation rules, CSAT, custom fields), Doc 14 (resolved-SMS consumer),
  Doc 15 (metrics). NFR-06 requires at least one consumer contract test per consuming
  document, run against that document's fixtures. You build the fixtures and the contract
  tests. You build none of their behaviour.
- **Statuses are platform-fixed in v1** (R-01). "One more status" is absorbed by future
  display *vocabularies* (§19), never by forking the machine. If a requirement seems to need
  a ninth status, you have misread it — STOP and ask.
- **`channel_unreachable` is Doc 05's field, schema-only here** (BR-07/EC-10). It ships as a
  column and participates in BR-07's orthogonality rules. You build no Doc 05 semantics.

## SCOPE

Implement **all of Doc 06** — enumerate every FR / NFR / US / BR / T / SEC / EC / FS / SS /
AC / R id from the document itself and cover each one. **State the id count YOU found**, by
family; your plan is audited against an independent count made from the same text.

**Out of scope (do not touch):**
- Queue and routing algorithms → **Doc 11** (you emit the events and call the seam).
- SLA policy definition, timer UI, automation rule engine, custom fields and forms →
  **Doc 12** (you build the hooks they attach to; §21 says so explicitly).
- Inbox and thread layout, list read models → **Doc 07** (T-08: list reads go through Doc
  07's read models; this module owns writes and the canonical row).
- CSAT surveys → **Docs 12/15**. Reporting formulas and the metric dictionary → **Doc 15**
  (you own the timestamps and the golden fixtures, not the formulas).
- Doc 05 channel semantics — `channel_unreachable` is schema plus BR-07 orthogonality only.
- Doc 08 identity and customer-merge rules — SEC-06's cross-customer merge prohibition is
  yours to enforce; the customer merge that would make one legal is Doc 08's.

**Pre-agreed closure buckets** — these are the ONLY sanctioned deferrals; each is verified
against Doc 06's own text and named with its unblocker. Do not invent a deferral beside them;
if you believe one is missing, STOP and ask before planning around it.

| Bucket | What defers | Unblocker |
|---|---|---|
| FR-11 handoff packet | The live CALLER (`createFromHandoff` invoked by real AI) and the packet's rendering | Doc 09 / Doc 07 — but the seam itself is **DONE-able here**: built, fixture-proven against a Doc 09 packet fixture, immutability enforced at the DB (AC-08) |
| FR-14 offline form | The widget creation path that calls it | Doc 13 — the validation path and `source=offline_form` + `offline` tag are built here, the caller waits |
| FR-15 / EC-06 | Re-route on unassign and on assignee deactivation | Doc 11 — you emit `ticket.assignee_deactivated` and the unassign→queue event; Doc 11 consumes |
| FR-06 / FR-17 / EC-08 | CSAT trigger, SLA re-target decision, and the "resolved by rule X" rule source | Doc 12 — you emit the events and carry the policy flag; the policy is theirs |
| FR-06 resolved-SMS | The `ticket_resolved` SMS consumer | Doc 14 — the event is yours, the send is theirs |
| FR-09 / AC-06 | Doc 15's CONSUMER assertions on metric exclusion | Doc 15 — **the exclusion flags, the restore semantics and the golden fixtures are THIS session's**; only the consuming assertions defer |
| §8 / §9 rendering | Property panel, operation dialogs, bulk bar, state affordances as product UI | Doc 07 — see the DESIGN FIDELITY block; verify this scoping yourself and state it |
| §12 / NFR p-values | Formal performance budget closure | Doc 19 L1/L2 profile — structure for the budgets, record them PARTIAL bucketed, do not fake numbers |

**The attached design files are the specification of every screen in scope. Implement them
at 100% fidelity per DESIGN-FIDELITY-MANDATE.md — every frame, state, viewport, theme,
and language, tokens from the design system only, deviations only in the mandate's three
classes and every one filed. Nothing skipped, nothing approximated, nothing redesigned.**

## INHERITED (each is a mandatory plan-table row, not a footnote)

0. **R-18's STEP 0** — satisfied and advisor-confirmed BEFORE this table exists. Its row
   cites the pasted evidence: root cause, the `make test`/CI reconciliation, the capture
   protocol, evidence paths, CI run ID.
1. **Doc 04 FR-24's ticket-range half** — transcript export for a ticket's message range, on
   the same gate (`conversations.transcript.export`), the same watermark and the same audit
   path. Upgrades Doc 04's FR-24 row on proof. Note Doc 06 SEC-05 keeps `tickets.export`
   (property/timeline) as a SEPARATE gate from Doc 04's transcript export; both watermarked.
2. **Doc 02 AC-05's literal fixture** (Q5 + R-12) — the 403 response naming the missing
   permission, built against Doc 06's REAL `tickets.reply` route, using catalog keys.
   R-12 is filed: Doc 02 AC-05's literal `tickets.read` ≙ catalog `tickets.view`; the catalog
   governs, no `tickets.read` key exists or will be invented. Closes a PARTIAL standing since
   Doc 02.
3. **The `message.received` reopen/creation consumer** (Doc 04 FR-10 step 6) — the subscriber
   driving FR-08 reopen-on-customer-reply, EC-01's cancel-wins race, and closed-beyond-window
   → new ticket carrying `previous_ticket_id`. Doc 04 emits; you are the first real
   subscriber.
4. **Doc 04 FR-03's spam-block seam consumed** — FR-09's dialog offers a separate,
   separately-confirmed conversation block that CALLS Doc 04's block. Never its own
   mechanism. Spam alone never blocks the conversation (EC-09).
5. **The open-family invariant reconciled, not rebuilt** — Doc 04 shipped and proved
   `open_ticket_id` + the `open_ticket_exists` refusal at app AND db layers. Your T-02
   partial unique index and FR-10 open-family definition RECONCILE with it. **One invariant,
   two layers — your plan states which layer owns what.** Rebuilding it is a violation.
6. **Doc 01 AC-03's tickets remainder** — the node-kill drill reconciles
   "tickets/messages/events"; messages and events are claimed, the TICKETS count lands with
   your transition pipeline. Extend the drill and cite the filing.
7. **Doc 03 EC-02's remainder** — mid-action permission expiry naming the granter. Doc 04
   verified this needs ticket actions. Prove it on a Doc 06 transition.
8. **Doc 04 EC-04's rule trigger** — the queued→canceled transition for
   blocked-between-compose-and-send exists and is tested; the "ticket closed by rule" TRIGGER
   source wires through your close/resolve events toward Doc 12's rule engine. **Claim this
   seam explicitly; never leave it implicit.**
9. **Route→permission mapping in the same commit** (the gate is blocking) — every route you
   add maps to a key that ALREADY EXISTS in the 111-key catalog. Verify against the registry;
   never invent (NFR-03). `tickets.delete` stays platform-disabled (BR-01). An audit outside
   this chat once claimed "21 seeded `tickets.*` keys" — treat that as an unverified claim and
   count the registry yourself.
10. **Standing honest PARTIALs are not re-claimed and not dropped**: Doc 03 SS-01/SS-02; Doc
    04's SS-01 (L2 profile → Doc 19), SS-02/US-05 (WhatsApp literal → §19), SS-04's
    literal-SIGKILL remainder (→ Doc 18 FR-23), AC-01 re-proofs (→ Docs 05/13), AC-07/AC-08
    renderer halves (→ Docs 19/07), SEC-01 inert-rendering half (→ Doc 07), EC-08 UI (→ 07),
    NFR-06 budget doc (→ 18), R-03 lifecycle (→ 18). Doc 04's deferred B12-A01 dispositions
    travel WITH their buckets — including AC-08's merge-chip half and AC-09's
    bulk-progress-at-25% clause, which Doc 06 §13 also requires. Each gets a stated
    disposition in your plan; none is silently dropped.

## THE SUBTLE PARTS (spell each out in your plan; every one traces to Doc 06's text, a filed ruling, or the handoff)

- **`TicketTransitionService` is the SOLE status writer** (T-01/NFR-03). One path:
  permission (Doc 03) → legality (FR-04's matrix **as data**, not as branches) → invariants
  (FR-10/BR-07) → FR-24 timestamps → persist + outbox, **one transaction**. NFR-03 requires
  a CI grep AND repository encapsulation proving no status write exists anywhere else.
  §21 is explicit: **build the service and the table-driven every-cell matrix test (AC-01)
  FIRST** — everything else composes on it. Same-state calls **no-op successfully** (AC-01,
  EC-07's documented idempotency), which is a legal outcome, not an error.
- **The T-02 partial unique index sits BEHIND the service check** — `WHERE status IN
  (open-family) AND linked_work = false`. §21: keep it even though the service checks first,
  because the one hotfix that bypasses the service otherwise creates a duplicate-ticket
  incident. AC-02 proves both halves: concurrent creates yield exactly one open ticket plus
  `open_ticket_exists` rejections, AND a **direct-SQL bypass fails on the index**. Reconcile
  with Doc 04's shipped layer (inherited row 5).
- **Open-family is defined, not assumed** (FR-10): `{new, open, pending_customer,
  pending_internal, escalated}`. **`resolved` is NOT open-family** — a customer message on a
  resolved ticket REOPENS it, and FR-08 takes precedence over new-ticket creation (BR-05:
  reopen beats duplicate).
- **Split's `linked_work = true` is the ONE sanctioned multi-open case**, capped at 3 per
  conversation (FR-21); EC-04 refuses the fourth with guidance, not a bare error.
- **Merge** (FR-20/BR-02/SEC-06): same-customer only, as a **hard server rule** — a
  cross-customer merge needs a Doc 08 customer merge first, and SEC-06 exists to prevent
  privacy bleed between players. Messages stay in their conversations; links move. Source →
  closed with `merged_into`; followers and tags union into the target; source `form_data`
  attaches as an artifact card; target SLA governs and source instances close superseded;
  events on both; the source carries a permanent banner to the target. Link rewrite in ONE
  transaction. **24 h unmerge from an immutable snapshot restoring byte-identical prior
  state** (AC-04, snapshot diff = ∅); FS-04's race — snapshot presence is the authority and
  the refusal states the window passed, both attempts audited. Chains resolve **transitively**
  (EC-03); banners link the final survivor; unmerge is per-link within its own 24 h. §21:
  **ship merge with unmerge or don't ship merge.**
- **Timers are ZSET + column truth** (T-03). `ticket_timers` ZSET, 1-min sweep, and the sweep
  **re-checks status in-transaction**. Customer-reply events cancel/reset event-drivenly and
  **the cancel WINS the exact-moment race** (EC-01) — never a close-then-reopen flicker.
  FS-01: a lost ZSET rebuilds from the columns, which are truth; event-driven paths keep
  working during the rebuild; worst case auto-closes are **delayed, never premature**. EC-02:
  the reopen boundary is a **DB-time comparison, inclusive, fixture-pinned** (`closed_at +
  7 d ± 1 s`). R-03: all-UTC storage with **DST dates in the fixtures**.
- **Metric timestamps are transactional** (FR-24/AC-09) — computed WITH the transition, never
  derived later. `first_response_at` = first PUBLIC staff or AI message after ticket creation
  (notes excluded, pre-ticket chatbot messages excluded); `agent_first_response_at` = first
  public HUMAN reply; `resolved_at`/`closed_at` = last transition into the state, and
  **reopen clears `resolved_at`** until re-resolved with history preserved in events;
  `waiting_customer_seconds`/`waiting_staff_seconds` accumulate from status intervals.
  §21: **share the golden lifecycle fixtures with Doc 15 from day one** — metric disputes die
  when both sides run the same fixtures. Doc 15 is not built; the fixtures still ship now.
- **The handoff packet is immutable, insert-only** (FR-11/T-05/SEC-02/AC-08):
  `ticket_handoff_packets` (ticket_id unique, payload jsonb, schema_version), **no UPDATE
  grants** — immutability proven at the DB layer, not by application politeness. The packet
  contract is normative: summary (2–4 sentences), intent + confidence, language, sentiment,
  urgency, suggested {department, priority} + reasons[], optional form_data, kb_shown[]
  (article ids), transcript_anchor (message id range). Built and proven against a **Doc 09
  fixture**; `createFromHandoff` is the seam Doc 09 will call.
- **Bulk is per-item everything** (FR-25/SEC-04/T-06/EC-12/AC-07/NFR-04): individually
  validated operations in a queued batch on the automation queue with per-tenant concurrency
  caps, **permissions checked LIVE at execution** (EC-12: a permission change mid-bulk
  re-validates the remaining items), **no batch-level privilege averaging**, per-item partial
  success with no all-or-nothing lock, honest partial results, capped 200 per batch, evented
  individually plus one bulk-completed summary `{ok, failed[]}`, progress via Reverb
  `user.{id}`. Doc 06 §9 caps bulk at **25 items on mobile, deliberately**.
- **Spam interplay** (FR-09/AC-06/EC-09): mark → status spam, excluded from SLA/CSAT/agent
  metrics (the Doc 15 contract); the dialog's conversation block is **separate and separately
  confirmed** and calls Doc 04's block; unmark within 7 days restores the prior status and
  re-includes metrics **with audit**; after 7 days spam is terminal — EC-09 refuses day 8
  with a terminality explanation and the customer's next message opens a fresh ticket. Spam
  alone never blocks the conversation.
- **Claim race** (FR-16/US-01): first commit wins; the loser receives `already_claimed {by}`
  and the UI updates in place — an inline avatar swap, explicitly **not** an error-modal
  storm. FR-05: `new → open` on first assignment OR first staff public reply (implicit claim,
  `tickets.implicit_claim` default on; BR-03 — a reply IS a claim).
- **Scope re-checks on TARGET objects** (SEC-01): merge target, transfer destination. Moving
  work must not smuggle visibility. Refusals are named: `assignee_out_of_scope`,
  `target_out_of_scope`. FR-15's assignee must hold view scope via the Doc 03 intersection.
- **Escalation** (FR-23/BR-06/EC-05): path ∈ {supervisor_review, department:{id},
  named:{user}} plus a **mandatory reason of ≥ 10 characters**; status → escalated; target
  notified; the original assignee **auto-follows**; de-escalation returns to open with a note.
  EC-05: a named target later disabled or out of scope **auto-falls back to
  supervisor_review** with a system note and a notification to the escalator.
- **Assignee-deactivated batch** (EC-06): 30 open tickets → `ticket.assignee_deactivated`
  batch → Doc 11 re-routes (seam; Doc 11 is not built) → hand-back system notes on each.
- **Numbering under concurrency** (FR-02/NFR-05/FS-03): per-tenant sequential integer from
  `tenant_sequences` (Doc 01 T-02), displayed `#4812`, **never reused**, gaps acceptable and
  documented on rolled-back creates. 100 parallel creates → 100 unique numbers, zero
  duplicates. FS-03: beyond the contention threshold, creates receive backpressure **429 —
  never a duplicate number**.
- **The event family** (FR-26/NFR-06/T-09/SS-04/R-05). **Enumerate FR-26's list yourself and
  state the number in your plan.** Do not adopt a count from any prose, including this
  prompt: at Doc 04 a plan's "16 events" figure reconciled with nothing and the enumerated
  catalog governed. Three things you will find and must resolve rather than paper over:
  (i) FR-26's pipe-list includes `follower_added|removed` — say explicitly whether you read
  that as one event or two; (ii) FR-25 writes `tickets.bulk_completed` while FR-26's family
  is `ticket.*` — the prefix inconsistency needs a filed interpretation, not a silent choice;
  (iii) **EC-06 requires `ticket.assignee_deactivated`, which FR-26's list does not name** —
  it is document-required and must enter the catalog with a filed interpretation, exactly as
  A-4 handled Doc 04's unnamed events. Every schema is versioned, with **at least one
  consumer contract test per consuming document, run against that document's fixtures**
  (Docs 12/15/17). All published through the Doc 01 outbox with closed payloads.
- **FS-05's nightly invariant auditor** — scans for illegal statuses and duplicate
  open-family, **pages with row ids**, and exists precisely because it should never fire.
  Build it and prove it fires on a seeded violation.
- **BR-01 is grant physics** — tickets are never deleted in v1. `tickets.delete` exists in
  the catalog and is disabled at platform level, reserved for legal-erasure via Doc 17.
  REVOKE DELETE on the tenant tables per ADR 0003, exactly as Doc 04 did for conversations.
  A flag alone is not the mechanism.
- **The four settings** (SS-03): `tickets.implicit_claim`, `tickets.resolve.requires_reply`,
  `tickets.autoclose.hours` (default 48), `tickets.reopen.days` (default 7) — registered per
  the settings-registry conventions, `tenant_editable` per US-07. **SS-03's semantics: a
  change applies to future resolutions only; armed timers keep their value, and the settings
  page says exactly that.**
- **FR-19 tags**: free-form per tenant with autocomplete, bulk-manageable. **T-07 tag
  rename/merge** is a chunked, resumable background rewrite over the GIN-indexed tags array,
  audited with counts, propagating historically. See the DESIGN FIDELITY block — this
  operation was never designed and needs my ruling before you build its surface.
- **FR-03 subject**: optional at creation; if absent, Doc 09 generates one within 30 s marked
  `subject_source=ai`, editable under `tickets.edit`. Doc 09 is not built — this is a seam.
  EC-11: generation failure leaves the subject empty with a retry affordance and lists fall
  back to a first-message snippet (Doc 07).
- **FR-07's immutability of closed tickets** — closed is immutable EXCEPT reopen, tag
  management, and serving as a merge target. Prove the exceptions are the only ones.

## PROTOCOL (unchanged, in full)

1. **PLAN FIRST**: a table of every Doc 06 id → file(s) → proving test, plus rows for every
   INHERITED item (0–10 above) and the DESIGN FIDELITY scoping row (below). **State the id
   count you found**, by family. Sequence safety tests BEFORE the features they constrain and
   justify any counter-intuitive ordering — at minimum: `TicketTransitionService` + the
   AC-01 matrix test BEFORE any operation composes on it (§21); the T-02 index and AC-02's
   direct-SQL bypass proof BEFORE the creation paths; the unmerge snapshot BEFORE merge is
   callable (§21's "ship merge with unmerge or don't ship merge"); the FR-24 golden fixtures
   BEFORE the timestamps they pin. State the FR-26 event enumeration and the three
   reconciliations named above. Name any new stack service or dependency so I approve stack
   growth with the plan. **WAIT for my approval.** Step 0's evidence is already pasted and
   confirmed before this table exists.
2. One commit per numbered step; registries updated as you go — permission mappings for every
   new route (**keys from the seeded catalog only**); settings (the four `tickets.*` keys,
   named per the registry conventions); audit-coverage for every new event, every operation
   and every export (SEC-03: spam-unmark, staff reopen, escalation reasons and bulk summaries
   are always audited with actor + old/new); features if any; traceability rows — the
   per-document flip makes Doc 06's ACs blocking the moment its BUILD-STATUS row leaves
   NOT STARTED (ADR 0004).
3. **Falsify every safety mechanism you build, with a positive control for every assertion,
   sabotage BEFORE counting anything proven** — and remember S19 sabotage #7: a sabotage that
   stays green condemns the TEST, not the guard; strengthen the test and keep the stronger
   one. At minimum: permit an illegal transition → AC-01's matrix goes red; drop the T-02
   index → AC-02's concurrent-create and direct-SQL proofs go red; merge without writing the
   snapshot → AC-04 goes red; average bulk permissions at batch level → AC-07/SEC-04 go red;
   ignore the spam exclusion flag → AC-06's fixtures go red; make the timer sweep skip its
   in-transaction re-check → EC-01 goes red; derive a timestamp post-hoc instead of
   transactionally → AC-09's golden fixtures go red; permit a cross-customer merge → SEC-06
   goes red; write a status outside the transition service → the NFR-03 grep/encapsulation
   test goes red. Paste fail AND restored-pass, every time. Backup before sabotage; nothing
   commits until `git status`/`git diff` proves the tree is clean of falsification damage.
   **Every diagnostic run in this session is captured first per step 0c** — full output to a
   file under `docs/evidence/` before any filtering or rerun.
4. Every user-visible string is an i18n key with `en` AND `am` entries (`tickets.*` per §14 —
   status and priority names, pending reasons, dialog and consequence copy, and failure
   reasons; countdown phrasing uses ICU duration formats; **tag content is operator free-text
   with no translation layer, by design**). `string-lint` stays green; new machine-authored
   Amharic joins the standing native-review queue, and you flag destructive-tier strings
   (merge, unmerge, spam, escalate, bulk close) in your close-out per R-14's rider.
5. Targeted tests while iterating; the FULL `make test`, `make gates`, `make lint` once per
   step-commit — pasted real output. Push and record the CI run ID at session close per the
   efficiency ruling (exceptions: CI/infra-file changes — your step 0 CI-job work qualifies —
   and rulings that name CI as a gate).
6. Finish with the full Doc 06 self-audit table (every id, DONE / PARTIAL / NOT DONE +
   one-line reason; PARTIALs cite their pre-agreed bucket; a false DONE is a Sev-1), a
   **sabotage ledger reconciled against this plan's enumerated list, item by item, never
   against a count** (governance #5: at Doc 04 a checkpoint claimed "eight of nine" and two
   of the plan's nine had never run), the DESIGN-DEVIATIONS section updated in BUILD-STATUS
   (an unfiled deviation is a fidelity violation; "no drawn surface built" is also stated
   there if true), the inherited-row outcomes (0–10) cited explicitly, and the CLAUDE.md §9
   report: BUILT / PROVEN / NOT DONE / BLOCKERS / RISKS / NEXT SESSION.

## DESIGN FIDELITY (scoping verified against Doc 06's own text — confirm it in your plan)

Per DESIGN-FIDELITY-MANDATE.md §5, which is in your reading list:

- **Verified scoping.** Doc 06 §8 opens: *"(The inbox/thread layout is Doc 07; this section
  is the ticket-property contract Doc 07 renders.)"* FR-11 says the packet is *"rendered by
  Doc 07"*, T-08 says *"list reads go through Doc 07's read models; this module owns writes
  and the canonical row"*, and §3 places inbox layout out of scope. **So no drawn product
  screen is REQUIRED to close Doc 06's ids this session.** Your plan carries ONE scoping row
  restating this verification against §8/§9 **in your own reading**; if you read those
  sections differently, STOP and ask before planning UI work. This is NOT the trivial case
  Doc 04 had — §9 fixes platform-wide tokens you must encode correctly, §13 imposes
  obligations on surfaces built later, and two recorded design gaps touch this document.
- **§9's fixed tokens are law you encode, not values you choose.** Status pill hues are fixed
  platform-wide (new=blue, open=green, pending_customer=amber, pending_internal=violet,
  escalated=orange, resolved=teal, closed=gray, spam=red-gray) and priority dots are
  low=gray, normal=blue, high=orange, urgent=red **plus a label, never colour-only**. These
  are the mandate's fixed tokens (design-system law R-07/R-08): they live in
  `B1-DS-design-system-v2` §2 in BOTH colourways and are never re-mixed. **Verify Doc 06 §9's
  hues against the design system's fixed-token values and report any mismatch as a
  design-vs-document conflict — STOP and ask; never reconcile it yourself.** Countdown chips
  reuse the Doc 05 component. Reduced motion: urgent styling is static, never pulsing.
- **If you build ANY drawn surface**, the frozen files at `design/frozen/` are its
  specification at 100% fidelity: **B1-S04-ticket-operations-v1** (merge / split / escalate /
  spam dialogs), **B2-S03-ticket-properties-v2** (property panel, pending reason, timeline,
  subject, mobile sheet), **B2-S04-state-affordances-v2** (resolved/closed/spam countdowns +
  the bulk tail), **B2-S05-states-v2** (panel states, claim races, mobile, sheets),
  **B1-S01-inbox-shell-v2** (tag autocomplete, the assignee inline refusal, the KB hover
  preview §8 requires on the packet card), **B1-S02-inbox-states-v2** (the inbox state matrix,
  reopened specifically for the visible, focusable bulk-bar disabled reason),
  **B2-S06-closing-audit-v2** (where the Batch 2 rulings that bind this document are
  recorded), **B12-A01-behaviour-acceptance-v1**, plus — always — the design system:
  **B1-DS-design-system-v2** (token source, §2 both colourways), **B1-DS-addendum-B5-v1**,
  **B1-DS-v3-addendum-B6-v1**, **B1-DS-v4-addendum-B7-v1**. Access wrapper: **read ONLY the
  named files**; screen/audit files — the highest version is the frozen one; the design system
  is v2 base plus all three addenda together. Frame-mapping rows in the plan (frame →
  component/file → proving test) for any surface you build; dark values from v2 §2's dark
  column, never mixed by eye; fixed tokens never re-mixed.
- **B12-A01 disposition, row by row.** Your plan states, for each of B12-A01's nine
  behaviour-acceptance rows, whether its surface is built HERE (the row closes as an executed
  test, cited by row id) or deferred to Doc 07 (the row is named in the Doc 07 bucket). Two
  rows bind this document directly and neither may be silently dropped: **AC-08** (draft
  survives merge — moves to the surviving ticket with an info chip; its Doc 04 half is
  already proven, the merge-chip half is yours or Doc 07's and you say which) and **AC-09**
  (live-region announcements — **bulk progress at 25% increments**, which Doc 06 §13
  independently requires).
- **Two recorded design gaps touch Doc 06. Neither is improvised.** Both are on my desk and
  I will rule before your plan is approved:
  1. **Tag rename/merge was never designed** — `B2-S06-closing-audit-v2` records "Tags —
     per-tenant autocomplete; no rename or merge designed", while Doc 06 FR-19/T-07 require
     the operation. The engine is buildable without a surface; the admin surface is the gap.
  2. **The bulk-bar disabled reason** — Doc 06 §8 requires disabled actions to show why and
     §13 requires it to be announced, not merely hovered. The program report records this
     BOTH as already resolved (B1-S02-inbox-states-v2 was reopened for the visible, focusable
     reason) AND as an open designer question in B2-S06. Read B1-S02-inbox-states-v2 before
     you touch anything bulk-bar shaped, and if the frozen file already carries the visible
     focusable reason, say so in your plan.
- Deviations only in the mandate's three classes, every one filed in BUILD-STATUS's
  DESIGN-DEVIATIONS **and** named in your close-out. Design-vs-document conflict → STOP and
  ask. Design gap → class-2 fill with design-system components, filed (Doc 04's R-17
  dead-letter view is the precedent). Design defect → class-3 flag, wait.

Then STOP.
