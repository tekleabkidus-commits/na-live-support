Table of Contents

# Document 08 — Customer Profiles & Identity

**Product:** AI-First Support Platform for Betting Companies **Document series:** 08 of 19 · Standalone enterprise specification **Audience:** Backend engineers, frontend engineers, QA, product, compliance, operator admins **Depends on:** Doc 01 (tenancy, events), Doc 03 (permissions customers.*), Doc 04 (identity-resolver call point, conversations), Doc 05 (Telegram identities, owner-phone attribution), Doc 06 (ticket linkage). Consumed by: Doc 07 (Customer panel slot), Doc 09 (AI context), Doc 11 (VIP routing), Doc 13 (visitor identity), Doc 14 (phone for SMS), Doc 15 (customer reports), Doc 17 (export/erasure). **Stack context:** Laravel, PostgreSQL, Meilisearch (directory), Redis

## 1. Executive Summary

This document specifies the customer domain: the Customer record built **entirely from support interactions** (the platform has no betting-platform API), the identity model (Telegram, web visitor, phone, email) with its resolver — the function Doc 04’s inbound pipeline calls on every message — shell-profile creation, staff identity linking/unlinking, the customer **merge** operation with a 30-day reversal window, profile data (contact fields, optional staff-collected betting fields, tags, notes, language, country), the customer directory with scope-correct search, blocking, the **Responsible Gaming flag** with its restricted visibility rules, satisfaction and interaction history, files gallery, and the export/erasure hooks Doc 17 consumes. Per explicit operator policy, the platform performs **no identity verification**: anyone may contact support on behalf of another player; profiles record who contacted and what was claimed. Identity fields are informational; the control point for money-moving actions is the operator’s own betting backoffice, and the UI language throughout says “staff-collected,” never “verified.”

## 2. Purpose

- Define the identity resolver contract (Doc 04 FR-10 step 3) exactly: input, matching order, shell creation, outputs, and idempotency.

- Specify a profile that maximizes agent context while honestly representing its provenance (everything is claimed or staff-collected).

- Specify merge/unlink operations safely enough for daily use in a market where players share phones and change Telegram usernames.

- Define RG-flag handling with visibility restricted by permission, given its sensitivity.

## 3. Scope

**In scope:** customer model & lifecycle; identity types & registry; the resolver; shell profiles; linking/unlinking identities; customer merge & reversal; profile fields incl. betting fields policy; notes; tags; language/country; blocking (customer-level vs conversation-level interplay); RG flag; satisfaction & interaction rollups; files gallery; directory & search; the Doc 07 Customer-panel contract; export/erasure hooks; events. **Out of scope:** conversation mechanics (Doc 04); RG *detection* (Doc 09) and RG queue routing (Doc 11) — this doc owns the flag’s storage/visibility only; CSAT capture (Doc 12); erasure approval workflow (Doc 17); marketing/consent management (not in product).

## 4. Functional Requirements

### 4.1 Customer model & lifecycle

FR-01 Fields (per Doc 01 schema + normative additions): display_name (derived: first non-empty of staff-set name → Telegram first+last → username → “Customer #short-id”), language (detected latest, staff-overridable with language_source=staff sticky), country (staff-set or GeoIP-hinted for web, marked by source), tags text[], rg_flag (bool + set_by/at/reason, restricted — §4.6), blocked (bool + reason), merged_into_id, custom fields (Doc 12 definitions with entity=customer), created_source (telegram|webchat|staff|api). FR-02 Lifecycle: shell → enriched → merged(tombstone); shells are full customers with minimal data — there is no separate “lead” concept; nothing auto-deletes (retention/erasure via Doc 17 only). FR-03 Rollups (denormalized, event-maintained): tickets_count, open_tickets, last_contact_at, satisfaction_avg + count (Doc 12 responses), repeat_contact_rate inputs; recomputable by job (drift-checker pattern per Doc 07 NFR-07).

### 4.2 Identity registry

FR-04 Identity types: telegram (value=user id; display=@username with username history in metadata), web (visitor_id, Doc 13; can be several), phone (E.164, normalized on save with country inference help), email (lowercased). Uniqueness: (tenant, type, value) unique — an identity belongs to exactly one customer at a time. FR-05 Sources & provenance: every identity records source (channel_auto | staff | api) and first_seen_at; UI always shows provenance (“added by Sara, Jul 12” / “from Telegram”). FR-06 Username/display changes (Telegram): the resolver updates display and appends {old, changed_at} to metadata history (Doc 05 EC-05); search matches historical usernames.

### 4.3 The identity resolver (normative contract — called by Doc 04)

FR-07 Input: {tenant, type, value, display?, extras{username?, phone_hint?}}. Algorithm:

1. exact match (type, value) → return customer (update display/history if changed)
2. no match → create shell customer + identity (source=channel_auto), return {customer, created: true}

The resolver NEVER auto-links across identity types (a Telegram user sharing a phone number in chat does not auto-attach that phone) — cross-type linking is always an explicit staff or customer-confirmed action (FR-09), preventing silent misattribution. Resolver is idempotent and race-safe (unique-index upsert; concurrent first messages yield one customer). FR-08 Resolver never merges; it only matches-or-creates. All consolidation is human-driven (FR-10) — an explicit product stance given the no-verification policy.

### 4.4 Linking & unlinking (staff actions)

FR-09 Link (customers.identity.link): add phone/email manually; or *move* an existing unattached-shell identity onto a profile via the merge flow (FR-10 is the mover — direct identity theft between two enriched customers is not a separate operation; it is always a merge or an unlink+link with both sides audited). Adding a phone that already exists on another customer → blocked with a link to that customer and a “merge instead?” shortcut. FR-10 Unlink (customers.identity.unlink, high-risk): removes an identity from a profile → the identity moves to a fresh shell (conversations/tickets tied to that identity’s conversations move with it — conversation ownership follows the identity that anchors it); requires typed confirmation listing exactly what moves; reversible only by re-merging; heavily audited.

### 4.5 Customer merge (customers.merge, high-risk)

FR-11 Flow: pick survivor + merged → side-by-side field diff with per-field survivor choice (name, language, country, custom fields; tags/notes/files always union; identities union — conflicts impossible by FR-04 uniqueness since they’re on different customers) → consequences checklist (tickets: N move; conversations: M move; satisfaction history merges into rollups) → confirm phrase. FR-12 Effects: merged → tombstone (merged_into), all conversations/tickets/files/notes/identities re-pointed to survivor (Doc 06 FR-20’s same-customer merge precondition references this operation as its prerequisite for cross-customer cases); open-family collision (both had open tickets, Doc 06 FR-10) → the newer open ticket is auto-flagged linked_work=true (sanctioned multi-open, Doc 06 FR-21 family) with a system note, surfaced in the confirm checklist. FR-13 Reversal: 30-day merge_snapshot (full re-pointing map) enables un-merge restoring both profiles and re-pointing objects back; objects *created after* the merge stay with the survivor (documented; listed in the un-merge preview); audited both ways.

### 4.6 Responsible Gaming flag

FR-14 rg_flag set manually (customers.rg_flag.set) or by Doc 09 detection (source=ai, always with the triggering excerpt reference); visible **only** to holders of customers.rg_flag.view — for everyone else the profile renders with no trace (no redacted placeholder — absence, per Doc 03 philosophy). Where visible: a banner on profile and Doc 07 Customer tab (badge dot) linking the approved handling script (Doc 09/16 content). FR-15 RG flag effects owned elsewhere but contracted here: Doc 11 routes flagged customers’ new tickets to the RG queue; Doc 12 suppresses promotional automations; Doc 14 suppresses non-essential SMS. Clearing the flag requires customers.rg_flag.set + reason; full history retained (never hard-deleted).

### 4.7 Betting fields, notes, blocking, files

FR-16 Betting fields (custom-field seeded set: betting_username, player_id, brand): plain staff-collected values labeled “staff-collected” in every render; free-editable with history; **no verification affordance exists anywhere** (deliberate; BR-01). Duplicate player_id across customers raises a soft indicator (“also on 2 other profiles” — visible with customers.view, link-outs scope-checked) as an FYI, never an enforcement. FR-17 Notes: per-customer, author + timestamp, customers.notes.add/view; pinned note (one) surfaces in Doc 07’s panel header; notes are never customer-visible and follow Doc 04 BR-01 structural isolation. FR-18 Blocking: customer-level block sets all their conversations to blocked (Doc 04 FR-03) and refuses new outbound + quarantines inbound platform-wide for that customer; reason mandatory; unblock restores conversation states; both evented. Conversation-level block (Doc 04) remains available for single-channel nuisance without full customer block. FR-19 Files gallery: all media the customer ever sent (from Doc 04 message media), filterable by kind/date/ticket, opens in the Doc 04 lightbox; respects infected-media blocks; export of the gallery is customers.export (high).

### 4.8 Directory, search, panel contract

FR-20 Directory page: scope-filtered table (name, identities glyph row, last contact, open tickets, satisfaction, tags) with filters (has-open, language, country, tag, RG [only if permitted], blocked) and Meilisearch-backed search over names, usernames (incl. history), phone, email, player_id. FR-21 Doc 07 Customer-panel contract (this doc owns contents): header (display name, language/country chips, tags, pinned note, RG badge [permitted only], blocked banner) → identity list with provenance → betting fields card (“staff-collected”) → rollups (tickets, satisfaction spark, last contact) → recent tickets (5, link-through) → notes tab → files tab. All sections lazy-loaded; every field inline-editable per permission with autosave + provenance update. FR-22 Events: customer.created|updated|identity.linked|identity.unlinked|merged|unmerged|blocked|unblocked|rg_flag.set|rg_flag.cleared|note.added|fields_changed via outbox.

## 5. Non-functional Requirements

NFR-01 Resolver ≤ 5 ms p99 (hot path — it runs on every inbound message; index-only exact match). NFR-02 Merge of a customer with 10k messages/500 tickets ≤ 10 s p95 (chunked re-pointing, progress events) — UI async with completion notification. NFR-03 Panel initial render ≤ 300 ms p75 warm (header + identities), sections stream. NFR-04 Directory search ≤ 150 ms p95 at 1 M customers. NFR-05 Rollup drift ≤ 0 in the weekly checker (Doc 07 pattern); any drift alerts. NFR-06 RG-flag reads bypass caches (Doc 03 recommendation 4 alignment) — always fresh.

## 6. User Stories

US-01 As an agent, the moment a chat opens I see who this is: name, language, how often they contact us, their last ticket — without asking them to repeat anything. US-02 As an agent, a caller says “I’m asking for my brother” — I just help; the profile records this contact under the contacting identity, and I can add a note referencing the brother’s player_id. US-03 As an agent, I add the phone number a player just shared, and it warns me it already belongs to another profile — one click shows me that profile so I can decide about merging. US-04 As a senior agent with merge rights, I consolidate a player’s old and new Telegram accounts in one guided flow and can undo it for 30 days if I chose wrong. US-05 As a compliance officer, RG-flagged customers are invisible-as-flagged to regular agents, and every flag set/clear has an author and reason I can export. US-06 As an operator admin, I block a serial abuser once and every channel goes quiet for them, with the paper trail intact. US-07 As a payments teammate, the “player_id also on 2 other profiles” hint tips me to check the betting backoffice before crediting anyone.

## 7. Business Rules

BR-01 **No verification, honest labeling:** identity data is informational; nothing in the product may render “verified” for customer identity; the seeded UI copy and component library contain no verified-badge variant for customers (design-system-level enforcement). Contacting on behalf of others is normal and supported. BR-02 The resolver matches, humans consolidate (FR-07/08): automatic cross-identity linking is prohibited — a wrong silent link in a betting context misroutes money conversations. BR-03 Absence over redaction for RG (FR-14): unpermitted staff must not be able to infer a flag exists. BR-04 One identity, one customer (FR-04): the invariant that makes merge/unlink meaningful; enforced by DB unique index. BR-05 Tombstones forever: merged profiles redirect, never 404 — old ticket links in emails/notes must keep working. BR-06 The profile is a support asset, not a marketing asset: no consent/marketing fields exist; outbound is limited to Doc 14’s transactional templates and window-bound replies. BR-07 Notes about a customer stay in the customer’s profile — agents are trained (onboarding copy) not to place another player’s sensitive details in unrelated profiles; the duplicate-player_id hint (FR-16) exists partly to keep cross-references structural instead of free-text.

## 8. UX Requirements

- **Panel (Doc 07 slot):** exactly per FR-21; inline edit affordances appear on hover/focus; every save shows the provenance line update (“staff · you · just now”); the RG banner (when visible) is calm, not alarming — a slate banner with the script link, no red.

- **Directory:** table per FR-20; row click → full profile page (same sections as panel, wider layout, plus Audit tab for audit.view holders scoped to this customer); bulk actions limited to tags and export (no bulk merge/block — deliberate).

- **Merge flow:** 3 steps (pick → diff/choose → consequences+confirm); the diff uses per-field radio (survivor/merged value) with “keep both” for tags; consequences list concrete numbers (“17 tickets and 3 conversations will move”); post-merge toast with “view survivor” and a 30-day undo entry point on the tombstone page.

- **Unlink flow:** modal lists the identity, its conversations/tickets that will move to a new shell, typed confirmation; result screen links both profiles.

- **Blocked state:** profile-wide red banner with reason/author/date; all composers for this customer show the Doc 04/07 blocked card; unblock is a distinct permissioned action with reason.

- **Empty states:** shell profile (“We only know their Telegram so far — add details as you learn them”); no files; no notes (“Pin the first note to brief teammates”).

- **Loading:** header instantly from list-hydration cache, sections skeleton-stream; merge progress (NFR-02) as a drawer with step list.

- **Error states:** duplicate-phone block (FR-09) with the merge shortcut; merge failure mid-chunk → automatic rollback to pre-merge with an incident reference (never a half-merged pair; see FS-02).

## 9. UI Requirements

- Identity rows: type glyph, value (mono for ids/phones), provenance microcopy, history popover for username changes; copy-on-click for values.

- “Staff-collected” label style: subtle uppercase micro-label on the betting-fields card — informative, not warning-colored.

- RG banner: slate/neutral tokens, icon + text, script link button; never red, never blinking.

- Duplicate-player_id hint: info-blue inline chip.

- Tombstone page: large redirect card (“This profile was merged into {name} on {date} by {actor}”) + undo button when within window and permitted.

- Responsive: profile page single-column on mobile with sticky header; merge flow full-screen steps on mobile; directory table → cards.

- Dark/light; reduced-motion (no skeleton shimmer, static placeholders).

## 10. Technical Requirements

T-01 Tables per Doc 01: customers, customer_identities (+unique (tenant,type,value)), customer_notes, merge_snapshots (payload jsonb, expires_at), rollup columns on customers; identity metadata jsonb for histories. T-02 Resolver: single upsert path (INSERT … ON CONFLICT for shell+identity creation) making FR-07’s race-safety a DB property; exposed as an internal service consumed by Doc 04/13 adapters and POST /v1/customers/{id}/identities. T-03 Merge: chunked re-pointing (conversations → tickets → files/notes → identities) inside a saga with compensation (FS-02); per-chunk progress events on user.{id}; survivor rollups recomputed at completion; Doc 07 projector consumes customer.merged to rewrite list rows. T-04 Search: Meilisearch customers_index (names, username+history, phone, email, player_id custom field) with scope filters via the Doc 07 T-04 search proxy; index updated from FR-22 events. T-05 RG storage: flag columns + rg_flag_history insert-only table; reads route through a no-cache repository (NFR-06); Doc 03’s high-sensitivity bypass list includes these paths. T-06 Blocking cascade: customer.blocked event → Doc 04 conversation-state updates (bulk, chunked) + a customer-level guard checked in the outbound pipeline (belt-and-braces so a new conversation created mid-cascade is still refused). T-07 Panel data: one aggregate endpoint GET /v1/customers/{id}/panel returning header+identities immediately with section stubs; sections fetch independently (FR-21 lazy contract). T-08 Contract tests: resolver matrix (match/create/race/username-change), merge saga chaos test (kill between chunks → compensation proven), RG visibility fixtures per role.

**Resolver placement (text diagram):**

Doc 04 inbound step 3 ──► resolver(type,value)
     ├─ hit → customer (display refresh, history append)
     └─ miss → shell customer + identity (channel_auto)
staff actions: link (add) · unlink (→ new shell) · merge (consolidate, 30-d undo)

## 11. Security Requirements

SEC-01 RG flag: permission-gated at query level (not serializer level) — unpermitted requests never load the columns; API responses omit the keys entirely (BR-03). SEC-02 Merge/unlink/high-risk actions require reasons and emit high-visibility audit (Doc 17); four-eyes optionally applies via Doc 03 T-06 when enabled. SEC-03 Phone/email values masked in logs (Doc 02 SEC-08 scrubber patterns extended); full values visible only in permissioned UI/API. SEC-04 Export (customers.export) produces a watermarked archive (profile + tickets index + files manifest) via async job with signed URL, audited; erasure requests only *initiate* here — approval and execution are Doc 17’s workflow. SEC-05 The duplicate-player_id hint reveals only counts + scope-checked links — never leaks names across an agent’s scope boundary. SEC-06 Directory export respects the viewer’s scope at generation time (re-checked in the job, Doc 03 SEC-06 job semantics).

## 12. Performance Requirements

- Resolver NFR-01 verified under L2 inbound rates (it is on the hot path of every message).

- Panel endpoint ≤ 120 ms p95 server-side for header payload; sections ≤ 200 ms each.

- Directory: 1 M-row tenant paginates ≤ 150 ms p95; filters composite-indexed.

- Merge NFR-02; blocking cascade for a customer with 50 conversations ≤ 3 s.

- Rollup event handlers ≤ 5 ms each (they run on every ticket/CSAT event).

## 13. Accessibility Requirements

Panel sections are labeled regions with heading hierarchy; inline-edit fields reachable and operable by keyboard with visible focus and save/cancel announced; identity history popovers keyboard-triggerable; merge diff radios grouped per field with both values read in context (“Name: keep ‘Abel T.’ from survivor, or ‘Abel Tesfaye’ from merged”); consequence checklists read as lists with counts; RG banner has role="note" with the script link labeled; tombstone redirect announced; all copy-on-click affordances have button semantics and success announcements.

## 14. Localization Requirements

All strings under customers.* (en + am); “staff-collected” label translated with care (reviewed phrasing so it doesn’t read as “untrusted” to agents); phone display formatted per country (E.164 stored, national format shown with country flag); name handling makes no Western assumptions (single display_name field, no forced first/last split; Telegram first+last concatenation is display-only); dates/relative times per Doc 01; directory filters localized; Amharic search tested for the customers index (Meilisearch tokenization validated in AC-07).

## 15. Edge Cases

EC-01 Two shells for the same human (Telegram + web) discovered mid-ticket → merge flow handles live objects (open tickets union per FR-12’s linked_work rule); the agent’s open thread re-points seamlessly (Doc 07 patches via customer.merged). EC-02 Phone shared by a family (one phone, three players) → phone identity sits on one profile; attempts to add it elsewhere hit FR-09’s block; the note pattern + duplicate-hint (if same player_id) support the reality without forcing a merge; documented as the intended handling in agent onboarding copy. EC-03 Telegram user deletes their account (id becomes inactive) → identity remains with history; sends fail per Doc 04/05 classifications; profile shows last-known display with an inactive hint from repeated hard failures (heuristic chip, informational). EC-04 Merge undo after the survivor was merged again (chains) → undo permitted only at the outermost link first (mirror of Doc 06 EC-03); UI explains the required order. EC-05 Unlink the only identity → allowed; original profile remains (staff data intact) with zero identities and an “unreachable” chip; the shell carries the conversations. EC-06 RG flag set by AI on a false positive → clearing requires the permission + reason; history keeps both; Doc 09’s calibration consumes clear-reasons. EC-07 Blocked customer messages via a brand-new channel identity (evasion) → new shell isn’t blocked (no auto-link, BR-02); agents can merge-then-inherit-block or block the shell; the duplicate-player_id hint often surfaces the evasion; documented limitation of the no-verification stance. EC-08 display_name collision (two “Abel T.”) → directory disambiguates with identity glyph values + short-id suffix on hover; no uniqueness constraint on names. EC-09 Custom-field definition deleted (Doc 12) while values exist → values retained read-only with a “retired field” tag (Doc 12 owns the rule; renderer here honors it). EC-10 Country/language disagreement (staff set vs newly detected) → staff-set is sticky (FR-01); a subtle chip offers “detected: Amharic — apply?” once per change. EC-11 Concurrent edits to the same profile field → last-writer-wins + conflict toast + both audited (Doc 01 EC-06 pattern). EC-12 Merge attempted where combined open linked_work would exceed Doc 06’s cap of 3 → confirm step blocks with the exact tickets listed (“resolve or transfer one first”).

## 16. Failure Scenarios

FS-01 Meilisearch down → directory search degrades to DB prefix search on names/phone (slower, labeled); panel unaffected. FS-02 Merge saga crash mid-chunk → compensation restores pre-merge pointers from the saga log; the pair is never left half-merged (T-08 chaos test proves); user notified with retry. FS-03 Rollup event handler backlog → rollups lag (counts stale) with the standard staleness pattern; weekly checker (NFR-05) heals residuals. FS-04 Blocking cascade partially applied at crash → the outbound guard (T-06 belt-and-braces) still refuses sends for the customer while a repair job completes the cascade. FS-05 Export job fails after partial archive → job restarts idempotently; partial artifacts never get signed URLs.

## 17. Success Scenarios

SS-01 An agent handles a returning player: opens the chat, sees 8 prior contacts and the pinned note (“VIP — always confirm withdrawals by voice”), resolves in 3 minutes without one repeated question. SS-02 A player’s lost-phone story (new Telegram account): agent finds the old profile by player_id, merges guided, history unified, 30-day undo unused because the choice was right — total time 90 seconds. SS-03 An RG detection flags a customer; only the two permitted staff see it; the routing/suppression contracts (FR-15) engage; the compliance export shows the full flag history. SS-04 The resolver sustains L2 inbound with p99 within budget and zero duplicate customers created under race testing.

## 18. Acceptance Criteria

AC-01 Resolver matrix (T-08): exact-match, create-shell, concurrent-first-message race (→1 customer), username-change history append, and *no* cross-type auto-link fixture (phone mentioned in chat text does NOT attach). AC-02 Identity invariants: (tenant,type,value) uniqueness enforced at DB (direct-SQL duplicate fails); FR-09 duplicate-phone block with merge shortcut verified. AC-03 Merge: full flow + 30-day undo round-trip restores byte-identical pointers (snapshot diff = ∅ for pre-merge objects); post-merge-created objects stay with survivor and are listed in the undo preview; EC-04 chain ordering enforced. AC-04 Unlink: identity moves to a new shell with exactly its conversations/tickets; typed confirmation content matches the actual move set. AC-05 RG visibility: per-role fixtures prove column-level absence (API), UI absence, cache-bypass freshness, and history immutability. AC-06 Blocking: customer-level block quiets all channels ≤ 3 s incl. the mid-cascade new-conversation guard; unblock restores; both audited. AC-07 Search: scope injection proven (Doc 03 AC-03 reuse); historical-username hits; Amharic name search returns expected results on the seeded corpus. AC-08 Panel contract: header ≤ 300 ms, sections lazy, inline edits save with provenance, all per Doc 07 slot integration test. AC-09 No-verification rule: repo-wide assertion that no customer-identity “verified” UI variant or API field exists (BR-01 design-system test).

## 19. Future Expansion

- Optional operator-side identity confirmation flows (if policy ever changes) — the provenance model already supports a confirmed_by_staff source without schema change; explicitly out of v1 per policy.

- Customer-visible profile/self-service (Doc 13 portal expansion) with self-asserted fields kept distinct by source.

- Household/related-profiles graph (formalizing EC-02’s reality) with relationship edges instead of merges.

- Enrichment connectors (if an operator ever gets betting-platform API access): a platform identity type and read-only synced fields are reserved namespaces.

- ML-assisted duplicate-profile suggestions (suggest-only, human-confirmed — BR-02 preserved).

## 20. Risks

R-01 Misattribution via wrong merges (High impact) → human-only consolidation (BR-02), guided diff, 30-day undo, high-risk permission + audit; merge-rate metric in Doc 15 for anomaly review. R-02 The no-verification stance enabling social engineering (High/accepted by policy) → mitigations are contextual honesty (“staff-collected” labels), the duplicate-player_id hint, and BR-01’s explicit reliance on the betting backoffice as the money control point; revisit trigger documented (§19 first bullet). R-03 RG flag leakage through side channels (Medium/High) → absence-not-redaction, query-level gating, scope-checked hints, AC-05; periodic red-team fixture in Doc 19. R-04 Profile data rot (stale names, dead identities) (High likelihood/Low impact) → provenance timestamps, inactive hints (EC-03), and honest display rather than false freshness. R-05 Merge performance on whale profiles (Medium/Medium) → chunked saga, async UX, NFR-02 budget with progress; tested at 10× fixture scale.

## 21. Recommendations

- Build the resolver + uniqueness index first and wire Doc 04 to it immediately — every day of traffic before merge tooling exists creates consolidation debt, so ship merge in the same milestone.

- Write the agent-onboarding page for EC-02/EC-07 realities (shared phones, on-behalf contacts, evasion limits) alongside this module; the product’s honesty stance only works if agents understand where the real control point is (the betting backoffice).

- Keep BR-01’s design-system test (AC-09) forever — the first well-meaning PR that adds a “verified” checkmark will otherwise arrive within a quarter.

- Dogfood the merge flow against deliberately messy staging data (shared phones, renamed usernames, chained merges) before agent access; EC-04’s ordering rule in particular needs real-flow validation.

- Review R-02’s revisit trigger quarterly with the operator: if impersonation-driven losses appear in *their* backoffice, the reserved confirmation flows (§19) are the designed escape hatch — not ad-hoc verification bolted into support.

*End of Document 08. Next in series: Document 09 — AI System: Chatbot, Copilot, Supervisor.*