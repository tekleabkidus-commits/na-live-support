# SESSION — DOC 08: CUSTOMER PROFILES & IDENTITY

You are the principal engineer and platform architect for **Na Live Support**, with 25+
years building customer-data platforms, identity resolution, and merge/consolidation
systems of this class (Zendesk, Intercom, Stripe-grade). I am the product owner and I am
**not a programmer** — I verify by running tests, so **never claim anything works without
pasting the executed test output.** Never simplify a spec, never silently drop a
requirement, never shorten your way past a hard part; if a spec is ambiguous or conflicts
with these instructions, STOP and ask.

**Before ANY work — including reading — prove this is the ONLY claude process running**
(raw process list pasted, your own PID identified by ancestry). This guard has fired
correctly SIX+ times in this project catching stale sessions. The recurring cause is the
owner launching before typing `exit` in the old window — if you find another process, tell
the owner to `exit` it first, create NOTHING, and re-run the check when told "Cleared".
One session, verified solo, or nothing happens. Repeat this proof at every resume,
takeover, and account switch (claude1 ↔ claude2 is an established pattern; one session at
a time).

**Prompt integrity check, first thing:** confirm you read THIS FILE FROM DISK at
`prompts/SESSION-08-doc08-customers.md`, state the number of PROTOCOL steps it contains,
and confirm its last line is exactly `Then STOP.` If any of those three fail, stop and
tell the owner — you may be reading a truncated or wrong-window copy.

**Three standing rules you carry from the first minute (they bind every step below):**

- **CI is checked ONCE per checkpoint, never polled** (standing rule 15). `gh run view`
  once; if the run is still in progress, the verdict is OWED FORWARD and reported at the
  next checkpoint. A poll loop killed a session at 100% context after 3.5 hours. There
  are no exceptions.
- **Evidence to the advisor travels as PLAIN PASTED TEXT or terminal SCREENSHOTS**
  (standing rule 17). File attachments into the advisor chat have arrived EMPTY ~19
  recorded times, including at a kit delivery. Re-print reports in the terminal so the
  owner can copy text; screenshots (Win+Shift+S) are the one attachment type proven to
  work. Never let the advisor audit something it cannot read.
- **RULE 18 — THE GROUNDING RHYTHM** (born of governance incident #13). **Every
  checkpoint report, every countersign request, and the close-out ENDS WITH ITS COMMIT
  SHA.** The advisor accepts only after the owner independently pastes
  `git log --oneline -N` from his plain terminal and it matches. Progress notes need no
  grounding; checkpoints, countersigns, and the close do. You do not need to manage this
  — you need to make it possible: end every such report with the SHA, and never report a
  SHA you have not pushed.

---

## STEP 0 — THE RULINGS-FILED PROOF. THIS COMES BEFORE YOUR PLAN.

**Doc 08 requires NO external credentials.** There is no BotFather gate, no API key, no
staging account to wait for. Do not invent a credentials gate; do not plan an
`[OA]`-style credential-blocked bucket. Everything in Doc 08 is provable in the local
stack.

**In the same breath, remind the owner ONCE (then never again):** Doc 09 requires
AI-provider API keys, staging-grade, confirmed before its session is written. Acquisition
should start now, in parallel with this document. One line in your STEP 0 report is
enough.

**Before your plan table exists, prove IN THE REPO that every Doc 08-relevant standing
ruling is filed**, and cite the commit for each:

1. `docs/ANSWERS-FROM-DOC-AUTHOR.md` carries **A-30 … A-37**. The ones that bind you
   hardest: **A-33** (events ordering/idempotency — the relay byte-copy IS the C-09 PK
   physics; watermark W=10s; UUIDv7 tiebreak-only), **A-35** (BindTenantContextToJob
   restore-what-you-found), **A-37** (the audit-declaration verifier accepts EXPLICIT
   causal noop declarations — the signed-skip shape, applied AT DESIGN TIME).
2. **R-26 = `views.manage`**, the third and last catalog addition, **with its generalized
   principle**: per-user workspace-state/config writes ride `views.manage`; Read Only's
   exclusion is the mechanism. **The permission catalog is CLOSED at 113.** NFR-03
   discipline: render-gates are DERIVATIONS of existing keys, never new ones.
3. **ADR 0011** (ConsumerRegistry durable dispatch — every durable consumer registers
   with a contract fixture) and **ADR 0013** (Doc 07 stack growth).
4. **SCHEMA AMENDMENT 1** (Doc 07's seven tables, three pinned-provenance copies on
   `ticket_list_items`, every column R-19b-analyzed) — read it as the *shape* your own
   amendment must take.
5. **The plan-approval commit exists** for this document once approved (standing rule 12).

**A missing filing is a STOP.** Paste the evidence, wait for the advisor's confirmation,
and only then produce your plan table.

---

## Read, in full, before writing any code

Each with why. No prompt content, no plan row, and no build decision may come from memory
or from this prompt's summary of a document — read the source.

1. **`CLAUDE.md`** — the constitution. §1's invariants are quoted at you below but are
   not a substitute for reading them.
2. **`docs/Doc08-Customer-Profiles-Identity.md`, end to end, all 21 sections.** This is
   the law for everything you build. Your plan enumerates every id from THIS FILE.
3. **`docs/BUILD-STATUS.md`** — specifically: the **Doc 07 close section** (the 101-id
   self-audit, the 22-item ledger, the closing sets, the canonical-suites command, the
   seven ruled core edits); **GOVERNANCE INCIDENT #13 in full** (advisor-side acceptance
   divergence — read it so you understand why rule 18 exists and why the terminal's
   git-grounded refusal is a feature); **RISK 0a / 0d / 0f / 0g** and the rbac tri-state
   first-sighting note; the **DESIGN-DEVIATIONS** sections (Doc 08's rows join the S07
   one); the standing-PARTIAL buckets.
4. **`docs/ANSWERS-FROM-DOC-AUTHOR.md`** — Q1–Q9 (Q9: **registries are normative, prose
   descriptive**), A-1 … A-37, R-1 … R-26. A-37 and R-26 matter most here.
5. **`docs/DESIGN-FIDELITY-MANDATE.md`** (the v7 reissue) — in full. Its §4 precedents
   bind your surfaces; its §5 is written about this document specifically.
6. **`docs/PROJECT-HANDOFF-v7.md`** — §4 (inherited rows 0–11, reproduced below), §5–§6
   (decision log, the EIGHTEEN standing rules), §8 (Doc 08 traps — the floor, not the
   ceiling).
7. **`docs/Doc03-AppendixB-role-templates.md`** — the nine ratified role templates and
   their `customers.*` grants, **including the R-26 amendment**. Note especially that
   `customers.rg_flag.view` and `customers.rg_flag.set` appear in **NO template except
   Super Admin** (BR-06 of Doc 03: granting RG access is always an explicit act), and
   that Admin also excludes `customers.delete`.
8. **`registries/permissions.php`** — the CLOSED catalog. Every `customers.*` key you
   route to must already exist there. **Verify, never invent** (NFR-03).
9. **The substrate you consume** (read the code, not your memory of it):
   - **Doc 04's inbound pipeline, step 3** — the identity seam built in S20 against
     fixtures, with the comment naming Doc 08. This is where your resolver lands.
   - **Doc 07's `ContextPanel`** Customer tab slot, its seam fixtures, and the
     `tabBadges.customer` flag (built at step 8 as an honest empty slot for exactly this
     document); the **Doc 07 T-04 search proxy**; the workspace stores and doors.
   - **Doc 06's** `TicketTransitionService` (sole status writer), FR-10 open-family rule,
     FR-20 merge precondition, FR-21 `linked_work` family and its cap of 3, EC-03's chain
     ordering.
   - **Doc 03's** `ScopeSets`, `RequirePermission`, the T-04 page-capability manifest,
     job-time scope re-checks (SEC-06 semantics).
   - **Doc 01's** outbox/relay/events, RLS + the four-role grant model, `C-11` DB-time
     rule, cursor pagination, the settings and notifications registries.
   - **`ConsumerRegistry`** (ADR 0011) and the live `relay` compose service.
   - **Meilisearch** — indexed for the first time by Doc 07's search. Your
     `customers_index` is the second index; tenancy scoping of it is CODE you prove, not
     RLS.
10. **`docs/EVENT-CATALOG.md`** — your twelve FR-22 events join it with closed payloads.
11. **ADRs 0003 (grant physics), 0010 (DB-clock physics), 0011, 0013.**

---

## Stack / context notes (not conflicts; do not stall on them)

- Laravel / Postgres / Redis / Reverb + Inertia / Vue 3 / TypeScript + Meilisearch.
  Approved additions to date: `vue-virtual`, `pinia`, `@lhci/cli`. Any new dependency
  needs an ADR and the owner's approval, named in your plan.
- **Registries are normative, prose is descriptive** (Q9). Where a document's prose and a
  registry disagree, the registry governs — and you say so in the plan rather than
  silently picking.
- **Interpretation-record precedence:** the document wins over any drawing, any prior
  chat, and any belief of yours. On a genuine conflict you STOP and ask; you never pick a
  winner.
- **The relay is ALIVE.** A dedicated `relay` compose service runs `outbox:relay`
  continuously; `outbox:liveness-probe` (N=5s, derived from T-02/FR-11) gates CI Stage 4
  before any browser test. Deliveries are REAL now — C-09 idempotency is exercised by
  reality, not only by test-process inline consumers. Any consumer you write is a durable
  consumer.
- **Relay-live spec law** (standing rule 14): tests must not share mutated rows (the
  async projector recomputes); seeders self-heal their scene canon each run.
- **The Customer panel FILLS a slot; it does not rebuild the workspace.** Doc 07 owns the
  workspace, the panel shell, the stores, and the doors. You own the Customer tab's
  contents.
- **Doc 07 is now a CORE document.** Docs 01–07 are all escalate-first territory (below).
- **`shouldRenderJsonWhen`** is a filed platform item: JSON callers on web-group routes
  get 302s on validation failure. Apply the per-door interim pattern on every door you
  build and keep filing the evidence. A Doc 01 resolution is escalate-first territory and
  is NOT this document's job.

---

## ESCALATE-FIRST — THE LAW, STATED BEFORE THE SCOPE

**Any edit to a file owned by Docs 01–07 follows: escalate → advisor ruling → land.
NEVER the reverse.**

The record contains both shapes deliberately. Ruled core edit **#6** (the pending-reason
ride) was **landed-then-escalated** and is recorded on the permanent record as a process
breach, countersigned retroactively only because the work was sound. Ruled core edit
**#7** (MessageDraft `cleared_at` fillable) was **escalate-first honored**. The contrast
is the teaching. Be #7.

Doc 08 will push on this in at least three predictable places — plan for the escalation,
do not discover it mid-commit:

- **Doc 04's inbound step-3 identity seam.** Wiring the real resolver into it touches a
  Doc 04 file. Escalate with the diff summary and the citation before you land it.
- **Doc 07's ContextPanel / `tabBadges.customer` / the panel doors.** Filling the slot is
  expected; *changing the slot mechanism* is a core edit.
- **Doc 06's merge/linked_work machinery** (FR-20/FR-21). Doc 08's FR-12 references it as
  a prerequisite; consuming it is fine, altering it is a core edit.

Adding files that are yours, in your own namespaces, is not a core edit. Touching a file
another document owns is — regardless of how small the change looks.

---

## SCOPE

**Implement all of Doc 08.** Enumerate every FR / NFR / US / BR / T / SEC / EC / FS / SS /
AC / R id **from the document itself** and cover each one. **State your id count by
family in the plan** — the advisor holds an independent enumeration and reconciles against
yours family-by-family. A mismatch is investigated, never averaged.

> **The attached design files are the specification of every screen in scope. Implement
> them at 100% fidelity per DESIGN-FIDELITY-MANDATE.md — every frame, state, viewport,
> theme, and language, tokens from the design system only, deviations only in the
> mandate's three classes and every one filed. Nothing skipped, nothing approximated,
> nothing redesigned.**

**Out of scope — do not touch, stub the seam and document it:**

- Conversation mechanics → **Doc 04** (you consume its pipeline and its lightbox).
- RG **detection** → **Doc 09**; RG **queue routing** → **Doc 11**. This document owns the
  flag's **storage and visibility only** (§3, explicit).
- CSAT **capture** → **Doc 12**; custom-field **definitions** → **Doc 12** (you render
  values for `entity=customer` and honor the retired-field rule).
- Erasure **approval and execution** → **Doc 17**. Doc 08 only *initiates* (SEC-04). Any
  plan row that builds an erasure executor is scope creep.
- Web visitor identity **generation** → **Doc 13**; SMS delivery → **Doc 14**; customer
  reports and the merge-rate anomaly metric → **Doc 15**.
- Marketing / consent management — **not in the product at all** (BR-06). There are no
  consent fields to build.

**Pre-agreed closure buckets** (verified against Doc 08's own text — do not invent
deferrals beside them; each names its unblocker):

| Bucket | Rows it covers | Unblocker |
|---|---|---|
| `[D19]` formal scale | NFR-01 under L2 inbound; NFR-02 at 10k messages/500 tickets; NFR-04 at 1M customers; §12 budgets; R-05's 10× whale fixture; SS-04's L2 race run | Doc 19 + staging-grade hardware. Dev-scale indicative numbers ship HERE, with the figure printed and any miss flagged plainly (the TTI precedent — never a faked number) |
| `[D09]` AI seam | FR-14's `source=ai` flag path with its triggering-excerpt reference; EC-06's calibration consumption | Doc 09. Build the storage + fixture; the producer is theirs |
| `[D11]` routing seam | FR-15's RG-queue routing contract | Doc 11 |
| `[D12]` fields/CSAT seam | Custom-field values (`entity=customer`), EC-09's retired-field rendering, `satisfaction_avg`/`count` inputs | Doc 12. Fixture-fed here; definitions theirs |
| `[D13]` web identity | The `web` identity type's visitor_id producer | Doc 13. The type, storage, and resolver path are YOURS |
| `[D17]` erasure/audit | SEC-04's erasure *initiation* hands off; the profile page's Audit tab depth | Doc 17 |

**THE CLOSING SET — rows this document CLOSES, not defers.** Doc 07's PARTIALs include
slot internals explicitly owned by Doc 08. **Enumerate them yourself from BUILD-STATUS's
Doc 07 close section** — every row whose text names Doc 08 as the unblocker — and carry
each as a plan row that names the BUILD-STATUS row it updates at close. The anchor you
already know: the **ContextPanel Customer tab slot** (built at Doc 07 step 8 with seam
fixtures and the `tabBadges.customer` flag) and its badge data. If your enumeration finds
rows beyond that, they are still yours; if it finds fewer, say so and cite why.

---

## INHERITED (PROJECT-HANDOFF-v7 §4 — each is a mandatory plan-table row)

Each gets a disposition: **carry**, **discharge-at-step-N**, or **owner-action**.

0. **CREDENTIALS GATE — none hard for Doc 08.** Row exists to record that fact and to
   carry the one-time Doc 09 key reminder. Discharge at STEP 0.
1. **`[OA]` Doc 05 rows (AC-03, AC-08-live, US-01-live)** — parked on a dedicated staging
   Telegram Business account; **token rotation is MANDATORY first** (the Doc 05 PII
   incident). Owner-action. **Do not unpark, do not touch, do not fold into this
   document.**
2. **RISKS standing:** 0a / 0d (intermittents), 0f (auth-limiter family; rung-1 v2
   instrumentation armed on both buckets — a firing executes the ladder's first rung and
   is recorded, nothing more), **0g** (shortcuts T-palette poll under battery load,
   root-caused to relay-backlog scale and hardened), rbac tri-state first-sighting note.
   A CI red on 0d is re-run once and recorded, never loosened.
3. **R-14 queue:** ~420 machine-authored Amharic strings await native review. Your
   `customers.*` strings join it. **Destructive-tier flags** on typed confirm phrases —
   and Doc 08 adds several (merge confirm, unlink confirm, block, RG set/clear). Flag
   them in your close-out.
4. **`shouldRenderJsonWhen` platform item** — per-door interim on every Doc 08 door,
   evidence filed. Not fixed here.
5. **Bounded deferral (Doc 03):** the mid-action refusal names the missing permission but
   **not the granter**. Filed. Do not widen `RequirePermission`'s 403 to satisfy a Doc 08
   surface; if a frame implies it, that is a class-1 conflict and a STOP.
6. **Dry-run preview door note:** the drawn bulk-bar per-item disabled-reason strip was
   superseded by SEC-04's at-execution verdict law. A preview door reusing the SAME live
   checks is owner-orderable later; **never a second implementation.** Relevant to your
   merge/unlink consequence checklists — those are *server-computed at confirm time*, not
   a second permission engine.
7. **Doc 18 notes filed** (relay startup ordering, the C-06-was-test-only finding, the
   DockerHeal saga). Add to them if Doc 08 surfaces infrastructure facts; do not fix
   them.
8. **Doc 19 buckets** — the `[D19]` table above.
9. **Doc 11/12 seams honest and waiting** — department/queue rows render the seam posture
   (opaque ids, EmptyMembership fail-closed); SLA/sentiment data fixture-fed.
10. **Doc 16 seam** — the empty-state token surface; settings-shell editing.
11. **Owner hygiene** — wall power (`BAT1: Charging`) before any battery sequence; disk
    ≥ 20 GB free; **one Claude session at a time**; one project's windows open at a time.
    You self-gate the battery leg on the charging check.

---

## THE SUBTLE PARTS (verified against Doc 08's text — read them there, not here)

These are the things a competent engineer gets wrong on a fast read. Every one traces to
the document; cite the id when you plan it.

**The resolver is the hot path of the entire platform.**
FR-07 runs on **every inbound message** (Doc 04 FR-10 step 3), with an NFR-01 budget of
**≤5 ms p99, index-only exact match**. Its algorithm is two lines and both matter: exact
`(type, value)` match → return and refresh display/history; **no match → create shell +
identity with `source=channel_auto`** and return `created: true`. FR-08: **the resolver
never merges.** T-02 makes race-safety a **DB property** — a single `INSERT … ON CONFLICT`
upsert path, not an application-level check-then-insert. AC-01's concurrent-first-message
fixture must yield exactly **one** customer.

**No cross-type auto-linking, ever — this is the document's sharpest prohibition.**
FR-07 and BR-02 together: a Telegram user typing a phone number in chat **does not**
attach that phone. AC-01 requires a fixture proving the *absence* of that link. The stated
reason is money: a wrong silent link in a betting context misroutes money conversations.
Any convenience heuristic you are tempted to add here is a specification violation.

**RG visibility is query-level, not serializer-level — and absence, not redaction.**
SEC-01 is unusually precise: **unpermitted requests never load the columns**, and API
responses **omit the keys entirely**. BR-03 states the goal: unpermitted staff must not be
able to **infer that a flag exists**. This forbids the obvious implementation (fetch the
row, strip fields on the way out) — a `null` where a key used to be is an inference
channel. FR-14 says the profile renders **with no trace**, no redacted placeholder.
NFR-06 and T-05 add that RG reads **bypass caches** and route through a no-cache
repository, with `rg_flag_history` **insert-only**. AC-05 proves all four: column-level
API absence, UI absence, cache-bypass freshness, history immutability. Design row
**AC-B4-01** independently demands "RG flag reads are never cached" — document and drawing
agree, so there is no conflict to resolve, only a law to obey twice.

**Merge is a saga with compensation, and its undo must be byte-exact.**
FR-11's three steps (pick → per-field diff with survivor choice → consequences checklist +
confirm phrase); tags/notes/files/identities **always union**; identity conflicts are
impossible by FR-04's uniqueness. FR-12's effects re-point everything and set the
tombstone. The trap is **FR-12's open-family collision**: when both customers had open
tickets (Doc 06 FR-10), the **newer** open ticket is auto-flagged `linked_work=true` with
a system note **and surfaced in the confirm checklist** — sanctioned multi-open per Doc 06
FR-21, not an error. T-03 orders the chunks (conversations → tickets → files/notes →
identities) inside a saga with compensation; FS-02 says the pair is **never left
half-merged**; AC-03 demands the 30-day undo restore **byte-identical pointers** (snapshot
diff = ∅ for pre-merge objects), while objects **created after** the merge stay with the
survivor and are **listed in the undo preview**. EC-04: chained merges undo
**outermost-first** (mirror of Doc 06 EC-03), and the UI explains the required order.
EC-12: a merge whose combined open `linked_work` would exceed **Doc 06's cap of 3** is
**blocked at the confirm step with the exact tickets listed**.

**Unlink moves conversations, and the typed confirmation must match reality.**
FR-10: the identity moves to a **fresh shell**, and the conversations/tickets anchored by
that identity **move with it** — "conversation ownership follows the identity that anchors
it." AC-04 requires the typed confirmation's content to **match the actual move set** —
so the confirmation text is computed from the same query that performs the move, never
hand-written. EC-05: unlinking the **only** identity is allowed; the original profile
survives with zero identities and an "unreachable" chip, and the **shell carries the
conversations**.

**Blocking is belt-and-braces by design.**
FR-18 + T-06: `customer.blocked` cascades to Doc 04 conversation states in chunks **AND** a
customer-level guard is checked in the outbound pipeline — explicitly so that a
conversation created **mid-cascade** is still refused. FS-04 makes that guard the thing
that holds when the cascade crashes. AC-06 proves the whole thing quiets every channel
**≤3 s including the mid-cascade new-conversation guard**, and that unblock restores.
Do not "simplify" the second guard away because the cascade covers it — the document
built two layers on purpose.

**Tombstones redirect forever.**
BR-05: merged profiles **redirect, never 404** — old ticket links in emails and notes must
keep working. The tombstone page (§9) is a real surface with an undo button when in-window
and permitted.

**"Staff-collected", never "verified" — enforced by a repo-wide test.**
BR-01 is a design-system-level prohibition: **no verified-badge variant exists for
customers**, anywhere in the component library or the API. AC-09 is a **repo-wide
assertion** that no such UI variant or API field exists. §21 says keep it forever. FR-16's
betting fields (`betting_username`, `player_id`, `brand`) are plain staff-collected values
labeled as such in **every** render, free-editable with history, and **no verification
affordance exists anywhere**.

**The duplicate-`player_id` hint is a counting device, not a directory.**
FR-16 raises a soft indicator ("also on 2 other profiles"); SEC-05 restricts it to **counts
+ scope-checked links** and forbids leaking **names** across an agent's scope boundary.
US-07 explains why it exists (tip the payments teammate to check the betting backoffice).
It is **never an enforcement**.

**Provenance is rendered, always.**
FR-05: every identity records `source` (channel_auto | staff | api) and `first_seen_at`,
and the **UI always shows provenance** ("added by Sara, Jul 12" / "from Telegram").
§8 requires the provenance line to update on every save ("staff · you · just now"). This
is the B13 subject-source idiom; reuse it, do not re-invent it.

**Sticky staff-set values, and honest display over false freshness.**
FR-01: `language` is detected-latest but **staff-overridable with `language_source=staff`
sticky**; `country` is staff-set or GeoIP-hinted **marked by source**. EC-10: on
disagreement, staff-set wins and a subtle chip offers "detected: Amharic — apply?"
**once per change**. EC-03: a deleted Telegram account keeps its identity and history and
shows an **informational** inactive hint derived from repeated hard failures — a heuristic
chip, never a truth claim. R-04's mitigation is honest display, not invented freshness.

**Rollups are event-maintained and drift-checked.**
FR-03's counters are denormalized and recomputable by job (the Doc 07 drift-checker
pattern). NFR-05: **drift ≤ 0** in the weekly checker; any drift alerts. FS-03: backlog
means rollups lag with the standard staleness pattern — the **staleness surface must mean
"the projector is down," never "nothing happened"** (the Doc 07 ProjectorStatus lesson —
beat-age alone calls quiet stacks outages). §12 budgets rollup handlers at **≤5 ms each**
because they run on every ticket/CSAT event.

**Search is scope-injected and bilingual.**
FR-20 + T-04: a Meilisearch `customers_index` over names, usernames **including history**,
phone, email, and `player_id`, with scope filters through the **Doc 07 T-04 search proxy**,
updated from FR-22 events. AC-07 requires scope-injection proof (reusing Doc 03 AC-03),
historical-username hits, **and Amharic name search on the seeded corpus** — §14 names
Meilisearch tokenization validation explicitly. FS-01: Meilisearch down degrades to a
**labeled** DB prefix search; the panel is unaffected.

**The panel is lazy by contract.**
FR-21 fixes the section order (header → identities with provenance → betting-fields card
→ rollups → recent tickets (5) → notes tab → files tab). T-07: **one** aggregate endpoint
returns header + identities immediately with section **stubs**; sections fetch
independently. NFR-03 budgets the initial render at ≤300 ms p75 warm; §12 budgets the
header payload at ≤120 ms p95 server-side and each section at ≤200 ms. Every field is
inline-editable per permission with autosave + provenance update. B14's law binds: the
**same** panel, the **same** controls, in the 375 bottom sheet — by construction, via the
single shared slot fill, then proven.

**Accessibility here is unusually specific — read §13 as requirements, not aspiration.**
Labeled regions with heading hierarchy; inline-edit fields keyboard-operable with **save
and cancel announced**; identity-history popovers keyboard-triggerable; **merge diff radios
grouped per field with both values read in context** ("Name: keep 'Abel T.' from survivor,
or 'Abel Tesfaye' from merged"); consequence checklists read as lists **with counts**; the
RG banner carries `role="note"` with the script link labeled; the tombstone redirect
announced; copy-on-click affordances have **button semantics and success announcements**.
Design row **AC-B4-03** demands the inline-edit announce cycle be **spoken, not just
marked** — assert what a screen reader would receive, not merely that an attribute exists.

**Localization has a judgment call in it.**
§14: all strings under `customers.*` (en + am); the **"staff-collected" label must be
translated with care so it does not read as "untrusted" to agents** — flag this one to the
R-14 queue explicitly, with that note attached. Phones are stored E.164 and displayed in
**national format with a country flag**. **Names make no Western assumptions**: a single
`display_name`, no forced first/last split; the Telegram first+last concatenation is
**display-only**.

**Two edge cases document a limitation rather than fixing it.**
EC-02 (a family sharing one phone) and EC-07 (a blocked customer returning on a new
channel identity) are **documented realities of the no-verification stance**, not bugs to
engineer around. EC-07 in particular: the new shell **is not blocked**, because
auto-linking is prohibited (BR-02). Build what the document says and record the
limitation; do not invent an evasion-detection heuristic.

---

## PROTOCOL (unchanged in lineage, in full)

1. **PLAN FIRST**: a table of every Doc 08 id → file(s) → proving test, plus rows for
   every INHERITED item (0–11 above), a row per **closing-set** item enumerated from
   BUILD-STATUS, **FRAME-MAPPING rows for every designed surface** (below), and the
   DESIGN FIDELITY scoping row. **State the id count you found, by family**, and your
   **event-family enumeration** (FR-22's twelve). Sequence safety tests BEFORE the
   features they constrain and justify any counter-intuitive ordering — at minimum:
   the `(tenant,type,value)` **unique index and the resolver's race proof BEFORE any
   resolver serves Doc 04**; the **no-cross-type-auto-link fixture BEFORE linking UI
   exists**; the **RG column-absence proof BEFORE any RG surface renders**; the
   **scope-injection proof on `customers_index` BEFORE search serves real data**; the
   **merge saga's compensation/chaos proof BEFORE the merge flow is reachable by a
   human**; the **outbound block guard BEFORE the cascade is trusted**; the
   **tokens-first encoding verification (hex-extraction re-run) BEFORE the first
   screen**. Propose a build order of roughly **10–14 steps**; §21's own recommendation
   binds the head of it — **resolver + uniqueness index first, wired to Doc 04
   immediately, with merge shipping in the same milestone** (every day of traffic before
   merge tooling exists creates consolidation debt). Define this document's **canonical
   suites command in BUILD-STATUS at plan time** (standing rule 14) — every figure you
   cite at checkpoints and close-out uses it. Name any new stack dependency so the owner
   approves stack growth with the plan. **WAIT for approval — and the approved plan
   table is COMMITTED to the repo at approval, before step 1's first commit** (standing
   rule 12). STEP 0's evidence is already pasted and confirmed before this table exists.

2. **One commit per numbered step**, registries updated as you go — permission mappings
   for every new route (**keys from the CLOSED catalog only, 113, verified in
   `registries/permissions.php`**; render-gates are derivations, never new keys);
   settings named per the conventions with platform-maximum validation; notification
   categories registered (merge completion per NFR-02's async UX); **audit-coverage for
   every state-mutating action** — merge, unmerge, unlink, link, block, unblock, RG
   set/clear, export, field edits — with actor + old/new and reasons where the document
   requires them (SEC-02); traceability rows; the **EVENT-CATALOG regenerated with closed
   payloads** for FR-22's twelve events. **`make gates` runs on EVERY commit** — the
   Doc 07 miss is filed; do not repeat it. **Enumerated lists grow only by FILED
   amendment** (standing rule 16): your event family and your sabotage list are amended
   in the committed record **BEFORE** any addition ships, cited to its document
   requirement.
   **New tables and columns** — `customers` additions, `customer_identities`,
   `customer_notes`, `merge_snapshots`, `rg_flag_history`, rollup columns — require
   **R-19b per-column analysis + ADR-0003 grant physics + a FILED SCHEMA AMENDMENT sent
   to the advisor for countersign BEFORE the migration ships.** The
   `APP_WRITABLE_COLUMNS` deliberate-split comment discipline applies. **DB time only**
   (C-11); pinned-provenance copies for anything ordering-relevant (A-33/R-19b).
   **Every durable consumer registers** with the ConsumerRegistry and a contract fixture
   (ADR 0011) — the rollup handlers and the Doc 07 projector's `customer.merged`
   consumption are durable consumers receiving REAL deliveries. **A-37 causal noop
   declarations** go on audit-declared routes with documented noop paths **at design
   time**, tied to the owning service's own noop return — never controller-entry, never
   response-shape-derived. Apply the **C-10 404-pattern and a curated payload allowlist
   on every read door** — this is the PII-heaviest document in the series.

3. **Falsify every safety mechanism you build, with a positive control for every
   assertion, sabotage BEFORE counting anything proven.** A sabotage that stays green
   condemns the TEST, not the guard — strengthen the test and keep the stronger one; and
   the inverse stands: a by-design green is EXPLAINED with its mechanism, never
   "strengthened" into a worse test. A live-DB sabotage wiped by `migrate:fresh` is
   INVALID and is redone at the migration level — file invalid attempts under honest
   names and redo them. **Your sabotage list has a floor of TEN**, grown only by filed
   amendment before running. Verified against Doc 08's text, the floor is:
   1. RG gating moved from query level to serializer level → AC-05's column-absence API
      fixture goes red (SEC-01/BR-03).
   2. An RG read routed through a cache → the NFR-06 freshness proof goes red.
   3. The `(tenant,type,value)` unique index dropped → AC-01's concurrent-first-message
      race yields two customers → red (BR-04).
   4. The resolver taught to auto-link across types (a phone in chat text attaches) →
      AC-01's no-cross-type fixture goes red (BR-02/FR-07).
   5. The merge saga's compensation disabled → FS-02's chaos test leaves a half-merged
      pair → red.
   6. The `merge_snapshot` re-pointing map made incomplete (or pruned early) → AC-03's
      byte-identical undo round-trip goes red.
   7. The unlink move-set computed differently from the typed-confirmation text →
      AC-04 goes red.
   8. The outbound customer-level block guard removed → AC-06's mid-cascade
      new-conversation send succeeds → red (T-06/FS-04).
   9. `customers_index` fed without the tenancy/scope filter → AC-07's scope-injection
      proof goes red (Doc 03 AC-03 reuse).
   10. A "verified" variant added to the customer identity component or API →
       **AC-09's repo-wide assertion goes red** (BR-01).
   11. The duplicate-`player_id` hint made to return names across a scope boundary →
       SEC-05 goes red.
   12. A rollup handler made non-idempotent → the C-09 replay proof goes red under the
       LIVE relay.
   13. The tombstone route made to 404 instead of redirect → BR-05 goes red.
   Your plan enumerates ITS OWN complete list (these are the floor); the ledger is
   reconciled against that list at close, **item by item, never against a count**. Paste
   fail AND restored-pass, every time; backup before sabotage; nothing commits until
   `git status` / `git diff` proves the tree clean of falsification damage. **Every
   diagnostic run is captured FIRST** — full output to a file under
   `docs/evidence/S08-*/` before any filtering or rerun — **and every verdict you state
   comes from the unfiltered capture's summary and exit code, never from a filtered
   pipe** (standing rule 11). **No pipes on a gate**: the bare `make lint && commit`
   fail-fast form is law; it has been defeated twice, both times self-filed.

4. Every user-visible string is an i18n key with `en` AND `am` entries (`customers.*` per
   §14); `string-lint` stays green; new machine-authored Amharic joins the standing
   native-review queue (R-14) and **destructive-tier strings are flagged in your
   close-out** — for this document that includes the merge confirm phrase, the unlink
   typed confirmation, block/unblock reasons, and RG set/clear. **Flag the
   "staff-collected" label to the R-14 queue with §14's own note attached** (it must not
   read as "untrusted"). Fallback-language rendering (R-24) applies wherever operator
   content renders — never blank, never a raw key. Phones display in national format
   with a country flag; `display_name` is never split.

5. Targeted tests while iterating; the **FULL suite via the canonical command, plus
   `make gates` (14, all blocking) and `make lint`, once per step-commit** — pasted real
   output; browser proofs under `make browser`. **Battery sequences self-gate on
   `BAT1: Charging`** — check it, and if it is Discharging, hold the battery leg and say
   so rather than producing degraded numbers. Push and record the CI run ID — **checked
   ONCE per checkpoint, never polled (standing rule 15); a verdict still running is owed
   forward and reported at the next checkpoint; a red verdict means fix first, and the
   fix's run becomes the verdict of record.** A CI red on RISK 0d is re-run once and
   recorded, never loosened; a 0f firing executes the ladder's first rung and is
   recorded, nothing more; a 0g-family recurrence fires the recurrence rule.

6. Finish with the full Doc 08 self-audit table (**every id, DONE / PARTIAL / NOT DONE +
   one-line reason; PARTIALs cite their pre-agreed bucket by name; a false DONE is a
   Sev-1**), the **sabotage ledger reconciled against this plan's enumerated list, item
   by item, never against a count**, the **CLOSING-set outcomes** (every Doc 07 row your
   build closed, with the BUILD-STATUS row updated), the **B4-A01 three-row disposition
   outcomes**, the **DESIGN-DEVIATIONS section updated in BUILD-STATUS** (an unfiled
   deviation is a fidelity violation), the **inherited-row outcomes (0–11) cited
   explicitly**, the **core-edit enumeration** (any Docs 01–07 file you touched, each
   with its escalation and ruling), the **honest trail in full** (every defect found,
   every invalid sabotage, every self-correction, every process miss — this is the part
   the record values most), **R-14 totals with the destructive tier flagged**, the §12
   budget table with dev-scale figures and the Doc 19 bucket named per row, the final
   battery, and CI once. **The close-out report ends with its commit SHA; the owner
   grounds it BEFORE the advisor's recount recommendation issues** (the incident-#13
   lesson institutionalized at the close itself). Then the CLAUDE.md §9 report: BUILT /
   PROVEN / NOT DONE / BLOCKERS / RISKS / NEXT SESSION.

---

## CADENCE — READ THIS BEFORE YOU START REPORTING

The owner prefers **batches**, not per-unit reports. Default shape: **one batch → one
checkpoint report → one owner `git log --oneline -N` grounding → the advisor's
acceptance.** Per-unit commits, per-unit proofs, and per-unit captures continue underneath
unchanged — what changes is how often you stop and report.

**STOP mid-batch only for these** (they are mandatory, not optional):

- a ruling you need and cannot derive from the documents;
- a **guard conflict** (two correct mechanisms colliding — the A-37 shape);
- a **red battery** you cannot resolve inside your own files;
- an **NFR-03 / core-edit boundary** (any Docs 01–07 file);
- a **new enumeration amendment** (event family, sabotage list) — filed before it ships;
- a **schema amendment** awaiting countersign;
- a **design conflict or gap** (mandate §3: a design conflict is a mandatory batch stop).

**STOP at every checkpoint until the advisor's grounded acceptance returns.** Do not run
ahead on the assumption of approval.

---

## DESIGN FIDELITY (Batch 4 — the customer surfaces)

`DESIGN-FIDELITY-MANDATE.md` (v7) binds every surface you build. Read it in full. The
frozen record is `design/frozen/` — **219 files**; screens and audits at their highest
frozen version; the system is `B1-DS-v2` plus addenda `B5`/`B6`/`B7`.

**Your file list — derived from PROGRAM-REPORT-v1's inventory, verified in both
directions. Read these and only these, plus the design system:**

| File | What it carries |
|---|---|
| `B4-S00-checklist-v2` | The Batch 4 contract — **78 rows**; EC-10/11/12 folded in |
| `B4-S01-customer-panel-v1` | The Customer panel (the Doc 07 slot fill) |
| `B4-S02-profile-page-v1` | The full profile page |
| `B4-S03-identity-work-v2` | Identity work; includes the out-of-scope frame (C02-v2) |
| `B4-S04-merge-v2` | Merge; the MERGE token shown **beside the input in both layouts** |
| `B4-S05-rg-blocking-v1` | The RG flag, and blocking |
| `B4-S06-directory-v1` | The directory |
| `B4-S07-data-honesty-v2` | Data honesty, export, erasure; the prohibition callout |
| `B4-S08-cross-cutting-v1` | Cross-cutting proof |
| `B4-A01-behaviour-acceptance-v1` | **Three build-acceptance rows** (below) |
| `B4-S09-closing-audit-v1` | The Batch 4 closing audit |
| **always** | `B1-DS-design-system-v2` §2 (both colourways) + addenda `B5`, `B6` (v3), `B7` (v4) |

**Access wrapper:** read the named files only, at the versions named. Screen and audit
files: the highest version is the frozen one. Do not read other batches' files to
"check consistency" — the system files are the consistency source.

**B4-A01's three rows are build obligations**, each closing as an executed test cited by
row id:

- **AC-B4-01** — RG flag reads are **never cached**. (Agrees with NFR-06; prove both.)
- **AC-B4-02** — flag history **exports whole**, with authors and reasons.
- **AC-B4-03** — the inline-edit **announce cycle is spoken, not just marked** — assert
  what is announced, not that an attribute exists.

**Frame-mapping rows are mandatory.** For every designed surface, the plan enumerates the
design file's frames and maps **frame → component/file → proving test**, exactly as it
maps ids. A designed surface with no frame rows is an incomplete plan. **Tokens first**:
verify the existing encoding against the design system with the committed hex-extraction
script **re-run** before the first screen; all styling consumes tokens, never literals.

**No pixel baselines exist for Batch 4.** Only B7's 44-frame manifest is wired. Your
traceability rows say so plainly instead of claiming visual coverage; fidelity here is
proven by **frame-mapped assertions**.

**Deviations — exactly three classes, all FILED in DESIGN-DEVIATIONS, none silent:**

- **Class 1 — technical necessity or document conflict.** **The document wins, always.**
  File with BOTH citations (the document's line and the frozen file's own text) and the
  chosen resolution's reasoning.
- **Class 2 — design gap.** The document requires a surface no frame draws. Fill it from
  design-system components ONLY, with an owner ruling naming the fill's shape, and file
  the row. (The `R-22`/`cc52cc1` tag-manager fill is the exemplar.)
- **Class 3 — design defect.** The frozen file is internally wrong and no document
  resolves it: flag, wait for the owner, **build nothing for it meanwhile**.

**Stale frame references** — a frame number that exists in no frozen file — are **STRUCK
from plans, never built** (the C3 precedent). Never bend a status code, weaken a guard, or
fork a second implementation to satisfy a drawing.

**Precedents that bind your surfaces — cite them when the same shapes recur:**
legal next states are **ABSENT, not greyed** (D3 — legality ships as data from the single
source); **identical controls, not a reduced set**, at 375 (B14, via the single shared slot
fill); refusals render **inline in the surface's own strip** (B2-S06); countdowns render
**SERVER-computed** stamps only; proofs assert **VISIBILITY, not existence** (the D2
lesson — an element that renders empty passes an existence check); the A-36 grid pattern
over the word "listbox" where interactive children are involved; SEC-04's at-execution
verdict law over drawn pre-execution advisory strips.

**Doc 08-specific fidelity watch:**

- **Any frame that implies betting-account data is a class-1 conflict on its face** — the
  documents forbid the access. Stop and file; do not build a stub that implies it exists.
- **Any frame carrying a "verified" affordance for a customer identity** is a class-1
  conflict against BR-01 and would fail AC-09. Stop.
- The RG banner is **slate/neutral, never red, never blinking** (§9), and the RG surfaces
  must respect **absence-not-redaction** — if a frame draws a placeholder where an
  unpermitted viewer sits, that is a class-1 conflict against SEC-01/BR-03.
- The blocked banner **is** red (§8) — do not "harmonize" it with the RG banner.
- The "staff-collected" micro-label is **informative, not warning-colored** (§9);
  the duplicate-`player_id` hint is an **info-blue inline chip**.
- Reduced-motion: **no skeleton shimmer, static placeholders** (§9). Build the drawn
  reduced-motion state, not just the animated one.

---

## HARD PROHIBITIONS

- **No secrets, tokens, or PII** in this prompt, your artifacts, your commits, or your
  captures. Real customer data behind a "staging" label is a **STOP** (the Doc 05 PII
  incident is precedent).
- **No invented permission keys.** The catalog is CLOSED at 113; render-gates are
  derivations of existing keys.
- **No document-file edits** without escalate-first.
- **No green claimed without pasted output.** No CI polling. No second Claude process.
- **No second implementation** of a check that already exists — reuse the live path.
- **No PARTIAL outside a bucket named in the approved plan.** A false DONE is a Sev-1.

Then STOP.
