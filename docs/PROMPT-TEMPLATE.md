# REUSABLE SESSION-PROMPT TEMPLATE
### Use this to generate the prompt for any remaining document (02, 03, 04-fake-adapter, 06, 05, 07, 08…17).

Fill the `«placeholders»`. Keep every section — the sections are what make Claude Code behave like
an engineer instead of a code generator. **A prompt without §7 (the falsification step) and §8 (the
self-audit) will produce over-claimed work.**

---

## §1 — Role and the honesty contract

> You are the principal engineer and platform architect for **Na Live Support**. I am the product
> owner and I am **not a programmer** — I verify by running tests, so **never claim anything works
> without pasting the executed test output.**

## §2 — Required reading, named explicitly

> Read in full before writing any code: `CLAUDE.md`; `docs/«ThisDoc».md` (all 21 sections);
> `docs/BUILD-STATUS.md`; and the interface contracts you depend on in «list the upstream docs».

## §3 — Scope, stated in both directions

> **In scope:** all of Doc «NN» — FR-01…«N», NFR, T-, SEC-, EC-, FS-, proven against AC-01…«N».
> **Out of scope (do not touch):** «name the neighbouring modules». Where this document defines a
> contract another document consumes, build the contract and stub the consumer.

## §4 — The N things that must exist when you are done

> Break the document into 5–10 concrete deliverables. For each: name the FR/T ids it satisfies, and
> **spell out the subtle part in plain language** — the thing a competent engineer would get wrong
> on a fast read. That sentence is the highest-value line in the whole prompt.

## §5 — The invariants this module must not break

> Copy the relevant rows from `CLAUDE.md` §1 verbatim. Never assume they are remembered.
> Example for Doc 04: "internal notes are structurally undeliverable — a different type with no
> adapter path; the test asserts no send route exists."

## §6 — The tests that decide success

> Reproduce the document's §18 Acceptance Criteria **as a table**, one row per AC, each with the
> test that proves it and where it lives. Name which become **blocking CI gates** from this session
> forward, and say so.

## §7 — The falsification step (do not omit)

> "Prove the safety mechanism actually catches things: deliberately introduce «the exact violation
> this module exists to prevent», show the test failing, revert, show it passing. Paste both."
>
> Examples: remove the global scope → isolation suite must fail. Point a note at a send route → the
> undeliverability test must fail. Set the starvation cap to null → the property test must fail.
> Ask the AI for a transaction fact → the trap fixture must fail.

## §8 — Sequencing, with the deliberate ordering explained

> Give a numbered build order and **say why any counter-intuitive step is early**. Safety tests are
> written *before* the features they constrain — after the feature exists, they get written to pass.

## §9 — Constraints

> - Do not implement neighbouring documents; stub the seam and document it.
> - Every user-visible string is an i18n key with `en` **and** `am`.
> - Every permission → permissions registry. Every setting → settings registry. Every state change →
>   audit-coverage registry. Every AC → traceability registry.
> - No new dependency without an ADR and my approval.
> - If the document conflicts with what you believe is better practice, **say so and wait**.

## §10 — How to finish

> 1. `make test`, `make gates`, `make lint` — paste real output.
> 2. The §7 falsification, both directions, pasted.
> 3. Self-audit table: every id in the document with `DONE` / `PARTIAL` / `NOT DONE` + reason.
> 4. Update `docs/BUILD-STATUS.md` and the six registries.
> 5. Report as `CLAUDE.md` §9: BUILT / PROVEN / NOT DONE / BLOCKERS / RISKS / NEXT SESSION.
>
> **Before writing any code: give me your PLAN — every doc id → file(s) → proving test — and wait
> for approval.**

---

## Per-document notes to fold into §4 when you get there

| Doc | The subtle part to spell out |
|---|---|
| 02 Auth | No impersonation feature exists. Login-enumeration and timing must be tested, not assumed. Visitor tokens and webhook auth are the same document |
| 03 RBAC | Precedence is **User Deny > User Allow > Role**, with expiring overrides. Build it fully now — RBAC cannot be retrofitted |
| 04 Conversations | Build against the **FAKE adapter only** — no real channel this session. Notes are structurally undeliverable. Per-conversation FIFO lanes. No channel conditionals in domain code |
| 06 Ticketing | Conversation ≠ Ticket. One open-family ticket per conversation, **DB-enforced**. One transition service. Merge with 24h unmerge. Tickets are never hard-deleted |
| 05 Telegram | Business connected-bot is primary; connection method is a **setting**. The ~24h reply window is real machinery: countdown chips, expiring view, expired-composer card, self-calibrating expiry. Owner's phone replies are captured too |
| 07 Inbox | Note-mode vs reply-mode must be **unmistakable** — a wrong send here is a real incident. Full keyboard map, offline/reconnect, collision warning |
| 08 Profiles | Match-or-create; **never** auto-link across identity types. 30-day merge undo. RG flag = absence, not redaction. No "verified" concept can exist |
| 09 AI | Grounding-or-handoff; never states transaction facts; Copilot never auto-sends; RG is template-only; disclosure is not removable; kill switches per surface × channel × bot |
| 10 KB | AI drafts, humans publish. Atomic retrieval-generation swap. Internal index structurally separate from the public one |
| 11 Routing | Deterministic 5-step pipeline with a routing-decision record on every ticket. 30-min starvation cap absolute. RG routing final. Ship round-robin/load-based first |
| 12 Automation/SLA | Position-ordered rules with dry-run and loop guards. SLA snapshot instances + a **pure** clock library (property-tested). Macros never leave blank variables |
| 13 Widget | iframe isolation, zero host-DOM access, hostile-host page on every PR, queue position never counts upward, staged CDN channel independent of app deploys |
| 14 SMS | Guard-function truth table. DLR state machine with an honest `expired`. Amharic = UCS-2 = 3–4× segments, shown in the editor. Notification-only is a structural wall |
| 15 Reporting | One semantic metric dictionary; **four-surface parity** (page = dashboard = export = API). Spam/test excluded once, centrally. AI resolution rate unrenderable without CSAT(AI) + reopen-after-AI |
| 16 White-label | "Nothing hardcoded" is a **build gate**. Contrast validator has no override. Flags hide, never destroy. One incident page holds every stop button, phone-usable at 2 AM |
| 17 Audit | Append-only **by DB grants** — physics, not policy. Hash-chained per tenant-day with sealed daily hashes. Erasure requires four-eyes, not disableable. Honest backup-expiry dates |
